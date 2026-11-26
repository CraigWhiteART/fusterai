<?php

namespace App\Domains\Agent\Mining\Stages;

use App\Domains\Agent\Mining\Agents\ExtractConversationAgent;
use App\Domains\Agent\Mining\BudgetExhausted;
use App\Domains\Agent\Mining\ConversationDigest;
use App\Domains\Agent\Mining\DigestBuilder;
use App\Domains\Agent\Mining\MacroDetector;
use App\Domains\Agent\Mining\MiningLlm;
use App\Domains\Agent\Mining\MiningPrompts;
use App\Domains\Agent\Mining\Models\MiningConversation;
use App\Domains\Agent\Mining\Models\MiningExtraction;
use App\Domains\Agent\Mining\Models\MiningRun;
use App\Domains\Agent\Mining\Pii\PiiScrubber;
use App\Domains\Agent\Mining\PlaybookCatalogue;
use App\Domains\Agent\Pipeline\UntrustedData;
use App\Domains\Conversation\Models\Conversation;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Stage B, one conversation at a time (one model call each: see
 * docs/agent-mining.md for why calls are not batched):
 *
 *   1. finished extraction with the same content hash and extractor version → reuse it;
 *   2. single human reply that matches an extracted macro → copy, no call;
 *   3. otherwise one history_extraction call; output enum-checked and scrubbed.
 *
 * A failing call is retried by the runner's backoff; after
 * extract.max_attempts failures the conversation is marked failed and the
 * run moves on.
 */
class Extractor
{
    public function __construct(
        private readonly DigestBuilder $digests,
        private readonly MiningPrompts $prompts,
        private readonly MiningLlm $llm,
        private readonly PiiScrubber $scrubber,
    ) {}

    public static function version(): string
    {
        return (string) config('agent_mining.extractor_version', 'extract-v1');
    }

    /** @param  list<array{slug: string, name: string, when_text: string}>  $catalogue */
    public function process(MiningRun $run, MiningConversation $row, array $catalogue): void
    {
        $conversation = Conversation::query()->where('workspace_id', $run->workspace_id)->find($row->conversation_id);
        if ($conversation === null) {
            $this->finish($row, MiningConversation::STATUS_FAILED, null, null, 'Conversation no longer exists');
            $run->bumpIn('dropped', 'conversation_gone');

            return;
        }

        $digest = $this->digests->build($conversation);
        if ($digest->humanReplies === []) {
            $this->finish($row, MiningConversation::STATUS_DONE, null, 'no_human_reply');
            $run->bumpIn('dropped', 'no_human_reply');

            return;
        }

        $version = self::version();
        $existing = MiningExtraction::query()
            ->where('conversation_id', $conversation->id)
            ->where('content_hash', $digest->contentHash)
            ->where('extractor_version', $version)
            ->first();
        if ($existing !== null) {
            $this->finish($row, MiningConversation::STATUS_DONE, $existing->id, MiningConversation::OUTCOME_REUSED);
            $run->bump(['extract_reused' => 1]);

            return;
        }

        $macroHash = count($digest->humanReplies) === 1 ? MacroDetector::hash($digest->humanReplies[0]['text']) : null;
        if ($macroHash !== null) {
            $canonical = MiningExtraction::query()
                ->where('workspace_id', $run->workspace_id)
                ->where('extractor_version', $version)
                ->where('macro_hash', $macroHash)
                ->whereNull('macro_of_id')
                ->where('conversation_id', '!=', $conversation->id)
                ->orderBy('id')
                ->first();
            if ($canonical !== null) {
                $extraction = $this->store($run, $digest, $version, [
                    'status' => MiningExtraction::STATUS_MACRO,
                    'is_support' => $canonical->is_support,
                    'situation' => $canonical->situation,
                    'language' => $canonical->language,
                    'outcome' => $canonical->outcome,
                    'data' => $canonical->data + ['macro_of' => $canonical->id],
                    'macro_hash' => $macroHash,
                    'macro_of_id' => $canonical->id,
                ]);
                $this->finish($row, MiningConversation::STATUS_DONE, $extraction->id, MiningConversation::OUTCOME_MACRO);
                $run->bump(['extract_macro_copies' => 1]);

                return;
            }
        }

        try {
            $result = $this->llm->call(
                $run,
                MiningRun::STAGE_EXTRACT,
                'history_extraction',
                new ExtractConversationAgent($this->prompts->extractSystem($catalogue)),
                $this->prompts->extractUser($digest, new UntrustedData),
                (int) config('agent_mining.extract.expected_output_tokens', 1200),
            );
        } catch (BudgetExhausted $e) {
            throw $e;
        } catch (Throwable $e) {
            $row->attempts++;
            $row->error = mb_substr(get_class($e).': '.$e->getMessage(), 0, 1000);
            if ($row->attempts >= max(1, (int) config('agent_mining.extract.max_attempts', 3))) {
                $row->status = MiningConversation::STATUS_FAILED;
                $row->save();
                $run->bump(['extract_failed' => 1]);

                return;
            }
            $row->save();

            throw $e;
        }

        $data = $this->sanitise($run, $result['data'], $digest);
        $extraction = $this->store($run, $digest, $version, [
            'status' => $data['category'] === 'support' ? MiningExtraction::STATUS_OK : MiningExtraction::STATUS_NOT_SUPPORT,
            'is_support' => $data['category'] === 'support',
            'situation' => $data['situation'],
            'language' => $data['language'],
            'outcome' => $data['outcome'],
            'data' => $data,
            'macro_hash' => $macroHash,
            'model' => $result['model'],
            'tokens_in' => $result['tokens_in'],
            'tokens_out' => $result['tokens_out'],
            'cost_micro_usd' => $result['cost_micro_usd'],
        ]);

        $this->finish($row, MiningConversation::STATUS_DONE, $extraction->id, MiningConversation::OUTCOME_NEW);
        $run->bump(['extract_new' => 1]);
        if (! $extraction->is_support) {
            $run->bumpIn('dropped', 'not_support_'.$data['category']);
        }
    }

    /**
     * Enum-check and scrub the model output. Free text that still carries an
     * identifier after scrubbing is blanked (statements and phrases dropped)
     * and counted.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function sanitise(MiningRun $run, array $raw, ConversationDigest $digest): array
    {
        $names = $digest->customerNames;
        $text = function (mixed $value, string $field) use ($run, $names): string {
            $value = trim((string) (is_scalar($value) ? $value : ''));
            if ($value === '') {
                return '';
            }
            $clean = $this->scrubber->clean(mb_substr($value, 0, 1000), $names, $reason);
            if ($clean === null) {
                $run->bumpIn('dropped', "pii_{$reason}");

                return '';
            }

            return $clean;
        };
        $enum = fn (mixed $value, array $allowed, string $default) => in_array($value, $allowed, true) ? $value : $default;

        $first = $digest->firstAt();
        $lastStaff = $digest->lastStaffAt() ?? $digest->lastAt();
        $statements = [];
        foreach (array_slice((array) ($raw['statements'] ?? []), 0, 12) as $st) {
            if (! is_array($st)) {
                continue;
            }
            $body = $text($st['text'] ?? '', 'statement');
            if ($body === '') {
                continue;
            }
            $statements[] = [
                'kind' => $enum($st['kind'] ?? null, ['policy', 'fact'], 'fact'),
                'topic' => PlaybookCatalogue::normaliseLabel((string) ($st['topic'] ?? 'general')),
                'text' => $body,
                'said_at' => $this->date($st['said_at'] ?? null, $first, $lastStaff),
            ];
        }

        $tone = is_array($raw['tone'] ?? null) ? $raw['tone'] : [];
        $phrases = [];
        foreach (array_slice((array) ($tone['phrases'] ?? []), 0, 5) as $phrase) {
            $clean = $text($phrase, 'phrase');
            if ($clean !== '' && mb_strlen($clean) <= 120) {
                $phrases[] = $clean;
            }
        }

        $human = implode("\n\n", array_column($digest->humanReplies, 'text'));

        return [
            'category' => $enum($raw['category'] ?? null, ExtractConversationAgent::CATEGORIES, 'other'),
            'situation' => PlaybookCatalogue::normaliseLabel((string) ($raw['situation'] ?? 'other')),
            'situation_is_new' => (bool) ($raw['situation_is_new'] ?? false),
            'situation_description' => $text($raw['situation_description'] ?? '', 'description'),
            'customer_asked' => $text($raw['customer_asked'] ?? '', 'customer_asked'),
            'decisions' => array_values(array_unique(array_filter(
                array_map('strval', (array) ($raw['decisions'] ?? [])),
                fn ($d) => in_array($d, ExtractConversationAgent::DECISIONS, true),
            ))),
            'decision_summary' => $text($raw['decision_summary'] ?? '', 'decision_summary'),
            'statements' => $statements,
            'tone' => [
                'greeting' => $text($tone['greeting'] ?? '', 'greeting') ?: null,
                'sign_off' => $text($tone['sign_off'] ?? '', 'sign_off') ?: null,
                'formality' => $enum($tone['formality'] ?? null, ['casual', 'neutral', 'formal'], 'neutral'),
                'humour' => (bool) ($tone['humour'] ?? false),
                'empathy' => (bool) ($tone['empathy'] ?? false),
                'apology' => (bool) ($tone['apology'] ?? false),
                'emoji' => (bool) ($tone['emoji'] ?? false),
                'phrases' => $phrases,
                // Measured, not asked: words per human reply.
                'words' => (int) round(str_word_count($human) / max(1, count($digest->humanReplies))),
            ],
            'outcome' => $enum($raw['outcome'] ?? null, ExtractConversationAgent::OUTCOMES, 'unclear'),
            'outcome_note' => $text($raw['outcome_note'] ?? '', 'outcome_note'),
            'language' => mb_substr(strtolower(preg_replace('/[^a-zA-Z-]/', '', (string) ($raw['language'] ?? 'en')) ?: 'en'), 0, 8),
            'exemplary' => (bool) ($raw['exemplary'] ?? false),
        ];
    }

    /** said_at as YYYY-MM-DD inside the conversation's dates; otherwise the last human reply's date. */
    private function date(mixed $value, ?CarbonImmutable $first, ?CarbonImmutable $fallback): ?string
    {
        try {
            $at = CarbonImmutable::parse((string) $value);
            if ($first !== null && $fallback !== null && ($at->lt($first->startOfDay()) || $at->gt($fallback->endOfDay()))) {
                throw new \InvalidArgumentException('out of range');
            }

            return $at->toDateString();
        } catch (Throwable) {
            return $fallback?->toDateString();
        }
    }

    /** @param  array<string, mixed>  $attributes */
    private function store(MiningRun $run, ConversationDigest $digest, string $version, array $attributes): MiningExtraction
    {
        try {
            // Own transaction (a savepoint when nested): a unique violation must
            // not abort an outer transaction on Postgres.
            return DB::transaction(fn () => MiningExtraction::query()->create($attributes + [
                'workspace_id' => $run->workspace_id,
                'conversation_id' => $digest->conversationId,
                'content_hash' => $digest->contentHash,
                'extractor_version' => $version,
                'staff_keys' => $digest->staffKeys(),
                'first_at' => $digest->firstAt(),
                'last_at' => $digest->lastAt(),
                'last_staff_at' => $digest->lastStaffAt(),
                'run_id' => $run->id,
            ]));
        } catch (UniqueConstraintViolationException) {
            return MiningExtraction::query()
                ->where('conversation_id', $digest->conversationId)
                ->where('content_hash', $digest->contentHash)
                ->where('extractor_version', $version)
                ->firstOrFail();
        }
    }

    private function finish(MiningConversation $row, string $status, ?int $extractionId, ?string $outcome, ?string $error = null): void
    {
        $row->forceFill([
            'status' => $status,
            'extraction_id' => $extractionId,
            'outcome' => $outcome,
            'error' => $error,
        ])->save();
    }
}

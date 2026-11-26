<?php

namespace App\Domains\Agent\Mining\Stages;

use App\Domains\Agent\Mining\Agents\VoiceProfileAgent;
use App\Domains\Agent\Mining\DigestBuilder;
use App\Domains\Agent\Mining\MiningLlm;
use App\Domains\Agent\Mining\MiningPrompts;
use App\Domains\Agent\Mining\Models\MiningExtraction;
use App\Domains\Agent\Mining\Models\MiningRun;
use App\Domains\Agent\Mining\Pii\PiiScrubber;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\VoiceVersion;
use App\Domains\Agent\Pipeline\UntrustedData;
use App\Domains\Conversation\Models\Conversation;
use Illuminate\Support\Facades\DB;

/**
 * Stage E: brand voice from ~150 human replies (role manager, one call).
 *
 * Sample: every staff member gets at least min(their replies, 30% of the
 * sample) and the rest goes by share; inside one staff member's quota the
 * pick goes round-robin across situations, newest first. A canned reply is
 * used once. Replies are scrubbed before the model sees them; every text
 * the model returns (items, example phrases) is scrubbed and post-checked.
 */
class VoiceProfiler
{
    public function __construct(
        private readonly DigestBuilder $digests,
        private readonly MiningPrompts $prompts,
        private readonly MiningLlm $llm,
        private readonly PiiScrubber $scrubber,
    ) {}

    public function profile(MiningRun $run): void
    {
        $picks = $this->sample($run);
        if ($picks === []) {
            $run->setResult('voice', null);
            $run->setCounters(['voice_sample' => 0]);

            return;
        }

        $replies = [];
        $names = [];
        $staff = [];
        $maxChars = max(200, (int) config('agent_mining.voice.reply_max_chars', 900));
        foreach ($picks as $pick) {
            $conversation = Conversation::query()->find($pick['conversation_id']);
            if ($conversation === null) {
                continue;
            }
            $digest = $this->digests->build($conversation);
            $reply = collect($digest->humanReplies)->firstWhere('staff_key', $pick['staff_key']) ?? ($digest->humanReplies[0] ?? null);
            if ($reply === null) {
                continue;
            }
            $names = [...$names, ...$digest->customerNames];
            $text = trim($this->scrubber->scrub($reply['text'], $digest->customerNames));
            $staff[$reply['staff_key']] ??= ['label' => $reply['staff_name'], 'replies' => 0];
            $staff[$reply['staff_key']]['replies']++;
            $replies[] = [
                'staff' => $reply['staff_name'],
                'situation' => $pick['situation'],
                'date' => $reply['at']->toDateString(),
                'text' => mb_strlen($text) > $maxChars ? mb_substr($text, 0, $maxChars).' [...]' : $text,
            ];
        }
        $names = array_values(array_unique($names));

        $settings = AgentSetting::forWorkspace($run->workspace_id);
        $live = $settings->live_voice_version_id !== null ? VoiceVersion::query()->find($settings->live_voice_version_id) : null;
        $labels = array_values(array_unique(array_column($staff, 'label')));

        $hash = MiningLlm::inputHash((string) config('agent_mining.voice_version', 'voice-v1'), [$live?->id, $labels, $replies]);
        $result = $this->llm->call(
            $run,
            MiningRun::STAGE_VOICE,
            'manager',
            new VoiceProfileAgent($this->prompts->voiceSystem($live, $labels)),
            $this->prompts->voiceUser($replies, new UntrustedData),
            (int) config('agent_mining.voice.expected_output_tokens', 6000),
            'voice',
            $hash,
        );

        $run->setResult('voice', $this->sanitise($run, $result['data'], $live, $names) + [
            'staff' => array_values($staff),
            'sample_size' => count($replies),
            'base_voice_version_id' => $live?->id,
            'cached' => $result['cached'],
        ]);
        $run->setCounters(['voice_sample' => count($replies)]);
    }

    /** @return list<array{conversation_id: int, staff_key: string, situation: string}> */
    public function sample(MiningRun $run): array
    {
        $size = max(1, (int) config('agent_mining.voice.sample_size', 150));
        $rows = DB::table('agent_mining_conversations as mc')
            ->join('agent_mining_extractions as e', 'e.id', '=', 'mc.extraction_id')
            ->where('mc.run_id', $run->id)
            ->where('e.is_support', true)
            ->where('e.status', MiningExtraction::STATUS_OK) // canned copies add nothing to the voice
            ->orderByDesc('e.last_staff_at')
            ->orderByDesc('e.id')
            ->get(['e.conversation_id', 'e.staff_keys', 'e.situation']);

        // staff key => situation => [conversation ids newest first]
        $pool = [];
        foreach ($rows as $row) {
            $keys = json_decode((string) $row->staff_keys, true) ?: ['staff'];
            foreach ($keys as $key) {
                $pool[(string) $key][(string) ($row->situation ?? 'other')][] = (int) $row->conversation_id;
            }
        }
        if ($pool === []) {
            return [];
        }

        $totals = array_map(fn ($bySituation) => array_sum(array_map('count', $bySituation)), $pool);
        $all = array_sum($totals);
        // Floor per staff member (30% of the sample, or an equal split when
        // there are more than three), then the rest by share, then top up.
        $floor = count($totals) > 3 ? intdiv($size, count($totals)) : (int) floor($size * 0.3);
        $quotas = array_map(fn ($n) => min($n, $floor), $totals);
        $spare = $size - array_sum($quotas);
        arsort($totals);
        foreach ($totals as $key => $n) {
            $add = min($n - $quotas[$key], max(0, (int) floor($spare * $n / max(1, $all))));
            $quotas[$key] += $add;
        }
        foreach (array_keys($totals) as $key) {
            while (array_sum($quotas) < $size && $quotas[$key] < $totals[$key]) {
                $quotas[$key]++;
            }
        }

        $picks = [];
        $seen = [];
        foreach ($pool as $key => $bySituation) {
            $taken = 0;
            $cursor = array_fill_keys(array_keys($bySituation), 0);
            while ($taken < $quotas[$key]) {
                $progress = false;
                foreach ($bySituation as $situation => $ids) {
                    if ($taken >= $quotas[$key]) {
                        break;
                    }
                    while (isset($ids[$cursor[$situation]]) && isset($seen[$ids[$cursor[$situation]]])) {
                        $cursor[$situation]++;
                    }
                    if (! isset($ids[$cursor[$situation]])) {
                        continue;
                    }
                    $id = $ids[$cursor[$situation]++];
                    $seen[$id] = true;
                    $picks[] = ['conversation_id' => $id, 'staff_key' => (string) $key, 'situation' => (string) $situation];
                    $taken++;
                    $progress = true;
                }
                if (! $progress) {
                    break;
                }
            }
        }

        return array_slice($picks, 0, $size);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $names
     * @return array<string, mixed>
     */
    private function sanitise(MiningRun $run, array $raw, ?VoiceVersion $live, array $names): array
    {
        $text = function (mixed $value, int $max = 500, bool $strict = true) use ($run, $names): ?string {
            $value = trim((string) (is_scalar($value) ? $value : ''));
            if ($value === '') {
                return null;
            }
            $clean = $this->scrubber->clean(mb_substr($value, 0, $max), $names, $reason, $strict);
            if ($clean === null) {
                $run->bumpIn('dropped', "pii_{$reason}");
            }

            return $clean === '' ? null : $clean;
        };

        $out = [];
        foreach (VoiceVersion::LIST_FIELDS as $field) {
            $ids = array_column($live->{$field} ?? [], 'id');
            $ops = [];
            foreach ((array) ($raw[$field] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $op = in_array($item['op'] ?? null, ['add', 'replace', 'retire'], true) ? $item['op'] : 'add';
                $id = is_string($item['id'] ?? null) && in_array($item['id'], $ids, true) ? $item['id'] : null;
                if ($op !== 'add' && $id === null) {
                    if ($op === 'retire') {
                        continue;
                    }
                    $op = 'add';
                }
                $body = $op === 'retire' ? '' : $text($item['text'] ?? '', 500, in_array($field, ['do_phrases', 'dont_phrases'], true));
                if ($op !== 'retire' && $body === null) {
                    continue;
                }
                $ops[] = ['op' => $op, 'id' => $id, 'text' => (string) $body];
            }
            $out[$field] = $ops;
        }

        $examples = [];
        foreach (array_slice((array) ($raw['example_phrases'] ?? []), 0, 15) as $ex) {
            $phrase = is_array($ex) ? $text($ex['text'] ?? '', 200, false) : null;
            if ($phrase !== null && str_word_count($phrase) <= 20) {
                $examples[] = ['text' => $phrase, 'staff' => mb_substr((string) ($ex['staff'] ?? ''), 0, 80)];
            }
        }

        $differences = [];
        foreach ((array) ($raw['staff_differences'] ?? []) as $d) {
            $note = is_array($d) ? $text($d['note'] ?? '', 500, false) : null;
            if ($note !== null) {
                $differences[] = ['topic' => mb_substr((string) ($d['topic'] ?? ''), 0, 80), 'note' => $note];
            }
        }

        $maxWords = $raw['max_words'] ?? null;

        return $out + [
            'personality' => $text($raw['personality'] ?? '', 4000),
            'max_words' => is_numeric($maxWords) ? max(20, min(2000, (int) $maxWords)) : null,
            'length_guidance' => $text($raw['length_guidance'] ?? '', 2000),
            'staff_differences' => $differences,
            'example_phrases' => $examples,
            'rationale' => $text($raw['rationale'] ?? '', 2000, false) ?? '',
        ];
    }
}

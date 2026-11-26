<?php

namespace App\Domains\Agent\Mining\Stages;

use App\Domains\Agent\Mining\Agents\ExtractConversationAgent;
use App\Domains\Agent\Mining\Agents\SynthesiseSituationAgent;
use App\Domains\Agent\Mining\BudgetExhausted;
use App\Domains\Agent\Mining\DigestBuilder;
use App\Domains\Agent\Mining\MiningLlm;
use App\Domains\Agent\Mining\MiningPrompts;
use App\Domains\Agent\Mining\Models\MiningExtraction;
use App\Domains\Agent\Mining\Models\MiningRun;
use App\Domains\Agent\Mining\Models\MiningSituation;
use App\Domains\Agent\Mining\Pii\PiiScrubber;
use App\Domains\Agent\Mining\PlaybookCatalogue;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Pipeline\UntrustedData;
use App\Domains\Conversation\Models\Conversation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Stage D, one situation per call (role manager).
 *
 * Sample (synthesis.sample_size): unusual cases first (customer came back
 * unhappy or unresolved, decisions rare in this situation) up to
 * outlier_share, then the rest by age tier: half from the newest third,
 * 30% from the middle, 20% from the oldest. A canned reply counts once,
 * with its number of uses. Every policy / fact statement of the situation
 * (deduplicated, newest first) goes in as well, for inconsistencies.
 *
 * The output is checked in code: evidence ids must belong to the situation
 * (a rule left without evidence is dropped), rule / step ids must exist in
 * the live version, knowledge scope is normalised, inconsistency and
 * knowledge dates come from the evidence conversations, and every text
 * passes the PII scrubber and post-check (failures dropped and counted).
 */
class Synthesiser
{
    /** Extraction decision => action key a human might enable. */
    public const DECISION_ACTIONS = [
        'refund_full' => 'refund',
        'refund_partial' => 'refund',
        'replacement' => 'replacement',
        'reship' => 'replacement',
        'discount_offered' => 'discount_code',
        'cancelled_order' => 'cancel_order',
        'address_change' => 'change_address',
    ];

    public function __construct(
        private readonly DigestBuilder $digests,
        private readonly MiningPrompts $prompts,
        private readonly MiningLlm $llm,
        private readonly PiiScrubber $scrubber,
        private readonly PlaybookCatalogue $catalogue,
    ) {}

    public function process(MiningRun $run, MiningSituation $situation): void
    {
        $extractions = MiningExtraction::query()->whereIn('id', $situation->extraction_ids)->get()->keyBy('id');
        $playbook = $situation->playbook_id !== null
            ? Playbook::with('liveVersion')->where('workspace_id', $run->workspace_id)->find($situation->playbook_id)
            : null;
        $live = $playbook?->liveVersion;
        $slugs = array_column($this->catalogue->forWorkspace($run->workspace_id), 'slug');
        $slug = $playbook->slug ?? $situation->key;

        $byConversation = $extractions->keyBy('conversation_id');
        $sample = $this->sample($extractions);
        $names = $this->customerNames($extractions->pluck('conversation_id')->all());

        $samples = [];
        foreach ($sample as $item) {
            /** @var MiningExtraction $e */
            $e = $item['extraction'];
            $conversation = Conversation::query()->find($e->conversation_id);
            $digest = $conversation !== null ? $this->digests->build($conversation) : null;
            $reply = $digest !== null && $digest->humanReplies !== [] ? $digest->humanReplies[0] : null;
            $convNames = $digest->customerNames ?? [];
            $data = $e->data;
            $samples[] = [
                'id' => $e->conversation_id,
                'date' => $e->last_staff_at?->toDateString() ?? $e->last_at?->toDateString() ?? '?',
                'staff' => $reply['staff_name'] ?? 'Staff',
                'outcome' => (string) ($e->outcome ?? 'unclear'),
                'decisions' => $e->decisions(),
                'decision_summary' => (string) ($data['decision_summary'] ?? ''),
                'customer_asked' => (string) ($data['customer_asked'] ?? ''),
                'statements' => array_values((array) ($data['statements'] ?? [])),
                'customer_excerpt' => $digest !== null ? $this->excerpt($digest->firstCustomerText(), $convNames, (int) config('agent_mining.synthesis.customer_max_chars', 500)) : '',
                'reply_excerpt' => $reply !== null ? $this->excerpt($reply['text'], $convNames, (int) config('agent_mining.synthesis.reply_max_chars', 800)) : '',
                'macro_uses' => $item['uses'],
            ];
        }

        $statements = $this->statements($extractions);
        $dates = $extractions->map(fn (MiningExtraction $e) => $e->last_staff_at ?? $e->last_at)->filter();
        $coverage = [
            'count' => $situation->conversation_count,
            'from' => $dates->min()?->toDateString(),
            'to' => $dates->max()?->toDateString(),
            'shown' => count($samples),
        ];

        $actions = (array) config('agent.actions', []);
        $hash = MiningLlm::inputHash((string) config('agent_mining.synthesis_version', 'synth-v1'), [
            $situation->key, $situation->name, $live?->id, $slugs, array_keys($actions), $samples, $statements, $coverage,
        ]);

        try {
            $result = $this->llm->call(
                $run,
                MiningRun::STAGE_SYNTHESISE,
                'manager',
                new SynthesiseSituationAgent($this->prompts->synthSystem($situation->name, $playbook?->slug, $live, $slugs, $actions), array_keys($actions)),
                $this->prompts->synthUser($samples, $statements, $coverage, new UntrustedData),
                (int) config('agent_mining.synthesis.expected_output_tokens', 6000),
                'synthesis',
                $hash,
            );
        } catch (BudgetExhausted $e) {
            throw $e;
        } catch (Throwable $e) {
            $situation->attempts++;
            $situation->error = mb_substr(get_class($e).': '.$e->getMessage(), 0, 1000);
            if ($situation->attempts >= max(1, (int) config('agent_mining.synthesis.max_attempts', 3))) {
                $situation->status = MiningSituation::STATUS_FAILED;
                $situation->save();
                $run->bump(['synthesis_failed' => 1]);

                return;
            }
            $situation->save();

            throw $e;
        }

        $synthesis = $this->sanitise(
            $run,
            $result['data'],
            $situation,
            $live?->toContentArray(),
            $slug,
            $slugs,
            $byConversation,
            array_column($samples, 'id'),
            $names,
        );
        $synthesis['coverage'] = $coverage;
        $synthesis['sample_ids'] = array_column($samples, 'id');
        $synthesis['playbook_slug'] = $slug;
        $synthesis['cached'] = $result['cached'];

        $situation->forceFill(['synthesis' => $synthesis, 'status' => MiningSituation::STATUS_SYNTHESISED, 'error' => null])->save();
        $run->bump(['situations_synthesised' => 1]);
    }

    /**
     * @param  Collection<int, MiningExtraction>  $extractions
     * @return list<array{extraction: MiningExtraction, uses: int}>
     */
    public function sample(Collection $extractions): array
    {
        $size = max(1, (int) config('agent_mining.synthesis.sample_size', 40));

        // A canned reply counts once (its canonical, or its first copy here).
        $uses = [];
        $candidates = [];
        foreach ($extractions->sortBy('id') as $e) {
            $root = $e->macro_of_id !== null && $extractions->has($e->macro_of_id) ? $e->macro_of_id : $e->id;
            $uses[$root] = ($uses[$root] ?? 0) + 1;
            if ($root === $e->id) {
                $candidates[$e->id] = $e;
            }
        }
        if (count($candidates) <= $size) {
            return array_values(array_map(fn ($e) => ['extraction' => $e, 'uses' => $uses[$e->id] ?? 1], $this->newestFirst($candidates)));
        }

        $decisionCounts = [];
        foreach ($candidates as $e) {
            foreach ($e->decisions() as $d) {
                $decisionCounts[$d] = ($decisionCounts[$d] ?? 0) + 1;
            }
        }
        $rare = max(1, (int) floor(count($candidates) * 0.15));

        $picked = [];
        $outlierCap = (int) floor($size * (float) config('agent_mining.synthesis.outlier_share', 0.3));
        foreach ($this->newestFirst($candidates) as $e) {
            if (count($picked) >= $outlierCap) {
                break;
            }
            $unusual = in_array($e->outcome, ['came_back_unhappy', 'unresolved'], true)
                || array_filter($e->decisions(), fn ($d) => ($decisionCounts[$d] ?? 0) <= $rare) !== [];
            if ($unusual) {
                $picked[$e->id] = $e;
            }
        }

        $rest = array_values(array_filter($this->newestFirst($candidates), fn ($e) => ! isset($picked[$e->id])));
        $third = (int) ceil(count($rest) / 3);
        $tiers = [array_slice($rest, 0, $third), array_slice($rest, $third, $third), array_slice($rest, 2 * $third)];
        $left = $size - count($picked);
        $quotas = [(int) round($left * 0.5), (int) round($left * 0.3)];
        $quotas[] = $left - $quotas[0] - $quotas[1];

        // Spare quota of a short tier moves to the next one.
        $carry = 0;
        foreach ($tiers as $t => $tier) {
            $take = min(count($tier), $quotas[$t] + $carry);
            $carry = $quotas[$t] + $carry - $take;
            // Spread through the tier instead of taking its newest only.
            $step = $take > 0 ? count($tier) / $take : 1;
            for ($k = 0; $k < $take; $k++) {
                $e = $tier[(int) floor($k * $step)];
                $picked[$e->id] = $e;
            }
        }

        return array_values(array_map(fn ($e) => ['extraction' => $e, 'uses' => $uses[$e->id] ?? 1], $this->newestFirst($picked)));
    }

    /**
     * @param  array<int, MiningExtraction>  $items
     * @return list<MiningExtraction>
     */
    private function newestFirst(array $items): array
    {
        $items = array_values($items);
        usort($items, fn (MiningExtraction $a, MiningExtraction $b) => [$b->last_at?->getTimestamp() ?? 0, $b->id] <=> [$a->last_at?->getTimestamp() ?? 0, $a->id]);

        return $items;
    }

    /**
     * Every statement in the situation, deduplicated by topic + text, newest first.
     *
     * @param  Collection<int, MiningExtraction>  $extractions
     * @return list<array{id: int, date: string, kind: string, topic: string, text: string}>
     */
    private function statements(Collection $extractions): array
    {
        $out = [];
        foreach ($extractions as $e) {
            if ($e->status === MiningExtraction::STATUS_MACRO) {
                continue; // same text as its canonical
            }
            foreach ((array) ($e->data['statements'] ?? []) as $st) {
                $key = ($st['topic'] ?? '').'|'.mb_strtolower(preg_replace('/\W+/u', ' ', (string) ($st['text'] ?? '')) ?? '');
                $row = [
                    'id' => $e->conversation_id,
                    'date' => (string) ($st['said_at'] ?? $e->last_staff_at?->toDateString() ?? ''),
                    'kind' => (string) ($st['kind'] ?? 'fact'),
                    'topic' => (string) ($st['topic'] ?? 'general'),
                    'text' => (string) ($st['text'] ?? ''),
                ];
                if (! isset($out[$key]) || strcmp($row['date'], $out[$key]['date']) > 0) {
                    $out[$key] = $row;
                }
            }
        }
        $out = array_values($out);
        usort($out, fn ($a, $b) => [$b['date'], $b['id']] <=> [$a['date'], $a['id']]);

        return array_slice($out, 0, max(1, (int) config('agent_mining.synthesis.statement_limit', 80)));
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>|null  $live
     * @param  list<string>  $slugs
     * @param  Collection<int, MiningExtraction>  $byConversation
     * @param  list<int>  $sampleIds
     * @param  list<string>  $names
     * @return array<string, mixed>
     */
    public function sanitise(MiningRun $run, array $raw, MiningSituation $situation, ?array $live, string $slug, array $slugs, Collection $byConversation, array $sampleIds, array $names): array
    {
        $allowed = array_flip(array_map('intval', $byConversation->keys()->all()));
        $evidence = function (mixed $ids) use ($allowed): array {
            $out = [];
            foreach ((array) $ids as $id) {
                if (is_numeric($id) && isset($allowed[(int) $id])) {
                    $out[(int) $id] = (int) $id;
                }
            }

            return array_values($out);
        };
        $text = function (mixed $value, int $max = 1000, bool $strict = true) use ($run, $names): ?string {
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
        $lastSeen = function (array $ids) use ($byConversation): ?string {
            $dates = array_filter(array_map(fn ($id) => $byConversation->get($id)->last_staff_at ?? $byConversation->get($id)->last_at ?? null, $ids));

            return $dates === [] ? null : Carbon::parse(max(array_map(fn ($d) => $d->toIso8601String(), $dates)))->toDateString();
        };
        $drop = fn (string $why) => $run->bumpIn('dropped', $why);

        $ruleIds = array_column((array) ($live['rules'] ?? []), 'id');
        $stepIds = array_column((array) ($live['steps'] ?? []), 'id');

        $rules = [];
        foreach ((array) ($raw['rules'] ?? []) as $r) {
            if (! is_array($r)) {
                continue;
            }
            $ev = $evidence($r['evidence'] ?? []);
            if ($ev === []) {
                $drop('rule_no_evidence');

                continue;
            }
            $op = in_array($r['op'] ?? null, ['add', 'replace', 'retire'], true) ? $r['op'] : 'add';
            $id = is_string($r['rule_id'] ?? null) && in_array($r['rule_id'], $ruleIds, true) ? $r['rule_id'] : null;
            if ($op !== 'add' && $id === null) {
                if ($op === 'retire') {
                    $drop('rule_unknown_id');

                    continue;
                }
                $op = 'add';
            }
            $then = $op === 'retire' ? '' : $text($r['then'] ?? '');
            $if = $op === 'retire' ? 'always' : ($text($r['if'] ?? '', 500) ?? 'always');
            if ($op !== 'retire' && $then === null) {
                continue;
            }
            $rules[] = ['op' => $op, 'rule_id' => $id, 'if' => $if, 'then' => (string) $then, 'evidence' => $ev, 'rationale' => $text($r['rationale'] ?? '', 500, false) ?? ''];
        }

        $steps = [];
        foreach ((array) ($raw['steps'] ?? []) as $s) {
            if (! is_array($s)) {
                continue;
            }
            $ev = $evidence($s['evidence'] ?? []);
            if ($ev === []) {
                $drop('step_no_evidence');

                continue;
            }
            $op = in_array($s['op'] ?? null, ['add', 'replace', 'retire'], true) ? $s['op'] : 'add';
            $id = is_string($s['step_id'] ?? null) && in_array($s['step_id'], $stepIds, true) ? $s['step_id'] : null;
            if ($op !== 'add' && $id === null) {
                if ($op === 'retire') {
                    continue;
                }
                $op = 'add';
            }
            $body = $op === 'retire' ? '' : $text($s['text'] ?? '', 500);
            if ($op !== 'retire' && $body === null) {
                continue;
            }
            $steps[] = ['op' => $op, 'step_id' => $id, 'text' => (string) $body, 'evidence' => $ev];
        }

        $knowledge = [];
        foreach ((array) ($raw['knowledge'] ?? []) as $k) {
            if (! is_array($k)) {
                continue;
            }
            $ev = $evidence($k['evidence'] ?? []);
            $body = $text($k['body'] ?? '', 2000);
            if ($ev === [] || $body === null) {
                $drop($ev === [] ? 'knowledge_no_evidence' : 'knowledge_empty');

                continue;
            }
            $scope = is_array($k['scope'] ?? null) ? $k['scope'] : [];
            $playbooks = array_values(array_intersect(array_map('strval', (array) ($scope['playbooks'] ?? [])), [...$slugs, $slug]));
            $countries = array_values(array_filter(array_map(fn ($c) => strtoupper(trim((string) $c)), (array) ($scope['countries'] ?? [])), fn ($c) => preg_match('/^[A-Z]{2}$/', $c) === 1));
            $products = array_values(array_filter(array_map(fn ($p) => $text($p, 60), (array) ($scope['products'] ?? []))));
            $global = ($scope['global'] ?? false) === true;
            if (! $global && $playbooks === [] && $countries === [] && $products === []) {
                $playbooks = [$slug]; // never unscoped: default to the situation it came from
            }
            $knowledge[] = [
                'kind' => in_array($k['kind'] ?? null, ['policy', 'fact'], true) ? $k['kind'] : 'fact',
                'topic' => PlaybookCatalogue::normaliseLabel((string) ($k['topic'] ?? 'general')),
                'title' => $text($k['title'] ?? '', 200),
                'body' => $body,
                'scope' => ['global' => $global, 'playbooks' => $playbooks, 'products' => $products, 'countries' => $countries],
                'evidence' => $ev,
                'last_seen' => $lastSeen($ev),
            ];
        }

        $inconsistencies = [];
        foreach ((array) ($raw['inconsistencies'] ?? []) as $inc) {
            if (! is_array($inc)) {
                continue;
            }
            $versions = [];
            foreach ((array) ($inc['versions'] ?? []) as $v) {
                $ev = is_array($v) ? $evidence($v['evidence'] ?? []) : [];
                $statement = is_array($v) ? $text($v['statement'] ?? '', 500) : null;
                if ($ev !== [] && $statement !== null) {
                    $versions[] = ['statement' => $statement, 'evidence' => $ev, 'last_seen' => $lastSeen($ev)];
                }
            }
            if (count($versions) < 2) {
                $drop('inconsistency_unsupported');

                continue;
            }
            usort($versions, fn ($a, $b) => strcmp((string) $b['last_seen'], (string) $a['last_seen']));
            $inconsistencies[] = [
                'topic' => PlaybookCatalogue::normaliseLabel((string) ($inc['topic'] ?? 'general')),
                'summary' => $text($inc['summary'] ?? '', 600, false) ?? '',
                'versions' => $versions,
                'most_recent' => $versions[0]['statement'],
                'recommendation' => $text($inc['recommendation'] ?? '', 600) ?? 'Use the most recent version: '.$versions[0]['statement'],
            ];
        }

        $actionKeys = array_keys((array) config('agent.actions', []));
        $suggested = [];
        foreach ((array) ($raw['suggested_actions'] ?? []) as $a) {
            $ev = is_array($a) ? $evidence($a['evidence'] ?? []) : [];
            if ($ev !== [] && in_array($a['action'] ?? null, $actionKeys, true)) {
                $suggested[] = ['action' => $a['action'], 'when' => $text($a['when'] ?? '', 300) ?? '', 'evidence' => $ev];
            }
        }

        $whenChanged = $live === null || (bool) ($raw['when_text_changed'] ?? false);

        return [
            'name' => $text($raw['name'] ?? '', 120) ?? $situation->name,
            'when_text' => $whenChanged ? ($text($raw['when_text'] ?? '', 2000) ?? $situation->description) : null,
            'rules' => $rules,
            'steps' => $steps,
            'escalation' => $text($raw['escalation'] ?? '', 2000),
            'escalation_evidence' => $evidence($raw['escalation_evidence'] ?? []),
            'required_evidence' => array_values(array_intersect(array_map('strval', (array) ($raw['required_evidence'] ?? [])), ['order', 'tracking', 'photos'])),
            'suggested_actions' => $suggested,
            'knowledge' => $knowledge,
            'inconsistencies' => $inconsistencies,
            'test_cases' => $this->testCases($run, (array) ($raw['test_cases'] ?? []), $byConversation, $sampleIds, $text),
            'rationale' => $text($raw['rationale'] ?? '', 2000, false) ?? '',
        ];
    }

    /**
     * 5-10 test-case candidates from the sample; topped up with
     * representative picks (newest resolved first) when the model gave too few.
     *
     * @param  list<mixed>  $raw
     * @param  Collection<int, MiningExtraction>  $byConversation
     * @param  list<int>  $sampleIds
     * @return list<array<string, mixed>>
     */
    private function testCases(MiningRun $run, array $raw, Collection $byConversation, array $sampleIds, callable $text): array
    {
        $min = (int) config('agent_mining.synthesis.test_cases_min', 5);
        $max = (int) config('agent_mining.synthesis.test_cases_max', 10);
        $inSample = array_flip($sampleIds);
        $cases = [];

        $list = fn (mixed $items) => array_values(array_filter(array_map(fn ($i) => $text($i, 200), array_slice((array) $items, 0, 6))));

        foreach ($raw as $tc) {
            $id = is_array($tc) && is_numeric($tc['conversation_id'] ?? null) ? (int) $tc['conversation_id'] : null;
            if ($id === null || ! isset($inSample[$id]) || isset($cases[$id]) || count($cases) >= $max) {
                continue;
            }
            $cases[$id] = [
                'conversation_id' => $id,
                'kind' => ($tc['kind'] ?? '') === 'edge' ? 'edge' : 'representative',
                'why' => $text($tc['why'] ?? '', 300, false) ?? '',
                'must_include' => $list($tc['must_include'] ?? []),
                'must_not_include' => $list($tc['must_not_include'] ?? []),
                'expected_outcome' => ($tc['expected_outcome'] ?? 'reply') === 'handover' ? 'handover' : 'draft',
                'expected_actions' => $this->expectedActions($byConversation->get($id)),
            ];
        }

        if (count($cases) < $min) {
            $fill = collect($sampleIds)
                ->map(fn ($id) => $byConversation->get($id))
                ->filter()
                ->sortByDesc(fn (MiningExtraction $e) => [in_array($e->outcome, ['thanked', 'resolved_quietly'], true) ? 1 : 0, (bool) ($e->data['exemplary'] ?? false) ? 1 : 0, $e->last_at?->getTimestamp() ?? 0]);
            foreach ($fill as $e) {
                if (count($cases) >= $min) {
                    break;
                }
                if (isset($cases[$e->conversation_id])) {
                    continue;
                }
                $cases[$e->conversation_id] = [
                    'conversation_id' => $e->conversation_id,
                    'kind' => 'representative',
                    'why' => 'Representative case (picked by code).',
                    'must_include' => [],
                    'must_not_include' => [],
                    'expected_outcome' => 'draft',
                    'expected_actions' => $this->expectedActions($e),
                ];
            }
        }

        return array_values($cases);
    }

    /** @return list<string> */
    private function expectedActions(?MiningExtraction $e): array
    {
        if ($e === null) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($d) => self::DECISION_ACTIONS[$d] ?? null,
            array_intersect($e->decisions(), ExtractConversationAgent::DECISIONS),
        ))));
    }

    /**
     * @param  list<int>  $conversationIds
     * @return list<string>
     */
    private function customerNames(array $conversationIds): array
    {
        $names = [];
        foreach (array_chunk($conversationIds, 1000) as $chunk) {
            $rows = DB::table('conversations as c')
                ->join('customers as cu', 'cu.id', '=', 'c.customer_id')
                ->whereIn('c.id', $chunk)
                ->get(['cu.name', 'cu.email']);
            foreach ($rows as $row) {
                $names = [...$names, ...PiiScrubber::namesFrom($row->name, $row->email)];
            }
        }

        return array_values(array_unique($names));
    }

    /** @param  list<string>  $names */
    private function excerpt(string $text, array $names, int $max): string
    {
        $text = trim($this->scrubber->scrub($text, $names));

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).' [...]' : $text;
    }
}

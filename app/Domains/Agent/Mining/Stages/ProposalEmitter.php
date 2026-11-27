<?php

namespace App\Domains\Agent\Mining\Stages;

use App\Domains\Agent\Mining\DigestBuilder;
use App\Domains\Agent\Mining\Models\MiningRun;
use App\Domains\Agent\Mining\Models\MiningSituation;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\Proposal;
use App\Domains\Agent\Models\ProposalChange;
use App\Domains\Agent\Models\VoiceVersion;
use App\Domains\Agent\Proposals\InvalidProposalException;
use App\Domains\Agent\Proposals\ProposalService;
use App\Domains\Agent\Proposals\ProposalStateException;
use App\Domains\Agent\Simulation\FrozenCaseFactory;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use InvalidArgumentException;

/**
 * Stage F: the run's results as proposals (origin "mining"), through
 * ProposalService (docs/agent-proposals.md). One per synthesised situation,
 * one for the voice, one summary of the inconsistencies that need a human
 * decision. Test cases are create_test_case ops on frozen cases
 * (FrozenCaseFactory), so they become active only on approval.
 *
 * Idempotent across runs. Each proposal carries evidence.mining.key and a
 * hash of its changes. For a key that already has a mining proposal:
 *   open (draft / ready / stale)      same hash: left alone; else its changes are replaced
 *   testing (gate running)            left alone
 *   applied / rejected / reverted     never touched; same hash: nothing new; else a new proposal
 * One item per slice, checkpointed in results.proposals.
 */
class ProposalEmitter
{
    public const SUMMARY = 'summary';

    public const VOICE = 'voice';

    public function __construct(
        private readonly ProposalService $proposals,
        private readonly FrozenCaseFactory $frozen,
        private readonly DigestBuilder $digests,
    ) {}

    /** Emits the next item; false when everything is emitted. */
    public function emitNext(MiningRun $run): bool
    {
        $done = (array) $run->result('proposals', []);

        foreach ($this->situations($run) as $situation) {
            $key = 'situation:'.$situation->key;
            if (! isset($done[$key])) {
                $this->record($run, $key, $this->emitSituation($run, $situation, $key));

                return true;
            }
        }

        if (! isset($done[self::VOICE])) {
            $this->record($run, self::VOICE, $this->emitVoice($run));

            return true;
        }

        if (! isset($done[self::SUMMARY])) {
            $this->record($run, self::SUMMARY, $this->emitSummary($run));

            return true;
        }

        return false;
    }

    /** @return iterable<MiningSituation> */
    private function situations(MiningRun $run): iterable
    {
        return MiningSituation::query()
            ->where('run_id', $run->id)
            ->where('status', MiningSituation::STATUS_SYNTHESISED)
            ->orderByDesc('conversation_count')
            ->orderBy('id')
            ->get();
    }

    // ── Situation ─────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function emitSituation(MiningRun $run, MiningSituation $situation, string $key): array
    {
        $s = (array) $situation->synthesis;
        $playbook = $situation->playbook_id !== null
            ? Playbook::with('liveVersion')->where('workspace_id', $run->workspace_id)->whereNull('archived_at')->find($situation->playbook_id)
            : null;
        $slug = $playbook->slug ?? str_replace('.', '_', $situation->key);
        $name = (string) ($s['name'] ?? $situation->name);

        $changes = [];
        $playbookChange = $playbook !== null ? $this->playbookOps($playbook, $s) : $this->createPlaybookOps($slug, $name, $situation, $s);
        if ($playbookChange !== null) {
            $changes[] = $playbookChange;
        }
        $playbookCreated = $playbook === null && $playbookChange !== null;

        foreach ($this->situationKnowledge($run, $s, $playbookCreated ? $slug : null) as $k) {
            $changes[] = ['target_type' => ProposalChange::TARGET_KNOWLEDGE, 'target_id' => null, 'ops' => [$this->knowledgeOp($k)]];
        }

        $testCases = [];
        foreach ((array) ($s['test_cases'] ?? []) as $tc) {
            $case = $this->freeze($run, (int) $tc['conversation_id'], ['mining_run_id' => $run->id, 'situation' => $situation->key]);
            if ($case === null) {
                $run->bumpIn('dropped', 'test_case_unfreezable');

                continue;
            }
            $testCases[] = $tc['conversation_id'];
            $changes[] = ['target_type' => ProposalChange::TARGET_TEST_CASE, 'target_id' => null, 'ops' => [[
                'op' => 'create_test_case',
                'frozen_case_id' => $case,
                'name' => mb_substr("{$name}: ".($tc['kind'] === 'edge' ? 'edge case' : 'representative')." (conversation {$tc['conversation_id']})", 0, 255),
                'expectation' => [
                    'must_include' => $tc['must_include'],
                    'must_not_include' => $tc['must_not_include'],
                    'expected_outcome' => $tc['expected_outcome'],
                    'expected_actions' => $tc['expected_actions'],
                    'notes' => $tc['why'],
                ],
                ...($playbook !== null ? ['playbook_id' => $playbook->id] : ($playbookCreated ? ['playbook_slug' => $slug] : [])),
            ]]];
        }

        $coverage = (array) ($s['coverage'] ?? []);
        $evidenceIds = $this->evidenceIds($s);
        $suggestions = array_map(fn ($a) => [
            'target_type' => ProposalChange::TARGET_PLAYBOOK,
            'target_id' => $playbook?->id,
            'op' => ['op' => 'allow_action', 'action' => $a['action'], 'playbook_slug' => $slug, 'evidence' => $a['evidence']],
            'reason' => 'Staff took this action in '.count($a['evidence']).' sampled conversation(s)'.($a['when'] !== '' ? " ({$a['when']})" : '')
                .'. Mining never enables actions: a person switches them on in the playbook settings.',
        ], (array) ($s['suggested_actions'] ?? []));

        $rationale = trim(implode("\n\n", array_filter([
            (string) ($s['rationale'] ?? ''),
            sprintf(
                'Learned from %d conversation(s) between %s and %s (%d read in full).',
                (int) ($coverage['count'] ?? $situation->conversation_count),
                $coverage['from'] ?? '?',
                $coverage['to'] ?? '?',
                (int) ($coverage['shown'] ?? 0),
            ),
            $this->knowledgeLines($s),
            ($s['inconsistencies'] ?? []) !== [] ? 'The history disagrees on: '.implode(', ', array_column($s['inconsistencies'], 'topic')).' (see the mining summary proposal).' : null,
            $suggestions !== [] ? 'Suggested actions (not enabled): '.implode(', ', array_unique(array_column($s['suggested_actions'], 'action'))).'.' : null,
        ])));

        $evidence = [
            'conversation_ids' => $evidenceIds,
            'rules' => array_map(fn ($r) => ['op' => $r['op'], 'rule_id' => $r['rule_id'], 'then' => $r['then'], 'conversation_ids' => $r['evidence']], (array) ($s['rules'] ?? [])),
            'knowledge' => array_map(fn ($k) => ['title' => $k['title'], 'last_seen' => $k['last_seen'], 'conversation_ids' => $k['evidence']], (array) ($s['knowledge'] ?? [])),
            'inconsistencies' => $s['inconsistencies'] ?? [],
            'suggested_actions' => $s['suggested_actions'] ?? [],
            'test_case_conversation_ids' => $testCases,
            'coverage' => $coverage,
        ];

        return $this->upsert($run, $key, 'Mining: '.$name, $rationale, $changes, $evidence, $suggestions);
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>|null
     */
    private function playbookOps(Playbook $playbook, array $s): ?array
    {
        $live = $playbook->liveVersion;
        $ops = [];
        foreach ((array) ($s['rules'] ?? []) as $r) {
            $ops[] = match ($r['op']) {
                'replace' => ['op' => 'replace_rule', 'id' => $r['rule_id'], 'if' => $r['if'], 'then' => $r['then']],
                'retire' => ['op' => 'remove_rule', 'id' => $r['rule_id']],
                default => ['op' => 'add_rule', 'if' => $r['if'], 'then' => $r['then']],
            };
        }
        foreach ((array) ($s['steps'] ?? []) as $st) {
            $ops[] = match ($st['op']) {
                'replace' => ['op' => 'replace_item', 'field' => 'steps', 'id' => $st['step_id'], 'text' => $st['text']],
                'retire' => ['op' => 'remove_item', 'field' => 'steps', 'id' => $st['step_id']],
                default => ['op' => 'add_step', 'text' => $st['text']],
            };
        }
        if (($s['when_text'] ?? null) !== null && trim((string) $s['when_text']) !== trim((string) $live?->when_text)) {
            $ops[] = ['op' => 'set_field', 'field' => 'when_text', 'value' => $s['when_text']];
        }
        if (($s['escalation'] ?? null) !== null && trim((string) $s['escalation']) !== trim((string) $live?->escalation)) {
            $ops[] = ['op' => 'set_field', 'field' => 'escalation', 'value' => $s['escalation']];
        }
        $required = (array) ($s['required_evidence'] ?? []);
        $current = (array) ($live->required_evidence ?? []);
        sort($required);
        sort($current);
        if ($required !== [] && $required !== $current) {
            $ops[] = ['op' => 'set_field', 'field' => 'required_evidence', 'value' => $required];
        }

        return $ops === [] ? null : ['target_type' => ProposalChange::TARGET_PLAYBOOK, 'target_id' => $playbook->id, 'ops' => $ops];
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>|null
     */
    private function createPlaybookOps(string $slug, string $name, MiningSituation $situation, array $s): ?array
    {
        $rules = array_values(array_map(
            fn ($r) => ['if' => $r['if'], 'then' => $r['then']],
            array_filter((array) ($s['rules'] ?? []), fn ($r) => $r['op'] !== 'retire'),
        ));
        if ($rules === []) {
            return null; // a playbook nobody demonstrated a rule for is not worth proposing
        }

        return ['target_type' => ProposalChange::TARGET_PLAYBOOK, 'target_id' => null, 'ops' => [[
            'op' => 'create_playbook',
            'slug' => $slug,
            'name' => $name,
            'when_text' => (string) ($s['when_text'] ?? $situation->description ?? $name),
            'rules' => $rules,
            'steps' => array_values(array_map(fn ($st) => $st['text'], array_filter((array) ($s['steps'] ?? []), fn ($st) => $st['op'] !== 'retire'))),
            'required_evidence' => (array) ($s['required_evidence'] ?? []),
            'escalation' => $s['escalation'] ?? null,
        ]]];
    }

    /**
     * Knowledge for the situation proposal: everything except items the
     * history disagrees about, which go to the summary, unless their scope
     * names the playbook this proposal creates (they could not be scoped
     * there).
     *
     * @param  array<string, mixed>  $s
     * @return list<array<string, mixed>>
     */
    private function situationKnowledge(MiningRun $run, array $s, ?string $createdSlug): array
    {
        $disputed = array_column((array) ($s['inconsistencies'] ?? []), 'topic');
        $known = [...Playbook::query()->where('workspace_id', $run->workspace_id)->pluck('slug')->all(), ...array_filter([$createdSlug])];

        $out = [];
        foreach ((array) ($s['knowledge'] ?? []) as $k) {
            $scope = (array) $k['scope'];
            $all = (array) ($scope['playbooks'] ?? []);
            $scope['playbooks'] = array_values(array_intersect($all, $known));
            $named = ($scope['global'] ?? false) || ($scope['countries'] ?? []) !== [] || ($scope['products'] ?? []) !== [];
            if (! $named && $scope['playbooks'] === []) {
                $run->bumpIn('dropped', 'knowledge_unscoped'); // its playbook is not proposed

                continue;
            }
            // Disputed items go to the summary, unless only this proposal's new playbook can scope them.
            $onlyNew = $createdSlug !== null && ! $named && $scope['playbooks'] === [$createdSlug];
            if (in_array($k['topic'] ?? null, $disputed, true) && ! $onlyNew) {
                continue;
            }
            $k['scope'] = $scope;
            $out[] = $k;
        }

        return $out;
    }

    /** @param  array<string, mixed>  $k */
    private function knowledgeOp(array $k): array
    {
        return [
            'op' => 'create_knowledge',
            'kind' => $k['kind'],
            'title' => $k['title'],
            'body' => $k['body'],
            'scope' => $k['scope'],
        ];
    }

    /** @param  array<string, mixed>  $s */
    private function knowledgeLines(array $s): ?string
    {
        $lines = array_map(
            fn ($k) => '- '.($k['title'] ?? 'Knowledge').': last stated '.($k['last_seen'] ?? '?').' (conversations '.implode(', ', $k['evidence']).')',
            (array) ($s['knowledge'] ?? []),
        );

        return $lines === [] ? null : "Knowledge found:\n".implode("\n", $lines);
    }

    /** @param  array<string, mixed>  $s */
    private function evidenceIds(array $s): array
    {
        $ids = [];
        foreach (['rules', 'steps', 'knowledge', 'suggested_actions'] as $field) {
            foreach ((array) ($s[$field] ?? []) as $item) {
                $ids = [...$ids, ...(array) ($item['evidence'] ?? [])];
            }
        }
        foreach ((array) ($s['inconsistencies'] ?? []) as $inc) {
            foreach ($inc['versions'] as $v) {
                $ids = [...$ids, ...$v['evidence']];
            }
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    /** The case is cut at the customer message the first human reply answered. */
    private function freeze(MiningRun $run, int $conversationId, array $meta): ?int
    {
        $conversation = Conversation::query()->where('workspace_id', $run->workspace_id)->find($conversationId);
        if ($conversation === null) {
            return null;
        }
        $digest = $this->digests->build($conversation);
        $first = $digest->humanReplies[0] ?? null;
        if ($first === null) {
            return null;
        }
        $cutId = null;
        foreach ($digest->turns as $turn) {
            if ($turn->threadId === $first['thread_id']) {
                break;
            }
            if ($turn->isCustomer() && $turn->threadId !== null) {
                $cutId = $turn->threadId;
            }
        }
        $cut = $cutId !== null ? Thread::query()->find($cutId) : null;
        if ($cut === null) {
            return null;
        }

        try {
            return $this->frozen->fromConversation($conversation, $cut, 'mining', $meta)->id;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    // ── Voice ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function emitVoice(MiningRun $run): array
    {
        $voice = $run->result('voice');
        if (! is_array($voice)) {
            return ['action' => 'skipped', 'reason' => 'no_voice_sample'];
        }
        if (AgentSetting::forWorkspace($run->workspace_id)->live_voice_version_id === null) {
            return ['action' => 'skipped', 'reason' => 'no_live_voice'];
        }

        $add = ['do_phrases' => 'add_do', 'dont_phrases' => 'add_dont', 'greetings' => 'add_greeting', 'sign_offs' => 'add_sign_off'];
        $ops = [];
        foreach (VoiceVersion::LIST_FIELDS as $field) {
            foreach ((array) ($voice[$field] ?? []) as $item) {
                $ops[] = match ($item['op']) {
                    'replace' => ['op' => 'replace_item', 'field' => $field, 'id' => $item['id'], 'text' => $item['text']],
                    'retire' => ['op' => 'remove_item', 'field' => $field, 'id' => $item['id']],
                    default => ['op' => $add[$field], 'text' => $item['text']],
                };
            }
        }
        foreach (['personality', 'max_words', 'length_guidance'] as $field) {
            if (($voice[$field] ?? null) !== null) {
                $ops[] = ['op' => 'set_field', 'field' => $field, 'value' => $voice[$field]];
            }
        }
        if ($ops === []) {
            return ['action' => 'skipped', 'reason' => 'no_changes'];
        }

        $staff = array_map(fn ($s) => "{$s['label']} ({$s['replies']} replies)", (array) ($voice['staff'] ?? []));
        $differences = array_map(fn ($d) => "- {$d['topic']}: {$d['note']}", (array) ($voice['staff_differences'] ?? []));
        $rationale = trim(implode("\n\n", array_filter([
            (string) ($voice['rationale'] ?? ''),
            'From '.(int) ($voice['sample_size'] ?? 0).' human replies: '.implode(', ', $staff).'.',
            $differences !== [] ? "Where the staff differ:\n".implode("\n", $differences) : null,
        ])));

        return $this->upsert($run, self::VOICE, 'Mining: brand voice', $rationale, [
            ['target_type' => ProposalChange::TARGET_VOICE, 'target_id' => null, 'ops' => $ops],
        ], [
            'quotes' => array_column((array) ($voice['example_phrases'] ?? []), 'text'),
            'example_phrases' => $voice['example_phrases'] ?? [],
            'staff' => $voice['staff'] ?? [],
            'staff_differences' => $voice['staff_differences'] ?? [],
            'sample_size' => $voice['sample_size'] ?? 0,
        ], []);
    }

    // ── Summary ───────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function emitSummary(MiningRun $run): array
    {
        $list = [];
        $changes = [];
        $existing = Playbook::query()->where('workspace_id', $run->workspace_id)->pluck('slug')->all();

        foreach ($this->situations($run) as $situation) {
            $s = (array) $situation->synthesis;
            $slug = (string) ($s['playbook_slug'] ?? $situation->key);
            foreach ((array) ($s['inconsistencies'] ?? []) as $inc) {
                $list[] = $inc + ['situation' => $situation->key, 'situation_name' => $situation->name];

                // The disputed knowledge (or the most recent statement) for a human to confirm.
                $knowledge = array_values(array_filter((array) ($s['knowledge'] ?? []), fn ($k) => ($k['topic'] ?? null) === $inc['topic']));
                $candidate = $knowledge[0] ?? [
                    'kind' => 'policy',
                    'title' => ucfirst(str_replace('_', ' ', $inc['topic'])),
                    'body' => $inc['most_recent'],
                    'scope' => ['global' => false, 'playbooks' => [$slug], 'products' => [], 'countries' => []],
                ];
                $scope = (array) $candidate['scope'];
                $scope['playbooks'] = array_values(array_intersect((array) ($scope['playbooks'] ?? []), $existing));
                if (! ($scope['global'] ?? false) && $scope['playbooks'] === [] && ($scope['countries'] ?? []) === [] && ($scope['products'] ?? []) === []) {
                    continue; // only scopable to a playbook another proposal creates: it stays there
                }
                $candidate['scope'] = $scope;
                $changes[] = ['target_type' => ProposalChange::TARGET_KNOWLEDGE, 'target_id' => null, 'ops' => [$this->knowledgeOp($candidate)]];
                // Which change answers this point (title and body as the proposal stores them), so the
                // review screen shows the competing statements on that change. Kept in step by editChange().
                $title = trim((string) ($candidate['title'] ?? ''));
                $list[array_key_last($list)]['knowledge'] = ['title' => $title !== '' ? $title : null, 'body' => trim((string) $candidate['body'])];
            }
        }

        $run->setResult('inconsistencies', $list);
        if ($list === []) {
            return ['action' => 'skipped', 'reason' => 'no_inconsistencies'];
        }

        $lines = array_map(function ($inc) {
            $versions = array_map(fn ($v) => "  - \"{$v['statement']}\" (last seen {$v['last_seen']}, conversations ".implode(', ', $v['evidence']).')', $inc['versions']);

            return "• {$inc['situation_name']}: {$inc['topic']}. {$inc['summary']}\n".implode("\n", $versions)."\n  Recommendation: {$inc['recommendation']}";
        }, $list);
        $rationale = "The history contradicts itself on these points. Each change below states the most recent version; approve it, edit it, or reject it and decide yourself.\n\n".implode("\n\n", $lines);

        $ids = [];
        foreach ($list as $inc) {
            foreach ($inc['versions'] as $v) {
                $ids = [...$ids, ...$v['evidence']];
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        if ($changes === []) {
            $run->setResult('summary_note', 'Every disputed item is scoped to a playbook another mining proposal creates; the inconsistencies are listed there and in this run.');

            return ['action' => 'skipped', 'reason' => 'no_applicable_changes', 'inconsistencies' => count($list)];
        }

        return $this->upsert($run, self::SUMMARY, 'Mining: inconsistencies to decide ('.count($list).')', $rationale, $changes, [
            'conversation_ids' => $ids,
            'inconsistencies' => $list,
        ], []);
    }

    // ── Create / update ───────────────────────────────────────────────

    /**
     * @param  list<array<string, mixed>>  $changes
     * @param  array<string, mixed>  $evidence
     * @param  list<array<string, mixed>>  $suggestions
     * @return array<string, mixed>
     */
    private function upsert(MiningRun $run, string $key, string $title, string $rationale, array $changes, array $evidence, array $suggestions): array
    {
        if ($changes === []) {
            return ['action' => 'skipped', 'reason' => 'no_changes'];
        }

        $hash = hash('sha256', (string) json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        $evidence['mining'] = ['key' => $key, 'run_id' => $run->id, 'content_hash' => $hash];

        $prior = Proposal::query()
            ->where('workspace_id', $run->workspace_id)
            ->where('origin', Proposal::ORIGIN_MINING)
            ->where('evidence->mining->key', $key)
            ->orderByDesc('id')
            ->first();

        if ($prior !== null && ($prior->evidence['mining']['content_hash'] ?? null) === $hash) {
            return ['action' => $prior->isOpen() ? 'unchanged' : 'unchanged_reviewed', 'proposal_id' => $prior->id, 'status' => $prior->status];
        }
        if ($prior !== null && $prior->status === Proposal::STATUS_TESTING) {
            return ['action' => 'skipped_in_review', 'proposal_id' => $prior->id, 'status' => $prior->status];
        }

        $dropped = 0;
        for ($attempt = 0; $attempt < 4; $attempt++) {
            try {
                if ($prior !== null && $prior->isOpen()) {
                    $proposal = $this->proposals->replaceChanges($prior, $changes);
                    $proposal->forceFill([
                        'title' => mb_substr($title, 0, 255),
                        'rationale' => $rationale,
                        'evidence' => $evidence,
                        'suggestions' => $suggestions,
                    ])->save();
                    $action = 'updated';
                } else {
                    $proposal = $this->proposals->createProposal($run->workspace_id, Proposal::ORIGIN_MINING, $title, $rationale, $changes, $evidence, $run->user_id, [
                        'suggestions' => $suggestions,
                    ]);
                    $action = 'created';
                }

                if ($dropped > 0) {
                    $run->bumpIn('dropped', 'invalid_op', $dropped);
                }

                return ['action' => $action, 'proposal_id' => $proposal->id, 'status' => $proposal->status, 'changes' => count($changes), 'ops_dropped' => $dropped];
            } catch (InvalidProposalException $e) {
                [$changes, $removed] = $this->withoutFailing($changes, $e->errors);
                $dropped += $removed;
                if ($removed === 0 || $changes === []) {
                    $run->bumpIn('dropped', 'invalid_op', $dropped);

                    return ['action' => 'failed', 'errors' => array_slice($e->errors, 0, 10)];
                }
            } catch (ProposalStateException $e) {
                return ['action' => 'skipped_in_review', 'proposal_id' => $prior->id ?? null, 'reason' => $e->reason];
            }
        }

        return ['action' => 'failed', 'errors' => ['Too many invalid ops.']];
    }

    /**
     * Drop the ops (or whole changes) an InvalidProposalException names.
     *
     * @param  list<array<string, mixed>>  $changes
     * @param  list<string>  $errors
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function withoutFailing(array $changes, array $errors): array
    {
        $removed = 0;
        foreach ($errors as $error) {
            if (preg_match('/^changes\.(\d+)(?:\.ops\.(\d+))?/', (string) $error, $m) !== 1) {
                continue;
            }
            $c = (int) $m[1];
            if (! isset($changes[$c])) {
                continue;
            }
            $opIndex = isset($m[2]) ? (int) $m[2] : null;
            $isCreate = (($changes[$c]['ops'][0]['op'] ?? null) === 'create_playbook');
            if ($opIndex !== null && isset($changes[$c]['ops'][$opIndex]) && ! ($isCreate && $opIndex === 0)) {
                $changes[$c]['ops'][$opIndex] = null;
            } else {
                $changes[$c] = null;
            }
            $removed++;
        }

        $out = [];
        foreach ($changes as $change) {
            if ($change === null) {
                continue;
            }
            $change['ops'] = array_values(array_filter($change['ops'], fn ($op) => $op !== null));
            if ($change['ops'] !== []) {
                $out[] = $change;
            }
        }

        return [$out, $removed];
    }

    /** @param  array<string, mixed>  $outcome */
    private function record(MiningRun $run, string $key, array $outcome): void
    {
        $done = (array) $run->result('proposals', []);
        $done[$key] = $outcome;
        $run->setResult('proposals', $done);
        $run->bumpIn('proposals', (string) ($outcome['action'] ?? 'unknown'));
    }
}

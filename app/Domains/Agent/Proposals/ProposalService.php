<?php

namespace App\Domains\Agent\Proposals;

use App\Domains\Agent\AutoSend\AutoModeGuard;
use App\Domains\Agent\Events\AgentLoopUpdated;
use App\Domains\Agent\Http\Presenters\LoopPresenter;
use App\Domains\Agent\Models\AgentExample;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\FrozenCase;
use App\Domains\Agent\Models\KnowledgeItem;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Models\Proposal;
use App\Domains\Agent\Models\ProposalChange;
use App\Domains\Agent\Models\Simulation;
use App\Domains\Agent\Models\VoiceVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates, validates, rebases, approves, rejects and reverts proposals.
 * The stable entry point for every producer (feedback, manager chat, QA,
 * history mining). Contract and ops format: docs/agent-proposals.md.
 */
class ProposalService
{
    public function __construct(
        private readonly OpsApplier $ops,
        private readonly ConfigFingerprint $fingerprint,
        private readonly ProposalApplier $applier,
    ) {}

    /**
     * Validate the ops against the current live versions and store a draft
     * proposal. Ops that touch permissions, limits, modes or rollout are
     * removed and stored in `suggestions` (plain advice for a human).
     *
     * @param  list<array{target_type: string, target_id?: int|null, ops: list<array<string, mixed>>}>  $changes
     * @param  array<string, mixed>  $evidence  Provenance, e.g. {conversation_ids: [], thread_ids: [], quotes: []}
     * @param  array<string, mixed>  $attributes  Optional columns: scope, conversation_id, run_id, feedback_event_id, suggestions
     *
     * @throws InvalidProposalException when an op does not apply, or nothing applicable is left
     */
    public function createProposal(
        int $workspaceId,
        string $origin,
        string $title,
        ?string $rationale,
        array $changes,
        array $evidence = [],
        ?int $createdBy = null,
        array $attributes = [],
    ): Proposal {
        if (! in_array($origin, Proposal::ORIGINS, true)) {
            throw new InvalidArgumentException("Unknown proposal origin [{$origin}].");
        }
        $title = trim($title);
        if ($title === '') {
            throw new InvalidProposalException(['title: required.']);
        }

        $validated = $this->validateChanges($workspaceId, $changes);
        $suggestions = [...array_values((array) ($attributes['suggestions'] ?? [])), ...$validated['suggestions']];

        return DB::transaction(function () use ($workspaceId, $origin, $title, $rationale, $validated, $evidence, $createdBy, $attributes, $suggestions) {
            $proposal = Proposal::create([
                'workspace_id' => $workspaceId,
                'origin' => $origin,
                'status' => Proposal::STATUS_DRAFT,
                'title' => mb_substr($title, 0, 255),
                'rationale' => $rationale,
                'evidence' => $evidence,
                'base_config_hash' => $this->fingerprint->forWorkspace($workspaceId),
                'scope' => $attributes['scope'] ?? null,
                'conversation_id' => $attributes['conversation_id'] ?? null,
                'run_id' => $attributes['run_id'] ?? null,
                'feedback_event_id' => $attributes['feedback_event_id'] ?? null,
                'suggestions' => $suggestions,
                'created_by' => $createdBy,
            ]);

            $this->writeChanges($proposal, $validated['changes']);

            return $proposal->load('changes');
        });
    }

    /**
     * Dry run: validate changes without storing anything.
     *
     * @param  list<array<string, mixed>>  $changes
     * @return array{changes: list<array<string, mixed>>, suggestions: list<array<string, mixed>>}
     *
     * @throws InvalidProposalException
     */
    public function validateChanges(int $workspaceId, array $changes): array
    {
        $errors = [];
        $suggestions = [];
        $out = [];
        $merged = [];
        $createdSlugs = [];
        $existingSlugs = Playbook::where('workspace_id', $workspaceId)->pluck('slug')->all();

        foreach (array_values($changes) as $n => $change) {
            $path = "changes.{$n}";
            if (! is_array($change)) {
                $errors[] = "{$path}: a change must be an object.";

                continue;
            }
            $type = (string) ($change['target_type'] ?? '');
            $targetId = isset($change['target_id']) && (int) $change['target_id'] > 0 ? (int) $change['target_id'] : null;

            $kept = [];
            foreach (array_values((array) ($change['ops'] ?? [])) as $op) {
                if (! is_array($op)) {
                    $kept[] = $op;

                    continue;
                }
                if (($reason = $this->ops->forbiddenReason($op)) !== null) {
                    $suggestions[] = ['target_type' => $type, 'target_id' => $targetId, 'op' => $op, 'reason' => $reason];

                    continue;
                }
                [$op, $removed] = $this->ops->stripProtected($op);
                if ($removed !== null) {
                    $suggestions[] = ['target_type' => $type, 'target_id' => $targetId, 'op' => $removed,
                        'reason' => 'Action permissions, mode and rollout of a new playbook are set by a person after approval (it starts in draft mode with no actions).'];
                }
                $kept[] = $op;
            }
            if ($kept === []) {
                continue;
            }

            // Two changes to the same existing target are one change.
            $key = $targetId !== null || $type === ProposalChange::TARGET_VOICE ? $type.':'.($targetId ?? 0) : null;
            if ($key !== null && isset($merged[$key])) {
                $out[$merged[$key]]['ops'] = [...$out[$merged[$key]]['ops'], ...$kept];

                continue;
            }

            $base = $this->baseContent($workspaceId, $type, $targetId, $errors, $path);
            if ($base === false) {
                continue;
            }

            $entry = ['target_type' => $type, 'target_id' => $targetId, 'base_version_id' => $base['base_version_id'], 'before' => $base['before'], 'ops' => $kept, 'path' => $path];
            if ($key !== null) {
                $merged[$key] = count($out);
            }
            $out[] = $entry;
        }

        $final = [];
        foreach ($out as $entry) {
            $result = $this->ops->apply($entry['target_type'], $entry['before'], $entry['ops'], $entry['path'].'.ops');
            foreach ($result['errors'] as $error) {
                $errors[] = $error;
            }
            if ($result['errors'] !== []) {
                continue;
            }

            $after = $result['after'];
            if ($entry['target_type'] === ProposalChange::TARGET_PLAYBOOK && $entry['target_id'] === null) {
                $slug = (string) ($after['slug'] ?? '');
                if (in_array($slug, $existingSlugs, true) || in_array($slug, $createdSlugs, true)) {
                    $errors[] = "{$entry['path']}: a playbook with slug \"{$slug}\" already exists.";

                    continue;
                }
                $createdSlugs[] = $slug;
            }
            if ($entry['target_type'] === ProposalChange::TARGET_TEST_CASE
                && ! FrozenCase::where('workspace_id', $workspaceId)->whereKey((int) $after['frozen_case_id'])->exists()) {
                $errors[] = "{$entry['path']}: frozen case {$after['frozen_case_id']} not found in this workspace.";

                continue;
            }

            $final[] = [
                'target_type' => $entry['target_type'],
                'target_id' => $entry['target_id'],
                'base_version_id' => $entry['base_version_id'],
                'ops' => $result['ops'],
                'before' => $entry['before'],
                'after' => $after,
                'diff' => $result['diff'],
            ];
        }

        // Knowledge scoped to playbooks must name real ones (or ones this proposal creates).
        foreach ($final as $n => $change) {
            if ($change['target_type'] !== ProposalChange::TARGET_KNOWLEDGE) {
                continue;
            }
            $unknown = array_diff((array) ($change['after']['scope']['playbooks'] ?? []), [...$existingSlugs, ...$createdSlugs]);
            if ($unknown !== []) {
                $errors[] = "changes.{$n}: knowledge scope names unknown playbooks: ".implode(', ', $unknown).'.';
            }
        }

        if ($errors !== []) {
            throw new InvalidProposalException($errors, $suggestions);
        }
        if ($final === []) {
            throw new InvalidProposalException(['No applicable changes: every op was empty or set aside as a suggestion.'], $suggestions);
        }

        return ['changes' => $final, 'suggestions' => $suggestions];
    }

    /**
     * Current content of a target, as ops are applied to it.
     *
     * @param  list<string>  $errors
     * @return array{before: array<string, mixed>|null, base_version_id: int|null}|false
     */
    public function baseContent(int $workspaceId, string $type, ?int $targetId, array &$errors = [], string $path = 'change'): array|false
    {
        switch ($type) {
            case ProposalChange::TARGET_VOICE:
                $id = AgentSetting::forWorkspace($workspaceId)->live_voice_version_id;
                $voice = $id !== null ? VoiceVersion::find($id) : null;
                if ($voice === null) {
                    $errors[] = "{$path}: this workspace has no live voice yet.";

                    return false;
                }

                return ['before' => $voice->toContentArray(), 'base_version_id' => $voice->id];

            case ProposalChange::TARGET_PLAYBOOK:
                if ($targetId === null) {
                    return ['before' => null, 'base_version_id' => null];
                }
                $playbook = Playbook::with('liveVersion')->where('workspace_id', $workspaceId)->find($targetId);
                if ($playbook === null || $playbook->liveVersion === null || $playbook->archived_at !== null) {
                    $errors[] = "{$path}: playbook {$targetId} not found (or archived, or without a live version).";

                    return false;
                }

                return ['before' => ['slug' => $playbook->slug, 'name' => $playbook->name, ...$playbook->liveVersion->toContentArray()], 'base_version_id' => $playbook->live_version_id];

            case ProposalChange::TARGET_KNOWLEDGE:
                if ($targetId === null) {
                    return ['before' => null, 'base_version_id' => null];
                }
                $item = KnowledgeItem::where('workspace_id', $workspaceId)->find($targetId);
                if ($item === null) {
                    $errors[] = "{$path}: knowledge item {$targetId} not found.";

                    return false;
                }

                return ['before' => self::knowledgeContent($item), 'base_version_id' => null];

            case ProposalChange::TARGET_EXAMPLE:
                $example = $targetId !== null ? AgentExample::where('workspace_id', $workspaceId)->find($targetId) : null;
                if ($example === null) {
                    $errors[] = "{$path}: example ".($targetId ?? 'null').' not found.';

                    return false;
                }

                return ['before' => [
                    'customer_message' => $example->customer_message,
                    'reply' => $example->reply,
                    'playbook_slug' => $example->playbook_slug,
                    'status' => $example->status,
                ], 'base_version_id' => null];

            case ProposalChange::TARGET_TEST_CASE:
                return ['before' => null, 'base_version_id' => null];

            default:
                $errors[] = "{$path}: unknown target_type \"{$type}\" (voice, playbook, knowledge, example, test_case).";

                return false;
        }
    }

    /** @return array<string, mixed> */
    public static function knowledgeContent(KnowledgeItem $item): array
    {
        return [
            'kind' => $item->kind,
            'title' => $item->title,
            'body' => $item->body,
            'scope' => KnowledgeItem::normalizeScope($item->scope),
            'valid_from' => $item->valid_from?->toIso8601String(),
            'valid_until' => $item->valid_until?->toIso8601String(),
            'status' => $item->status,
        ];
    }

    /**
     * Replace a proposal's changes (conflict resolution, human edits): the
     * new set is validated against the current live versions, and the
     * conflict check and gate result are cleared when the ops changed.
     *
     * @param  list<array<string, mixed>>  $changes  Same shape as createProposal()
     *
     * @throws InvalidProposalException|ProposalStateException
     */
    public function replaceChanges(Proposal $proposal, array $changes, bool $keepConflicts = false): Proposal
    {
        $this->assertOpen($proposal);
        $validated = $this->validateChanges($proposal->workspace_id, $changes);

        return DB::transaction(function () use ($proposal, $validated, $keepConflicts) {
            $proposal->changes()->delete();
            $this->writeChanges($proposal, $validated['changes']);
            $proposal->forceFill([
                'status' => Proposal::STATUS_DRAFT,
                'base_config_hash' => $this->fingerprint->forWorkspace($proposal->workspace_id),
                'suggestions' => [...array_values((array) ($proposal->suggestions ?? [])), ...$validated['suggestions']],
                'gate_simulation_id' => null,
                ...($keepConflicts ? [] : ['conflicts' => null, 'conflicts_checked_at' => null]),
            ])->save();

            return $proposal->load('changes');
        });
    }

    /**
     * Partial approval: leave whole changes, or single ops within a change,
     * out of the proposal. What remains is re-validated against the live
     * versions (like a rebase); the conflict check, preview and gate are
     * invalidated and must run again for what is left (the controller
     * restarts them). Each excluded item is recorded in `excluded` and,
     * on approval, copied into `applied`.
     *
     * @param  list<int>  $changeIds  ProposalChange ids to leave out entirely
     * @param  list<array{change_id: int, op_index: int}>  $ops  single ops (index into the change's `ops`)
     *
     * @throws InvalidProposalException when an id is unknown, nothing would be left, or what is left no longer validates
     * @throws ProposalStateException not_open | gate_running
     */
    public function exclude(Proposal $proposal, array $changeIds, array $ops, int $userId, ?string $note = null): Proposal
    {
        $this->assertOpen($proposal);
        $this->assertGateIdle($proposal);
        $proposal->load('changes');

        $byId = $proposal->changes->keyBy('id');
        $errors = [];
        $dropChange = [];
        foreach ($changeIds as $id) {
            if (! $byId->has((int) $id)) {
                $errors[] = "change {$id} is not part of this proposal.";
            }
            $dropChange[(int) $id] = true;
        }
        $dropOp = [];
        foreach ($ops as $ref) {
            $change = $byId->get((int) $ref['change_id']);
            if ($change === null || ! array_key_exists((int) $ref['op_index'], (array) $change->ops)) {
                $errors[] = "op {$ref['op_index']} of change {$ref['change_id']} is not part of this proposal.";

                continue;
            }
            $dropOp[(int) $ref['change_id']][(int) $ref['op_index']] = true;
        }
        if ($errors !== []) {
            throw new InvalidProposalException($errors);
        }

        $at = now()->toIso8601String();
        $records = [];
        $input = [];
        foreach ($proposal->changes as $change) {
            $base = [
                'target_type' => $change->target_type,
                'target_id' => $change->target_id,
                'target_label' => LoopPresenter::targetLabel($change),
                'anchor' => $change->target_id === null ? ($change->after['slug'] ?? null) : null,
                'excluded_by' => $userId,
                'excluded_at' => $at,
                'note' => $note !== null ? mb_substr($note, 0, 500) : null,
            ];
            if (isset($dropChange[$change->id])) {
                $records[] = ['id' => 'x-'.Str::lower(Str::random(10)), 'kind' => 'change', ...$base, 'ops' => array_values((array) $change->ops)];

                continue;
            }
            $kept = [];
            foreach (array_values((array) $change->ops) as $index => $op) {
                if (isset($dropOp[$change->id][$index])) {
                    $records[] = ['id' => 'x-'.Str::lower(Str::random(10)), 'kind' => 'op', ...$base, 'op_index' => $index, 'ops' => [$op]];
                } else {
                    $kept[] = $op;
                }
            }
            if ($kept !== []) {
                $input[] = ['target_type' => $change->target_type, 'target_id' => $change->target_id, 'ops' => $kept];
            }
        }
        if ($records === []) {
            return $proposal;
        }
        if ($input === []) {
            throw new InvalidProposalException(['Nothing would be left to approve. Reject the proposal instead.']);
        }

        return $this->rewriteWithExclusions($proposal, $input, [...array_values((array) ($proposal->excluded ?? [])), ...$records]);
    }

    /**
     * Put excluded items back (all of them when $ids is empty).
     *
     * @param  list<string>  $ids  `excluded[].id`
     *
     * @throws InvalidProposalException|ProposalStateException
     */
    public function restoreExclusions(Proposal $proposal, array $ids = []): Proposal
    {
        $this->assertOpen($proposal);
        $this->assertGateIdle($proposal);
        $proposal->load('changes');

        $all = array_values((array) ($proposal->excluded ?? []));
        $restore = $ids === [] ? $all : array_values(array_filter($all, fn ($e) => in_array($e['id'] ?? null, $ids, true)));
        if ($ids !== [] && count($restore) !== count(array_unique($ids))) {
            throw new InvalidProposalException(['Some of those items were not excluded from this proposal.']);
        }
        if ($restore === []) {
            return $proposal;
        }

        $input = [];
        $anchors = [];
        foreach ($proposal->changes as $change) {
            if ($change->target_id === null && ! empty($change->after['slug'])) {
                $anchors[$change->target_type.':'.$change->after['slug']] = count($input);
            }
            $input[] = ['target_type' => $change->target_type, 'target_id' => $change->target_id, 'ops' => array_values((array) $change->ops)];
        }
        foreach ($restore as $entry) {
            $key = ($entry['anchor'] ?? null) !== null ? $entry['target_type'].':'.$entry['anchor'] : null;
            if ($entry['kind'] === 'op' && $key !== null && isset($anchors[$key])) {
                $input[$anchors[$key]]['ops'] = [...$input[$anchors[$key]]['ops'], ...(array) $entry['ops']];

                continue;
            }
            // Existing targets merge into their change in validateChanges.
            $input[] = ['target_type' => $entry['target_type'], 'target_id' => $entry['target_id'], 'ops' => array_values((array) $entry['ops'])];
        }

        $restoredIds = array_column($restore, 'id');
        $left = array_values(array_filter($all, fn ($e) => ! in_array($e['id'] ?? null, $restoredIds, true)));

        return $this->rewriteWithExclusions($proposal, $input, $left);
    }

    /** Ops a reviewer may reword, and the text fields of each. */
    public const EDITABLE_OPS = [
        'create_knowledge' => ['title', 'body'],
        'add_rule' => ['if', 'then'],
        'replace_rule' => ['if', 'then'],
    ];

    /**
     * A reviewer rewords one op before approving: only the text fields of
     * a create_knowledge (title, body) or add_rule / replace_rule (if, then)
     * op change. Everything is re-validated against the live versions and
     * the review restarts, exactly as after exclude().
     *
     * @param  array<string, mixed>  $fields  only the keys of EDITABLE_OPS for that op are read
     *
     * @throws InvalidProposalException unknown change / op, an op that cannot be edited, or text that does not validate
     * @throws ProposalStateException not_open | gate_running
     */
    public function editChange(Proposal $proposal, int $changeId, int $opIndex, array $fields): Proposal
    {
        $this->assertOpen($proposal);
        $this->assertGateIdle($proposal);
        $proposal->load('changes');

        $input = $this->changeInput($proposal);
        $position = (int) array_search($changeId, $proposal->changes->pluck('id')->all(), true);
        $op = $proposal->changes->contains('id', $changeId) ? ($input[$position]['ops'][$opIndex] ?? null) : null;
        if (! is_array($op)) {
            throw new InvalidProposalException(["op {$opIndex} of change {$changeId} is not part of this proposal."]);
        }
        $allowed = self::EDITABLE_OPS[(string) ($op['op'] ?? '')] ?? null;
        if ($allowed === null) {
            throw new InvalidProposalException(['This kind of change cannot be edited here. Leave it out, or reject the proposal.']);
        }

        $edited = [...$op, ...array_intersect_key($fields, array_flip($allowed))];
        if ($edited === $op) {
            return $proposal;
        }
        $input[$position]['ops'][$opIndex] = $edited;

        $updated = $this->rewriteWithExclusions($proposal, $input, array_values((array) ($proposal->excluded ?? [])));

        // Mining links a disputed knowledge item to its evidence by title and body: keep the link.
        $evidence = (array) ($updated->evidence ?? []);
        if ($op['op'] === 'create_knowledge' && is_array($evidence['inconsistencies'] ?? null)) {
            $now = (array) ($updated->changes->get($position)->after ?? []);
            foreach ($evidence['inconsistencies'] as $n => $inc) {
                $link = is_array($inc) ? ($inc['knowledge'] ?? null) : null;
                if (is_array($link) && ($link['title'] ?? null) === ($op['title'] ?? null) && ($link['body'] ?? null) === ($op['body'] ?? null)) {
                    $evidence['inconsistencies'][$n]['knowledge'] = ['title' => $now['title'] ?? null, 'body' => $now['body'] ?? null];
                }
            }
            $updated->forceFill(['evidence' => $evidence])->save();
        }

        return $updated;
    }

    /**
     * @param  list<array<string, mixed>>  $input
     * @param  list<array<string, mixed>>  $excluded
     */
    private function rewriteWithExclusions(Proposal $proposal, array $input, array $excluded): Proposal
    {
        // Keep earlier conflict resolutions so a re-check carries them over.
        $updated = $this->replaceChanges($proposal, $input, keepConflicts: true);
        $updated->forceFill([
            'excluded' => $excluded === [] ? null : $excluded,
            'conflicts_checked_at' => null,
            'preview' => null,
        ])->save();
        $this->broadcast($updated);

        return $updated->load('changes');
    }

    private function assertGateIdle(Proposal $proposal): void
    {
        $gate = $proposal->gate_simulation_id !== null ? Simulation::find($proposal->gate_simulation_id) : null;
        if ($gate !== null && ! $gate->isFinished() && $gate->status !== Simulation::STATUS_PAUSED) {
            throw new ProposalStateException('The regression gate is still running. Wait for it to finish (or pause it) first.', 'gate_running', ['simulation_id' => $gate->id]);
        }
    }

    /**
     * The proposal's changes as the ops input for createProposal / replaceChanges.
     *
     * @return list<array{target_type: string, target_id: int|null, ops: list<array<string, mixed>>}>
     */
    public function changeInput(Proposal $proposal): array
    {
        return $proposal->changes->map(fn (ProposalChange $c) => [
            'target_type' => $c->target_type,
            'target_id' => $c->target_id,
            'ops' => $c->ops,
        ])->values()->all();
    }

    /** True when the live config moved since the proposal was written. */
    public function isStale(Proposal $proposal): bool
    {
        return $this->fingerprint->forWorkspace($proposal->workspace_id) !== $proposal->base_config_hash;
    }

    /**
     * Re-validate the same ops against the current live versions and reset
     * the proposal to draft (conflicts and gate must be re-run).
     *
     * @throws InvalidProposalException when an op no longer applies (the proposal stays stale)
     * @throws ProposalStateException when the proposal is no longer open
     */
    public function rebase(Proposal $proposal): Proposal
    {
        if (! $proposal->isOpen()) {
            throw new ProposalStateException("A {$proposal->status} proposal cannot be rebased.", 'not_open');
        }

        try {
            $proposal->status = Proposal::STATUS_DRAFT;

            return tap($this->replaceChanges($proposal, $this->changeInput($proposal)), fn (Proposal $p) => $this->broadcast($p));
        } catch (InvalidProposalException $e) {
            $proposal->refresh()->forceFill(['status' => Proposal::STATUS_STALE])->save();

            throw $e;
        }
    }

    /**
     * InstructionSet overlay that previews the proposal: see InstructionSet.
     *
     * @return array<string, mixed>
     */
    public function overlay(Proposal $proposal): array
    {
        return self::overlayFor($proposal->changes->all());
    }

    /**
     * @param  list<ProposalChange|array<string, mixed>>  $changes
     * @return array<string, mixed>
     */
    public static function overlayFor(array $changes): array
    {
        $overlay = [];
        foreach ($changes as $change) {
            $c = $change instanceof ProposalChange ? $change->toArray() : $change;
            $after = (array) ($c['after'] ?? []);
            $targetId = $c['target_id'] ?? null;

            switch ($c['target_type']) {
                case ProposalChange::TARGET_VOICE:
                    $overlay['voice'] = array_intersect_key($after, array_flip(['personality', 'do_phrases', 'dont_phrases', 'greetings', 'sign_offs', 'max_words', 'length_guidance']));
                    break;
                case ProposalChange::TARGET_PLAYBOOK:
                    $slug = (string) ($after['slug'] ?? '');
                    $fields = array_intersect_key($after, array_flip(['when_text', 'rules', 'steps', 'required_evidence', 'escalation']));
                    if ($targetId === null) {
                        $fields = [...$fields, 'name' => $after['name'] ?? $slug, 'mode' => Playbook::MODE_DRAFT, 'allowed_actions' => []];
                    }
                    if ($slug !== '') {
                        $overlay['playbooks'][$slug] = $fields;
                    }
                    break;
                case ProposalChange::TARGET_KNOWLEDGE:
                    if ($targetId !== null) {
                        $overlay['knowledge_remove'][] = (int) $targetId;
                    }
                    if (($after['status'] ?? KnowledgeItem::STATUS_ACTIVE) === KnowledgeItem::STATUS_ACTIVE) {
                        $overlay['knowledge_add'][] = ['kind' => $after['kind'] ?? 'fact', 'title' => $after['title'] ?? null, 'body' => $after['body'] ?? '', 'scope' => $after['scope'] ?? []];
                    }
                    break;
                case ProposalChange::TARGET_EXAMPLE:
                    if ($targetId !== null) {
                        $overlay['examples_remove'][] = (int) $targetId;
                    }
                    break;
            }
        }

        return $overlay;
    }

    /**
     * Apply: one transaction writes the new versions, flips live pointers,
     * creates the test case(s), marks the proposal applied and links the
     * feedback event.
     *
     * @throws ProposalStateException stale | not_open | gate_running | blocking_conflicts | unchecked
     */
    public function approve(Proposal $proposal, int $userId, ?string $note = null): Proposal
    {
        $this->assertOpen($proposal);
        $this->assertGateIdle($proposal);
        if ($this->isStale($proposal)) {
            $proposal->forceFill(['status' => Proposal::STATUS_STALE])->save();
            $this->broadcast($proposal);

            throw new ProposalStateException('The live configuration changed since this proposal was written. Rebase it, then review again.', 'stale');
        }
        if ($proposal->conflicts_checked_at === null) {
            throw new ProposalStateException('Run the conflict check before approving.', 'unchecked');
        }
        if ($proposal->blockingConflicts() !== []) {
            throw new ProposalStateException('Resolve the blocking conflicts first.', 'blocking_conflicts', ['conflicts' => $proposal->blockingConflicts()]);
        }

        $applied = $this->applier->apply($proposal, $userId, $note);
        $this->broadcast($applied);

        // Post-approve (Phase 8): an auto playbook that changed is held and re-tested.
        app(AutoModeGuard::class)->afterProposalApplied($applied);

        return $applied;
    }

    public function reject(Proposal $proposal, int $userId, ?string $note = null): Proposal
    {
        $this->assertOpen($proposal);
        $proposal->forceFill([
            'status' => Proposal::STATUS_REJECTED,
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();
        $this->broadcast($proposal);

        return $proposal;
    }

    /**
     * Undo an applied proposal by restoring the prior versions (new
     * versions copied from the old ones) and reversing knowledge / example /
     * test-case changes.
     *
     * @throws ProposalStateException not_applied | moved
     */
    public function revert(Proposal $proposal, int $userId): Proposal
    {
        if ($proposal->status !== Proposal::STATUS_APPLIED) {
            throw new ProposalStateException('Only an applied proposal can be reverted.', 'not_applied');
        }

        $reverted = $this->applier->revert($proposal, $userId);
        $this->broadcast($reverted);
        app(AutoModeGuard::class)->afterProposalApplied($reverted);

        return $reverted;
    }

    /** @param  list<array<string, mixed>>  $changes */
    private function writeChanges(Proposal $proposal, array $changes): void
    {
        foreach (array_values($changes) as $position => $change) {
            ProposalChange::create([
                'proposal_id' => $proposal->id,
                'position' => $position,
                'target_type' => $change['target_type'],
                'target_id' => $change['target_id'],
                'base_version_id' => $change['base_version_id'],
                'ops' => $change['ops'],
                'before' => $change['before'],
                'after' => $change['after'],
                'diff' => $change['diff'],
            ]);
        }
    }

    private function assertOpen(Proposal $proposal): void
    {
        if (! $proposal->isOpen()) {
            throw new ProposalStateException("This proposal is {$proposal->status}.", 'not_open');
        }
    }

    public function broadcast(Proposal $proposal): void
    {
        AgentLoopUpdated::dispatchSafely($proposal->workspace_id, AgentLoopUpdated::PROPOSAL_UPDATED, [
            'proposal_id' => $proposal->id,
            'status' => $proposal->status,
            'origin' => $proposal->origin,
            'conversation_id' => $proposal->conversation_id,
            'feedback_id' => $proposal->feedback_event_id,
        ]);
    }
}

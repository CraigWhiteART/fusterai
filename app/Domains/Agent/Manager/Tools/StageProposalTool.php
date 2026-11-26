<?php

namespace App\Domains\Agent\Manager\Tools;

use App\Domains\Agent\Http\Presenters\LoopPresenter;
use App\Domains\Agent\Manager\ManagerOps;
use App\Domains\Agent\Manager\ManagerSession;
use App\Domains\Agent\Manager\ScopeGuard;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\Proposal;
use App\Domains\Agent\Proposals\InvalidProposalException;
use App\Domains\Agent\Proposals\ProposalService;
use App\Domains\Conversation\Models\Conversation;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The manager's only write: stage ONE draft proposal (origin `manager`)
 * through ProposalService. Nothing becomes live: a person reviews, checks
 * conflicts, optionally previews / gates, and approves. Scope restrictions
 * of the chat are enforced here, in code, before anything is stored.
 */
class StageProposalTool extends ManagerTool
{
    public function __construct(
        ManagerSession $session,
        private readonly ProposalService $proposals,
        private readonly ScopeGuard $guard,
    ) {
        parent::__construct($session);
    }

    public function name(): string
    {
        return 'stage_proposal';
    }

    public function description(): string
    {
        return implode(' ', [
            'Stage a draft proposal of minimal field-level instruction changes for a person to review. It is NOT applied: nothing changes until a human approves it.',
            'Each change targets one thing: voice (target_id 0), a playbook (target_id = playbook id, or 0 with create_playbook), a knowledge item (target_id = item id, or 0 with create_knowledge), an example (target_id = example id).',
            'Ops: voice add_do / add_dont / add_greeting / add_sign_off {text}, replace_item {field, id, text}, remove_item {field, id}, set_field {field: personality|max_words|length_guidance, value};',
            'playbook add_rule {if?, then}, replace_rule {id, then, if?}, remove_rule {id}, add_step {text}, replace_item / remove_item {field: steps, id, text}, set_field {field: when_text|escalation|required_evidence, value or values}, create_playbook {slug, name, when_text, rules?, steps?, required_evidence?, escalation?};',
            'knowledge create_knowledge {kind: fact|policy|snippet, title?, body, scope {global, playbooks[]}, valid_from?, valid_until?}, set_field {field: title|body|scope|kind|valid_from|valid_until, value or scope}, retire_knowledge; example retire_example {reason?}, replace_example {reply}.',
            'Use existing item ids exactly as returned by the read tools. Prefer replacing the rule that is wrong over adding a contradicting one.',
            'Permissions (allowed actions), limits, modes, rollout and auto-send can never be staged: they are set aside as suggestions for a person.',
            'Cite the conversation_ids / run_ids that motivated the change.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        $op = $schema->object([
            'op' => $schema->string()->description('Op name, see the tool description')->required(),
            'id' => $schema->string()->description('Existing item id (rule r-..., step-..., do-..., dont-..., greet-..., sign-...)'),
            'field' => $schema->string(),
            'text' => $schema->string(),
            'if' => $schema->string()->description('Rule condition; omit or "always" for unconditional'),
            'then' => $schema->string()->description('Rule instruction'),
            'value' => $schema->string()->description('set_field value as text'),
            'values' => $schema->array()->items($schema->string())->description('set_field list value (required_evidence: order, tracking, photos)'),
            'kind' => $schema->string(),
            'title' => $schema->string(),
            'body' => $schema->string(),
            'scope' => $schema->object([
                'global' => $schema->boolean(),
                'playbooks' => $schema->array()->items($schema->string()),
                'products' => $schema->array()->items($schema->string()),
                'countries' => $schema->array()->items($schema->string()),
            ]),
            'slug' => $schema->string(),
            'name' => $schema->string(),
            'when_text' => $schema->string(),
            'rules' => $schema->array()->items($schema->object([
                'if' => $schema->string(),
                'then' => $schema->string()->required(),
            ])),
            'steps' => $schema->array()->items($schema->string()),
            'required_evidence' => $schema->array()->items($schema->string()),
            'escalation' => $schema->string(),
            'valid_from' => $schema->string(),
            'valid_until' => $schema->string(),
            'reason' => $schema->string(),
            'reply' => $schema->string(),
        ]);

        return [
            'title' => $schema->string()->description('Short title, e.g. "Warranty: replace genuine faults, not cosmetic damage"')->required(),
            'rationale' => $schema->string()->description('Why, for the reviewer, citing what you looked at')->required(),
            'changes' => $schema->array()->items($schema->object([
                'target_type' => $schema->string()->enum(['voice', 'playbook', 'knowledge', 'example'])->required(),
                'target_id' => $schema->integer()->description('0 for voice and for creates'),
                'ops' => $schema->array()->items($op)->required(),
            ]))->required(),
            'conversation_ids' => $schema->array()->items($schema->integer()),
            'run_ids' => $schema->array()->items($schema->integer()),
        ];
    }

    protected function isStage(): bool
    {
        return true;
    }

    protected function label(array $arguments): string
    {
        return 'Staging proposal "'.mb_substr((string) ($arguments['title'] ?? ''), 0, 80).'"';
    }

    protected function run(array $arguments): string
    {
        $s = $this->session;
        if (count($s->proposalIds) >= $s->maxProposals) {
            return "Not staged: this turn already staged {$s->maxProposals} proposals. Tell the user what else you would change.";
        }
        $title = trim((string) ($arguments['title'] ?? ''));
        $changes = ManagerOps::changes($s->workspaceId, (array) ($arguments['changes'] ?? []));
        if ($title === '' || $changes === []) {
            return 'Not staged: a title and at least one change with ops are required.';
        }

        $violations = $this->guard->violations($s->workspaceId, $changes, $s->restrictions);
        if ($violations !== []) {
            foreach ($violations as $violation) {
                $s->suggestions[] = ['type' => 'scope', 'reason' => $violation, 'op' => null, 'target_type' => null, 'target_id' => null];
            }

            return "Not staged: the request's scope restrictions forbid part of this proposal.\n- ".implode("\n- ", $violations)
                ."\nStage only changes inside the allowed scope (or none), and tell the user what you left out and why.";
        }

        $hash = hash('sha256', (string) json_encode($changes));
        if (isset($s->stagedHashes[$hash])) {
            return "Already staged as proposal #{$s->stagedHashes[$hash]} in this turn.";
        }

        $conversationIds = $this->ownedIds(Conversation::class, (array) ($arguments['conversation_ids'] ?? []));
        $runIds = $this->ownedIds(AgentRun::class, (array) ($arguments['run_ids'] ?? []));
        $types = array_values(array_unique(array_column($changes, 'target_type')));

        try {
            $proposal = $this->proposals->createProposal(
                $s->workspaceId,
                Proposal::ORIGIN_MANAGER,
                $title,
                mb_substr(trim((string) ($arguments['rationale'] ?? '')), 0, 4000) ?: null,
                $changes,
                [
                    'conversation_ids' => $conversationIds,
                    'run_ids' => $runIds,
                    'manager_chat_id' => $s->chatId,
                    'manager_message_id' => $s->messageId,
                    'request' => mb_substr($s->request, 0, 2000),
                    'restrictions' => ScopeGuard::isEmpty($s->restrictions) ? null : array_diff_key($s->restrictions, ['sources' => true]),
                ],
                $s->userId,
                ['scope' => count($types) === 1 ? $types[0] : null],
            );
        } catch (InvalidProposalException $e) {
            if ($e->suggestions !== []) {
                foreach ($e->suggestions as $suggestion) {
                    $s->suggestions[] = ['type' => 'forbidden', ...$suggestion];
                }
            }
            $forbiddenOnly = $e->suggestions !== [] && count($e->errors) === 1 && str_starts_with($e->errors[0], 'No applicable changes');

            return $forbiddenOnly
                ? "Not staged: every change touched permissions, limits, modes or rollout, which only a person can change in settings:\n- "
                    .implode("\n- ", array_map(fn ($x) => (string) $x['reason'], $e->suggestions))
                    ."\nTell the user plainly that you cannot make this change and what they can do in settings."
                : "Not staged: validation failed.\n- ".implode("\n- ", array_slice($e->errors, 0, 12))."\nFix the ops (use ids exactly as the read tools returned them) and call stage_proposal again.";
        }

        $s->proposalIds[] = $proposal->id;
        $s->stagedHashes[$hash] = $proposal->id;
        $s->cite('proposal', $proposal->id, $proposal->title, 'staged');
        foreach ($conversationIds as $id) {
            $s->cite('conversation', $id, 'Conversation #'.$id, 'staged');
        }
        foreach ($runIds as $id) {
            $s->cite('run', $id, 'Run #'.$id, 'staged');
        }
        foreach ((array) ($proposal->suggestions ?? []) as $suggestion) {
            $s->suggestions[] = ['type' => 'forbidden', 'proposal_id' => $proposal->id, ...(array) $suggestion];
        }

        $lines = ["Staged proposal #{$proposal->id} \"{$proposal->title}\" (status draft). Nothing is live: a person must review it, run the conflict check and approve it.", 'Diff:'];
        foreach (LoopPresenter::flatDiff($proposal) as $d) {
            $lines[] = '- '.$d['target_label'].' / '.$d['label'].': '.(self::cut((string) ($d['before'] ?? ''), 200) ?: '∅').' → '.(self::cut((string) ($d['after'] ?? ''), 300) ?: '∅');
        }
        if (($proposal->suggestions ?? []) !== []) {
            $lines[] = 'Set aside (only a person can change these in settings; tell the user plainly):';
            foreach ((array) $proposal->suggestions as $suggestion) {
                $lines[] = '- '.(string) ($suggestion['reason'] ?? '');
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  class-string<Conversation|AgentRun>  $model
     * @param  list<mixed>  $ids
     * @return list<int>
     */
    private function ownedIds(string $model, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
        if ($ids === []) {
            return [];
        }

        return array_values(array_map('intval', $model::where('workspace_id', $this->session->workspaceId)->whereIn('id', array_slice($ids, 0, 50))->pluck('id')->all()));
    }
}

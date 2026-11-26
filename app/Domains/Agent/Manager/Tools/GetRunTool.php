<?php

namespace App\Domains\Agent\Manager\Tools;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\FeedbackEvent;
use App\Domains\Agent\Pipeline\HandoverReasons;
use App\Domains\Agent\Qa\Models\QaReview;
use App\Support\PlainText;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class GetRunTool extends ManagerTool
{
    public function name(): string
    {
        return 'get_run';
    }

    public function description(): string
    {
        return 'One agent run in detail: the customer message it answered, triage (sentiment, risk flags, playbook choice), the playbook rules it cited, the evidence it had (order / tracking facts), knowledge it saw, the draft, the validator result, why it handed over, what staff actually sent, and the QA review.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'run_id' => $schema->integer()->required(),
        ];
    }

    protected function label(array $arguments): string
    {
        return 'Reading run #'.(int) ($arguments['run_id'] ?? 0);
    }

    protected function run(array $arguments): string
    {
        $run = AgentRun::with(['playbook', 'playbookVersion', 'customerThread', 'feedback'])
            ->where('workspace_id', $this->session->workspaceId)
            ->find((int) ($arguments['run_id'] ?? 0));
        if ($run === null) {
            return 'No such run in this workspace.';
        }
        $this->session->cite('run', $run->id, 'Run #'.$run->id);
        if ($run->conversation_id !== null) {
            $this->session->cite('conversation', $run->conversation_id, 'Conversation #'.$run->conversation_id);
        }

        $rules = collect((array) ($run->playbookVersion->rules ?? []))->keyBy('id');
        $customer = $run->customerThread !== null ? PlainText::from($run->customerThread->body_plain ?: $run->customerThread->body) : null;
        $sent = $run->feedback->first(fn (FeedbackEvent $f) => $f->final_text !== null);
        $qa = QaReview::where('workspace_id', $this->session->workspaceId)->where('run_id', $run->id)->latest('id')->first();
        $evidence = (array) ($run->evidence ?? []);

        return self::json([
            'run_id' => $run->id,
            'conversation_id' => $run->conversation_id,
            'purpose' => $run->purpose,
            'at' => $run->created_at?->toIso8601String(),
            'status' => $run->status,
            'decision' => $run->decision,
            'handover_reason' => $run->handover_reason,
            'handover_meaning' => $run->handover_reason !== null ? HandoverReasons::label($run->handover_reason) : null,
            'customer_message' => self::cut($customer, 2500),
            'triage' => array_intersect_key((array) ($run->triage ?? []), array_flip(['language', 'sentiment', 'risk_flags', 'playbook', 'confidence', 'reason', 'error'])),
            'playbook' => $run->playbook === null ? null : ['id' => $run->playbook->id, 'slug' => $run->playbook->slug, 'version' => $run->playbookVersion?->version,
                'version_is_live' => $run->playbook_version_id === $run->playbook->live_version_id],
            'rules_cited' => array_map(fn ($id) => ['id' => $id, 'then' => $rules[$id]['then'] ?? '(no longer in that version)', 'if' => $rules[$id]['if'] ?? null], (array) ($run->rules_used ?? [])),
            'evidence_facts' => array_slice(array_map(fn ($f) => ['id' => $f['id'] ?? null, 'source' => $f['source'] ?? null, 'label' => $f['label'] ?? null, 'value' => self::cut((string) ($f['value'] ?? ''), 300)],
                (array) ($evidence['facts'] ?? [])), 0, 40),
            'evidence_notes' => array_slice((array) ($evidence['notes'] ?? []), 0, 10),
            'knowledge_seen' => array_map(fn ($k) => ['id' => $k['id'] ?? null, 'title' => $k['title'] ?? null, 'body' => self::cut((string) ($k['body'] ?? ''), 300)], array_slice((array) ($evidence['knowledge'] ?? []), 0, 10)),
            'draft' => self::cut($run->draft, 3000),
            'internal_note' => self::cut($run->internal_note, 1500),
            'claims' => array_slice((array) ($run->claims ?? []), 0, 15),
            'proposed_actions' => (array) ($run->proposed_actions ?? []),
            'validator' => [
                'passed' => $run->validator['passed'] ?? null,
                'deterministic_issues' => array_slice((array) collect((array) ($run->validator['deterministic'] ?? []))->flatten(1)->all(), 0, 10),
                'llm' => $run->validator['llm'] ?? null,
                'rejected_draft' => self::cut($run->validator['rejected_draft'] ?? null, 1500),
            ],
            'what_staff_did' => $run->feedback->map(fn (FeedbackEvent $f) => ['kind' => $f->kind, 'percent_changed' => $f->percent_changed, 'comment' => self::cut($f->text, 500)])->values()->all(),
            'sent_reply' => self::cut($sent?->final_text, 2500),
            'qa_review' => $qa === null ? null : ['id' => $qa->id, 'overall' => $qa->overall, 'failure_class' => $qa->failure_class, 'summary' => $qa->summary, 'gap' => $qa->gap],
            'cost_usd' => round($run->cost_micro_usd / 1_000_000, 4),
        ]);
    }
}

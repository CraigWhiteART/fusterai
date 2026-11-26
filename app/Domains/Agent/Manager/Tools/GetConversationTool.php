<?php

namespace App\Domains\Agent\Manager\Tools;

use App\Domains\Agent\Manager\ManagerSession;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Pipeline\TranscriptBuilder;
use App\Domains\Agent\Pipeline\TranscriptTurn;
use App\Domains\Agent\Qa\Models\QaReview;
use App\Domains\Conversation\Models\Conversation;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class GetConversationTool extends ManagerTool
{
    public function __construct(ManagerSession $session, private readonly TranscriptBuilder $transcripts)
    {
        parent::__construct($session);
    }

    public function name(): string
    {
        return 'get_conversation';
    }

    public function description(): string
    {
        return 'One conversation: subject, status, the last messages (customer and staff, with who sent each and from where), the agent runs on it and its QA reviews.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'conversation_id' => $schema->integer()->required(),
        ];
    }

    protected function label(array $arguments): string
    {
        return 'Reading conversation #'.(int) ($arguments['conversation_id'] ?? 0);
    }

    protected function run(array $arguments): string
    {
        $conversation = Conversation::where('workspace_id', $this->session->workspaceId)->find((int) ($arguments['conversation_id'] ?? 0));
        if ($conversation === null) {
            return 'No such conversation in this workspace.';
        }
        $this->session->cite('conversation', $conversation->id, self::cut((string) $conversation->subject, 100) ?: 'Conversation #'.$conversation->id);

        $turns = array_slice($this->transcripts->fromConversation($conversation)->turns, -12);

        return self::json([
            'conversation_id' => $conversation->id,
            'subject' => $conversation->subject,
            'status' => $conversation->status->value,
            'imported' => (bool) $conversation->imported,
            'handover_reason' => $conversation->handover_reason,
            'created_at' => $conversation->created_at?->toIso8601String(),
            'messages' => array_map(fn (TranscriptTurn $t) => [
                'thread_id' => $t->threadId,
                'role' => $t->role,
                'author' => $t->author,
                'origin' => $t->origin,
                'at' => $t->at->toIso8601String(),
                'text' => self::cut($t->text, 1500),
            ], $turns),
            'runs' => AgentRun::with('playbook:id,slug')->where('workspace_id', $this->session->workspaceId)->where('conversation_id', $conversation->id)
                ->whereIn('purpose', [AgentRun::PURPOSE_LIVE, AgentRun::PURPOSE_SHADOW])->latest('id')->limit(10)->get()
                ->map(fn (AgentRun $r) => ['run_id' => $r->id, 'at' => $r->created_at?->toIso8601String(), 'purpose' => $r->purpose, 'decision' => $r->decision,
                    'handover_reason' => $r->handover_reason, 'playbook' => $r->playbook?->slug])->all(),
            'qa_reviews' => QaReview::where('workspace_id', $this->session->workspaceId)->where('conversation_id', $conversation->id)
                ->whereIn('status', [QaReview::STATUS_REVIEWED, QaReview::STATUS_TRIAGED])->latest('id')->limit(5)->get()
                ->map(fn (QaReview $q) => ['id' => $q->id, 'author_type' => $q->author_type, 'overall' => $q->overall, 'triage_score' => $q->triage_score,
                    'failure_class' => $q->failure_class, 'summary' => $q->summary, 'human_verdict' => $q->human_verdict])->all(),
        ]);
    }
}

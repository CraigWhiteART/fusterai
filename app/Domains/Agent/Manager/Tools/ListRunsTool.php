<?php

namespace App\Domains\Agent\Manager\Tools;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\FeedbackEvent;
use App\Domains\Agent\Qa\Models\QaReview;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class ListRunsTool extends ManagerTool
{
    public function name(): string
    {
        return 'list_runs';
    }

    public function description(): string
    {
        return 'Recent agent runs (each run = the agent handling one customer message) with filters: playbook, decision (draft / handover), handover reason, what staff did with the draft, subject words, date range. Newest first. Use get_run for details.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'playbook' => $schema->string()->description('Playbook id or slug'),
            'decision' => $schema->string()->enum(['draft', 'handover'])->description('draft = a reply was drafted; handover = handed to a human'),
            'handover_reason' => $schema->string()->description('Prefix match, e.g. "missing_evidence" or "writer:policy_unclear"'),
            'feedback' => $schema->string()->enum(['sent_as_is', 'sent_edited', 'gmail_reply', 'rejected', 'comment'])->description('Only runs whose draft got this outcome'),
            'subject_contains' => $schema->string()->description('Words in the conversation subject'),
            'conversation_id' => $schema->integer(),
            'since' => $schema->string()->description('ISO date, e.g. 2026-11-01'),
            'until' => $schema->string()->description('ISO date (inclusive)'),
            'purpose' => $schema->string()->enum(['live', 'shadow'])->description('Default live'),
            'limit' => $schema->integer()->description('Default 20, max 50'),
        ];
    }

    protected function label(array $arguments): string
    {
        $filters = array_filter(array_intersect_key($arguments, array_flip(['playbook', 'decision', 'handover_reason', 'feedback', 'since', 'until', 'subject_contains'])));

        return 'Listing runs'.($filters !== [] ? ' ('.implode(', ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($filters), $filters)).')' : '');
    }

    protected function run(array $arguments): string
    {
        $ws = $this->session->workspaceId;
        $query = AgentRun::with(['playbook:id,slug', 'conversation:id,subject,status,imported', 'feedback:id,run_id,kind,percent_changed'])
            ->where('workspace_id', $ws)
            ->where('purpose', ($arguments['purpose'] ?? 'live') === 'shadow' ? AgentRun::PURPOSE_SHADOW : AgentRun::PURPOSE_LIVE)
            ->latest('id');

        if (($playbook = $this->playbook($arguments['playbook'] ?? null)) !== null) {
            $query->where('playbook_id', $playbook->id);
        } elseif (filled($arguments['playbook'] ?? null)) {
            return 'No such playbook in this workspace.';
        }
        if (in_array($arguments['decision'] ?? null, ['draft', 'handover'], true)) {
            $query->where('decision', $arguments['decision']);
        }
        if (filled($arguments['handover_reason'] ?? null)) {
            $query->where('handover_reason', 'like', str_replace(['%', '_'], ['\%', '\_'], (string) $arguments['handover_reason']).'%');
        }
        if (in_array($arguments['feedback'] ?? null, FeedbackEvent::KINDS, true)) {
            $query->whereHas('feedback', fn ($q) => $q->where('kind', $arguments['feedback']));
        }
        if (filled($arguments['subject_contains'] ?? null)) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $arguments['subject_contains']).'%';
            $query->whereHas('conversation', fn ($q) => $q->where('subject', 'ilike', $like));
        }
        if (isset($arguments['conversation_id']) && (int) $arguments['conversation_id'] > 0) {
            $query->where('conversation_id', (int) $arguments['conversation_id']);
        }
        if (($since = self::date($arguments['since'] ?? null)) !== null) {
            $query->where('created_at', '>=', $since);
        }
        if (($until = self::date($arguments['until'] ?? null, true)) !== null) {
            $query->where('created_at', '<=', $until);
        }

        $runs = $query->limit(self::limit($arguments['limit'] ?? null, 20, 50))->get();
        $qa = QaReview::where('workspace_id', $ws)->whereIn('run_id', $runs->pluck('id'))->whereNull('human_verdict')
            ->get(['run_id', 'overall', 'failure_class'])->keyBy('run_id');

        return self::json([
            'count' => $runs->count(),
            'runs' => $runs->map(fn (AgentRun $r) => [
                'run_id' => $r->id,
                'conversation_id' => $r->conversation_id,
                'at' => $r->created_at?->toIso8601String(),
                'subject' => self::cut($r->conversation?->subject, 120),
                'playbook' => $r->playbook?->slug,
                'status' => $r->status,
                'decision' => $r->decision,
                'handover_reason' => $r->handover_reason,
                'feedback' => $r->feedback->map(fn (FeedbackEvent $f) => array_filter(['kind' => $f->kind, 'percent_changed' => $f->percent_changed], fn ($v) => $v !== null))->values()->all(),
                'qa' => isset($qa[$r->id]) ? ['overall' => $qa[$r->id]->overall, 'failure_class' => $qa[$r->id]->failure_class] : null,
            ])->values()->all(),
        ]);
    }
}

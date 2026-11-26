<?php

namespace App\Domains\Agent\Manager\Tools;

use App\Domains\Agent\Models\FeedbackEvent;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class FeedbackStatsTool extends ManagerTool
{
    public function name(): string
    {
        return 'feedback_stats';
    }

    public function description(): string
    {
        return 'What staff did with drafts over a period (default 30 days): sent as-is, sent edited (with average % changed), replied from Gmail instead, rejected; edit rate per playbook; and the latest reviewer comments on drafts.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'since' => $schema->string()->description('ISO date'),
            'until' => $schema->string()->description('ISO date (inclusive)'),
            'playbook' => $schema->string()->description('Playbook id or slug'),
        ];
    }

    protected function label(array $arguments): string
    {
        return 'Reading feedback statistics';
    }

    protected function run(array $arguments): string
    {
        $since = self::date($arguments['since'] ?? null) ?? now()->subDays(30)->startOfDay();
        $until = self::date($arguments['until'] ?? null, true) ?? now();
        $base = FeedbackEvent::query()->from('agent_feedback_events as f')
            ->leftJoin('agent_runs as r', 'r.id', '=', 'f.run_id')
            ->leftJoin('agent_playbooks as p', 'p.id', '=', 'r.playbook_id')
            ->where('f.workspace_id', $this->session->workspaceId)
            ->whereBetween('f.created_at', [$since, $until]);
        if (($playbook = $this->playbook($arguments['playbook'] ?? null)) !== null) {
            $base->where('r.playbook_id', $playbook->id);
        }

        $rows = (clone $base)->selectRaw("coalesce(p.slug, '(none)') as playbook, f.kind, count(*) as n, avg(f.percent_changed) as avg_changed")
            ->groupBy('p.slug', 'f.kind')->toBase()->get();
        $byPlaybook = [];
        foreach ($rows as $row) {
            $byPlaybook[(string) $row->playbook][(string) $row->kind] = ['n' => (int) $row->n, 'avg_changed' => $row->avg_changed !== null ? (int) round((float) $row->avg_changed) : null];
        }
        $summaries = [];
        foreach ($byPlaybook as $slug => $kinds) {
            $asIs = $kinds[FeedbackEvent::KIND_SENT_AS_IS]['n'] ?? 0;
            $edited = $kinds[FeedbackEvent::KIND_SENT_EDITED]['n'] ?? 0;
            $summaries[] = [
                'playbook' => $slug,
                'sent_as_is' => $asIs,
                'sent_edited' => $edited,
                'avg_percent_changed' => $kinds[FeedbackEvent::KIND_SENT_EDITED]['avg_changed'] ?? null,
                'gmail_reply' => $kinds[FeedbackEvent::KIND_GMAIL_REPLY]['n'] ?? 0,
                'rejected' => $kinds[FeedbackEvent::KIND_REJECTED]['n'] ?? 0,
                'comments' => $kinds[FeedbackEvent::KIND_COMMENT]['n'] ?? 0,
                'edit_rate' => ($asIs + $edited) > 0 ? round($edited / ($asIs + $edited), 3) : null,
            ];
        }

        $comments = (clone $base)->where('f.kind', FeedbackEvent::KIND_COMMENT)->orderByDesc('f.id')->limit(10)
            ->toBase()->get(['f.id', 'f.run_id', 'f.conversation_id', 'f.text', 'f.created_at', 'p.slug']);

        return self::json([
            'since' => $since->toDateString(),
            'until' => $until->toDateString(),
            'by_playbook' => $summaries,
            'latest_comments' => $comments->map(fn ($c) => ['feedback_id' => (int) $c->id, 'run_id' => $c->run_id !== null ? (int) $c->run_id : null,
                'conversation_id' => $c->conversation_id !== null ? (int) $c->conversation_id : null, 'playbook' => $c->slug, 'text' => self::cut((string) $c->text, 400)])->all(),
        ]);
    }
}

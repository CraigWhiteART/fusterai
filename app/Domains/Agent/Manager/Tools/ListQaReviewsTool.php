<?php

namespace App\Domains\Agent\Manager\Tools;

use App\Domains\Agent\Qa\Models\QaReview;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class ListQaReviewsTool extends ManagerTool
{
    public function name(): string
    {
        return 'list_qa_reviews';
    }

    public function description(): string
    {
        return 'QA reviews of closed conversations (AI-drafted and human replies): overall score 1-5, failure class, the gap found and the suggested fix. Reviews a human dismissed are left out.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'failure_class' => $schema->string()->enum(QaReview::FAILURE_CLASSES),
            'author_type' => $schema->string()->enum(QaReview::AUTHOR_TYPES),
            'playbook' => $schema->string()->description('Playbook id or slug'),
            'max_overall' => $schema->number()->description('Only reviews scoring at most this (1-5)'),
            'since' => $schema->string()->description('ISO date'),
            'until' => $schema->string()->description('ISO date (inclusive)'),
            'limit' => $schema->integer()->description('Default 20, max 50'),
        ];
    }

    protected function label(array $arguments): string
    {
        return 'Listing QA reviews'.(filled($arguments['failure_class'] ?? null) ? ' ('.$arguments['failure_class'].')' : '');
    }

    protected function run(array $arguments): string
    {
        $query = QaReview::with('playbook:id,slug')->where('workspace_id', $this->session->workspaceId)
            ->where('status', QaReview::STATUS_REVIEWED)->whereNull('human_verdict')->latest('id');
        if (in_array($arguments['failure_class'] ?? null, QaReview::FAILURE_CLASSES, true)) {
            $query->where('failure_class', $arguments['failure_class']);
        }
        if (in_array($arguments['author_type'] ?? null, QaReview::AUTHOR_TYPES, true)) {
            $query->where('author_type', $arguments['author_type']);
        }
        if (($playbook = $this->playbook($arguments['playbook'] ?? null)) !== null) {
            $query->where('playbook_id', $playbook->id);
        }
        if (isset($arguments['max_overall']) && is_numeric($arguments['max_overall'])) {
            $query->where('overall', '<=', (float) $arguments['max_overall']);
        }
        if (($since = self::date($arguments['since'] ?? null)) !== null) {
            $query->where('created_at', '>=', $since);
        }
        if (($until = self::date($arguments['until'] ?? null, true)) !== null) {
            $query->where('created_at', '<=', $until);
        }

        return self::json(['reviews' => $query->limit(self::limit($arguments['limit'] ?? null, 20, 50))->get()->map(fn (QaReview $q) => [
            'qa_review_id' => $q->id,
            'conversation_id' => $q->conversation_id,
            'run_id' => $q->run_id,
            'author_type' => $q->author_type,
            'playbook' => $q->playbook?->slug,
            'overall' => $q->overall,
            'scores' => $q->scores,
            'failure_class' => $q->failure_class,
            'summary' => self::cut($q->summary, 400),
            'gap' => self::cut($q->gap, 400),
            'suggested_fix' => self::cut($q->suggested_fix, 400),
            'replied_at' => $q->replied_at?->toDateString(),
        ])->values()->all()]);
    }
}

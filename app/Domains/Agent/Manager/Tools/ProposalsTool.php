<?php

namespace App\Domains\Agent\Manager\Tools;

use App\Domains\Agent\Http\Presenters\LoopPresenter;
use App\Domains\Agent\Models\Proposal;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class ProposalsTool extends ManagerTool
{
    public function name(): string
    {
        return 'proposals';
    }

    public function description(): string
    {
        return 'Staged instruction changes awaiting review (from feedback, you, QA or history mining). Without proposal_id: open proposals (or a status). With proposal_id: its diff, suggestions and conflict summary. Check here before staging something that is already proposed.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'proposal_id' => $schema->integer(),
            'status' => $schema->string()->enum(Proposal::STATUSES)->description('Default: every open status'),
            'origin' => $schema->string()->enum(Proposal::ORIGINS),
        ];
    }

    protected function label(array $arguments): string
    {
        return isset($arguments['proposal_id']) ? 'Reading proposal #'.(int) $arguments['proposal_id'] : 'Listing proposals';
    }

    protected function run(array $arguments): string
    {
        $ws = $this->session->workspaceId;
        if (isset($arguments['proposal_id']) && (int) $arguments['proposal_id'] > 0) {
            $proposal = Proposal::with('changes')->where('workspace_id', $ws)->find((int) $arguments['proposal_id']);
            if ($proposal === null) {
                return 'No such proposal in this workspace.';
            }
            $this->session->cite('proposal', $proposal->id, $proposal->title);

            return self::json([
                'id' => $proposal->id,
                'title' => $proposal->title,
                'status' => $proposal->status,
                'origin' => $proposal->origin,
                'rationale' => self::cut($proposal->rationale, 1500),
                'diff' => array_map(fn ($d) => array_intersect_key($d, array_flip(['target_label', 'label', 'item_id', 'before', 'after'])), LoopPresenter::flatDiff($proposal)),
                'suggestions' => $proposal->suggestions ?? [],
                'blocking_conflicts' => count($proposal->blockingConflicts()),
                'created_at' => $proposal->created_at?->toIso8601String(),
            ]);
        }

        $query = Proposal::with('changes')->where('workspace_id', $ws)->latest('id')->limit(20);
        if (in_array($arguments['status'] ?? null, Proposal::STATUSES, true)) {
            $query->where('status', $arguments['status']);
        } else {
            $query->whereIn('status', Proposal::OPEN_STATUSES);
        }
        if (in_array($arguments['origin'] ?? null, Proposal::ORIGINS, true)) {
            $query->where('origin', $arguments['origin']);
        }

        return self::json(['proposals' => $query->get()->map(fn (Proposal $p) => [
            'id' => $p->id,
            'title' => $p->title,
            'status' => $p->status,
            'origin' => $p->origin,
            'targets' => $p->changes->map(fn ($c) => LoopPresenter::targetLabel($c))->values()->all(),
            'created_at' => $p->created_at?->toDateString(),
        ])->values()->all()]);
    }
}

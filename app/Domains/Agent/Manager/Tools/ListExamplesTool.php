<?php

namespace App\Domains\Agent\Manager\Tools;

use App\Domains\Agent\Models\AgentExample;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class ListExamplesTool extends ManagerTool
{
    public function name(): string
    {
        return 'list_examples';
    }

    public function description(): string
    {
        return 'Example replies the agent is shown as few-shot examples (ids for retire_example / replace_example), optionally for one playbook or containing some words.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'playbook' => $schema->string()->description('Playbook slug'),
            'contains' => $schema->string()->description('Only examples whose reply or customer message contains this text'),
            'include_retired' => $schema->boolean(),
        ];
    }

    protected function label(array $arguments): string
    {
        return 'Listing example replies'.(filled($arguments['playbook'] ?? null) ? ' for '.$arguments['playbook'] : '');
    }

    protected function run(array $arguments): string
    {
        $query = AgentExample::where('workspace_id', $this->session->workspaceId)->latest('id');
        if (! ($arguments['include_retired'] ?? false)) {
            $query->where('status', 'active');
        }
        if (filled($arguments['playbook'] ?? null)) {
            $query->where('playbook_slug', (string) $arguments['playbook']);
        }
        if (filled($arguments['contains'] ?? null)) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $arguments['contains']).'%';
            $query->where(fn ($q) => $q->where('reply', 'ilike', $like)->orWhere('customer_message', 'ilike', $like));
        }

        return self::json(['examples' => $query->limit(15)->get()->map(fn (AgentExample $e) => [
            'id' => $e->id,
            'playbook' => $e->playbook_slug,
            'status' => $e->status,
            'customer_message' => self::cut($e->customer_message, 300),
            'reply' => self::cut($e->reply, 700),
        ])->values()->all()]);
    }
}

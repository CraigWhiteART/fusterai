<?php

namespace App\Domains\Agent\Http\Controllers;

use App\Domains\Agent\Services\AgentBootstrapper;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

abstract class AgentController extends Controller
{
    /** Admin gate shared by every instruction screen; seeds the workspace on first visit. */
    protected function workspaceFor(Request $request): int
    {
        $this->authorize('manage-settings');
        $workspaceId = (int) $request->user()->workspace_id;
        app(AgentBootstrapper::class)->ensureForWorkspace($workspaceId);

        return $workspaceId;
    }

    protected function ensureWorkspace(Model $model, int $workspaceId): void
    {
        abort_unless((int) $model->getAttribute('workspace_id') === $workspaceId, 404);
    }
}

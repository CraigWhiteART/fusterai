<?php

namespace App\Domains\Agent\Mining;

use RuntimeException;

final class MiningAlreadyRunning extends RuntimeException
{
    public static function forWorkspace(int $workspaceId): self
    {
        return new self("Workspace {$workspaceId} already has an unfinished mining run. Finish, pause or cancel it first.");
    }
}

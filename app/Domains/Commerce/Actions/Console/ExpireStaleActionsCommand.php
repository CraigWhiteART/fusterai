<?php

namespace App\Domains\Commerce\Actions\Console;

use App\Domains\Commerce\Actions\ActionApprovals;
use Illuminate\Console\Command;

class ExpireStaleActionsCommand extends Command
{
    protected $signature = 'agent:actions-expire';

    protected $description = 'Expire action approvals nobody decided within agent_action_settings.approval_expiry_hours';

    public function handle(ActionApprovals $approvals): int
    {
        $this->info('Expired '.$approvals->expireStale().' pending action(s).');

        return self::SUCCESS;
    }
}

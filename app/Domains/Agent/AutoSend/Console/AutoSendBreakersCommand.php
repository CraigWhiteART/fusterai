<?php

namespace App\Domains\Agent\AutoSend\Console;

use App\Domains\Agent\AutoSend\CircuitBreaker;
use App\Domains\Agent\Models\Playbook;
use Illuminate\Console\Command;

/**
 * Re-evaluates the per-playbook circuit breakers of every auto playbook
 * (signals that arrive later: unhappy replies, reopens, QA reviews).
 * Scheduled every 15 minutes in routes/console.php. Only ever demotes.
 */
class AutoSendBreakersCommand extends Command
{
    protected $signature = 'agent:autosend-breakers';

    protected $description = 'Check the AI auto-send circuit breakers and demote playbooks that trip one';

    public function handle(CircuitBreaker $breakers): int
    {
        $tripped = 0;
        $workspaces = Playbook::where('mode', Playbook::MODE_AUTO)->distinct()->pluck('workspace_id');
        foreach ($workspaces as $workspaceId) {
            $tripped += $breakers->evaluateWorkspace((int) $workspaceId);
        }

        $this->info("Checked {$workspaces->count()} workspace(s); {$tripped} playbook(s) demoted.");

        return self::SUCCESS;
    }
}

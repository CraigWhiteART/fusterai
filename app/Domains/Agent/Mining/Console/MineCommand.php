<?php

namespace App\Domains\Agent\Mining\Console;

use App\Domains\Agent\Mining\MiningAlreadyRunning;
use App\Domains\Agent\Mining\MiningPresenter;
use App\Domains\Agent\Mining\MiningRunner;
use App\Domains\Agent\Mining\MiningService;
use App\Domains\Agent\Mining\Models\MiningRun;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * Learn voice, playbooks, knowledge and test cases from closed conversations
 * (docs/agent-mining.md). Everything lands as proposals for a human.
 *
 *   agent:mine 1 --estimate                    what a run would cost, nothing started
 *   agent:mine 1 --months=3 --max=200 --budget=3  queue a run on ai-bulk
 *   agent:mine 1 --sync ...                    run it in this process
 *   agent:mine --status=5 | --pause=5 | --resume=5 [--budget=10] | --cancel=5
 *   agent:mine --recover                       re-queue stalled runs (scheduled)
 */
class MineCommand extends Command
{
    protected $signature = 'agent:mine
                            {workspace? : Workspace id (optional when there is only one)}
                            {--months= : Window in months back from --until (default agent_mining.defaults.months)}
                            {--since= : Window start (date), instead of --months}
                            {--until= : Window end (date, default now)}
                            {--max= : Maximum conversations}
                            {--budget= : Budget cap in USD (with --resume: the new cap)}
                            {--strategy= : stratified | newest}
                            {--estimate : Print the dry-run estimate only}
                            {--sync : Run in this process instead of on the ai-bulk queue}
                            {--status= : Show a run}
                            {--pause= : Pause a run}
                            {--resume= : Resume a paused or failed run}
                            {--cancel= : Cancel a run}
                            {--recover : Re-queue runs that stopped making progress}';

    protected $description = 'Mine closed support conversations into proposals (voice, playbooks, knowledge, test cases)';

    public function handle(MiningService $mining, MiningRunner $runner, MiningPresenter $presenter): int
    {
        if ($this->option('recover')) {
            $this->info('Re-queued '.$mining->recoverStalled().' stalled mining run(s).');

            return self::SUCCESS;
        }

        foreach (['status', 'pause', 'resume', 'cancel'] as $action) {
            if ($this->option($action) !== null) {
                return $this->runAction($action, (int) $this->option($action), $mining, $presenter);
            }
        }

        $workspaceId = $this->workspaceId();
        if ($workspaceId === null) {
            return self::FAILURE;
        }

        $input = array_filter([
            'months' => $this->option('months'),
            'since' => $this->option('since'),
            'until' => $this->option('until'),
            'max_conversations' => $this->option('max'),
            'budget_usd' => $this->option('budget'),
            'sample_strategy' => $this->option('strategy'),
        ], fn ($v) => $v !== null && $v !== '');

        $estimate = $mining->estimate($workspaceId, $input);
        $this->printEstimate($estimate);
        if ($this->option('estimate')) {
            return self::SUCCESS;
        }

        try {
            $run = $mining->start($workspaceId, $input, dispatch: ! $this->option('sync'));
        } catch (MiningAlreadyRunning $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Mining run #{$run->id} started (budget \${$run->option('budget_usd')}).");
        if (! $this->option('sync')) {
            $this->info('Queued on '.config('agent_mining.queue.queue').'. Follow it with agent:mine --status='.$run->id.' or GET /agent/mining/'.$run->id.'.');

            return self::SUCCESS;
        }

        $stage = null;
        while (true) {
            $delay = $runner->runStep($run->id, $run->job_token);
            $run->refresh();
            if ($run->stage !== $stage) {
                $stage = $run->stage;
                $this->line("  stage: {$stage}");
            }
            if ($delay === null) {
                break;
            }
            if ($delay >= 30) {
                sleep(min($delay, 60)); // backing off after an error
            }
        }

        $this->printRun($presenter->detail($run));

        return $run->status === MiningRun::STATUS_COMPLETED ? self::SUCCESS : self::FAILURE;
    }

    private function runAction(string $action, int $id, MiningService $mining, MiningPresenter $presenter): int
    {
        $run = MiningRun::query()->find($id);
        if ($run === null) {
            $this->error("No mining run {$id}.");

            return self::FAILURE;
        }

        $ok = match ($action) {
            'pause' => $mining->pause($run),
            'resume' => $mining->resume($run, $this->option('budget') !== null ? (float) $this->option('budget') : null),
            'cancel' => $mining->cancel($run),
            default => true,
        };
        if (! $ok) {
            $this->error("Run {$id} is {$run->status}; cannot {$action} it.");

            return self::FAILURE;
        }

        $this->printRun($presenter->detail($run->fresh() ?? $run));

        return self::SUCCESS;
    }

    private function workspaceId(): ?int
    {
        $arg = $this->argument('workspace');
        if ($arg !== null) {
            if (Workspace::query()->whereKey((int) $arg)->doesntExist()) {
                $this->error("No workspace {$arg}.");

                return null;
            }

            return (int) $arg;
        }

        $ids = Workspace::query()->limit(2)->pluck('id');
        if ($ids->count() !== 1) {
            $this->error('Give a workspace id.');

            return null;
        }

        return (int) $ids->first();
    }

    /** @param  array<string, mixed>  $e */
    private function printEstimate(array $e): void
    {
        $this->info(sprintf(
            'Estimate: %d eligible, %d selected (%d already extracted, %d to extract), ~%d situations.',
            $e['eligible'], $e['selected'], $e['already_extracted'], $e['to_extract'], $e['situations_estimated'],
        ));
        $rows = [];
        foreach ($e['stages'] as $stage => $s) {
            $rows[] = [$stage, $s['model'] ?? '-', $s['calls'], number_format($s['tokens_in']), number_format($s['tokens_out']), '$'.number_format($s['cost_usd'], 2)];
        }
        $rows[] = ['total', '', $e['total']['calls'], number_format($e['total']['tokens_in']), number_format($e['total']['tokens_out']), '$'.number_format($e['total']['cost_usd'], 2)];
        $this->table(['stage', 'model', 'calls', 'tokens in', 'tokens out', 'cost'], $rows);
        $this->line('Budget cap: $'.number_format($e['budget_usd'], 2).($e['within_budget'] ? '' : ' (the estimate is above the cap: the run will pause when it is reached)'));
    }

    /** @param  array<string, mixed>  $r */
    private function printRun(array $r): void
    {
        $this->newLine();
        $this->info("Run #{$r['id']}: {$r['status']} (stage {$r['stage']})".($r['message'] ? " - {$r['message']}" : '').($r['last_error'] ? " (last error: {$r['last_error']})" : ''));
        $p = $r['progress'];
        $this->table(['', 'count'], [
            ['Eligible', $p['eligible']],
            ['Selected', $p['selected']],
            ['Extracted (new)', $p['extracted_new']],
            ['Reused extractions', $p['reused']],
            ['Canned-reply copies', $p['macro_copies']],
            ['Pending', $p['pending']],
            ['Failed', $p['failed']],
            ['Situations', $p['situations']],
            ['Synthesised', $p['situations_synthesised']],
            ['Proposals', $p['proposals_emitted']],
        ]);
        $this->line(sprintf('Cost: $%.4f of $%.2f (%s tokens in, %s out)', $r['cost_usd'], $r['budget_usd'], number_format($r['tokens']['in']), number_format($r['tokens']['out'])));
        if ($r['coverage'] !== []) {
            $this->table(['situation', 'conversations', 'status', 'proposal'], array_map(fn ($c) => [
                $c['key'].($c['is_new'] ? ' (new)' : ''), $c['conversation_count'], $c['status'], $c['proposal']['proposal_id'] ?? '-',
            ], $r['coverage']));
        }
        if ($r['dropped'] !== []) {
            $this->table(['dropped', 'count'], array_map(fn ($k, $v) => [$k, $v], array_keys($r['dropped']), $r['dropped']));
        }
    }
}

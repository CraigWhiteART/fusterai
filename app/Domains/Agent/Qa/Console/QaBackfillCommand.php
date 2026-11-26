<?php

namespace App\Domains\Agent\Qa\Console;

use App\Domains\Agent\Qa\QaService;
use App\Domains\Conversation\Models\Conversation;
use App\Enums\ConversationStatus;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * Review closed (non-imported) conversations of the last N days that have no
 * review yet. Queued on `ai-bulk` (redis-long); --sync runs in-process.
 */
class QaBackfillCommand extends Command
{
    protected $signature = 'agent:qa-backfill {workspace? : Workspace id (optional when there is only one)}
        {--days=7 : Conversations closed / updated in the last N days}
        {--limit= : Maximum conversations (default agent_qa.backfill.max)}
        {--sync : Review in this process instead of queueing}
        {--dry-run : Count only}';

    protected $description = 'QA-review recently closed conversations (AI and human replies)';

    public function handle(QaService $qa): int
    {
        $workspaceId = $this->argument('workspace');
        if ($workspaceId === null) {
            $ids = Workspace::query()->limit(2)->pluck('id');
            if ($ids->count() !== 1) {
                $this->error('Pass a workspace id.');

                return self::FAILURE;
            }
            $workspaceId = $ids->first();
        }
        $workspaceId = (int) $workspaceId;
        if (($reason = $qa->disabledReason($workspaceId)) !== null) {
            $this->error("QA does not run for this workspace: {$reason}.");

            return self::FAILURE;
        }

        $limit = max(1, (int) ($this->option('limit') ?: config('agent_qa.backfill.max', 500)));
        $query = Conversation::where('workspace_id', $workspaceId)
            ->where('status', ConversationStatus::Closed->value)
            ->where('imported', false)
            ->where('updated_at', '>=', now()->subDays(max(1, (int) $this->option('days'))))
            ->orderByDesc('updated_at')
            ->limit($limit);

        if ($this->option('dry-run')) {
            $this->info($query->count().' closed conversations in range (existing reviews are skipped when run).');

            return self::SUCCESS;
        }

        $queued = 0;
        $seen = 0;
        foreach ($query->cursor() as $conversation) {
            $seen++;
            if ($qa->backfill($conversation, queue: ! $this->option('sync'), sync: (bool) $this->option('sync')) !== null) {
                $queued++;
            }
        }
        $this->info("{$seen} conversations checked, {$queued} ".($this->option('sync') ? 'reviewed' : 'queued').'.');

        return self::SUCCESS;
    }
}

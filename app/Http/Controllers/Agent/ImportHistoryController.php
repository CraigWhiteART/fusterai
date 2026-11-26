<?php

namespace App\Http\Controllers\Agent;

use App\Domains\Conversation\Models\Conversation;
use App\Domains\Mailbox\Models\Mailbox;
use App\Domains\Mailbox\Sync\Backfill\BackfillAlreadyRunning;
use App\Domains\Mailbox\Sync\Backfill\BackfillService;
use App\Domains\Mailbox\Sync\Backfill\MailBackfillRun;
use App\Domains\Mailbox\Sync\Backfill\MailBackfillThread;
use App\Domains\Mailbox\Sync\Backfill\ThreadFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\StartImportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Import history" (/agent/import): start a dry run or a real import of a
 * mailbox's Gmail history, follow it, pause / resume / cancel / undo it.
 * Admins only (manage-settings). The page polls index() for progress.
 */
class ImportHistoryController extends Controller
{
    public function __construct(private readonly BackfillService $backfills) {}

    public function index(Request $request): Response
    {
        $this->authorize('manage-settings');
        $workspaceId = (int) $request->user()->workspace_id;

        $runs = MailBackfillRun::query()
            ->where('workspace_id', $workspaceId)
            ->with('mailbox:id,name,email')
            ->latest('id')
            ->limit(15)
            ->get();

        $selected = $request->filled('run')
            ? $runs->firstWhere('id', (int) $request->query('run'))
            : $runs->first();

        return Inertia::render('Agent/Import', [
            'mailboxes' => Mailbox::query()
                ->where('workspace_id', $workspaceId)
                ->whereNotNull('imap_config')
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'defaults' => [
                'months' => (int) config('mailsync.backfill.default_months', 18),
                'include_unanswered' => (bool) config('mailsync.backfill.include_unanswered', false),
                'active_window_days' => (int) config('mailsync.backfill.active_window_days', 14),
            ],
            'runs' => $runs->map(fn (MailBackfillRun $run) => $this->present($run))->values(),
            'selected' => $selected !== null ? $this->present($selected) + [
                'samples' => $this->samples($selected),
                'dropped_samples' => $this->droppedSamples($selected),
            ] : null,
        ]);
    }

    public function store(StartImportRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $mailbox = Mailbox::query()->where('workspace_id', $request->user()->workspace_id)->findOrFail($data['mailbox_id']);

        try {
            $run = $this->backfills->start(
                $mailbox,
                months: (int) $data['months'],
                dryRun: (bool) ($data['dry_run'] ?? false),
                includeUnanswered: (bool) ($data['include_unanswered'] ?? false),
                user: $request->user(),
            );
        } catch (BackfillAlreadyRunning $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('agent.import.index', ['run' => $run->id])
            ->with('success', $run->isDryRun() ? 'Dry run started.' : 'Import started.');
    }

    public function action(Request $request, MailBackfillRun $run, string $action): RedirectResponse
    {
        $this->authorize('manage-settings');
        abort_unless($run->workspace_id === (int) $request->user()->workspace_id, 404);

        try {
            $ok = match ($action) {
                'pause' => $this->backfills->pause($run),
                'resume' => $this->backfills->resume($run),
                'cancel' => $this->backfills->cancel($run),
                'undo' => $this->backfills->undo($run),
                default => abort(404),
            };
        } catch (BackfillAlreadyRunning $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('agent.import.index', ['run' => $run->id])
            ->with($ok ? 'success' : 'error', $ok ? ucfirst($action).' requested.' : "This import cannot {$action} now (it is {$run->status}).");
    }

    /** @return array<string, mixed> */
    private function present(MailBackfillRun $run): array
    {
        $c = $run->counters ?? [];
        $stageIndex = array_search($run->stage, MailBackfillRun::STAGES, true);

        return [
            'id' => $run->id,
            'mailbox' => $run->mailbox !== null ? ['id' => $run->mailbox->id, 'name' => $run->mailbox->name, 'email' => $run->mailbox->email] : null,
            'months' => $run->months,
            'since' => $run->since->toDateString(),
            'dry_run' => $run->isDryRun(),
            'include_unanswered' => (bool) $run->option('include_unanswered', false),
            'status' => $run->status,
            'stage' => $run->stage,
            'stage_index' => $stageIndex === false ? 0 : $stageIndex,
            'counters' => [
                'envelopes_fetched' => (int) ($c['envelopes_fetched'] ?? 0),
                'messages_seen' => (int) ($c['messages_seen'] ?? 0),
                'threads_total' => (int) ($c['threads_total'] ?? 0),
                'threads_kept' => (int) ($c['threads_kept'] ?? 0),
                'threads_active' => (int) ($c['threads_active'] ?? 0),
                'threads_dropped' => (int) ($c['threads_dropped'] ?? 0),
                'threads_skipped' => (int) ($c['threads_skipped'] ?? 0),
                'messages_to_import' => (int) ($c['messages_to_import'] ?? 0),
                'bodies_fetched' => (int) ($c['bodies_fetched'] ?? 0),
                'threads_materialised' => (int) ($c['threads_materialised'] ?? 0),
                'conversations_created' => (int) ($c['conversations_created'] ?? 0),
                'messages_ingested' => (int) ($c['messages_ingested'] ?? 0),
                'messages_linked' => (int) ($c['messages_linked'] ?? 0),
                'messages_skipped' => (int) ($c['messages_skipped'] ?? 0),
                'errors' => $run->error_count + (int) ($c['message_errors'] ?? 0),
            ],
            'kept_reasons' => (object) ($c['kept_reasons'] ?? []),
            'dropped_reasons' => (object) ($c['dropped_reasons'] ?? []),
            'skipped_reasons' => (object) ($c['skipped_reasons'] ?? []),
            'last_error' => $run->last_error,
            'last_error_at' => $run->last_error_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'heartbeat_at' => $run->heartbeat_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
            'can' => [
                'pause' => in_array($run->status, [MailBackfillRun::STATUS_QUEUED, MailBackfillRun::STATUS_RUNNING], true),
                'resume' => in_array($run->status, [MailBackfillRun::STATUS_PAUSED, MailBackfillRun::STATUS_FAILED], true),
                'cancel' => in_array($run->status, [MailBackfillRun::STATUS_QUEUED, MailBackfillRun::STATUS_RUNNING, MailBackfillRun::STATUS_PAUSED, MailBackfillRun::STATUS_FAILED], true),
                'undo' => ! $run->isDryRun() && in_array($run->status, [MailBackfillRun::STATUS_COMPLETED, MailBackfillRun::STATUS_CANCELLED, MailBackfillRun::STATUS_FAILED], true),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function samples(MailBackfillRun $run): array
    {
        return Conversation::query()
            ->where('backfill_run_id', $run->id)
            ->with('customer:id,name,email')
            ->withCount(['threads as messages_count' => fn ($q) => $q->where('type', 'message')])
            ->orderByDesc('last_reply_at')
            ->limit(12)
            ->get(['id', 'subject', 'status', 'customer_id', 'created_at', 'last_reply_at', 'imported'])
            ->map(fn (Conversation $c) => [
                'id' => $c->id,
                'subject' => $c->subject,
                'status' => $c->status->value,
                'imported' => (bool) $c->imported,
                'customer' => $c->customer?->name ?: $c->customer?->email,
                'messages' => (int) $c->getAttribute('messages_count'),
                'created_at' => $c->created_at?->toIso8601String(),
                'last_reply_at' => $c->last_reply_at?->toIso8601String(),
                'url' => route('conversations.show', $c->id),
            ])
            ->values()
            ->all();
    }

    /** A few threads the filter left out, so the owner can check the rules. @return list<array<string, mixed>> */
    private function droppedSamples(MailBackfillRun $run): array
    {
        return MailBackfillThread::query()
            ->where('run_id', $run->id)
            ->where('decision', ThreadFilter::DROPPED)
            ->orderByDesc('last_at')
            ->limit(12)
            ->get(['id', 'reason', 'subject', 'customer_email', 'message_count', 'last_at'])
            ->map(fn (MailBackfillThread $t) => [
                'id' => $t->id,
                'reason' => $t->reason,
                'subject' => $t->subject,
                'customer_email' => $t->customer_email,
                'messages' => $t->message_count,
                'last_at' => $t->last_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}

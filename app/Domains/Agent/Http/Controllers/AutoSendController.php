<?php

namespace App\Domains\Agent\Http\Controllers;

use App\Domains\Agent\AutoSend\AutoModeGuard;
use App\Domains\Agent\AutoSend\AutoSendAudit;
use App\Domains\Agent\AutoSend\AutoSendService;
use App\Domains\Agent\AutoSend\CircuitBreaker;
use App\Domains\Agent\AutoSend\Shadow\ShadowReport;
use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\FeedbackEvent;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Agent\Pipeline\PolicyGate;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Enums\ThreadType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * Auto-send endpoints (docs/agent-autosend.md):
 *  - conversation level (anyone who can see / work the conversation):
 *    cancel a scheduled auto-reply (optionally "edit instead"), flag a sent
 *    one as wrong;
 *  - admin (manage-settings): pause / resume all auto-sending, the shadow
 *    report, a playbook's auto-send status (preconditions, breakers, history).
 */
class AutoSendController extends AgentController
{
    public function cancel(Request $request, Thread $thread, AutoSendService $service): JsonResponse
    {
        $conversation = $this->conversationOf($thread);
        $this->authorize('update', $conversation);
        $data = $request->validate(['edit' => ['sometimes', 'boolean']]);
        $edit = (bool) ($data['edit'] ?? false);

        abort_unless(AutoSendService::isAutoThread($thread), 404);

        $result = $service->cancel($thread, $edit ? 'edit_instead' : 'cancelled_by_user', (int) $request->user()->id, restoreDraft: true);
        if ($result === null) {
            return response()->json(['message' => 'This auto-reply is no longer scheduled (it was sent or already cancelled).', 'status' => AutoSendService::state($thread->fresh() ?? $thread)['status'] ?? null], 409);
        }

        return response()->json([
            'ok' => true,
            'draft' => $result['draft'],
            'suggestion_id' => $result['suggestion']?->id,
            'edit' => $edit,
        ]);
    }

    public function wrong(Request $request, Thread $thread, CircuitBreaker $breakers): JsonResponse
    {
        $conversation = $this->conversationOf($thread);
        $this->authorize('view', $conversation);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);

        $state = AutoSendService::state($thread);
        abort_unless(AutoSendService::isAutoThread($thread) && $thread->type === ThreadType::Message && ($state['status'] ?? null) === AutoSendService::STATUS_SENT, 404);

        $userId = (int) $request->user()->id;
        $existing = FeedbackEvent::where('thread_id', $thread->id)->where('kind', FeedbackEvent::KIND_AUTO_SEND_WRONG)->where('user_id', $userId)->first();
        if ($existing !== null) {
            return response()->json(['ok' => true, 'feedback_id' => $existing->id, 'already_flagged' => true, 'playbook_demoted' => false]);
        }

        $run = isset($state['run_id']) ? AgentRun::find((int) $state['run_id']) : null;
        $playbookId = $run->playbook_id ?? ($state['playbook_id'] ?? null);
        $event = FeedbackEvent::create([
            'workspace_id' => $conversation->workspace_id,
            'run_id' => $run?->id,
            'conversation_id' => $conversation->id,
            // thread_id stays null: agent_feedback_events.thread_id is unique (one sent-reply capture per thread).
            'thread_id' => null,
            'user_id' => $userId,
            'kind' => FeedbackEvent::KIND_AUTO_SEND_WRONG,
            'draft_before' => $run?->draft,
            'final_text' => $thread->body_plain,
            'text' => $data['reason'],
            'meta' => ['reason' => 'auto_send_wrong', 'auto_thread_id' => $thread->id, 'playbook_id' => $playbookId !== null ? (int) $playbookId : null],
        ]);

        AutoSendAudit::record('auto_send_flagged_wrong', 'An auto-sent reply was marked wrong: '.mb_substr($data['reason'], 0, 200), $conversation, $run, [
            'thread_id' => $thread->id,
            'feedback_id' => $event->id,
            'playbook_id' => $playbookId,
        ], $userId);

        $demoted = $playbookId !== null && $breakers->evaluatePlaybookId((int) $playbookId) !== null;

        return response()->json(['ok' => true, 'feedback_id' => $event->id, 'already_flagged' => false, 'playbook_demoted' => $demoted], 201);
    }

    public function pause(Request $request, AutoSendService $service): JsonResponse
    {
        $workspaceId = $this->workspaceFor($request);
        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);
        $settings = AgentSetting::forWorkspace($workspaceId);
        // Default: paused until someone resumes it (one year).
        $until = now()->addHours((int) ($data['hours'] ?? 8760));
        $reason = trim((string) ($data['reason'] ?? '')) ?: 'Paused by '.$request->user()->name;
        $settings->forceFill(['paused_until' => $until, 'paused_reason' => mb_substr($reason, 0, 255)])->save();

        $cancelled = $service->cancelPending($workspaceId, 'paused', userId: (int) $request->user()->id);
        AutoSendAudit::record('autosend_paused', "All AI auto-sending paused until {$until->toIso8601String()}: {$reason}", $settings, null, [
            'paused_until' => $until->toIso8601String(), 'reason' => $reason, 'cancelled' => $cancelled,
        ], (int) $request->user()->id);

        return response()->json(['paused_until' => $until->toIso8601String(), 'paused_reason' => $reason, 'cancelled' => $cancelled]);
    }

    public function resume(Request $request): JsonResponse
    {
        $workspaceId = $this->workspaceFor($request);
        $settings = AgentSetting::forWorkspace($workspaceId);
        $was = $settings->paused_until?->toIso8601String();
        $settings->forceFill(['paused_until' => null, 'paused_reason' => null])->save();

        AutoSendAudit::record('autosend_resumed', 'AI auto-sending resumed', $settings, null, ['was_paused_until' => $was], (int) $request->user()->id);

        return response()->json(['paused_until' => null]);
    }

    public function shadowReport(Request $request, ShadowReport $report): JsonResponse
    {
        $workspaceId = $this->workspaceFor($request);
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'worst' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return response()->json($report->build($workspaceId, (int) ($data['days'] ?? 30), (int) ($data['worst'] ?? 10)));
    }

    public function playbookStatus(Request $request, Playbook $playbook, AutoModeGuard $guard, CircuitBreaker $breakers): JsonResponse
    {
        $this->ensureWorkspace($playbook, $this->workspaceFor($request));

        return response()->json(self::playbookPayload($playbook, $guard, $breakers));
    }

    /** @return array<string, mixed> */
    public static function playbookPayload(Playbook $playbook, AutoModeGuard $guard, CircuitBreaker $breakers): array
    {
        return [
            'preconditions' => $guard->preconditions($playbook),
            'breakers' => $breakers->metrics($playbook),
            'history' => self::history($playbook),
            'rollout_preview' => self::rolloutPreview($playbook),
            'auto_hold' => $playbook->auto_hold,
            'demoted_at' => $playbook->demoted_at?->toIso8601String(),
            'demotion_reason' => $playbook->demotion_reason,
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function history(Playbook $playbook, int $limit = 20): array
    {
        return Activity::query()
            ->where('log_name', AutoSendAudit::LOG)
            ->where('subject_type', $playbook->getMorphClass())
            ->where('subject_id', $playbook->id)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Activity $a) => [
                'id' => $a->id,
                'event' => $a->event,
                'description' => $a->description,
                'properties' => $a->properties?->toArray() ?? [],
                'causer_id' => $a->causer_id,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->values()->all();
    }

    /**
     * Rollout buckets of the last 100 conversations this playbook answered
     * (live or shadow runs), so the page can say "about N of the last 100
     * would have been in the rollout" for any percentage (bucket < percent).
     *
     * @return array{sample: int, buckets: list<int>}
     */
    public static function rolloutPreview(Playbook $playbook): array
    {
        $ids = AgentRun::where('playbook_id', $playbook->id)
            ->whereIn('purpose', [AgentRun::PURPOSE_LIVE, AgentRun::PURPOSE_SHADOW])
            ->whereNotNull('conversation_id')
            ->orderByDesc('id')
            ->limit(500)
            ->pluck('conversation_id')
            ->unique()
            ->take(100)
            ->values();

        return [
            'sample' => $ids->count(),
            'buckets' => $ids->map(fn ($id) => PolicyGate::rolloutBucket((int) $id))->values()->all(),
        ];
    }

    private function conversationOf(Thread $thread): Conversation
    {
        $conversation = Conversation::find($thread->conversation_id);
        abort_if($conversation === null, 404);

        return $conversation;
    }
}

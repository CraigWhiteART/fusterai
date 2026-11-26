<?php

namespace App\Domains\Agent\AutoSend;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Agent\Models\AgentSetting;
use App\Domains\Agent\Models\FeedbackEvent;
use App\Domains\Agent\Models\Playbook;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\Thread;
use App\Enums\ThreadType;
use App\Models\User;
use App\Notifications\AgentAutoSendNotification;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * Circuit breakers. They only ever take autonomy away:
 *
 *  - per playbook (auto -> draft, never the other way), over a rolling
 *    window (config agent.auto_send.breakers):
 *      validator failure rate of its live runs,
 *      negative signal after auto-sends (the customer's next message is
 *        angry / frustrated / risk-flagged, or the conversation is reopened),
 *      "this was wrong" marks on its auto-sent replies,
 *      QA score of its auto-sent replies (when agent_qa_reviews exists);
 *  - global: more than `max_per_hour` auto-sends in an hour pauses all
 *    auto-sending (agent_settings.paused_until) for `pause_hours`.
 *
 * Each trip cancels the affected scheduled auto-replies (they come back as
 * drafts), is written to the activity log and notifies the workspace
 * admins. Nothing here ever re-enables anything.
 */
class CircuitBreaker
{
    public function __construct(
        private readonly AutoSendService $service,
        private readonly QaSignal $qa,
    ) {}

    public function evaluatePlaybookId(int $playbookId): ?string
    {
        $playbook = Playbook::find($playbookId);

        return $playbook !== null ? $this->evaluatePlaybook($playbook) : null;
    }

    /** Demote when a breaker is over its threshold. @return string|null the tripped breaker */
    public function evaluatePlaybook(Playbook $playbook): ?string
    {
        if ($playbook->mode !== Playbook::MODE_AUTO) {
            return null;
        }

        $metrics = $this->metrics($playbook);
        foreach ($metrics['breakers'] as $key => $breaker) {
            if ($breaker['tripped']) {
                $this->demote($playbook, $key, $breaker['explanation'], $metrics);

                return $key;
            }
        }

        return null;
    }

    public function evaluateWorkspace(int $workspaceId): int
    {
        $tripped = 0;
        foreach (Playbook::where('workspace_id', $workspaceId)->where('mode', Playbook::MODE_AUTO)->get() as $playbook) {
            try {
                $tripped += $this->evaluatePlaybook($playbook) !== null ? 1 : 0;
            } catch (Throwable $e) {
                Log::error('Breaker evaluation failed', ['playbook_id' => $playbook->id, 'error' => $e->getMessage()]);
            }
        }

        return $tripped;
    }

    /**
     * Current breaker readings for a playbook (also shown on the playbook page).
     *
     * @return array{window_hours: int, auto_sent: int, breakers: array<string, array{label: string, value: float|int|null, threshold: float|int, sample: int, min_sample: int, tripped: bool, explanation: string, available?: bool}>}
     */
    public function metrics(Playbook $playbook): array
    {
        $cfg = (array) config('agent.auto_send.breakers', []);
        $hours = (int) ($cfg['window_hours'] ?? 72);
        $since = now()->subHours($hours);
        $minSample = (int) ($cfg['min_sample'] ?? 10);

        // 1. Validator failures of this playbook's live runs.
        $validated = AgentRun::where('playbook_id', $playbook->id)
            ->where('purpose', AgentRun::PURPOSE_LIVE)
            ->where('status', AgentRun::STATUS_COMPLETED)
            ->where('created_at', '>=', $since)
            ->get(['id', 'validator'])
            ->filter(fn (AgentRun $r) => array_key_exists('passed', (array) $r->validator) && ($r->validator['passed'] ?? null) !== null);
        $vFailed = $validated->filter(fn (AgentRun $r) => ($r->validator['passed'] ?? null) === false)->count();
        $vRate = $validated->count() > 0 ? $vFailed / $validated->count() : null;
        $vMax = (float) ($cfg['validator_failure_rate'] ?? 0.3);

        // Auto-sent replies of this playbook in the window.
        $sent = $this->sentThreads($playbook, $since);

        // 2. Negative signal after the send.
        $negative = 0;
        foreach ($sent as $thread) {
            if ($this->negativeAfter($thread)) {
                $negative++;
            }
        }
        $nRate = $sent->count() > 0 ? $negative / $sent->count() : null;
        $nMax = (float) ($cfg['negative_signal_rate'] ?? 0.25);

        // 3. "This was wrong" marks.
        $wrong = FeedbackEvent::where('workspace_id', $playbook->workspace_id)
            ->where('kind', FeedbackEvent::KIND_AUTO_SEND_WRONG)
            ->where('created_at', '>=', $since)
            ->where('meta->playbook_id', (string) $playbook->id)
            ->count();
        $wMax = (int) ($cfg['wrong_marks'] ?? 2);

        // 4. QA (optional).
        $qa = $this->qa->forThreads($sent->pluck('id')->map(fn ($id) => (int) $id)->values()->all());
        $qMin = (float) ($cfg['qa_min_overall'] ?? 3.0);
        $qSample = (int) ($cfg['qa_min_sample'] ?? 5);

        $pct = fn (?float $v) => $v === null ? 'n/a' : round($v * 100).'%';

        return [
            'window_hours' => $hours,
            'auto_sent' => $sent->count(),
            'breakers' => [
                'validator_failures' => [
                    'label' => 'Validator failure rate',
                    'value' => $vRate !== null ? round($vRate, 3) : null,
                    'threshold' => $vMax,
                    'sample' => $validated->count(),
                    'min_sample' => $minSample,
                    'tripped' => $validated->count() >= $minSample && $vRate !== null && $vRate >= $vMax,
                    'explanation' => "{$vFailed} of {$validated->count()} drafts failed validation in the last {$hours}h ({$pct($vRate)}; limit {$pct($vMax)}).",
                ],
                'negative_signal' => [
                    'label' => 'Unhappy replies after an auto-send',
                    'value' => $nRate !== null ? round($nRate, 3) : null,
                    'threshold' => $nMax,
                    'sample' => $sent->count(),
                    'min_sample' => $minSample,
                    'tripped' => $sent->count() >= $minSample && $nRate !== null && $nRate >= $nMax,
                    'explanation' => "{$negative} of {$sent->count()} auto-replies were followed by an unhappy customer reply or a reopen in the last {$hours}h ({$pct($nRate)}; limit {$pct($nMax)}).",
                ],
                'wrong_marks' => [
                    'label' => '"This was wrong" marks',
                    'value' => $wrong,
                    'threshold' => $wMax,
                    'sample' => $wrong,
                    'min_sample' => 0,
                    'tripped' => $wrong >= $wMax,
                    'explanation' => "{$wrong} auto-replies were marked wrong in the last {$hours}h (limit {$wMax}).",
                ],
                'qa_score' => [
                    'label' => 'QA score of auto-replies',
                    'value' => $qa['avg'] ?? null,
                    'threshold' => $qMin,
                    'sample' => (int) ($qa['count'] ?? 0),
                    'min_sample' => $qSample,
                    'available' => $qa !== null,
                    'tripped' => $qa !== null && $qa['avg'] !== null && $qa['count'] >= $qSample && $qa['avg'] < $qMin,
                    'explanation' => $qa === null
                        ? 'QA reviews are not available.'
                        : 'Average QA score '.($qa['avg'] !== null ? round($qa['avg'], 2) : 'n/a')." over {$qa['count']} reviewed auto-replies (minimum {$qMin}).",
                ],
            ],
        ];
    }

    /** @return Collection<int, Thread> */
    private function sentThreads(Playbook $playbook, DateTimeInterface $since): Collection
    {
        return Thread::query()
            ->where('origin', Thread::ORIGIN_AI)
            ->where('type', ThreadType::Message->value)
            ->where('meta->auto_send->status', AutoSendService::STATUS_SENT)
            ->where('meta->auto_send->playbook_id', (string) $playbook->id)
            ->where('created_at', '>=', $since)
            ->whereHas('conversation', fn ($q) => $q->where('workspace_id', $playbook->workspace_id))
            ->get(['id', 'conversation_id', 'sent_at', 'created_at', 'meta']);
    }

    /** The customer's next message was unhappy / risky, or the conversation was reopened after a close. */
    private function negativeAfter(Thread $thread): bool
    {
        $next = Thread::where('conversation_id', $thread->conversation_id)
            ->where('type', ThreadType::Message->value)
            ->whereNotNull('customer_id')
            ->where('id', '>', $thread->id)
            ->orderBy('id')
            ->value('id');

        if ($next !== null) {
            $run = AgentRun::where('conversation_id', $thread->conversation_id)
                ->where('customer_thread_id', $next)
                ->where('purpose', AgentRun::PURPOSE_LIVE)
                ->whereNotNull('triage')
                ->latest('id')
                ->first(['id', 'triage', 'handover_reason']);
            $triage = (array) ($run->triage ?? []);
            if (in_array((string) ($triage['sentiment'] ?? ''), ['angry', 'frustrated'], true)
                || array_diff((array) ($triage['risk_flags'] ?? []), ['triage_error']) !== []
                || $run?->handover_reason === 'writer:customer_requests_human') {
                return true;
            }
        }

        $sentAt = $thread->sent_at ?? $thread->created_at;

        return Activity::query()
            ->where('subject_type', (new Conversation)->getMorphClass())
            ->where('subject_id', $thread->conversation_id)
            ->where('created_at', '>=', $sentAt)
            ->where('properties->old->status', 'closed')
            ->where('properties->attributes->status', 'open')
            ->exists();
    }

    /**
     * Switch a playbook from auto back to draft. Never the other way.
     *
     * @param  array<string, mixed>  $metrics
     */
    public function demote(Playbook $playbook, string $breaker, string $explanation, array $metrics = [], string $event = 'playbook_demoted'): bool
    {
        $demoted = DB::transaction(function () use ($playbook, $explanation) {
            /** @var Playbook|null $locked */
            $locked = Playbook::query()->lockForUpdate()->find($playbook->id);
            if ($locked === null || $locked->mode !== Playbook::MODE_AUTO) {
                return false;
            }
            $locked->forceFill([
                'mode' => Playbook::MODE_DRAFT,
                'auto_hold' => null,
                'auto_hold_simulation_id' => null,
                'demoted_at' => now(),
                'demotion_reason' => mb_substr($explanation, 0, 255),
            ])->save();

            return true;
        });

        if (! $demoted) {
            return false;
        }
        $playbook->refresh();

        AutoSendAudit::record($event, "Playbook \"{$playbook->name}\" switched from auto to draft: {$explanation}", $playbook, null, [
            'playbook_id' => $playbook->id,
            'breaker' => $breaker,
            'explanation' => $explanation,
            'metrics' => $metrics['breakers'][$breaker] ?? null,
        ]);

        $this->service->cancelPending((int) $playbook->workspace_id, 'breaker', playbookId: $playbook->id);

        $this->notifyAdmins((int) $playbook->workspace_id, new AgentAutoSendNotification(
            AgentAutoSendNotification::KIND_PLAYBOOK_DEMOTED,
            "AI auto-send stopped for \"{$playbook->name}\"",
            "The playbook \"{$playbook->name}\" was switched from auto-send back to draft. {$explanation} Replies for it are drafts for a human again.",
            "/agent/playbooks/{$playbook->id}",
        ));

        return true;
    }

    /** Pause ALL auto-sending (the hourly breaker). Idempotent while paused. */
    public function tripGlobal(int $workspaceId, string $reason): bool
    {
        $settings = AgentSetting::forWorkspace($workspaceId);
        if ($settings->isPaused()) {
            return false;
        }

        $until = now()->addHours((int) config('agent.auto_send.pause_hours', 24));
        $settings->forceFill(['paused_until' => $until, 'paused_reason' => mb_substr('Circuit breaker: '.$reason, 0, 255)])->save();

        AutoSendAudit::record('autosend_paused', "All AI auto-sending paused until {$until->toIso8601String()}: {$reason}", $settings, null, [
            'paused_until' => $until->toIso8601String(),
            'reason' => $reason,
            'breaker' => 'hourly',
        ]);

        $this->service->cancelPending($workspaceId, 'paused');

        $this->notifyAdmins($workspaceId, new AgentAutoSendNotification(
            AgentAutoSendNotification::KIND_PAUSED,
            'AI auto-send paused',
            "All AI auto-sending is paused until {$until->toDayDateTimeString()} ({$reason}). Replies are drafts for a human until an admin resumes it.",
            '/agent/settings',
        ));

        return true;
    }

    private function notifyAdmins(int $workspaceId, AgentAutoSendNotification $notification): void
    {
        try {
            $admins = User::where('workspace_id', $workspaceId)->whereIn('role', ['admin', 'super_admin'])->get();
            Notification::send($admins, $notification);
        } catch (Throwable $e) {
            Log::error('Could not notify admins about an auto-send breaker', ['workspace_id' => $workspaceId, 'error' => $e->getMessage()]);
        }
    }
}

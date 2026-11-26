<?php

namespace App\Domains\Commerce\Actions;

use App\Domains\Agent\Models\AgentRun;
use App\Domains\Commerce\Actions\Models\ActionExecution as E;

/**
 * Reply coupling. A draft whose text depends on an action ("I've refunded
 * ...") must not reach the customer before that action has succeeded.
 *
 *   ready              no reply-blocking action, or all of them succeeded
 *   waiting            a reply-blocking action is pending / approved / executing / unknown
 *   needs_rewrite      a reply-blocking action failed, was rejected, expired or was cancelled:
 *                      the draft promises something that will not happen
 *
 * The auto-send path (and SendReplyJob) calls blocksSending($run); the inbox
 * reads state() through the API to disable Send.
 */
class ActionGate
{
    public const VERDICT_READY = 'ready';

    public const VERDICT_WAIT = 'wait';

    public const VERDICT_CANCEL = 'cancel';

    public static function blocksSending(AgentRun $run): bool
    {
        return self::state($run)['state'] !== 'ready';
    }

    /**
     * The unattended path's view of a run's actions (stricter than state()):
     *
     *   ready   every action succeeded, or none blocks the reply and none needs a human
     *   wait    an unattended, reply-blocking action is still approved / executing
     *   cancel  any action needs a human approval (route `approval`), failed,
     *           was rejected / expired / cancelled, has an unknown outcome, or a
     *           proposal was never recorded: the reply must go to a human
     *           (reason = the first problem found)
     *
     * RunEffects calls it before scheduling an auto-send, and AutoSendGuard
     * on every pre-send check (together with blocksSending()).
     *
     * @return array{verdict: string, reason: string|null}
     */
    public static function autoSendVerdict(AgentRun $run): array
    {
        $ids = [];
        foreach ((array) ($run->proposed_actions ?? []) as $action) {
            if (! is_array($action) || ($action['status'] ?? '') !== 'proposed') {
                continue;
            }
            if (! isset($action['execution_id'])) {
                return ['verdict' => self::VERDICT_CANCEL, 'reason' => 'action_unrecorded'];
            }
            $ids[] = (int) $action['execution_id'];
        }
        if ($ids === []) {
            return ['verdict' => self::VERDICT_READY, 'reason' => null];
        }

        $executions = E::whereIn('id', $ids)->get(['id', 'status', 'route', 'reply_blocking']);
        if ($executions->count() !== count(array_unique($ids))) {
            return ['verdict' => self::VERDICT_CANCEL, 'reason' => 'action_unrecorded'];
        }

        $wait = false;
        foreach ($executions as $e) {
            if ($e->status === E::SUCCEEDED) {
                continue;
            }
            if ($e->route === E::ROUTE_APPROVAL || $e->status === E::PENDING_APPROVAL) {
                return ['verdict' => self::VERDICT_CANCEL, 'reason' => 'action_needs_approval'];
            }
            if (in_array($e->status, [E::FAILED, E::REJECTED, E::EXPIRED, E::CANCELLED, E::UNKNOWN], true)) {
                return ['verdict' => self::VERDICT_CANCEL, 'reason' => 'action_failed'];
            }
            if ($e->reply_blocking) {
                $wait = true; // proposed / approved / executing on the unattended route
            }
        }

        return ['verdict' => $wait ? self::VERDICT_WAIT : self::VERDICT_READY, 'reason' => $wait ? 'actions_running' : null];
    }

    /**
     * @return array{state: string, blocking: list<int>, failed: list<int>, executions: list<array{id: int, action_key: string, status: string, reply_blocking: bool}>}
     */
    public static function state(AgentRun $run): array
    {
        $ids = [];
        $unlinked = false;
        $registry = app(ActionRegistry::class);
        foreach ((array) ($run->proposed_actions ?? []) as $action) {
            if (! is_array($action) || ($action['status'] ?? '') !== 'proposed') {
                continue;
            }
            if (isset($action['execution_id'])) {
                $ids[] = (int) $action['execution_id'];
            } elseif (! $registry->has((string) ($action['action'] ?? '')) || $registry->get((string) $action['action'])->replyWaits()) {
                $unlinked = true;
            }
        }

        $executions = $ids === [] ? collect() : E::whereIn('id', $ids)->get(['id', 'action_key', 'status', 'reply_blocking']);
        $blocking = [];
        $failed = [];
        foreach ($executions as $e) {
            if (! $e->reply_blocking || $e->status === E::SUCCEEDED) {
                continue;
            }
            if (in_array($e->status, [E::FAILED, E::REJECTED, E::EXPIRED, E::CANCELLED], true)) {
                $failed[] = $e->id;
            } else {
                $blocking[] = $e->id;
            }
        }

        // A live run whose proposals were never turned into executions (the
        // recorder failed) is not safe to send either: fail closed.
        $state = match (true) {
            $failed !== [] => 'needs_rewrite',
            $blocking !== [] || ($unlinked && $run->isLive()) => 'waiting',
            default => 'ready',
        };

        return [
            'state' => $state,
            'blocking' => $blocking,
            'failed' => $failed,
            'executions' => $executions->map(fn (E $e) => ['id' => $e->id, 'action_key' => $e->action_key, 'status' => $e->status, 'reply_blocking' => $e->reply_blocking])->values()->all(),
        ];
    }
}

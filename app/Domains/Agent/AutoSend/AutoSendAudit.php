<?php

namespace App\Domains\Agent\AutoSend;

use App\Domains\Agent\Models\AgentRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One audit trail for auto-send: every decision, send, cancellation,
 * demotion and pause goes to the activity log (log name `agent_autosend`,
 * with the run id in the properties) and, when there is a run, into
 * agent_runs.auto_send.events (shown on the run payload).
 */
final class AutoSendAudit
{
    public const LOG = 'agent_autosend';

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function record(string $event, string $description, ?Model $subject = null, ?AgentRun $run = null, array $properties = [], ?int $causerId = null): void
    {
        $at = now()->toIso8601String();
        $properties = array_filter(['run_id' => $run?->id, ...$properties], fn ($v) => $v !== null);

        if ($run !== null) {
            $fresh = $run->fresh() ?? $run;
            $state = (array) ($fresh->auto_send ?? []);
            $state['events'] = array_values([...((array) ($state['events'] ?? [])), ['event' => $event, 'at' => $at, ...$properties]]);
            $fresh->forceFill(['auto_send' => $state])->save();
            $run->setRawAttributes($fresh->getAttributes(), true);
        }

        try {
            $logger = activity(self::LOG)->event($event)->withProperties($properties);
            if ($subject !== null) {
                $logger->performedOn($subject);
            }
            if ($causerId !== null) {
                $logger->causedBy($causerId);
            }
            $logger->log($description);
        } catch (Throwable $e) {
            Log::warning('Auto-send audit log failed', ['event' => $event, 'run_id' => $run?->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Merge fields into agent_runs.auto_send (keeps events).
     *
     * @param  array<string, mixed>  $fields
     */
    public static function update(AgentRun $run, array $fields): void
    {
        $fresh = $run->fresh() ?? $run;
        $fresh->forceFill(['auto_send' => array_replace((array) ($fresh->auto_send ?? []), $fields)])->save();
        $run->setRawAttributes($fresh->getAttributes(), true);
    }
}

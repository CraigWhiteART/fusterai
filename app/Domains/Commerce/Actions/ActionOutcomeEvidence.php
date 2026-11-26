<?php

namespace App\Domains\Commerce\Actions;

use App\Domains\Agent\Pipeline\EvidenceLedger;
use App\Domains\Commerce\Actions\Models\ActionExecution as E;
use App\Domains\Conversation\Models\Conversation;

/**
 * Puts the outcome of this conversation's recent actions into a live run's
 * evidence ledger, so a rewrite after a rejected / failed action says so,
 * and a reply after a succeeded one may state it (DeterministicValidator
 * accepts `action.succeeded.*` facts as grounding for "I have refunded").
 */
final class ActionOutcomeEvidence
{
    public static function addTo(EvidenceLedger $ledger, ?Conversation $conversation): void
    {
        if ($conversation === null) {
            return;
        }

        $executions = E::where('conversation_id', $conversation->id)
            ->where('created_at', '>=', now()->subDays(14))
            ->whereIn('status', [E::SUCCEEDED, E::FAILED, E::REJECTED, E::EXPIRED, E::CANCELLED, E::PENDING_APPROVAL, E::APPROVED, E::EXECUTING, E::UNKNOWN])
            ->orderBy('id')
            ->limit(10)
            ->get();

        foreach ($executions as $e) {
            $what = trim((string) $e->description) !== '' ? (string) $e->description : $e->action_key;
            $amount = $e->amount !== null ? ' Amount: '.trim(($e->currency ?? '').' '.$e->amount).'.' : '';
            $code = isset($e->params['code']) ? ' Code: '.$e->params['code'].'.' : '';

            switch ($e->status) {
                case E::SUCCEEDED:
                    $ledger->add('action', 'action.succeeded.'.$e->action_key.'.'.$e->id, "Shopify action done ({$e->action_key})", $what.$amount.$code);
                    break;
                case E::FAILED:
                case E::REJECTED:
                case E::EXPIRED:
                case E::CANCELLED:
                    $why = $e->rejection_reason ?? $e->error ?? '';
                    $ledger->add('action', 'action.'.$e->status.'.'.$e->action_key.'.'.$e->id, "Shopify action {$e->status} ({$e->action_key})", $what.($why !== '' ? ' Reason: '.$why : ''));
                    $ledger->note("The {$e->action_key} action was {$e->status}; it has NOT happened. Do not tell the customer it is done; a human decides what to offer instead.");
                    break;
                default:
                    $ledger->add('action', 'action.pending.'.$e->action_key.'.'.$e->id, "Shopify action waiting ({$e->action_key}, {$e->status})", $what.$amount);
            }
        }
    }
}

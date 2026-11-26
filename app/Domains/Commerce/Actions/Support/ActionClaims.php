<?php

namespace App\Domains\Commerce\Actions\Support;

use App\Domains\Agent\Pipeline\EvidenceLedger;

/**
 * Keeps the writer honest: a draft that says an action is done ("I have
 * refunded / cancelled / updated your address") must correspond to that
 * action being proposed in the same run (ledger fact action.proposed.<key>.*,
 * added by propose_action), or already done for this conversation
 * (action.succeeded.*, added by ActionOutcomeEvidence). A still-pending action
 * from an earlier run does not count: this run's reply would not wait for it.
 * Pure: reads only the ledger.
 */
final class ActionClaims
{
    private const DONE = "(?:i|we)(?:\\s*'ve|\\s+have)?\\s+(?:now\\s+|just\\s+|already\\s+|gone\\s+ahead\\s+and\\s+)?";

    /** @return array<string, list<string>> action key(s) => patterns */
    private static function patterns(): array
    {
        $done = self::DONE;

        return [
            'refund|cancel_order' => [
                "/\\b{$done}(?:refunded|processed\\s+(?:a|the|your)\\s+(?:full\\s+|partial\\s+)?refund|issued\\s+(?:a|the|your)\\s+(?:full\\s+|partial\\s+)?refund)\\b/i",
                '/\\byour\\s+(?:full\\s+|partial\\s+)?refund\\s+has\\s+(?:now\\s+)?been\\s+(?:processed|issued|sent|completed)\\b/i',
                '/\\b(?:your\\s+(?:order|payment)|it)\\s+has\\s+(?:now\\s+)?been\\s+refunded\\b/i',
            ],
            'cancel_order' => [
                "/\\b{$done}cancel(?:l)?ed\\s+(?:your|the|this)\\s+order\\b/i",
                '/\\byour\\s+order\\s+has\\s+(?:now\\s+)?been\\s+cancel(?:l)?ed\\b/i',
            ],
            'change_address' => [
                "/\\b{$done}(?:updated|changed|corrected|amended)\\s+(?:your|the)\\s+(?:shipping\\s+|delivery\\s+|postal\\s+)?address\\b/i",
                '/\\b(?:your|the)\\s+(?:shipping\\s+|delivery\\s+)?address\\s+has\\s+(?:now\\s+)?been\\s+(?:updated|changed|corrected|amended)\\b/i',
            ],
            'discount_code' => [
                "/\\b{$done}(?:created|issued|generated|set\\s+up)\\s+(?:a|the|your)\\s+(?:single-use\\s+|one-time\\s+)?(?:discount|coupon|promo)(?:\\s+code)?\\b/i",
            ],
            'replacement' => [
                "/\\b{$done}(?:sent|arranged|organi[sz]ed|created|placed|dispatched)\\s+(?:a|the|your)\\s+(?:free\\s+)?replacement\\b/i",
                '/\\b(?:a|the|your)\\s+replacement\\s+(?:order\\s+)?has\\s+(?:now\\s+)?been\\s+(?:sent|arranged|created|placed|dispatched)\\b/i',
            ],
            'fulfilment_hold' => [
                "/\\b{$done}(?:put|placed)\\s+(?:your|the)\\s+order\\s+on\\s+hold\\b/i",
            ],
        ];
    }

    /** @return list<array{check: string, value: string, problem: string}> */
    public static function unbacked(string $draft, EvidenceLedger $ledger): array
    {
        $backed = [];
        foreach ($ledger->facts('action') as $fact) {
            if (preg_match('/^action\.(?:proposed|succeeded)\.([a-z_]+)\./', $fact['key'], $m) === 1) {
                $backed[$m[1]] = true;
            }
        }

        $issues = [];
        foreach (self::patterns() as $keys => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $draft, $match) !== 1) {
                    continue;
                }
                $ok = false;
                foreach (explode('|', $keys) as $key) {
                    $ok = $ok || isset($backed[$key]);
                }
                if (! $ok) {
                    $issues[] = [
                        'check' => 'claimed_action',
                        'value' => $match[0],
                        'problem' => "The draft says \"{$match[0]}\", but no such action was proposed in this run. Do not claim an action is done unless you proposed it.",
                    ];
                }
            }
        }

        return $issues;
    }
}

<?php

namespace App\Domains\Agent\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Read-only: the carrier facts and shipment guidance already in evidence. */
class LookupTrackingTool extends WriterTool
{
    public function name(): string
    {
        return 'lookup_tracking';
    }

    public function description(): string
    {
        return 'Get verified carrier tracking facts (ids E...) and the shipment rules that apply to them.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(array $arguments): string
    {
        $ledger = $this->session->ledger;
        if ($ledger->meta('order_withheld')) {
            return 'Tracking is withheld: the order email does not match the sender. Hand over.';
        }

        $lines = array_map(fn ($f) => "[{$f['id']}] {$f['label']}: {$f['value']}", $ledger->facts('tracking'));
        if ($lines === []) {
            $lines[] = 'No carrier tracking data. Do not describe carrier scans, delivery attempts or signatures.';
        }
        foreach ($ledger->guidance() as $rule) {
            $lines[] = 'RULE: '.$rule;
        }

        return implode("\n", $lines);
    }
}

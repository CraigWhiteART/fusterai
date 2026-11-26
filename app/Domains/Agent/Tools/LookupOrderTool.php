<?php

namespace App\Domains\Agent\Tools;

use App\Domains\Agent\Pipeline\EvidenceCollector;
use App\Domains\Agent\Pipeline\WriterSession;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Read-only. Returns the prefetched order facts; on a live run it can look up
 * one more order number, still subject to the sender-email match.
 */
class LookupOrderTool extends WriterTool
{
    public function __construct(WriterSession $session, private readonly EvidenceCollector $collector)
    {
        parent::__construct($session);
    }

    public function name(): string
    {
        return 'lookup_order';
    }

    public function description(): string
    {
        return 'Get verified Shopify order facts (ids E...). Pass order_number only to look up an order the customer named that is not already in the evidence.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'order_number' => $schema->string()->nullable()->description('Order number such as SD1234, or null for the order already in evidence')->required(),
        ];
    }

    protected function run(array $arguments): string
    {
        $ledger = $this->session->ledger;
        $number = strtoupper(ltrim(trim((string) ($arguments['order_number'] ?? '')), '#'));

        if ($ledger->meta('order_withheld')) {
            return 'Order details are withheld: the order email does not match the sender. Do not disclose any order information. You may answer in general terms and ask the customer to reply from the email address used on the order, or to confirm it.';
        }

        $current = strtoupper(ltrim((string) $ledger->valueOf('shopify.order_number'), '#'));
        if ($number !== '' && $number !== $current && ! str_ends_with($current, $number)) {
            $added = $this->collector->lookupAdditionalOrder($this->session->input, $ledger, $number);
            if ($added === []) {
                return "No disclosable order {$number}. Ask the customer to confirm the order number, or hand over.";
            }
        }

        $facts = $ledger->facts('shopify');
        if ($facts === []) {
            return 'No verified Shopify order is available. Do not pretend an order exists.';
        }

        return implode("\n", array_map(fn ($f) => "[{$f['id']}] {$f['label']}: {$f['value']}", $facts));
    }
}

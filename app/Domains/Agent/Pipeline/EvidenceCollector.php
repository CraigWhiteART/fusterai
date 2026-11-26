<?php

namespace App\Domains\Agent\Pipeline;

use App\Domains\Commerce\Actions\ActionOutcomeEvidence;
use App\Domains\Commerce\Models\CommerceSetting;
use App\Domains\Commerce\Models\ShopifySnapshot;
use App\Domains\Commerce\Models\TrackingSnapshot;
use App\Domains\Commerce\Services\CommerceFacts;
use App\Domains\Commerce\Services\ShopifyLookupService;
use App\Domains\Commerce\Services\TrackingLookupService;

/**
 * Step 4: prefetch Shopify order + carrier tracking into the evidence ledger.
 *
 * Only a live run calls Shopify or the carrier. Every other purpose uses the
 * evidence it was given, or (for a run against a real conversation) the last
 * snapshots already stored for it.
 *
 * Order details are disclosed only when the order's email matches the
 * conversation's customer (or a Reply-To customer address); otherwise the
 * order facts are withheld and the gate hands over.
 */
class EvidenceCollector
{
    public function __construct(
        private readonly ShopifyLookupService $shopify,
        private readonly TrackingLookupService $tracking,
        private readonly CommerceFacts $facts,
    ) {}

    public function collect(PipelineInput $input): EvidenceLedger
    {
        if ($input->suppliedEvidence !== null) {
            $ledger = EvidenceLedger::fromArray($input->suppliedEvidence->toArray());
            $ledger->setMeta('source', 'supplied');
            $this->addCustomerFacts($ledger, $input);

            return $ledger;
        }

        $ledger = new EvidenceLedger;
        $this->addCustomerFacts($ledger, $input);

        $conversation = $input->conversation;
        if ($conversation === null) {
            $ledger->setMeta('source', 'none');
            $ledger->note('No order or carrier evidence was supplied for this run.');

            return $ledger;
        }

        if ($input->isLive()) {
            $ledger->setMeta('source', 'live');
            $snapshot = $this->shopify->lookup($conversation);
            $this->applyShopify($ledger, $input, $snapshot->payload ?? [], $snapshot->id);

            $trackingSnapshot = $ledger->meta('order_withheld') ? null : $this->tracking->lookup($conversation, $snapshot);
            $this->applyTracking($ledger, $input, $trackingSnapshot?->payload, $trackingSnapshot?->id, $trackingSnapshot !== null);

            // ── Phase 9: outcomes of this conversation's Shopify actions (docs/agent-actions.md) ──
            if (! $ledger->meta('order_withheld')) {
                ActionOutcomeEvidence::addTo($ledger, $conversation);
            }
            // ── end Phase 9 ──
        } else {
            $ledger->setMeta('source', 'recorded');
            $snapshot = ShopifySnapshot::where('conversation_id', $conversation->id)->latest('id')->first();
            if ($snapshot === null) {
                $ledger->note('No stored Shopify snapshot for this conversation (non-live runs never call Shopify).');
            } else {
                $this->applyShopify($ledger, $input, $snapshot->payload ?? [], $snapshot->id);
            }

            $trackingSnapshot = ($snapshot === null || $ledger->meta('order_withheld'))
                ? null
                : TrackingSnapshot::where('conversation_id', $conversation->id)->latest('id')->first();
            $this->applyTracking($ledger, $input, $trackingSnapshot?->payload, $trackingSnapshot?->id, $trackingSnapshot !== null);
        }

        return $ledger;
    }

    /**
     * A ledger from stored Shopify / carrier payloads (a frozen case's
     * recorded or reconstructed evidence), with the same disclosure rules as
     * a live run. No I/O. Customer facts are added later by collect().
     *
     * @param  array<string, mixed>|null  $shopify  ShopifySnapshot-shaped payload
     * @param  array<string, mixed>|null  $tracking  TrackingSnapshot-shaped payload
     */
    public function ledgerFromPayloads(PipelineInput $input, ?array $shopify, ?array $tracking, ?int $shopifySnapshotId = null, ?int $trackingSnapshotId = null): EvidenceLedger
    {
        $ledger = new EvidenceLedger;
        if ($shopify === null) {
            $ledger->setMeta('order_found', false);
            $ledger->setMeta('tracking_found', false);
            $ledger->note('No order or carrier evidence was recorded for this past case.');

            return $ledger;
        }

        $this->applyShopify($ledger, $input, $shopify, $shopifySnapshotId);
        $this->applyTracking($ledger, $input, $tracking, $trackingSnapshotId, $tracking !== null);

        return $ledger;
    }

    /**
     * Live-only lookup of another order number the writer asked for. Returns
     * the ids of the facts added (empty when nothing new was disclosed).
     *
     * @return list<string>
     */
    public function lookupAdditionalOrder(PipelineInput $input, EvidenceLedger $ledger, string $orderNumber): array
    {
        if (! $input->isLive() || $input->conversation === null) {
            return [];
        }

        $before = count($ledger->facts());
        $snapshot = $this->shopify->lookup($input->conversation, [$orderNumber]);
        $payload = $snapshot->payload ?? [];

        if (! ($payload['found'] ?? false) || ! $this->emailMatches($input, $payload)) {
            $ledger->note("Order {$orderNumber} could not be disclosed (not found, or its email does not match the sender).");

            return [];
        }

        $ledger->addMany('shopify', $this->facts->shopifyFacts($payload));

        return array_map(fn ($fact) => $fact['id'], array_slice($ledger->facts(), $before));
    }

    private function addCustomerFacts(EvidenceLedger $ledger, PipelineInput $input): void
    {
        if (filled($input->customerName)) {
            $ledger->add('conversation', 'customer.name', 'Customer name (helpdesk)', (string) $input->customerName);
        }
        if (filled($input->customerEmail)) {
            $ledger->add('conversation', 'customer.email', 'Customer email (helpdesk)', (string) $input->customerEmail);
        }
        if ($input->transcript->hasCustomerImages()) {
            $ledger->add('conversation', 'customer.images', 'Customer attached images', 'yes');
        }

        foreach ($this->shopify->extractOrderNumbers($input->subject."\n".$input->transcript->customerText()) as $number) {
            $ledger->add('customer', 'customer.order_number.'.$number, 'Order number the customer quoted (not verified)', $number);
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function applyShopify(EvidenceLedger $ledger, PipelineInput $input, array $payload, ?int $snapshotId): void
    {
        $ledger->setMeta('shopify_snapshot_id', $snapshotId);

        if (! ($payload['found'] ?? false)) {
            $ledger->setMeta('order_found', false);
            $ledger->note('No verified Shopify order. Reason: '.($payload['reason'] ?? 'unknown').' Do not pretend an order exists; ask for the order number if needed.');

            return;
        }

        $hasOrder = filled($payload['shopify_order_id'] ?? null) || filled($payload['order_number'] ?? null);
        $ledger->setMeta('order_found', $hasOrder);

        if ($hasOrder && ! $this->emailMatches($input, $payload)) {
            $ledger->setMeta('order_email_match', false);
            $ledger->setMeta('order_withheld', true);
            $ledger->note('An order matched the order number in the mail, but its email does not match the sender. Order details are withheld and must not be disclosed. Reply in general terms only and ask the customer to write from the email address used on the order, or to confirm it.');

            return;
        }

        $ledger->setMeta('order_email_match', $hasOrder ? true : null);
        $ledger->addMany('shopify', $this->facts->shopifyFacts($payload));
        $ledger->setUntrustedNotes($this->facts->shopifyNotes($payload));
        $ledger->setMeta('shopify_payload', $payload);
    }

    /** @param  array<string, mixed>|null  $payload */
    private function applyTracking(EvidenceLedger $ledger, PipelineInput $input, ?array $payload, ?int $snapshotId, bool $looked): void
    {
        $ledger->setMeta('tracking_snapshot_id', $snapshotId);

        if ($ledger->meta('order_withheld')) {
            return;
        }

        if (! $looked || $payload === null) {
            $ledger->setMeta('tracking_found', false);
            $ledger->note('No carrier tracking data. Use Shopify fulfilment data only and do not describe carrier scans.');
        } elseif (! ($payload['found'] ?? false)) {
            $ledger->setMeta('tracking_found', false);
            $ledger->note('The carrier has no record for this tracking number yet.');
        } else {
            $ledger->setMeta('tracking_found', true);
            $ledger->addMany('tracking', $this->facts->trackingFacts($payload));
        }

        $staleDays = (int) (CommerceSetting::forWorkspace($input->workspaceId)->tracking_stale_days ?: 14);
        $shopifyPayload = $ledger->meta('shopify_payload');
        foreach ($this->facts->shipmentGuidance(is_array($shopifyPayload) ? $shopifyPayload : null, $looked ? $payload : null, $staleDays) as $guidance) {
            $ledger->guide($guidance);
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function emailMatches(PipelineInput $input, array $payload): bool
    {
        // Only the order's own email counts: the Shopify customer was looked up
        // by the sender's address, so it says nothing about who owns an order
        // found by number. A snapshot without order_email fails closed.
        $orderEmail = mb_strtolower(trim((string) ($payload['order_email'] ?? '')));
        if ($orderEmail === '') {
            return false;
        }

        return in_array($orderEmail, $input->senderEmails(), true);
    }
}

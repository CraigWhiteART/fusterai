<?php

namespace Modules\CommerceAssist\Services;

use App\Domains\Conversation\Models\Conversation;
use Illuminate\Support\Collection;
use Modules\CommerceAssist\Models\ApprovedResponse;
use Modules\CommerceAssist\Models\CommerceIntent;
use Modules\CommerceAssist\Models\ShopifySnapshot;
use Modules\CommerceAssist\Models\TrackingSnapshot;
use Modules\CommerceAssist\Support\PlainText;

class ContextBuilder
{
    /**
     * @param  Collection<int, ApprovedResponse>  $examples
     * @param  Collection<int, object{title: string, content: string}>  $kbDocs
     * @param  array<string, mixed>  $classification
     * @return array{system: string, user: string, sources: array<string, mixed>}
     */
    public function build(
        Conversation $conversation,
        string $customerMessage,
        ShopifySnapshot $snapshot,
        array $classification,
        CommerceIntent $intent,
        ?CommerceIntent $subtype,
        Collection $examples,
        Collection $kbDocs,
        bool $hasPhotos,
        ?int $trackingStaleDays = null,
        ?Collection $liveFacts = null,
        ?TrackingSnapshot $tracking = null,
    ): array {
        $liveFacts ??= collect();
        $shopify = $this->formatShopify($snapshot);
        $carrier = $this->formatTracking($tracking);
        $customer = $this->formatCustomer($conversation, $hasPhotos);
        $kb = $this->formatKnowledge($kbDocs);
        $facts = $this->formatLiveFacts($liveFacts);
        $rules = $this->formatRules($intent, $subtype, $snapshot, $trackingStaleDays, $tracking);
        $exampleBlock = $this->formatExamples($examples);
        $subtypeLabel = $classification['subtype'] ?? $subtype?->slug ?? 'none';

        $system = <<<PROMPT
You write a customer-support email reply for an agent to review. Never send anything yourself.

Verified data and knowledge may be used for factual claims.

Approved responses are examples of style, structure and response strategy only.

Never copy customer-specific facts from examples.
Never invent order status, tracking, dates, policies, prices, or product facts.
If a fact is not in the verified sections below, say you do not have that information.
Current business facts override older knowledge base articles when they conflict.
Do not promise refunds, replacements, address changes, or dispatch dates unless verified data supports it.
Do not add a Subject line. Write only the reply body.
Address the customer by first name when known.
Keep the tone warm, direct, and specific.

CUSTOMER MESSAGE
{$customerMessage}

VERIFIED CUSTOMER DATA
{$customer}

VERIFIED SHOPIFY DATA
{$shopify}

VERIFIED CARRIER TRACKING DATA
{$carrier}

CURRENT BUSINESS FACTS
{$facts}

VERIFIED KNOWLEDGE BASE FACTS
{$kb}

INTENT-SPECIFIC RULES
Intent: {$intent->slug}
Subtype: {$subtypeLabel}
{$rules}

RELEVANT APPROVED RESPONSE EXAMPLES
{$exampleBlock}

WRITING INSTRUCTIONS
- Use verified data, current business facts, and knowledge for factual claims.
- Current business facts win if they conflict with older knowledge base articles.
- Use approved responses only for style, structure, and strategy.
- Never copy customer-specific facts from examples.
- If tracking is missing, say tracking is not on the order — do not invent a number.
- If Shopify data was not found, do not pretend an order exists.
- Carrier scan history is verified data. Quote the status and last scan only as written above.
- Never describe a scan, a delivery attempt, or a signature that is not in the carrier data.
- If the carrier data says not found, say tracking has no updates yet — do not infer the parcel is lost or stuck.
PROMPT;

        $user = 'Write the reply body for the customer message above.';

        return [
            'system' => $system,
            'user' => $user,
            'sources' => [
                'shopify' => $this->shopifySourceLabel($snapshot),
                'tracking' => $this->trackingSourceLabel($tracking),
                'facts' => $liveFacts->map(fn ($fact) => $fact->title ?: mb_substr(PlainText::from($fact->body), 0, 60))->values()->all(),
                'knowledge' => $kbDocs->map(fn ($doc) => $doc->title)->values()->all(),
                'examples' => $examples->map(fn (ApprovedResponse $example) => trim(($example->intent ?: 'example').' #'.$example->id))->values()->all(),
                'intent' => $intent->slug,
                'subtype' => $classification['subtype'] ?? $subtype?->slug,
            ],
        ];
    }

    public function formatShopify(ShopifySnapshot $snapshot): string
    {
        $payload = $snapshot->payload ?? [];
        if (! ($payload['found'] ?? false)) {
            return 'No verified Shopify data. Reason: '.($payload['reason'] ?? 'unknown');
        }

        $lines = [
            'customer_name: '.$this->val($payload['customer_name'] ?? null),
            'order_number: '.$this->val($payload['order_number'] ?? null),
            'order_date: '.$this->val($payload['order_date'] ?? null),
            'order_age_days: '.$this->val($payload['order_age_days'] ?? null),
            'payment_status: '.$this->val($payload['payment_status'] ?? null),
            'fulfilment_status: '.$this->val($payload['fulfilment_status'] ?? null),
            'tracking_number: '.$this->val($payload['tracking_number'] ?? null),
            'tracking_url: '.$this->val($payload['tracking_url'] ?? null),
            'tracking_company: '.$this->val($payload['tracking_company'] ?? null),
            'tracking_age_days: '.$this->val($payload['tracking_age_days'] ?? null),
            'shipping_country: '.$this->val($payload['shipping_country'] ?? null),
            'cancelled: '.$this->bool($payload['cancelled'] ?? null),
            'refunded: '.$this->bool($payload['refunded'] ?? null),
            'order_tags: '.$this->list($payload['order_tags'] ?? []),
            'notes: '.$this->val($payload['notes'] ?? null),
            'has_preorder_items: '.$this->bool($payload['has_preorder_items'] ?? null),
            'preorder_determined: '.$this->bool($payload['preorder_determined'] ?? null),
        ];

        $products = [];
        foreach ($payload['products'] ?? [] as $product) {
            $preorder = $product['preorder'] === true ? 'preorder' : ($product['preorder'] === false ? 'current stock' : 'stock type unknown');
            $products[] = sprintf(
                '- %s / %s x%s (sku %s) [%s]',
                $product['title'] ?? 'unknown',
                $product['variant'] ?? 'default',
                $product['quantity'] ?? '?',
                $product['sku'] ?? 'n/a',
                $preorder,
            );
        }
        $lines[] = 'products:';
        $lines[] = $products !== [] ? implode("\n", $products) : '- none listed';

        return implode("\n", $lines);
    }

    /**
     * The carrier block. Deliberately terse and label-value shaped: the model
     * should quote these values, not paraphrase a narrative.
     */
    public function formatTracking(?TrackingSnapshot $tracking): string
    {
        if ($tracking === null) {
            return 'No carrier tracking source configured. Use Shopify fulfilment data only, and do not describe carrier scans.';
        }

        if (! $tracking->found()) {
            $reason = $tracking->payload['reason'] ?? $tracking->payload['status_raw'] ?? 'The carrier has no record for this number yet.';

            return 'No verified carrier data. Reason: '.$reason
                ."\nDo not infer from this that the parcel is lost, stuck, or delayed.";
        }

        $payload = $tracking->payload ?? [];

        $lines = [
            'carrier: '.$this->val($payload['carrier_name'] ?? $tracking->carrier_name),
            'carrier_status: '.$this->val($payload['status_label'] ?? null),
            'carrier_sub_status: '.$this->val($payload['sub_status'] ?? null),
            'last_scan: '.$this->val($payload['last_event'] ?? null),
            'last_scan_at: '.$this->val($payload['last_event_at'] ?? null),
            'days_since_last_scan: '.$this->val($payload['days_since_last_scan'] ?? null),
            'estimated_delivery: '.$this->val($payload['estimated_delivery_at'] ?? null),
            'delivered_at: '.$this->val($payload['delivered_at'] ?? null),
            'last_mile_carrier: '.$this->val($payload['last_mile_carrier'] ?? null),
            'last_mile_tracking_number: '.$this->val($payload['last_mile_tracking_number'] ?? null),
        ];

        $proof = $tracking->proof();
        if ($proof !== null) {
            $lines[] = 'proof_of_delivery: '.$this->val($proof['label'] ?? null);
            $lines[] = 'proof_detail: '.$this->val($proof['detail'] ?? null);
            $lines[] = 'proof_location: '.$this->val($proof['location'] ?? null);
        }

        $events = [];
        foreach (array_slice($payload['events'] ?? [], 0, 8) as $event) {
            $events[] = sprintf(
                '- %s: %s%s%s',
                $event['occurred_at'] ?? 'unknown time',
                $event['description'] ?? 'no description',
                filled($event['location'] ?? null) ? ' ('.$event['location'].')' : '',
                ($event['last_mile'] ?? false) ? ' [last mile]' : '',
            );
        }
        $lines[] = 'recent_scans:';
        $lines[] = $events !== [] ? implode("\n", $events) : '- none recorded';

        return implode("\n", $lines);
    }

    public function trackingSourceLabel(?TrackingSnapshot $tracking): string
    {
        if ($tracking === null) {
            return 'No carrier source';
        }

        if (! $tracking->found()) {
            return 'Carrier has no record';
        }

        $parts = array_filter([
            $tracking->carrier_name,
            $tracking->trackingStatus()->label(),
            $tracking->days_since_last_scan !== null ? "last scan {$tracking->days_since_last_scan}d ago" : null,
        ]);

        return implode(' — ', $parts);
    }

    public function shopifySourceLabel(ShopifySnapshot $snapshot): string
    {
        $payload = $snapshot->payload ?? [];
        if (! ($payload['found'] ?? false)) {
            return 'No matching order';
        }

        $number = $payload['order_number'] ?? 'Unknown order';
        $status = $payload['fulfilment_status'] ?? 'unknown';

        return trim($number.' — '.$status);
    }

    private function formatCustomer(Conversation $conversation, bool $hasPhotos): string
    {
        $customer = $conversation->customer;

        return implode("\n", [
            'name: '.$this->val($customer?->name),
            'email: '.$this->val($customer?->email),
            'company: '.$this->val($customer?->company),
            'helpdesk_notes: '.$this->val($customer?->notes),
            'photos_attached: '.$this->bool($hasPhotos),
            'subject: '.$this->val($conversation->subject),
        ]);
    }

    /**
     * @param  Collection<int, object{title?: string, body: string}>  $facts
     */
    public function formatLiveFacts(Collection $facts): string
    {
        if ($facts->isEmpty()) {
            return 'No current business facts. Use knowledge base and Shopify data only.';
        }

        return $facts->map(function ($fact) {
            $title = $fact->title ?: 'Update';

            return "- {$title}: {$fact->body}";
        })->implode("\n");
    }

    /**
     * @param  Collection<int, object{title: string, content: string}>  $docs
     */
    private function formatKnowledge(Collection $docs): string
    {
        if ($docs->isEmpty()) {
            return 'No knowledge base articles were retrieved.';
        }

        return $docs->map(function ($doc) {
            $body = PlainText::from($doc->content);
            $body = mb_substr($body, 0, 900);

            return "## {$doc->title}\n{$body}";
        })->implode("\n\n---\n\n");
    }

    private function formatRules(
        CommerceIntent $intent,
        ?CommerceIntent $subtype,
        ShopifySnapshot $snapshot,
        ?int $trackingStaleDays,
        ?TrackingSnapshot $tracking = null,
    ): string {
        $blocks = [$intent->name.":\n".$intent->rules];
        if ($subtype && $subtype->id !== $intent->id) {
            $blocks[] = $subtype->name.":\n".$subtype->rules;
        }

        // Prefer days since the last carrier scan — the fulfilment age only says
        // when the label was created, which is not what the customer is asking.
        $scanAge = $tracking?->found() ? $tracking->days_since_last_scan : null;
        $age = $scanAge ?? ($snapshot->payload['tracking_age_days'] ?? null);
        $ageLabel = $scanAge !== null ? 'The carrier last scanned this parcel' : 'Tracking age is';

        if ($trackingStaleDays && is_numeric($age) && (int) $age >= $trackingStaleDays && ! ($tracking?->isDelivered() ?? false)) {
            $blocks[] = "{$ageLabel} {$age} days ago, which exceeds the configured threshold of {$trackingStaleDays} days. Escalate rather than claiming the parcel is lost.";
        }

        if ($tracking?->found()) {
            if ($tracking->needsAttention()) {
                $blocks[] = 'The carrier has flagged this shipment ('.$tracking->trackingStatus()->label().'). State the carrier status plainly, do not speculate about the cause, and say a human is reviewing it.';
            }

            if ($tracking->hasLastMileHandoff()) {
                $blocks[] = 'This parcel has been handed to a domestic carrier ('.$tracking->last_mile_carrier.'). You may give the customer the last-mile tracking number shown in the carrier data, but only exactly as written.';
            }

            $proof = $tracking->proof();
            if ($proof !== null) {
                $blocks[] = $tracking->proofIsAttributable()
                    ? 'The carrier recorded how the parcel was accepted: '.$proof['label'].'. You may state this. Do not claim a signature image or photo is available — we do not have one.'
                    : 'The carrier marked this delivered but did not record who accepted it ('.$proof['label'].'). Do not assert the customer received it. Do not claim a signature exists.';
            }
        }

        return implode("\n\n", $blocks);
    }

    /**
     * @param  Collection<int, ApprovedResponse>  $examples
     */
    private function formatExamples(Collection $examples): string
    {
        if ($examples->isEmpty()) {
            return 'No approved response examples retrieved.';
        }

        return $examples->values()->map(function (ApprovedResponse $example, int $i) {
            $n = $i + 1;
            $label = trim(($example->subtype ?: $example->intent ?: 'example').' #'.$example->id);

            return <<<EXAMPLE
Example {$n} ({$label})
Customer:
{$example->customer_message}

Agent reply (style only — do not copy facts):
{$example->final_reply}
EXAMPLE;
        })->implode("\n\n");
    }

    private function val(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'unknown';
        }

        return (string) $value;
    }

    private function bool(mixed $value): string
    {
        if ($value === null) {
            return 'unknown';
        }

        return $value ? 'yes' : 'no';
    }

    private function list(mixed $value): string
    {
        if (! is_array($value) || $value === []) {
            return 'none';
        }

        return implode(', ', $value);
    }
}

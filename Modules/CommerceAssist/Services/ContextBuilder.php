<?php

namespace Modules\CommerceAssist\Services;

use App\Domains\Conversation\Models\Conversation;
use Modules\CommerceAssist\Models\ApprovedResponse;
use Modules\CommerceAssist\Models\CommerceIntent;
use Modules\CommerceAssist\Models\ShopifySnapshot;
use Modules\CommerceAssist\Support\PlainText;
use Illuminate\Support\Collection;

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
    ): array {
        $liveFacts ??= collect();
        $shopify = $this->formatShopify($snapshot);
        $customer = $this->formatCustomer($conversation, $hasPhotos);
        $kb = $this->formatKnowledge($kbDocs);
        $facts = $this->formatLiveFacts($liveFacts);
        $rules = $this->formatRules($intent, $subtype, $snapshot, $trackingStaleDays);
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
PROMPT;

        $user = 'Write the reply body for the customer message above.';

        return [
            'system' => $system,
            'user' => $user,
            'sources' => [
                'shopify' => $this->shopifySourceLabel($snapshot),
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

    private function formatRules(CommerceIntent $intent, ?CommerceIntent $subtype, ShopifySnapshot $snapshot, ?int $trackingStaleDays): string
    {
        $blocks = [$intent->name.":\n".$intent->rules];
        if ($subtype && $subtype->id !== $intent->id) {
            $blocks[] = $subtype->name.":\n".$subtype->rules;
        }

        $age = $snapshot->payload['tracking_age_days'] ?? null;
        if ($trackingStaleDays && is_numeric($age) && (int) $age >= $trackingStaleDays) {
            $blocks[] = "Tracking age is {$age} days, which exceeds the configured threshold of {$trackingStaleDays} days. Escalate rather than claiming the parcel is lost.";
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

<?php

/**
 * Default intent catalog for Commerce Assist.
 *
 * Knowledge base = what is true.
 * Approved responses = how the agent responds.
 * These rules = what this intent is allowed to do.
 */
return [
    [
        'slug' => 'order_status',
        'parent_slug' => null,
        'name' => 'Order status',
        'always_human' => false,
        'auto_send_allowed' => true,
        'data_requirements' => ['shopify_order'],
        'sort_order' => 10,
        'rules' => <<<'RULES'
- Look up the order in verified Shopify data before saying anything about status.
- State only payment status, fulfilment status, and dates that appear in verified Shopify data.
- If no matching order is found, say so and ask for the order number. Do not invent an order.
- Do not promise a dispatch or delivery date unless that date is in verified data or knowledge base facts.
RULES,
    ],
    [
        'slug' => 'order_status.unfulfilled',
        'parent_slug' => 'order_status',
        'name' => 'Order status — unfulfilled',
        'always_human' => false,
        'auto_send_allowed' => true,
        'data_requirements' => ['shopify_order'],
        'sort_order' => 11,
        'rules' => <<<'RULES'
- Confirm the order is unfulfilled using verified Shopify data only.
- Explain current processing using knowledge base facts (e.g. dispatch windows), never guessed dates.
- If the order has preorder line items, say so only when the snapshot marks them as preorder.
- Do not say the order has shipped.
RULES,
    ],
    [
        'slug' => 'order_status.fulfilled',
        'parent_slug' => 'order_status',
        'name' => 'Order status — fulfilled',
        'always_human' => false,
        'auto_send_allowed' => true,
        'data_requirements' => ['shopify_order', 'tracking'],
        'sort_order' => 12,
        'rules' => <<<'RULES'
- Confirm fulfilment using verified Shopify data.
- Include tracking number and tracking URL only if present in the snapshot.
- Do not claim a delivery date unless it is in verified data.
RULES,
    ],
    [
        'slug' => 'tracking_problem',
        'parent_slug' => null,
        'name' => 'Tracking problem',
        'always_human' => false,
        'auto_send_allowed' => true,
        'data_requirements' => ['shopify_order', 'tracking'],
        'sort_order' => 20,
        'rules' => <<<'RULES'
- Check tracking data first. Quote only the tracking number, URL, and fulfilment status from verified Shopify data.
- Do not say the parcel is lost unless verified data or knowledge base explicitly supports that conclusion.
- Do not promise a replacement or refund.
- Explain expected tracking behaviour only using knowledge base facts.
- If tracking age exceeds the configured stale threshold, say a human will review and escalate. Do not invent a carrier investigation.
RULES,
    ],
    [
        'slug' => 'tracking.no_updates',
        'parent_slug' => 'tracking_problem',
        'name' => 'Tracking — no updates',
        'always_human' => false,
        'auto_send_allowed' => true,
        'data_requirements' => ['shopify_order', 'tracking'],
        'sort_order' => 21,
        'rules' => <<<'RULES'
- Check tracking data first.
- Do not say the parcel is lost unless verified.
- Do not promise replacement.
- Explain expected tracking behaviour only using KB facts.
- Escalate if tracking age exceeds the configured threshold.
RULES,
    ],
    [
        'slug' => 'tracking.delivered_not_received',
        'parent_slug' => 'tracking_problem',
        'name' => 'Tracking — delivered not received',
        'always_human' => true,
        'auto_send_allowed' => false,
        'data_requirements' => ['shopify_order', 'tracking'],
        'sort_order' => 22,
        'rules' => <<<'RULES'
- Acknowledge the carrier marked the shipment delivered only if that status is in verified data.
- Do not promise a replacement or refund.
- Ask for the delivery address confirmation and whether neighbours or building reception were checked.
- Escalate to a human. This is always a human-required case.
RULES,
    ],
    [
        'slug' => 'preorder_status',
        'parent_slug' => null,
        'name' => 'Preorder status',
        'always_human' => false,
        'auto_send_allowed' => false,
        'data_requirements' => ['shopify_order'],
        'sort_order' => 30,
        'rules' => <<<'RULES'
- Use the snapshot's preorder flags. If we cannot determine preorder vs current stock, say we cannot determine it.
- Quote dispatch guidance only from knowledge base facts.
- Do not invent a restock or ship date.
RULES,
    ],
    [
        'slug' => 'address_change',
        'parent_slug' => null,
        'name' => 'Address change',
        'always_human' => true,
        'auto_send_allowed' => false,
        'data_requirements' => ['shopify_order'],
        'sort_order' => 40,
        'rules' => <<<'RULES'
- Check fulfilment status. If already fulfilled, do not imply the address can still be changed.
- Never say the address has been updated — this system cannot edit Shopify orders.
- Escalate to a human. Collect the requested new address if missing.
RULES,
    ],
    [
        'slug' => 'refund_request',
        'parent_slug' => null,
        'name' => 'Refund request',
        'always_human' => true,
        'auto_send_allowed' => false,
        'data_requirements' => ['shopify_order'],
        'sort_order' => 50,
        'rules' => <<<'RULES'
- Check order age, payment status, cancelled/refunded state from verified Shopify data.
- Never promise a refund or partial refund automatically.
- Explain the refund policy only from knowledge base facts.
- Escalate to a human.
RULES,
    ],
    [
        'slug' => 'refund.change_of_mind',
        'parent_slug' => 'refund_request',
        'name' => 'Refund — change of mind',
        'always_human' => true,
        'auto_send_allowed' => false,
        'data_requirements' => ['shopify_order'],
        'sort_order' => 51,
        'rules' => <<<'RULES'
- Never promise a refund.
- Use knowledge base return/refund policy only.
- Escalate to a human.
RULES,
    ],
    [
        'slug' => 'damaged_product',
        'parent_slug' => null,
        'name' => 'Damaged product',
        'always_human' => true,
        'auto_send_allowed' => false,
        'data_requirements' => ['shopify_order', 'photos'],
        'sort_order' => 60,
        'rules' => <<<'RULES'
- Check order age from verified Shopify data.
- Check whether photos are attached to the conversation.
- Never promise refund or replacement automatically.
- Ask for required evidence if photos are missing.
- Escalate unusual damage to a human.
RULES,
    ],
    [
        'slug' => 'damage.screen',
        'parent_slug' => 'damaged_product',
        'name' => 'Damage — screen',
        'always_human' => true,
        'auto_send_allowed' => false,
        'data_requirements' => ['shopify_order', 'photos'],
        'sort_order' => 61,
        'rules' => <<<'RULES'
- Check order age. Check whether photos are attached.
- Never promise refund/replacement automatically.
- Ask for required evidence if missing.
- Escalate screen damage to a human.
RULES,
    ],
    [
        'slug' => 'damage.shipping',
        'parent_slug' => 'damaged_product',
        'name' => 'Damage — shipping',
        'always_human' => true,
        'auto_send_allowed' => false,
        'data_requirements' => ['shopify_order', 'photos'],
        'sort_order' => 62,
        'rules' => <<<'RULES'
- Check order age and whether photos of packaging and product are attached.
- Never promise refund/replacement automatically.
- Ask for required evidence if missing.
- Escalate unusual shipping damage.
RULES,
    ],
    [
        'slug' => 'technical_support',
        'parent_slug' => null,
        'name' => 'Technical support',
        'always_human' => false,
        'auto_send_allowed' => false,
        'data_requirements' => [],
        'sort_order' => 70,
        'rules' => <<<'RULES'
- Answer only with knowledge base facts and verified product data.
- If the issue is not covered, say so and escalate rather than guessing troubleshooting steps.
- Do not invent firmware versions, compatibility, or repair procedures.
RULES,
    ],
    [
        'slug' => 'duties_tariffs',
        'parent_slug' => null,
        'name' => 'Duties and tariffs',
        'always_human' => false,
        'auto_send_allowed' => true,
        'data_requirements' => ['shopify_order'],
        'sort_order' => 80,
        'rules' => <<<'RULES'
- Use shipping country from verified Shopify data when explaining who pays duties.
- Quote only knowledge base policy for duties/tariffs. Never invent tax amounts.
- If the destination country is unknown, ask rather than assuming.
RULES,
    ],
    [
        'slug' => 'product_question',
        'parent_slug' => null,
        'name' => 'Product question',
        'always_human' => false,
        'auto_send_allowed' => true,
        'data_requirements' => [],
        'sort_order' => 90,
        'rules' => <<<'RULES'
- Answer specifications and gameplay questions only from knowledge base facts.
- If the fact is not in the knowledge base, say we do not have that information.
- Do not invent dimensions, materials, compatibility, or stock.
RULES,
    ],
    [
        'slug' => 'wholesale',
        'parent_slug' => null,
        'name' => 'Wholesale',
        'always_human' => true,
        'auto_send_allowed' => false,
        'data_requirements' => [],
        'sort_order' => 100,
        'rules' => <<<'RULES'
- Do not negotiate pricing or terms.
- Acknowledge the enquiry and escalate to a human.
- Do not invent wholesale MOQs, discounts, or lead times unless they appear in the knowledge base.
RULES,
    ],
    [
        'slug' => 'influencer',
        'parent_slug' => null,
        'name' => 'Influencer',
        'always_human' => true,
        'auto_send_allowed' => false,
        'data_requirements' => [],
        'sort_order' => 110,
        'rules' => <<<'RULES'
- Do not promise free product, affiliate terms, or collaborations.
- Acknowledge and escalate to a human.
RULES,
    ],
    [
        'slug' => 'spam',
        'parent_slug' => null,
        'name' => 'Spam',
        'always_human' => true,
        'auto_send_allowed' => false,
        'data_requirements' => [],
        'sort_order' => 120,
        'rules' => <<<'RULES'
- Do not write a customer-facing reply. Flag as spam for a human.
- If a draft is required, keep it empty and mark requires_human.
RULES,
    ],
    [
        'slug' => 'other',
        'parent_slug' => null,
        'name' => 'Other',
        'always_human' => true,
        'auto_send_allowed' => false,
        'data_requirements' => [],
        'sort_order' => 130,
        'rules' => <<<'RULES'
- If the request is unclear, ask one clarifying question.
- Do not invent order or policy facts.
- Prefer escalating over guessing.
RULES,
    ],
];

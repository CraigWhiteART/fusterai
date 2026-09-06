# Commerce Assist

Isolated Fuster module for Shopify-grounded support replies.

Fuster remains the helpdesk. This module adds:

1. Shopify customer/order lookup (verified facts only)
2. Intent + subtype classification
3. Per-intent rule sets
4. Approved-response library with semantic retrieval (separate from the knowledge base)
5. Structured prompt sections
6. AI draft → final sent reply tracking, with optional “save as example”
7. A second-pass unsupported-fact validator
8. Confidence / human-required flags (draft-only by default)
9. Source visibility on the conversation
10. Historical ticket replay

It does **not** edit Shopify orders, issue refunds, auto-send, or fine-tune a model.

Enable it in **Settings → Modules**. Configure Shopify under **Settings → Commerce Assist**.

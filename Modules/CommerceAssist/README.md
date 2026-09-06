# Commerce Assist

Isolated Fuster module for Shopify-grounded support replies.

Fuster remains the helpdesk. This module adds:

1. Shopify customer/order lookup (verified facts only)
2. Carrier tracking lookup — live scan state, last-mile handoff, delivery proof
3. Intent + subtype classification
4. Per-intent rule sets
5. Approved-response library with semantic retrieval (separate from the knowledge base)
6. Structured prompt sections
7. AI draft → final sent reply tracking, with optional “save as example”
8. A second-pass unsupported-fact validator
9. Confidence / human-required flags (draft-only by default)
10. Source visibility on the conversation
11. Historical ticket replay

Live facts: while reviewing a draft, type what is true now (stock, dates, timelines). Related unsent drafts are rewritten. Current facts override older knowledge base articles. Nothing is sent to customers.

It does **not** edit Shopify orders, issue refunds, auto-send, or fine-tune a model.

Enable it in **Settings → Modules**. Configure Shopify and carrier tracking under **Settings → Commerce Assist**.

## Carrier tracking

Shopify gives you a tracking number, not the scans. This module looks the number
up with a carrier-tracking provider before a draft is written, so replies quote
real carrier state instead of guessing around a bare number.

The lookup is preemptive but cheap: it only runs when Shopify returned something
to look up, and a stored reading is reused for `tracking_ttl_minutes` (default
120) so regenerating a draft does not re-bill the provider. Agents can force a
fresh read from the conversation panel.

### Providers

Selected per workspace in settings. `TrackingProvider` is a two-method
interface, so adding another source (or a first-party carrier client) means one
new adapter and one entry in `TrackingProviderFactory::PROVIDERS`.

| Provider | Lookup model | Notes |
| --- | --- | --- |
| `track123` | Order-keyed | Preferred for a Shopify store running the Track123 app. Reads data the app already collects, so there is no register-and-wait step and no per-number quota. Verified against the published Shopify App API. |
| `17track` | Register-then-poll | A number is unknown until registered; the first read after registering usually returns nothing. Endpoint shapes follow the published v2.2 API — **confirm against a live response before enabling.** |
| `aftership` | Register-then-poll | Returns `signed_by`, the strongest proof signal of the three. Endpoint shapes follow the published v4 API — **confirm against a live response before enabling.** |

Track123 keys its endpoints by the myshopify subdomain, which is derived from
the configured Shopify shop domain unless overridden in settings.

### What the carrier data changes

- **Stale means stale.** `tracking_stale_days` now measures days since the last
  carrier scan, not the age of the fulfilment record. A label created three weeks
  ago on a parcel that scanned yesterday is no longer treated as a problem.
- **Last-mile handoff is resolved.** When 4PX hands off to a domestic carrier,
  that carrier and its tracking number are verified data the reply may quote.
- **Exceptions and stalls escalate.** A carrier exception, a failed delivery
  attempt, or a stalled parcel sets `requires_human` — these end in a claim, not
  a reassuring paragraph.
- **Partial proof of delivery without a browser.** Carrier sub-statuses such as
  “Sign by customer” or “Delivered to the front door” are normalised into a
  `DeliveryProof` with a type and whether it is *attributable* — whether a person
  was recorded accepting the parcel. Delivered-but-not-attributable always
  requires a human.

The prompt is explicitly told never to describe a scan, delivery attempt, or
signature that is not in the carrier block, and never to claim a signature image
or delivery photo exists — the module does not hold one.

### Proof of delivery beyond this

Signature images, photos and GPS proof live with the last-mile carrier, usually
behind a postcode challenge. Before reaching for browser automation:

1. Use the last-mile carrier's own API with your account credentials (AusPost,
   Royal Mail and others expose delivery and signature data to account holders).
   `TrackingSnapshot::lastMileHandle()` gives you the carrier and number to ask
   with.
2. Raise the investigation with 4PX — as your contracted carrier that is
   normally their job.

Anything scraped should stay agent-triggered, out of the request path, and
marked unverified. It must never be injected into the verified-facts block.

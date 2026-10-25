# 10 — Webhooks, History and Integrity (complete — built and tested; provider re-registration never run against a real provider)

## Status

**Complete 2026-10-17**, committed in `www` as `bd73a39b5` (files both steps touched are in `bbfb108a6`, step 09's commit). The full seven-folder suite was **not** run to the end; the affected files were (see Tests). **Not verified:** the Stripe/Square/PayPal registrars moving an old endpoint to the per-processor URL — only the URL building is tested, no provider was called.

## Phases

| Phase | What shipped |
|---|---|
| P0 — per-processor URL | `/portal/webhooks?type={provider}&p={processor_ref}`. The named processor is verified **alone** (archived or paused still counts); an unknown or absent `p` is the older URL and scans every processor of the provider. Registrars register that URL and move a legacy endpoint in place (Stripe POST url, Square PUT notification_url, PayPal PATCH /url); the admin status carries `url_is_legacy` and `ProcessorDetailView` shows a hint. Candidate rule and orphan-before-rate-limiter were already done in step 16 |
| P1 — leg lifecycle | `SubscriptionLegLifecycle::providerEnded`: leg → `ended`; a migration hand-over or a leg that never took over changes nothing else; otherwise, with no other live leg, the contract gets `canceled_at`/`access_ends_at` (paid-through or now, per the cancellation-access policy), an audit entry and the new `SubscriptionEnded` event. `SubscriptionEndSync` delegates to it; `settleEnded()` moves cancelled contracts past their access to `ended` and runs from `direct_payments:subscription-migrate --run` (every 15 minutes) |
| P2 — integrity | Invoice `customer_id` is now the provider customer id on every recorder (Stripe invoice charge, Square payment, PayPal order and sale); every invoice gets `payer_reference_num` from the payer-customer mapping on save (never fails the save); `ProcessorSubscription` lookups are scoped by processor (`scopeOnProcessor`); refund ownership compares the provider id (legacy local id still accepted); the `laravel_processor_charges` table is dropped from the create migration |
| P3 — history | `PayerHistory::purchases`, `SubscriptionHistory::periods`; routes `GET /{dpRef}/me/purchases` and `GET /{dpRef}/subscription/periods`; `ProcessorRouter::forInvoice` so a refund goes to the account that took the charge even when archived; the Money ledger rows carry `processor_reference_num`, `processor_name`, `multiple_processors` and the stored `dispute` |

## Tests

`WebhooksHistoryIntegrityTest` (15, passing): URL building; named processor verified alone, legacy scan, unknown name, archived processor; 30 bad signatures on a named URL → 30 orphans; sole leg ended → contract ended + event; another live leg → contract kept; `settleEnded`; charge table absent; invoice payer from customer / none for an unknown one; processor-scoped lookups; purchases across processors; periods per leg; both endpoints over HTTP as the caller; refund routing to an archived processor.

## Deviations and residue

* **Not tested:** the Money ledger's new fields, the registrars' in-place move, and the migration hand-over branch of the lifecycle (covered earlier by the step 07 tests only for the migrator).
* `MoneyView.ts` / `ProcessorDetailView.ts` changes were not checked in a browser.
* Existing endpoints registered at providers keep the old URL until an owner re-registers them; nothing does it automatically.

# 00 — Overview and Verdict

## 0.1 The verdict in one paragraph

The foundation is right and most of it is built. A Direct Payment has a stable `reference_num`; its processor is a
binding with a history; a person is a Direct-Payments-issued `payer_reference_num` that maps to a provider customer id
per processor, keeping every old one; tiers have per-processor prices; money objects record the processor they were
taken on and refunds resolve from the charge. **What is missing is everything that makes a swap safe on a live website
and everything that keeps a subscription renewing after one.** Today a swap is a correct database transition with no
enforcement around it: the browser still chooses the processor, archived or inactive processors can still take charges
through a query bug, a processor can be hard-deleted with its connected accounts cascading away, a Connect Direct Payment
can be swapped to another Stripe platform past the guard that the ordinary save path has, and a live subscription can
only "stay where it is until it ends" — there is no way to move it. The plan below closes those gaps without throwing
away what shipped.

## 0.2 What works today (verified, keep it)

| Area | What is right | Where |
|---|---|---|
| Stable handle | A Direct Payment keeps its `reference_num` through a swap; modules store only that | `BindingRegistry::swap()` |
| Processor history | `laravel_direct_payment_processor_bindings`, exactly one `active`, any number `retiring`/`retired` | migration `2026_04_03_160000…`, `BindingRegistry` |
| Payer identity | `laravel_direct_payment_payers` (`pay_` + 12) and `laravel_direct_payment_payer_customers` — one row per (payer, processor), old rows superseded, never deleted | `PayerService` |
| Tier identity and price | Tier `reference_num` survives a save (D9 fixed); price per (tier, processor) in `laravel_direct_payment_tier_bindings`; FORWARD/BACKWARD resolvers | `TierBindingRegistry`, `DirectPaymentsController::saveDirectPayment()` |
| Backward resolution | Refunds resolve the processor from the charge; subscription cancel/update/set-payment-method resolve it from the subscription | `ProcessorBillingController::refund()`, `RefundService`, `DirectPaymentSubscriptionController` |
| Swap preview | Impact counts, `impact_hash`, blockers, retire-with-blockers | `SwapService`, `DirectPaymentSwapController` |
| Webhooks after a swap | A processor still bound (non-retired) stays a webhook candidate even when inactive; unmatched signatures are recorded as orphans | `ProcessorWebhookController::webhookCandidateProcessorIds()` |
| Developer surface | The playground integrates with nothing provider-specific: a DP reference, `initializeDirectPayment`, `setupSinglePayPayment` / tier `level`, `charge`/`confirmPayment` | `public/playground/externals/direct-payments/*` |
| Plan provisioning | A plan can be created on all three providers from the same canonical fields (name, amount, interval, currency, trial) | `ProcessorPlanAdminController::store()` |

## 0.3 What is missing or wrong — the ten that matter most

Full list with evidence in [01](01-audit-findings.md).

| # | Finding | Why it matters for "switch without interruption" | Fixed in |
|---|---|---|---|
| 1 | **A processor can be hard-deleted**, and the delete cascades to its connected accounts, webhook endpoints and transfer intents (A1, A2) | Destroys the record every past charge, refund, dispute and subscription resolves through | [03](03-processor-lock-in-and-archive.md) |
| 2 | **A processor's account can be silently replaced** by editing its keys or mode in place (A3) | A "key rotation" can point the same processor row at a different Stripe account — every binding, customer id and subscription is then wrong with no swap ever happening | [03](03-processor-lock-in-and-archive.md) §3.4 |
| 3 | **`where(ref)->orWhere(id)->where(provider)->where(is_active)` lets any processor matched by reference skip the active and provider checks** on every intent, session, order, capture, subscription and public-config endpoint (A4) | Archiving or deactivating a processor does not stop new charges on it | [03](03-processor-lock-in-and-archive.md) §3.6 |
| 4 | **The browser chooses the processor.** Every charge endpoint takes `processor_reference_num` from the request; the SDK caches it in `sessionStorage` and honours `processor_override` (A20–A23) | After a swap, open browser sessions, cached pages and any hardcoded reference keep charging the old account; `retiring` ("no new charges") is not enforced anywhere | [06](06-swap-engine-v2.md) §6.3 |
| 5 | **The swap path skips the Connect guards the save path has** — no "Stripe only" check, no "connected accounts present" check (A12) | A Connect Direct Payment can be swapped to another Stripe platform, orphaning every seller account | [05](05-provider-parity-and-portability.md) §5.5, [06](06-swap-engine-v2.md) §6.4 |
| 6 | **A live subscription cannot move.** There is no subscription "leg" concept, no hosted re-authorisation, no deferred-start creation on the target, and the subscription link's own `reference_num` is regenerated on every write (A30–A35) | The owner's central requirement. Today a swap leaves subscriptions on the old account until they end, and nothing can migrate them | [07](07-subscription-continuity.md) |
| 7 | **A subscription ended at the provider never ends locally** — `canceled_at` is only written by the app's own cancel (A36) | "Live subscriptions" never reaches zero, so a retiring binding can never be retired | [10](10-webhooks-history-and-integrity.md) §10.2 |
| 8 | **Provider parity gaps**: PayPal has no real customer (`"paypal:{email}"`), Square subscriptions ignore discounts and trials, PayPal ignores discounts, the SDK shows Square/PayPal tier prices as 0, currency is hardcoded `usd` in the SDK (A40–A49) | A swap to PayPal or Square silently loses behaviour the tenant was relying on | [05](05-provider-parity-and-portability.md) |
| 9 | **Direct Payments code depends on appfactory studio** — it reads appfactory tables for subscription self-service, notification e-mail and the Money ledger, and uses appfactory vocabulary ("billing", "member") in classes, routes, config, logs and markers (A50–A54, [13](13-naming-and-separation.md)) | A tenant website's customers cannot manage their own subscriptions through the DP API at all; two separate systems are mixed | [09](09-extension-points.md), [13](13-naming-and-separation.md) |
| 10 | **The unauthenticated subscription-link endpoint accepts any `tier_reference_num`** for any `provider_subscription_id` and overwrites an existing link (A33) | Anyone who knows a subscription id can rewrite which tier it grants — the tier any website or host application reads to decide what a subscriber gets | [07](07-subscription-continuity.md) §7.3 |

## 0.4 The target in one picture

```
 WEBSITE / MODULE                 DIRECT PAYMENTS (the stable side)                          PROVIDERS (disposable)
 ────────────────                 ────────────────────────────────                          ──────────────────────
 holds only:                      laravel_direct_payments  dp_ref ── binding_version
   dp_ref                            │
   payer token / site user          ├─ processor_bindings ──┬─ active    ──▶ processor B ──▶ PayPal (acct fp)
   subscription ref (sub_…)         │                       └─ retiring  ──▶ processor A ──▶ Stripe (acct fp)  [archived later, never deleted]
                                     │
                                     ├─ catalog: items (price in DP) · tiers (canonical price) ─▶ tier_bindings per processor (auto-provisioned)
                                     │
   payer_reference_num ─────────────┼─ payers ─▶ payer_customers: (pay_x, B) → PayPal vault customer   active
                                     │                            (pay_x, A) → cus_… on Stripe A          superseded/kept
                                     │
   subscription ref ────────────────└─ subscription contract (sub_…, tier, payer, access) ─▶ legs:
                                                                     leg 1  Stripe A  sub_…   ending at T (paid through T)
                                                                     leg 2  PayPal B  I-…     starts at T (no charge before T)
```

Everything left of the vertical line never changes when a provider changes. Everything right of it is created, used,
and retired, and every old piece is kept and stays reachable for refunds, disputes and history.

## 0.5 What "no interruption" can and cannot mean — the honest table

The first plan ([`../direct_payments/00`](../direct_payments/00-overview.md) §2) said stored cards and live subscriptions
"cannot move". That is true of the **card token and the provider mandate**. It is not true of the **subscription as a
business relationship**, which this plan moves by creating a new provider subscription that starts exactly when the old
one's paid period ends. The table below replaces that one.

| Claim | Same account (key rotation) | Stripe → Stripe (other account) | Cross provider (e.g. Stripe → PayPal) |
|---|---|---|---|
| Website code and stored references unchanged | Yes | Yes | Yes |
| New checkouts work immediately after the swap | Yes | Yes | Yes |
| Past purchases readable, refundable, disputable | Yes | Yes | Yes |
| Returning customer recognised (same payer, history continuous) | Yes | Yes | Yes |
| Saved card usable without re-entry | Yes | **Only if** Stripe migrates the vault (a request the owner makes to Stripe); then fully automatic ([07](07-subscription-continuity.md) §7.5) | **No** — card tokens cannot leave a provider. The customer enters a card or approves PayPal once |
| Subscription keeps renewing with no gap and no double charge | Yes | Yes — automatic when the vault was migrated; otherwise one re-authorisation by the customer | Yes, **after one re-authorisation by the customer**; until they do, the old subscription keeps renewing on the old account (no revenue lost) |
| Connect (marketplace) Direct Payment | Yes | Yes while no seller Connect accounts are live; with live sellers, only after a Connect platform migration (its own future plan — [05](05-provider-parity-and-portability.md) §5.5) | **Never** — Connect exists only on Stripe |

## 0.6 Design decisions (confirmed by the owner — [12](12-open-questions.md))

| # | Decision |
|---|---|
| **Q1** | A processor is archived, never deleted — enforced in the controller, the model, and the database (RESTRICT foreign keys) |
| **Q2** | A processor's provider, mode and **account fingerprint** are locked once it is created. Key rotation is allowed only when the new keys verify against the same account |
| **Q3** | The server, never the browser, decides which processor a new charge goes to: the Direct Payment's active binding for a new payment, the object's own processor for an existing one |
| **Q4** | A swap is refused unless it is compatible: same mode (sandbox → live is a separate "Go live" action that keeps the DP reference), target not archived, webhook registered on target, currency equal, every capability the DP uses supported by the target. A Connect DP — or any DP on a processor with Connect enabled — moves only to another Stripe processor, and while seller Connect accounts are live only after a Connect platform migration |
| **Q5** | Tiers carry a canonical price; a swap provisions matching plans on the target automatically (dry-run first) instead of asking the tenant to re-create them |
| **Q6** | A Direct Payments subscription is a contract with a stable `sub_` reference and one or more provider **legs**; moving a subscription = new leg that starts when the old leg's paid period ends, then the old leg is set to end |
| **Q7** | Provider customer ids, provider subscription ids and card tokens never leave the server toward the browser except where a provider's own JS SDK requires a short-lived secret |
| **Q8** | Direct Payments knows nothing about appfactory studio: no billing / member vocabulary, no appfactory tables or code. Host applications plug in through generic extension points; appfactory's own changes are appfactory studio modifications ([13](13-naming-and-separation.md), [14](14-appfactory-studio-modifications.md)) |

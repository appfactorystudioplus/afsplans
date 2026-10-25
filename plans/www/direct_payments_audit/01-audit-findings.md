# 01 — Audit Findings

Read from `w/www` on 2026-10-16. Paths relative to `w/www/`. Severity is about the promise this folder exists for —
switching provider with no interruption — and about money and entitlement correctness, not code style.

| Severity | Meaning |
|---|---|
| **Critical** | Can lose the audit trail, charge the wrong account, or grant entitlement nobody paid for |
| **High** | Breaks a swap, or breaks payments/subscriptions after one, in a normal operation |
| **Medium** | Wrong in an edge case, or a parity gap a tenant would notice after switching provider |
| **Low** | Hygiene that makes the above harder to fix or verify |

## 1.1 The first plan, checked against the code

[`../direct_payments/`](../direct_payments/README.md) reports DP0–DP6 shipped. Checked claim by claim:

| Claim (first plan) | Verdict | Evidence / note |
|---|---|---|
| DP0 — a live DP refuses a processor change through `saveDirectPayment()` | ✅ with a defect | `guardProcessorChange()` works; but the same save still writes the column from the request even when it is empty (**A11**) |
| DP1 — subscriptions and invoices carry `processor_reference_num` / `payer_reference_num` | ✅ | migration `2026_04_03_160000…` lines 83, 354–355 |
| DP2 — payers and payer_customers | ✅ with defects | `PayerService`; the record-existing path trusts the browser (**A24**), PayPal has no real customer (**A26**) |
| DP3 — bindings, one active per DP | ✅ | `BindingRegistry::active()`, `active_marker` unique |
| DP4 — refunds resolve from the charge | ✅ with a defect | processor resolution is right; the ownership check beside it compares mixed id types (**A57**) |
| DP4 — subscription actions resolve from the subscription | ✅ | `DirectPaymentSubscriptionController::cancel()/update()/setPaymentMethod()` use `$subscription->processor_reference_num` |
| DP4 — webhooks accept retiring bindings, record orphans | ✅ partly | a processor whose binding is **retired** and which is inactive is no longer a candidate (**A55**); orphans are rate-limited (**A56**) |
| DP5 — swap preview / execute / retire | ✅ with defects | Connect bypass (**A12**), not transactional (**A13**), sandbox↔live allowed (**A14**), no webhook check (**A15**) |
| DP5 — "a retiring binding takes no new charges" (`../direct_payments/03` §3.6) | ❌ | nothing enforces it (**A21**) |
| `../direct_payments/03` §3.5 — "cards on a retiring binding are not listed as usable" | ❌ | the site-user card list merges every payer_customer row, superseded included (**A39**) |
| DP6 — SDK payer resolver | ⚠️ | `DirectPaymentsPayerResolver` exists in `api.ts` (:419) but its payer only reaches the server as `user_id = "su:pay_…"`, never as `payer_reference_num` (**A29**) |
| §6.5 grep gates | ⚠️ | never wired into CI (completion record 04 says so) |

## 1.2 Processor lifecycle — A1–A9

| # | Sev | Finding | Where | Consequence |
|---|---|---|---|---|
| **A1** | Critical | **A processor can be hard-deleted.** `destroy()` calls `$processor->delete()`; the admin UI exposes it | `Http/Controllers/Externals/Processor/ProcessorAdminController.php:195–201`; `resources/js/portal/admin/Processors/ProcessorsService.ts:380` | Every invoice, refund, subscription, payer_customer and binding that names the processor resolves to nothing. `refund()` then answers `DP_E_ORIGIN_PROCESSOR_MISSING`; subscription cancel answers `DP_E_NO_SUBSCRIPTION` while the provider **keeps charging** |
| **A2** | Critical | **Deleting a processor cascades.** `laravel_processor_connected_accounts`, `laravel_processor_transfer_intents` and `laravel_processor_webhook_endpoints` have `onDelete('cascade')` onto `laravel_processors` | `database/migrations/2026_05_21_000001_create_processor_tables.php:210, 244, 376` | One click erases every Connect seller account record, every pending marketplace transfer, and the webhook registration — data that cannot be re-derived |
| **A3** | Critical | **A processor's account is mutable in place.** `update()` accepts `mode`, `publishable_key`, `secret_key`, `webhook_secret`, `extra_config` with no check that the new keys belong to the same provider account | `ProcessorAdminController.php:127–162` (fillable at :143–145) | A tenant "rotating keys" can paste another Stripe account's keys. No swap happens, no binding changes, but every `cus_…`, `sub_…`, price id and refund now points at an account where it does not exist. This is D1 again, one layer down, with no guard at all |
| **A4** | Critical | **Operator-precedence bug on processor lookup.** `where('reference_num',$x)->orWhere('id',$x)->where('provider',…)->where('is_active',true)` compiles to `ref = x OR (id = x AND provider AND active)` — a match by reference skips the provider and active checks | `ProcessorIntentController.php:56, 155, 343, 409, 488, 604`; `ProcessorSubscriptionController.php:39`; `ProcessorClientController.php:52`. (`ProcessorSquarePaymentController.php:44` is the one written correctly, with a closure) | Deactivating or archiving a processor does not stop intents, sessions, PayPal orders/captures, subscriptions or public config on it. Any "no new charges" rule built on `is_active` is void |
| **A5** | High | **No archive state.** `is_active` means both "may take new charges" and "is reachable for webhooks"; the webhook scan reads it | `Models/Externals/Processor/Processor.php`; `ProcessorWebhookController.php:370–382` | There is no way to say "closed to new business, still answers refunds, disputes and subscription events forever" |
| **A6** | Medium | `store()` accepts a client-chosen 7-character `reference_num` | `ProcessorAdminController.php:75, 81–83` | A reference that is meant to be issued by us can be chosen to look like another processor's in logs and support conversations |
| **A7** | Medium | `extra_config` is free JSON and decides behaviour: `connect.enabled`, `currency`, `location_id`, `paypal_client_id`, saved-card defaults | `ProcessorAdminController.php:78, 140`; `SwapService::blockersFor()`; `ConnectProfileService::for()` | A PayPal processor can be given `connect.enabled = true` and pass the swap's Connect check (**A12**); currency used by the swap's currency blocker is optional and unvalidated |
| **A8** | Medium | **No account fingerprint is stored.** Swap kind is inferred from equal publishable keys | `SwapService::kindOf()` (:112–127) | Square's application id and PayPal's client id identify an *app*, not an *account*; two Stripe accounts never share a key, but the same Stripe account can be re-added with a new restricted key. The system cannot tell "same account" from "different account" reliably |
| **A9** | Low | Processor lookups accept either `reference_num` or the numeric `id` everywhere | every `orWhere('id', …)` in A4 | Two identities for one row; the numeric one leaks table size and invites the precedence bug |

## 1.3 Direct Payment configuration and the swap — A10–A19

| # | Sev | Finding | Where | Consequence |
|---|---|---|---|---|
| **A10** | High | **A Direct Payment can be hard-deleted** with its items, tiers, Connect config and notifications | `DirectPaymentsController::destroy()` :308–330 | Invoices, subscriptions, bindings and module rows keep `direct_payment_reference_num` pointing at nothing. `blockIfPublished()` only checks builder pages, not money |
| **A11** | Medium | `saveDirectPayment()` writes `processor_reference_num = $procRef` even when the request sent none | `DirectPaymentsController.php:382` | The binding stays correct, but the column — which the SDK reads through `GET /directpayments` (`api.ts:1032`) — becomes `''` and every checkout on that DP fails until the next save |
| **A12** | Critical | **The swap skips the Connect guards.** `SwapService::blockersFor()` checks only `connectProfiles->for($target)->enabled`. `guardConnectPayType()` additionally refuses a non-Stripe target and a target on another platform while connected accounts exist | `SwapService.php:204–209` vs `DirectPaymentsController.php:895–953` | A CONNECT Direct Payment with live sellers can be swapped to another Stripe platform (sellers' `acct_` ids do not exist there), and with A7 even to a PayPal processor |
| **A13** | Medium | **The swap is not atomic and retires first.** `swap()` marks the current binding `retiring`, then looks up the target and throws if missing; nothing wraps it in a transaction or locks the DP | `BindingRegistry.php:100–128` | A failure between the two writes leaves the DP with **no active binding** — every checkout fails. Two concurrent swaps race on `active_marker` |
| **A14** | High | **A swap may cross modes** (sandbox ↔ live) | `SwapService::blockersFor()` has no mode rule | A live store can be pointed at a sandbox account: checkouts "succeed" and no money moves |
| **A15** | High | **A swap does not require the target's webhooks** | `SwapService::blockersFor()`; registration is manual (`ProcessorWebhookAdminController::register()`) | Square and PayPal invoices, and every subscription renewal, reach "paid" only through webhooks. A target with no registered endpoint takes money that is never recorded |
| **A16** | Medium | Changing `pay_type` on a live DP deletes its items or tiers | `DirectPaymentsController.php:430–431, 488–490` | A SUBSCRIPTION DP switched to SINGLE_PAY loses its tiers while subscriptions still point at them |
| **A17** | Medium | Saving with an `items` array deletes and recreates every item; an item may be created with `reference_num = ''` | `DirectPaymentsController.php:432–446` | Item primary keys change on every save; a second blank reference violates the unique index and the save half-fails after the delete |
| **A18** | Medium | A tier dropped from the payload is hard-deleted even with live subscriptions on it | `DirectPaymentsController.php:488–490` | `update()` for those subscriptions 404s, and anything that resolves access from the tier loses it (appfactory's own case: **A38**) |
| **A19** | Low | `bound_by` on a swap is taken from the request body | `DirectPaymentSwapController::swap()` | The audit trail of who swapped the processor is whatever the caller typed |

## 1.4 Routing a payment, and who holds which identifier — A20–A29

| # | Sev | Finding | Where | Consequence |
|---|---|---|---|---|
| **A20** | Critical | **The caller chooses the processor for every new payment.** Intent, setup intent, checkout session, PayPal order/capture, Square payment and subscription creation all take `processor_reference_num` from the request | `ProcessorIntentController::createIntent/createSetupIntent/createSession/createPayPalOrder/capturePayPalOrder`; `ProcessorSubscriptionController::store()`; `ProcessorSquarePaymentController::store()` | The Direct Payment's binding decides nothing at charge time. After a swap, anything holding the old reference keeps charging the old account |
| **A21** | High | **`retiring` is not enforced.** No endpoint refuses a new charge, customer or subscription on a processor that is not the active binding of the DP being paid | (absence) — `BindingRegistry` is read only by DP admin code and `PayerService` | The plan's §3.6 table ("retiring: new charges ❌") is documentation only |
| **A22** | High | **The SDK caches the processor and lets the page override it.** DP record, processor ref, provider and publishable key are put in `sessionStorage`; `processor_override` is honoured | `resources/js/external/api/direct_payments/api.ts:1030–1047, 445, 1239, 1256, 1265` | A visitor mid-checkout during a swap, or any page that hardcodes a processor, keeps paying the old account until the session ends |
| **A23** | High | **The browser holds the provider customer id.** `afs_customer_id` in `sessionStorage`; anonymous customers are created from the browser per processor; the id is sent back on invoice save and subscription creation | `api.ts:56, 1452–1474, 1752, 1836`; routes `POST /processors/customers/create-anonymous`, `/find-or-create`, `/update-email` | The exact leak [`../direct_payments/00`](../direct_payments/00-overview.md) §3 diagnosed, still alive in the SDK. After a swap the stored id belongs to the old processor and is sent with the new one |
| **A24** | High | **`saveInvoice` lets the browser rewrite a payer's customer mapping.** It calls `recordExistingCustomer(payer, request.processor, request.customer_id)`, which supersedes the payer's active customer on that processor with whatever id was sent | `DirectPaymentsController.php:75–81` → `PayerService::recordExistingCustomer()/upsertActiveCustomer()` :164–176, 261–286 | Anyone who types a victim's e-mail at checkout re-points that victim's payer to an arbitrary provider customer, or detaches them from their real one |
| **A25** | High | **`recreateStripeCustomer()` overwrites the customer id in place** on `laravel_processor_customers` and never tells `payer_customers` | `ProcessorSubscriptionController.php:624–655` | The payer map keeps a dead `cus_…` as active; the old id's history link is lost. Violates "retain the old ids" |
| **A26** | High | **PayPal has no customer.** `createCustomer()` returns the string `"paypal:{email}"`; anonymous returns `null`; that string is then used as the PayPal Vault `customer.id` | `Externals/Processor/ProcessorCustomerGateway.php:28, 37`; `ProcessorPaymentMethodService.php:217` | The payer→PayPal mapping is keyed on a mutable e-mail. PayPal Vault customer ids are restricted to a short alphanumeric/`_`/`-` pattern, so `paypal:x@y.com` is very likely rejected (**verify in sandbox**); PayPal saved cards then never work |
| **A27** | Medium | **Two customer registries written independently**: `laravel_processor_customers` (processor layer, written by browser-driven endpoints and subscription creation) and `laravel_direct_payment_payer_customers` (DP layer) | `ProcessorCustomerController`, `ProcessorSubscriptionController::store()` :54–71, `PayerService` | A customer created by the processor layer never gets a payer mapping unless an invoice save happens to carry it |
| **A28** | High | **E-mail matching crosses from guest to account.** `resolveForCheckout()` does `firstOrCreate(['email' => …])`; `resolveForSiteUser()` keeps the e-mail on the new payer when it is free | `PayerService.php:62–67, 97–110` | A guest who types a signed-in user's e-mail is resolved to that user's payer, and `customerFor()` hands back that user's provider customer — the plan's own rule ("guest-to-account matching is a way to inherit a stranger's saved card", `../direct_payments/02` §2.2) is violated in the opposite direction |
| **A29** | Medium | **The SDK never sends `payer_reference_num`.** The resolver's payer goes into `user_id` as `"su:pay_…"`; the server resolves the payer from e-mail alone | `api.ts:774–780, 798–813`; `DirectPaymentsController.php:67–72` | A signed-in user who checks out with a different e-mail gets a second payer |

## 1.5 Subscriptions — A30–A39

| # | Sev | Finding | Where | Consequence |
|---|---|---|---|---|
| **A30** | High | **A Direct Payments subscription is one provider subscription.** One `provider_subscription_id`, unique per DP; no notion of the same subscription moving to another processor | migration :347–368 (`dp_subscriptions_uq`) | Moving a subscriber means a new row with a new reference — every consumer (modules, websites, host applications, the notifications ledger) loses track |
| **A31** | High | **The link row is written by the browser, after the provider subscription exists**, through an unauthenticated endpoint, best-effort | `routes/portal.php:1280`; `DirectPaymentSubscriptionController::store()` :79–131 | A closed tab between "provider created" and "link written" leaves a renewing subscription that Direct Payments does not know; `direct_payments:backfill-subscriptions` exists precisely to mop this up |
| **A32** | High | **The link's own `reference_num` is regenerated on every write** — `Str::random(12)` sits in the *update* values of `updateOrCreate()` | `DirectPaymentSubscriptionController.php:114` | The stable handle of a subscription is not stable. Anything that stored it is stale after the next call |
| **A33** | Critical | **The link accepts any `tier_reference_num` and overwrites it on an existing link.** Nothing checks that the tier matches the subscription's actual plan | `DirectPaymentSubscriptionController::store()` :108–118 | Whoever knows a `provider_subscription_id` can re-point it at the most expensive tier. Every website or host application that grants access by tier reads that column (appfactory's own resolver among them — [14](14-appfactory-studio-modifications.md)): this is an entitlement escalation path |
| **A34** | High | **No deferred-start creation on any provider.** Stripe uses `trial_end` only for a plan's own trial; Square's `start_date` and PayPal's `start_time` are never used | `ProcessorSubscriptionController::createStripeSubscription/createSquareSubscription/createPayPalSubscription` | The mechanism a gap-free migration needs ("start charging exactly when the old paid period ends") does not exist |
| **A35** | High | **No migration flow.** `direct_payments:subscription-migrate` only lists; there is no hosted re-authorisation for any provider | `Console/Commands/BillingSubscriptionMigrate.php`; `../direct_payments/complete/03` "Not implemented" | Subscriptions on a retiring binding can only run out |
| **A36** | High | **A subscription that ends at the provider never ends locally.** `canceled_at` / `access_ends_at` are written only by the app's own cancel (and Brain staff cancel); `customer.subscription.deleted` and the Square/PayPal equivalents update `laravel_processor_subscriptions` only | `ProcessorWebhookController::logStripeSubscription()` :831–945; `DirectPaymentSubscriptionController.php:299`; `BrainStaffSubscriptionController.php:144` | `BindingRegistry::canRetire()` counts `whereNull('canceled_at')` — a subscription cancelled in the provider dashboard or ended by failed payments blocks retirement forever, and the swap preview over-counts live subscriptions |
| **A37** | Medium | `ProcessorSubscription` is looked up and upserted by `provider_subscription_id` alone, not scoped by processor | `DirectPaymentSubscriptionController::resolveOwnSubscription()` :624; webhook `updateOrCreate(['provider_subscription_id' => …])` :870 | Harmless while ids are globally unique per provider; wrong the moment two processors of different modes or providers produce colliding ids, and a webhook for one processor can rewrite the other's row |
| **A38** | — | **appfactory studio modification, not Direct Payments.** appfactory's tier resolver reads Direct Payments tables directly and its fallback matches a tier's single `processor_tier_id` column, which is wrong after a processor change | `Services/Billing/MembershipTierResolver.php` | Set aside by the owner (OQ11). Explained in [14](14-appfactory-studio-modifications.md) §14.3 (M9) |
| **A39** | Medium | **The site-user card list includes every payer_customer row** — superseded customers and processors no longer bound | `Http/Controllers/Externals/SiteUsers/SiteUsersPaymentsController.php:77–95` | A customer is offered a card on an account the website no longer charges through; the SDK interface comment (`api.ts:421`) promises the opposite |

## 1.6 Provider parity — A40–A49

| # | Sev | Finding | Where | Consequence |
|---|---|---|---|---|
| **A40** | High | **Discounts differ per provider.** Stripe mints a new `duration: forever` coupon per subscription; Square writes `phases[0].order_template_id = ''` (a stub that cannot work); PayPal ignores the code | `ProcessorSubscriptionController.php` Stripe coupon block, Square discount block, PayPal (absent) | A discount a tenant sells on Stripe silently disappears on PayPal and breaks on Square |
| **A41** | Medium | **Trials differ.** Stripe: `trial_end` from `plan.trial_days`; PayPal: a TRIAL billing cycle on the plan; Square: one phase, no trial at all | `ProcessorPlanAdminController::createSquarePlan/createPayPalPlan`; Stripe subscription create | A tier with a trial on Stripe has none after moving to Square |
| **A42** | Medium | **Plan change**: Stripe yes, PayPal revise, Square refused | `SquareSubscriptionActions::update()` :40–43 | Upgrades stop working after a move to Square |
| **A43** | Medium | **Card change on a subscription**: Stripe and Square yes, PayPal refused (payer-only) | `PayPalSubscriptionActions::setPaymentMethod()` :71–74 | Known and correct to refuse; must be surfaced as a capability, not discovered |
| **A44** | Medium | **PayPal one-time payments carry no customer.** The order has no `customer`/vault association; capture resolves a `ProcessorCustomer` by PayPal `payer_id` or e-mail | `ProcessorIntentController::createPayPalOrder()/capturePayPalOrder()` | No saved-payment parity with Stripe/Square; the payer map has no PayPal row for one-time buyers |
| **A45** | Medium | **PayPal client id lives in two places**: `publishable_key` (orders, subscriptions, deletes) and `extra_config.paypal_client_id` (vault list/setup/confirm) | `ProcessorIntentController.php:497, 613`; `ProcessorPaymentMethodService.php:202, 242, 447` vs `:570` | A PayPal processor configured "correctly" for checkout lists no saved cards, and vice versa |
| **A46** | Medium | **SDK tier display is Stripe-only.** Square and PayPal tiers report `amountCents: 0, currency: 'usd', interval: 'month'`; the tier's `original_data.processor` is read to pick a processor | `api.ts:9–50` | After a move to Square/PayPal the pricing page shows $0; a tier's own JSON can steer the processor choice |
| **A47** | Medium | **Currency is hardcoded** `'usd'` on invoice save and Square confirm in the SDK; a processor's currency is an optional `extra_config` key | `api.ts:808, 2268`; `SwapService::processorCurrency()` | Non-USD tenants are mis-recorded; the swap's currency blocker passes whenever the key is unset |
| **A48** | Medium | **Idempotency keys are `uniqid()`** on Square card/subscription creation and PayPal order/capture | `ProcessorSubscriptionController::createSquareSubscription()`; `ProcessorIntentController` PayPal calls | A retried request creates a second card, subscription or order instead of returning the first — dangerous for an automated migration |
| **A49** | Low | PayPal subscription return/cancel URLs are `url('/')`; Square `location_id` falls back to `env('SQUARE_LOCATION_ID')` | `ProcessorSubscriptionController::createPayPalSubscription()`, `createSquareSubscription()` | Approval lands on the Brain's home page, not the tenant's site; a missing location silently uses a global one |

## 1.7 appfactory studio code inside Direct Payments — A50–A54, A61

Direct Payments must not know about appfactory studio at all ([13](13-naming-and-separation.md)). A50–A53 are Direct
Payments code that does; A54 (and A38 above) are appfactory code, listed for completeness and owned by
[14](14-appfactory-studio-modifications.md).

| # | Sev | Finding | Where | Consequence |
|---|---|---|---|---|
| **A50** | High | **Subscription self-service resolves the caller through Checkpoint + `brain_memberships`.** `show/history/cancel/update/setPaymentMethod` find the payer via `Membership::where('reference_num', …)->value('payer_reference_num')` | `DirectPaymentSubscriptionController.php:205, 615, 745` | A tenant website's own customers (Site Users or guests) cannot view, cancel or change their subscription through `dp.subscription` at all. The generic API only works for appfactory members |
| **A51** | Medium | The subscription response is decorated with `brain_membership_billing_state` | `DirectPaymentSubscriptionController::resolveBillingState()` :679–705 | appfactory dunning state in a tenant API response |
| **A52** | Medium | Notification recipient falls back to `Membership.email` instead of the payer's e-mail | `Externals/DirectPayments/Notifications/NotificationDispatcher.php:250, 349` | A tenant's subscriber with no `ProcessorCustomer` e-mail gets no notification |
| **A53** | Low | The Money ledger maps payers to members | `Http/Controllers/Externals/Processor/MoneyController.php:190` | Harmless on tenants (no rows), but generic code depends on a Brain model |
| **A54** | — | **appfactory studio modification, not Direct Payments.** appfactory's signup step claims a subscription by its **provider** subscription id | `Http/Controllers/Portal/Admin/MembershipController.php` (~:361–372) | Set aside by the owner (OQ11). Explained in [14](14-appfactory-studio-modifications.md) §14.3 (M10) |
| **A61** | Medium | **appfactory vocabulary throughout Direct Payments and processor code**: `ProcessorBillingController` and `/processors/billing/*` routes, `Billing*` command classes, `config/billing.php` keys, `[Billing]` log tags, `member_ref` API option, Connect `member_reference_num` columns, "Cancelled by member." sent to PayPal, 182 `AFS_BILLING_MODIFIER_ADDITION` markers and 143 `plans/billing/…` references | full inventory in [13](13-naming-and-separation.md) §13.3 | Two separate systems read as one; a developer reading the Direct Payments API sees appfactory concepts |

## 1.8 Webhooks, history and data integrity — A55–A60

| # | Sev | Finding | Where | Consequence |
|---|---|---|---|---|
| **A55** | High | **Webhook candidates exclude an inactive processor whose bindings are all retired** | `ProcessorWebhookController::webhookCandidateProcessorIds()` :370–382 | Disputes and late refunds arrive months after a charge. Once the tenant retires the binding and deactivates (or archives) the processor, those events are dropped as orphans |
| **A56** | Low | The orphan row is written after the rejection rate-limiter returns | `recordRejectedWebhook()` :401 vs :429 | During a burst — exactly what a mis-retired binding produces — the "did we miss money" count stops counting |
| **A57** | Medium | **The charge model is two models on one table, with a mixed `customer_id`.** `ProcessorCharge` maps to `laravel_direct_payment_invoices`; `laravel_processor_charges` is created but unused. `invoice.customer_id` holds the provider id on some paths and the local `laravel_processor_customers.id` on others; `refund()` compares it strictly to a local id | `Models/Externals/Processor/ProcessorCharge.php:32`; `ProcessorWebhookController.php:486/511` (provider id) vs `:560/590, 1115/1150, 1765/1787` (local id); `ProcessorBillingController::refund()` :112–140 | Customer-scoped refunds and history are unreliable; `DirectPaymentSubscriptionController::history()` has to query both id kinds to compensate |
| **A58** | Medium | **Disputes are logged, not stored** | `ProcessorWebhookController::logStripeChargeback()`, `logSquareDispute()`, `logPayPalDispute()` | `canRetire()` cannot check open disputes; an operator retires a binding with a dispute pending |
| **A59** | High (security) | **Payment-method store/delete/default routes have no middleware**, and delete detaches any `method_id` without checking it belongs to the named customer | `routes/portal.php:944–946`; `ProcessorBillingController::deletePaymentMethod()` :445–527 | Anyone can detach a Stripe payment method by id. Listed for completeness; the fix belongs to [`../externals_security/`](../externals_security/) and to [04](04-payer-customer-identity.md) §4.6, which moves these behind payer ownership |
| **A60** | Low | **Dead SDK calls**: `ExternalProcessorsAPI.getProcessors()` → `/processors/list`, `chargeSinglePay()` → `/charge`, `chargeSubscription()` → `/subscribe` — none of these routes exist | `resources/js/external/api/processors/api.ts:41–90` | A developer reading the SDK sees methods that 404 |

## 1.9 Found later — the email system (E1–E5)

Found while planning the default emails and recorded in [15](15-default-emails.md) §15.2: every handler attached to a
Direct Payment is sent as a receipt (**E1**); receipt variables are very likely never substituted (**E2**); Direct Payments
sends through appfactory's `BillingMailer` (**E3**); a notification cannot exist without a hand-built handler (**E4**);
unset brand values reach emails as `- NOT SET -` (**E5**).

## 1.10 Findings carried from `../direct_payments/TODOS.md`

Not repeated here; they are prerequisites. In short: an intent can be created with no invoice (F1), the invoice is
written by the browser (F2), lines are not checked against the catalogue (F3), status is caller-settable (F4/F5),
internal identifiers go to the provider (F8), the caller chooses the processor (F9 = **A20**). [06](06-swap-engine-v2.md)
§6.3 depends on TODOS P0 landing first or together.

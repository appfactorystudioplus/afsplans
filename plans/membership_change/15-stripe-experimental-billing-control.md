# 15 — Experimental billing controls in Direct Payments and the processor API

Status: PLAN — nothing built. Written 2026-10-22, revised the same day after the owner's answers. Follows 13 (server lifecycle) and 14 (phone + dashboard lifecycle).

## 1. What this is

Stripe can do far more with a subscription than the neutral Direct Payments surface exposes. This step adds those controls **inside Direct Payments and the processor API
themselves**, so the owner's admin dashboard and the Brain phone app can control a member's billing as fully as the providers allow.

Where Square or PayPal can do the same thing, the same control is implemented for them too, behind the same call. Whatever a provider cannot do answers `409 DPX_E_UNSUPPORTED`
with the provider named, and `GET capabilities` says so ahead of time, so a client never has to guess.

Every control here is labelled **experimental**: it ships in the SDK and the API for everyone, but it is **not in the public documentation** until it is promoted.
Promotion later = move its docs, drop the label. No code moves.

## 2. Decisions taken (owner, 2026-10-22)

1. Build order is mine to pick: layer → subscription control → money → customer/cards → schedules/price → disputes/dunning.
2. The phone gets every control, same as the dashboard.
3. No second live-mode switch. Dangerous writes still ask for confirmation in the client (see rule 5), in test and live alike.
4. **No on/off switches and no environment flags.** Everything is on.
5. **No level-99 gate and no Brain staff routes.** The features are for everyone who may already manage that payer/subscription through Direct Payments: the same auth as the
   routes beside them (`externals.dashboard` for dashboard calls, payer token for the payer's own calls, the API key for server-to-server). The Brain's staff screens call these
   existing Direct Payments / processor routes; they get no routes of their own.
6. Experimental = undocumented, not restricted.

## 3. Rules

1. **Where the routes live.** `routes/externals.php`, under the existing `processors` group (processor-level, dashboard auth) and the `directpayments` group (payer / subscription level).
   New path segment `x/` is **not** used: the routes sit where the neutral ones would, named like them (`/subscriptions/pause`, `/invoices/{id}/void`), so promoting one is a docs change only.
   Existing neighbours to extend rather than duplicate: `POST processors/subscriptions/discount`, `/amount`, `/renewal-date`, `processors/refunds`, `processors/discounts`, `money/charges/{id}/refund`.
2. **One provider interface.** `App\Externals\DirectPayments\Experimental\ExperimentalProvider` (pause, resume, trial, anchor, invoices, credits, cards, customer, disputes, schedules, inspect),
   implemented by `StripeExperimental`, `SquareExperimental`, `PayPalExperimental`. A method a provider lacks throws `ExperimentalUnsupported` → `409 DPX_E_UNSUPPORTED`.
   Sits beside `SubscriptionActionsProvider` / `SubscriptionReader` and is resolved the same way (`SubscriptionActionsFactory` pattern).
3. **Capabilities, per provider.** `ProviderCapabilities::for()` gains an `experimental` block (a list of control keys, each `true`, `false` or `partial` with a note). `GET processors/capabilities`
   returns it. Clients draw only the controls the member's provider supports. The block is not in the public docs.
4. **Sync back.** After every write the subscription, charge and invoice rows are re-read from the provider (`SubscriptionSyncService`), and the reply carries the fresh state. Webhooks already
   keep rows current; the re-read just makes the reply the truth.
5. **Dangerous writes preview first.** Anything that moves money or ends access takes `preview: true` and returns what would happen (amounts, dates, who is emailed) without doing it; the write
   takes the same body with `preview` absent plus an `idempotency_key` and a `reason`. Both clients show the preview in words and confirm. Applies to: refund, void, credit, mark uncollectible,
   cancel at a date, pause with void, detach card, schedule release, one-off charge.
6. **Audit.** Every experimental write goes into the Direct Payments activity log (who, payer, subscription, action, request, provider object ids, result), written before the call, completed after.
   Reuses the activity/notification publisher so the existing event log screens show it.
7. **Inspector.** `GET processors/inspect/{object}?id=` returns the provider's own JSON for a customer, subscription, invoice, payment, dispute or plan (secrets scrubbed). Read-only escape hatch
   while a typed control is missing, and the first thing built (P0).
8. **SDK.** Added to `portal.d.ts` and the external SDK under a `experimental` namespace (`dp.experimental.pauseSubscription(...)`). The contract file marks the namespace `@experimental`
   (kept current per the portal.d.ts rule). The playground does not show them.
9. **Clients.** Dashboard (`services/memberStaffX.ts` pure rules + words, tested; panel in `brain_member_subscription.ts`) and phone (models, bloc, screens, tested) call the **same**
   routes, at `/portal/api/externals/v1/...`, with the same login token (bearer token or `portal_auth_token` cookie). Nothing goes through `brain/v1`. Section 6 records how that works today.
   Each phase gets its client controls right after its server work. The words come from one list the server returns (`preview.text`), so the two clients cannot drift.
10. **Auth on the new routes.** `ApiAuthMiddleware` directly (as `money/*` does), not the `externals.dashboard` alias: that alias only enforces when the externals security mode is `enforce`,
   and these routes move money. Payer-level routes that a payer may call on their own subscription also accept the payer token, like the neighbours in `directpayments`.
11. **Addressing.** The new routes are keyed by Direct Payments references (`payer_reference_num`, subscription link `reference_num` / `provider_subscription_id`, charge id), never by a Brain
   member reference. Both clients already receive those from the subscription detail they load (`link.reference_num`, `link.provider_subscription_id`, `member.payer_reference_num`).

## 4. Research: what each provider can do

Sources: Stripe API reference (known well); Square Subscriptions / Invoices reference pages and PayPal Subscriptions v1 reference page read on 2026-10-22; Square Disputes/Refunds/Cards and
PayPal Invoicing/Disputes/Payment Method Tokens from the providers' documentation, **to be re-confirmed against the live reference before each phase is coded** (the existing plan 05 marks the same
rows with †).

| Control | Stripe | Square | PayPal |
|---|---|---|---|
| Pause / resume | `pause_collection` (resume date, behavior keep_as_draft / mark_uncollectible / void) | `pause` (Beta), `resume`; scheduled by date | `suspend`, `activate` (no date; resume is immediate) |
| Cancel at a chosen date | `cancel_at` | `cancel` schedules at period end; scheduled actions can be listed and deleted (`DELETE …/actions/{id}`) | `cancel` is immediate only → **partial** (immediate only) |
| Undo a scheduled cancel | clear `cancel_at` / `cancel_at_period_end` | delete the scheduled action | not possible (activate a suspended one only) |
| Trial: extend or end now | `trial_end` | no trial field; a zero-price phase swapped in via plan variation → **partial** | `billing_cycle_overrides` only at create, plan trial cycles → **no** on a live one |
| Move the billing day | `billing_cycle_anchor` (+ proration choice) | `change billing anchor date` (Beta) | `PATCH` next billing time is not exposed → **no** |
| Change the amount for one member | new private Price on the item (already used by `subscriptions/amount`) | swap to a one-member plan variation | `revise` with a plan override / pricing scheme per subscription → **partial** |
| Quantity | item `quantity` | not on subscriptions → **no** | `revise` (quantity) |
| Plan change with proration choice + preview | `proration_behavior`, `invoices/upcoming` | `swap-plan` (no proration choice) → **partial**, no preview | `revise` (proration set on create/plan) → **partial** |
| Apply / remove a discount on a live subscription | `coupon` / `promotion_code` / delete discount | discounts through the order template → **partial** | plan-level only (`update-pricing-schemes`) → **partial** |
| Phased schedules | `subscription_schedules` (create, update phases, release) | phases are part of the plan variation; no per-member schedule → **no** | no → **no** |
| One-off charge on next invoice | `invoice_items` | Orders API line + Invoice → **partial** | `capture` of outstanding balance (`outstanding_balance` on subscription) → **partial** |
| Account credit / balance | customer balance transactions | gift card / store credit is a separate product → **no** | no → **no** |
| Credit note | `credit_notes` | no (cancel invoice + new) → **no** | invoice `record-refund` → **partial** |
| Invoices: list, get | `invoices` | `invoices` (list, search, get) | Invoicing API v2 (list, search, get) |
| Invoice: finalize / send / remind | `finalize`, `send` | `publish` | `send`, `remind` |
| Invoice: pay now / retry | `pay` | no direct "retry" (card on file is charged on publish) → **partial** | `record-payment`, `capture` → **partial** |
| Invoice: void / uncollectible | `void`, `mark_uncollectible` | `cancel` (void equivalent), no uncollectible | `cancel` |
| Collection method (auto-charge vs send) | `collection_method`, `days_until_due` | invoice `delivery_method` + payment request type | invoice only → **partial** |
| Partial refund with reason | `refunds` (reason enum) | `refunds` (reason text) | capture `refund` (note, partial) |
| Payment methods: list / default / detach | `payment_methods`, `invoice_settings.default_payment_method`, `detach` | Cards API (list, create, disable); subscription `card_id` | Vault v3 payment tokens (list, delete); no default switch on a live subscription → **partial** |
| Card-update link | Checkout `mode=setup` / billing portal | no hosted page; Web Payments SDK in our own page (already used) | payer updates at PayPal; link to manage → **partial** |
| Customer edit (name, email, address, phone) | `customers` update | `customers` update | none for the payer (PayPal owns it) → **no** |
| Tax exempt / tax IDs | `tax_exempt`, `tax_ids` | customer tax IDs not in the API → **no** | no → **no** |
| Metadata | `metadata` | `custom_attributes` | `custom_id` |
| Disputes: list / evidence / accept | `disputes` (update evidence, close = accept) | Disputes API: list, retrieve, accept, evidence upload + submit | Disputes API: list, accept-claim, provide-evidence, escalate, make-offer |
| Dunning override per member | subscription `payment_settings`, retry via `pay` | Square retries on its own schedule; no per-subscription control → **no** | `payment_failure_threshold` set at create / revise → **partial** |
| Hosted portal link | `billing_portal/sessions` | none → **no** | none → **no** |
| Event timeline for the member | `events` list | subscription `events` (list) | `transactions` list |
| Inspector | any object by id | any object by id | any object by id |

**Reading the table:** a plain cell means implemented to that API; **partial** means implemented with the stated limit and `capabilities` says `partial` plus a note; **no** means `409 DPX_E_UNSUPPORTED`.
Square and PayPal rows are built from the reference pages and sandbox-confirmed before they are marked ready; until then they are marked `unconfirmed` in `capabilities` and the clients show
"experimental, not yet confirmed on this provider".

## 5. Steps and phases

Step = file, phase = work inside it (as in the other plans). Each phase is shippable and ends with its tests and its two clients.

- **P0 — the layer.** `ExperimentalProvider` + three implementations (empty), `ExperimentalUnsupported`, `DPX_E_*` codes, the `experimental` block in `ProviderCapabilities` and `processors/capabilities`,
  the inspector, audit wrapper, preview/idempotency helpers, `portal.d.ts` namespace, dashboard + phone "Inspect" screen. Tests: unsupported → 409, audit written, scrubbing.
- **P1 — subscription control.** Pause/resume, cancel at date and undo, trial extend/end, billing-anchor move, quantity, plan change with proration choice and preview, apply/remove discount on a live subscription.
  Providers per the table. Clients: a "Subscription controls" section on the member subscription page and the phone's billing screen.
- **P2 — money.** One-off charge/credit, balance, credit notes, partial refunds with reason, invoice list / send / pay / retry / void / uncollectible / collection method. Clients: Invoices tab and a money menu.
- **P3 — customer and cards.** Customer edit, tax exempt and IDs, cards list / default / detach, card-update link, hosted portal link (Stripe).
- **P4 — schedules and one-member price.** Subscription schedules (Stripe), one-member plan variation (Square), `revise` pricing (PayPal); release a schedule. Builds on step 14's custom-price work.
- **P5 — disputes and dunning.** List/view/evidence/accept across the three; per-member retry settings where the provider allows.

## 6. How the dashboard and the phone reach money today (researched 2026-10-22)

- **Refunds and the money ledger: already the same route for both.** The dashboard (`resources/js/portal/admin/services/memberStaffApi.ts`, `refund()`) and the phone
  (`afsbrain_flutter/lib/core/api/api_paths.dart` `moneyRefund`, used by `features/payments/data/payments_repository.dart`) both call
  `POST /portal/api/externals/v1/money/charges/{chargeId}/refund` and `GET /portal/api/externals/v1/money/ledger`, defined in `w/www/routes/externals.php` (`Route::prefix('money')`,
  `ApiAuthMiddleware`, no level gate by design). Both send the dashboard login token. The phone's own comment says it never uses the unauthenticated `processors` refund route.
- **Notifications:** also `/portal/api/externals/v1/notifications` for both.
- **Where the Brain is still in the middle:** the member lifecycle I built in steps 13/14 (create a member, start billing, cancel, change tier, sync, plans list, a member's payments) is
  `brain/v1/staff/*`. Those routes call Direct Payments inside the server. They stay as they are (they are about the Brain's membership, not the payment API). **The new experimental controls
  do not extend them.**
- **Consequence:** no pass-through and no Brain code is needed. The two clients only need to call the new Direct Payments / processor routes the way they already call `money/*`.

## 6b. Open items before coding

- Re-read the live Square and PayPal references for every row marked **partial** or **no** (the table is from the reference indexes; parameter-level detail was not on the pages read) and flip
  rows both ways if the API allows more.
- Sandbox accounts for Square and PayPal must be reachable from dev to confirm any provider row. If they are not, those rows ship `unconfirmed`.
- Today the clients learn a member's subscription references only from `brain/v1/staff/members/{ref}/subscription` (a read). That read stays; it is the lookup, not a control. If the owner wants
  even that read moved to a Direct Payments route (`directpayments/payers/{ref}/subscriptions`), say so and it becomes a P0 task.

## 7. Not in scope

Stripe Connect payouts (Marketplace has its own), Radar, tax registrations, billing meters, Terminal, changing a provider account's own settings (those stay in each provider's dashboard).

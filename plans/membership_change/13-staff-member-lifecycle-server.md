# 13 — The server: create a member with its billing through Direct Payments, cancel, change (Brain only)

> **STATUS: PLAN ONLY (2026-10-20, revised twice the same day after the owner's answers; third round: tax yes where Stripe Tax can be used, a 7-day grace for paused members, and the work runs in the `development` environment).**
> Nothing here is built, and nothing was run. Read from `w/www`, `w/auth` and `w/members`. Paths are relative to `w/www` unless
> stated. The clients are [14](14-phone-and-dashboard-member-lifecycle.md); the welcome e-mail, the bell, the set-password link and the shared "finish creating a member" routine are
> [11](11-new-member-welcome-and-alerts.md); the notification system underneath is [`www/notifications_and_analytics`](../www/notifications_and_analytics/README.md).
> Findings **NA#** continue 11/12; decisions **NM#**; server changes **NS#**; open questions **OQ-N#**.

## 0. What the owner asked for (2026-10-20) and the rules he set

From the phone **and** the Brain dashboard (same abilities, same server surface):
1. **Create a member through the standard process**, choosing: welcome e-mail or not; how the password is set; the *membership tier*; address and the other profile fields.
2. **Then decide how the member pays.** Free tier: everything goes through. Paid tier: enter payment details now, or a **free period** (7 days, 14 days, 1 month, 3 months, 6 months, 12 months), or both, or
   **complimentary** (nothing billed). Stripe is always the processor.
3. **Cancel** a membership and a member; **change** the membership.

**Rules from the owner:**
- **Everything goes the standard way through Direct Payments and the processor implementation**, so it is recorded correctly and refunds and money checks work as normal. **Nothing bypasses Direct Payments.** Stripe
  being the only processor is a fact to tailor to, not a reason to call Stripe directly.
- **No member can ever be level 99.** There is exactly one level-99 member, `appfactorystudio`. (§2, NM15.)
- Work and rehearsal happen in the **`development` environment** (`www15` / `auth15`, Stripe **test** keys), not the Vagrant lab. Nothing is run on any server by me; the proofs of P0 are run by the owner or on his say-so.
- When a free period ends with **no card, the subscription pauses**, and a paused member keeps the paid plan for a **7-day grace** before dropping to the free plan. The chosen tier is **active immediately**. **Complimentary is wanted.** The welcome e-mail **mentions the free period and its end date.**
  Staff-created subscriptions **get tax.**

## 1. What the code does today (verified by reading)

| # | Finding | Where |
|---|---|---|
| **NA15** | The staff surface does not create subscriptions or take cards ("each needs the member's own payment authentication"). **This step does not override that by going around it:** the operator acts through Direct Payments' own mechanisms (a payer token and an administrator-set start option, §3), so the member's subscription is created by Direct Payments' normal start. | `Http/Controllers/Brain/Staff/BrainStaffSubscriptionController.php` docblock |
| **NA16** | The standard signup is: the browser builds an invoice with the Direct Payments SDK (tier item, locked, saved against the payer), mounts the card with `setupSubscriptionPayment`, confirms, and only then registers the member and claims the subscription. | `resources/js/afs/create.ts`; `DirectPaymentCheckoutController::startSubscription` |
| **NA17** | `startSubscription` resolves the customer **from the caller's payer** (a payer token or the caller's session), routes the processor itself (`router->forNewBusiness`), prices the tier (`TierBindingRegistry::priceFor`), runs the processor's `store()`, then writes the Direct Payments link row. A deferred start (`starts_at` → Stripe `trial_end`, no proration) already exists but is set **by server code only, never from a request**. | `DirectPaymentCheckoutController`, `ProcessorSubscriptionController::createStripeSubscription`, `ProviderSubscriptionCreator` |
| **NA18** | The SDK already handles a trial: when Stripe returns a `pending_setup_intent` it confirms a **SetupIntent** (`stripe.confirmSetup`), so "free period **with** a card now" works in the normal flow once the server sets the trial end. | `resources/js/external/api/direct_payments/api.ts` ~673; `ProcessorSubscriptionController` ~261 |
| **NA19** | An admin-created member has **no payer** today ([11](11-new-member-welcome-and-alerts.md) NA2), so a subscription cannot be attached to them. Step 11 P1 comes first. | — |
| **NA20** | A **payer token** (`PayerToken::issue(payerRef)`, signed with the app key, default 180 days) is the existing proof a returning browser presents; the SDK keeps it in `localStorage` under `afs_dp_payer_token`. It is the standard way to act as a known payer. | `app/Externals/DirectPayments/PayerToken.php` |
| **NA21** | **`paused` is not understood anywhere.** `StripeSubscriptionReader::normalise()` has no case for it (it falls through as an unknown status), `DunningService` only handles `active`/`trialing` (good standing) and `past_due`, and **leaves every other status untouched, so a paused member would keep the paid tier for ever.** Nothing resumes a paused subscription when a card is added. | `Subscriptions/Providers/StripeSubscriptionReader.php` ~72; `app/Services/Billing/DunningService.php` ~84–110 |
| **NA22** | `trialing` is fine: the billing ladder treats it as good standing (the tier stays), and Direct Payments treats it as live. | `DunningService`, `SubscriptionContracts` |
| **NA23** | A member can add a card later in the **members app** (`w/members/resources/js/controllers/BillingApp.ts` calls `set-payment-method`). That route changes the card but does not resume a paused subscription. | `members/…/BillingApp.ts`; `DirectPaymentSubscriptionController::setPaymentMethod` |
| **NA24** | **Tax is only an estimate today.** The signup form shows a Stripe Tax *estimate* (`ProcessorTaxDiscountController`) and stores `tax_rate`/`tax_cents` on the member, but I found **no code that sends tax to Stripe when a subscription starts** (no `automatic_tax`, no tax rates in `ProviderSubscriptionCreator::stripe`). So a normal signup does not appear to be charged tax. | `ProviderSubscriptionCreator.php`; `ProcessorTaxDiscountController` |
| **NA25** | *Fixed by [11](11-new-member-welcome-and-alerts.md) (2026-10-20).* Checkpoint sent its own password e-mail (SendGrid, silently skipped if no API key) with a link to `{its base URL}/checkpoint/auth/reset-password?selector=…&token=…`, but the `auth` repo had no page at that address. Step 11 added the page, a set-password e-mail and a system-key route; an account registered without a confirmation callback is verified, so the link is sent. | `w/auth/app/Services/Checkpoint/EmailService.php` ~81; `w/auth/routes/*.php` |
| **NA26** | `level` is a platform-superuser marker; it was removed from every form in `membership_change/08` (D2). The app's word "level" for a *plan tier* (`PlanOption.level`) is a different thing and a source of confusion. | `08-member-management.md`; `afsbrain_flutter/.../create_options.dart` |
| **NA27** | Cancel and change exist on the staff surface and are audited: `POST …/subscription/cancel` (reason; `immediately` or at period end), `POST …/subscription/change-tier` (at the processor), `PUT /v1/memberships/{ref}/tier` (database tier, reason), `PUT …/status`. | `BrainStaffSubscriptionController`, `MembershipController` |
| **NA28** | Removing a member has no endpoint; the real delete is the unbuilt `account_close` plan. The interim (suspend / mark for deletion) exists. | brain_flutter 06 §6.6 |
| **NA29** | The Brain dashboard's own Subscription and Payments tabs call routes that do not exist (audit BA8). They cannot be mirrored, only replaced. | `brain_membership.ts` ~3058, ~3224 |

## 2. Decisions

- **NM9 — One staff surface, two clients** (`api/v1/brain/staff/*`, the existing `brain.only` + `portal.level99` group). Nothing is built twice.
- **NM10 — The standard flow, driven by the operator.** Direct Payments does the work: it saves the invoice against the member's payer, routes the processor, prices the tier, starts the subscription, writes its own rows, and the
  webhooks record the charges as they always do. Refunds, the money ledger and "checking money" are therefore the normal ones. The Brain adds only *who may ask* and *what the free period is* (§3).
- **NM11 — Creating a member and starting their billing are separate, repeatable steps.** A: create the member (complete, per 11). B: start billing. If B is abandoned the member exists with `billing_setup = pending`
  and B can be re-run from the member's screen.
- **NM12 — Five ways a member can be set up:**

  | Mode | Tier | What happens |
  |---|---|---|
  | `none` | free | everything goes through, no subscription |
  | `card_now` | paid | card entered now through Direct Payments' normal card form, first invoice charged now |
  | `free_period` + `card_now` | paid | card entered now (a SetupIntent), first charge when the free period ends |
  | `free_period` (no card) | paid | no card yet; when the period ends with no card the subscription **pauses** (§5) |
  | `complimentary` | paid | tier set by hand, **nothing billed**, no subscription, `tier_source = 'admin'` |

  A paid tier with no card, no free period and not complimentary is refused.
- **NM13 — The free period is a fixed menu computed on the server:** `7d`, `14d`, `1m`, `3m`, `6m`, `12m`. Months are **calendar months** (UTC): 1 month from 31 January is 28 February. The result is the subscription's
  `starts_at` (Stripe `trial_end`, no proration). The clients never send a date. **The chosen tier is active immediately**; billing treats `trialing` as good standing (NA22).
- **NM14 — The start option is held by Direct Payments, not sent by the browser.** A new, generic Direct Payments table `laravel_direct_payment_start_options` (payer, tier, `starts_at`, `end_behavior`, expiry 24 h, who set it)
  is written only by an **administrator-authenticated** route. `startSubscription` reads an unexpired option for (payer, tier) and passes `starts_at` and the end behaviour to the processor. Direct Payments stays
  module-agnostic: it knows payers, tiers and administrators, not memberships. A normal customer's request can never carry a free period (NA17 stays true).
- **NM15 — No member is ever level 99.** Not selectable anywhere, not settable by any route, not by the dashboard, not by the phone. `appfactorystudio` (`brain_memberships.id 1`) is the only level-99 row.
  Enforced three ways: (1) every create/update path ignores and refuses `level`; creation always writes the column default `0`; (2) a **model guard** on `Membership` refuses to save `level = 99` for any
  username other than `appfactorystudio`, and refuses to change `appfactorystudio`'s level; (3) a **test** and a read-only check command `memberships:check-levels` that fail when more than one row (or the wrong row) has level 99.
  There is therefore **no "change level" operation**; the vocabulary in the clients says "plan", never "level" (NA26).
- **NM16 — A free period with no card pauses.** Stripe `trial_settings[end_behavior][missing_payment_method] = pause`, set by the processor creation code whenever a subscription starts with a deferred start and no
  saved payment method. §5 makes the rest of the system understand `paused`.
- **NM17 — Tax is a processor setting, applied to every subscription start** (§6), so staff-created and signup subscriptions are treated the same.
- **NM18 — Every action is audited** through `StaffAudit` (actor = the device-token or dashboard user), refuses the acting member, and carries `expected_updated_at`.

## 3. How an operator starts billing the standard way (NS4…)

**Card now (with or without a free period).**
1. The operator's client calls `POST …/staff/members/{ref}/billing-session` with `{tier, mode, free_period}`. The server (a) checks the member has a payer (11), (b) for a free period writes the **start option** (NM14),
   (c) mints a **one-time session** (15 minutes, bound to this operator and member) and a **payer token for the member's payer issued with a short remaining life** (the token's `issuedAt` is set so that it expires in one hour,
   not 180 days), and (d) returns `{session_id, url, expires_at}` where `url` comes from `config('memberships.card_page_url')`.
2. The **card page** (a Brain page served from the admin bundle) loads the Direct Payments SDK for the Brain's Direct Payment, installs that payer token, and runs the **same calls `create.ts` makes**: `createInvoice` →
   `addItem(tier item)` → `lock` → `setupSubscriptionPayment` → the card form → `confirmPayment`. The card goes from the page to Stripe; the Brain never sees it. The page clears the token and storage when done.
3. The page tells the server it finished (`POST …/billing-session/{id}/complete`); the server finds the payer's new subscription, **claims it for the member** (`SubscriptionClaim`, as `store()` does), sets the member's
   tier and `billing_setup = done`, and the operator's client polls `GET …/billing-session/{id}` for `pending | saved | failed` (with brand and last four for confirmation).

**Free period, no card.** No browser is involved. `POST …/staff/members/{ref}/subscription/start` runs the **same two Direct Payments steps on the server**: save the invoice for the member's payer with the tier item (the code
`saveInvoice` uses), then `startSubscription` with the start option and the pause end behaviour. The result is the same rows and the same webhooks as any other subscription.

**Complimentary / free.** No Direct Payments call at all (nothing is billed): tier set, `tier_source = 'admin'`, as `adminStore()` does today.

**Cancel / change.** Unchanged routes (NA27). Cancel additionally accepts being called while `trialing` or `paused` and states exactly when access ends; "change plan" for a member with a subscription is the processor change
(`change-tier`), for one without it is the audited database tier.

## 4. Server changes

| # | Change |
|---|---|
| **NS4** | `GET …/staff/create-options` (extend): `free_periods` (six labels), `payment_modes`, `password_modes`, plans with `paid` and the tier reference, the Brain Direct Payment (existing `membership_direct_payment`), **no processor list and no level list**. |
| **NS5** | `POST …/staff/members` — create the member (standard process): profile and address, `membership` (tier), `account_status`, `password_mode` (`set_now`/`send_link`/`both`), `password` (set_now/both only), `send_welcome`, `discount_code`, `billing {mode, free_period}`. Runs 11's shared routine. Reply: member, `notices {storage, welcome, password_link}`, `billing_setup`, and `next` (`null` or `card`). `adminStore` keeps working and delegates. |
| **NS6** | `POST …/staff/members/{ref}/billing-session`, `GET …/billing-session/{id}`, `POST …/billing-session/{id}/complete` (§3). One-time, 15 minutes, bound to operator and member, never reusable. |
| **NS7** | The card page (Brain-served; authorised by the session id alone; no card data in any log). |
| **NS8** | `POST …/staff/members/{ref}/subscription/start` (free period, no card, and re-running billing). Idempotent per member + tier + date. Refused if the member already has a live subscription (`BS_E_ALREADY_SUBSCRIBED`). |
| **NS9** | Direct Payments (generic): table `laravel_direct_payment_start_options`; an administrator-authenticated route to write one; `startSubscription` reads it. Tests prove a public caller cannot supply one. |
| **NS10** | Processor creation (generic): when a subscription starts deferred and has no saved payment method, send `trial_settings[end_behavior][missing_payment_method]=pause` (NM16). |
| **NS11** | `paused` support (§5). |
| **NS12** | Tax (§6). |
| **NS13** | Level guard (NM15): model guard, `memberships:check-levels`, tests; remove any code path that reads `level` from a request. |
| **NS14** | `whoami` capabilities `member_create`, `member_billing`; `config/memberships.php` keys of 11 §3.5 (the processor key is dropped: Direct Payments routes the processor). |

## 5. `paused` (NS11)

- **Read it:** `StripeSubscriptionReader::normalise()` maps provider `paused` to a Direct Payments status `paused` (and the `customer.subscription.paused` / `resumed` webhooks are recorded the same way the other
  subscription events are), so push and pull agree.
- **Billing:** `DunningService` gets a `paused` branch that works like the existing past-due ladder but with its own length: the day the subscription is first seen `paused` is recorded (`paused_since`), the member **keeps the paid plan for
  7 days** (`memberships.paused_grace_days`, from `.env` `MEMBERSHIPS_PAUSED_GRACE_DAYS`, default 7), and on day 7 the existing downgrade runs (tier effective `0`). Adding a card inside the grace simply resumes the subscription and
  nothing is lost; after the downgrade the existing restore path brings the member back when the subscription is `active` again. The member's *chosen* plan is kept throughout so it can be restored. The `trial_ending`/
  `payment_details_required` notices and a new `subscription_paused` notice (Direct Payments event) tell the member the grace is running and when it ends.
- **Resume:** `set-payment-method` (the route the members app's `BillingApp.ts` uses) additionally **resumes a paused subscription** once the new card is set (Stripe `resume`), so adding a card is all the member does.
- **Notices:** the existing `trial_ending` and `payment_details_required` notices (Direct Payments events) tell the member before and when the period ends; the welcome e-mail also states the end date (below).

## 6. Tax (NS12)

**Owner answer (2026-10-20): use tax wherever Stripe Tax can be used.** So the setting below is switched **on** for the Brain's Stripe processor as soon as P0 shows Stripe Tax works on the development Stripe account (account
registration with a tax origin address, product tax codes, the member's address on the customer). If Stripe Tax is not available on that account, the setting stays off and the operator's review screen says "tax not applied" so it is never
silent.

Found (NA24): tax is estimated and stored but not applied to the Stripe subscription. Plan:
- Add a Processor setting `automatic_tax` (default **off**). When on, the processor creation code sends `automatic_tax[enabled]=true` and makes sure the Stripe customer carries the member's address
  (a small `PayerService::updateAddress` beside the existing `updateEmail`), because Stripe Tax needs it.
- It is turned on for the Brain's Stripe processor (above). **Because the setting belongs to the processor, it applies to every new subscription on it, including the public signup** — which today charges no tax. The owner has
  accepted that (OQ-N21): signups on the Brain's Stripe processor are taxed from the day it is switched on.
- P0 proves, in the development environment with a Stripe **test** account, that Stripe Tax is enabled and that the tax line appears on the invoice Direct Payments records.

## 7. The welcome e-mail and the free period

The `member_created` Activity (11) carries the facts `free_period_end` (a date) and `billing_mode`; the variable builder reads them from the member's subscription/option and adds `memberships_free_period` to the template:
"Your {period} free period runs until {date}." Nothing secret, no amounts.

## 8. Phases

| Phase | Work | Proof |
|---|---|---|
| **P0** | In the `development` environment with Stripe **test** keys, prove: a deferred start with no card and `pause` (and what the dashboard shows), a trial with a card (SetupIntent), Stripe Tax on the invoice, `resume`, the SDK card flow driven by a payer token. Record what happened. (The `auth` reset link is done and tested in [11](11-new-member-welcome-and-alerts.md).) | a written record; no production change |
| **P1** | `paused` support (NS11) and processor `pause`/tax options (NS10, NS12) in the generic layer, with the **existing subscription tests unedited**. | existing tests green; new tests per behaviour |
| **P2** | Start options (NS9) and the guard that a public caller cannot set one. | tests |
| **P3** | Billing session, card page, complete (NS6, NS7). | tests with `Http::fake`; session single-use, expiry, binding |
| **P4** | `subscription/start` (NS8) for no-card free periods and re-runs; the free-period dates. | tests for each period incl. month-end |
| **P5** | Create member (NS4, NS5) delegating from `adminStore`; capabilities. | 11's tests plus billing-mode tests |
| **P6** | Level guard (NS13). | the one-level-99 test; save of level 99 for another username refused |
| **P7** | Cancel/change adjustments; audit. | tests |
| **P8** | Docs, the completion record `complete/13-staff-member-lifecycle-server.complete.md`, the marker in `plans/markers.md`. | — |

## 9. Risks

- **Real money** and a **behaviour change to live signups** (tax, `paused`). Everything is rehearsed in the `development` environment with Stripe test keys; production gets a first member charged by hand, watched. Idempotency keys throughout.
- **The generic Direct Payments and processor changes (NS9–NS12) touch the paths real customers use.** P1 and P2 are guarded by the existing tests staying green and unedited.
- **A paused member loses the paid plan after 7 days** (by design); the restore path is the existing one.
- **The one-hour payer token** is a bearer for that payer's saved cards; it is session-bound, short, and cleared by the page.

## 10. Questions and the owner's answers (2026-10-20)

| # | Question | Answer |
|---|---|---|
| **OQ-N12** | No card when a free period ends | **Pause** (NM16, §5) |
| **OQ-N13** | Tier active during the free period | **Yes, immediately** (NM13) |
| **OQ-N14** | Complimentary mode | **Yes** (NM12) |
| **OQ-N15** | Tax on staff-created subscriptions | **Yes** (§6) |
| **OQ-N16** | Free period and end date in the welcome | **Yes** (§7) |
| **OQ-N17** | Which levels exist | **None selectable; exactly one level-99 member** (NM15) |
| **OQ-N21** | Turning tax on for the Brain's Stripe processor also taxes public signups | **Yes, use tax wherever Stripe Tax can be used** (§6) |
| **OQ-N22** | A paused member drops to tier `0` at once, or after a grace period | **7-day grace** (§5) |

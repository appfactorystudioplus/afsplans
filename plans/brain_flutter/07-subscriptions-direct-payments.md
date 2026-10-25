# 07 — Controlling subscriptions through Direct Payments

## Completion document

Built 2026-10-05 (P5b deliberately not built; device and server verification pending): [complete/07-subscriptions-direct-payments.complete.md](complete/07-subscriptions-direct-payments.complete.md).

Phases: read **P4**; actions **P5**; pricing wrappers (optional) **P5b**. Server contracts: [04](04-server-additions.md) BS6, BS8, BS9.

> "I want to be able to control the members subscriptions using the direct payments implementation."

## 7.1 Three layers, three owners — the model the screens must keep apart

A member's "plan" is stored in **three different places** that can legitimately disagree. The app shows all three and
never merges them.

```
 brain_memberships.membership        ← what the member is ENTITLED to today          (0 Free … 4 Enterprise)
        ▲   written by: the admin tier endpoint (tier_source=manual),
        │   by ApplyTierOnPlanChange when a subscription's plan changes,
        │   by billing:enforce-membership when it downgrades for non-payment
        │
 laravel_direct_payment_subscriptions (the "link row")   ← what the member PAYS FOR (a tier of a Direct Payment)
        │   direct_payment_tier_reference_num → laravel_direct_payment_tiers.level
        │   payer_reference_num → the payer;  processor_reference_num → the account it is billed on
        ▼
 laravel_processor_subscriptions     ← what the PROVIDER is doing (Stripe/Square/PayPal): status, next charge, amount
        ▲   kept current by webhooks and SubscriptionSyncService
        │
 brain_membership_billing_state      ← where the member is on the DUNNING ladder: current → past_due → downgraded
            tier_paid vs tier_effective, downgrade_after
```

`drift` ([04](04-server-additions.md) §4.8.3) is the app's name for "entitled ≠ paid for". It is **not an error**: a manual grant
(`tier_source = 'manual'`) is supposed to differ. The app labels it ("Plan set manually — differs from what they pay for")
instead of flagging red, and flags red only when `tier_source` is **not** manual and the two differ.

Because a Direct Payment can be **swapped to another processor** (`direct_payments/03-the-swap`), a member's subscription lives on
**its own** processor, not necessarily the Direct Payment's current one. The app always shows `processor_name` and
`provider` from the link and never assumes the DP's current processor ([04](04-server-additions.md) BS6 resolves it per subscription).

## 7.2 What "control" includes

| Capability | Mechanism | Phase | Included |
|------------|-----------|-------|:--------:|
| See subscription state, next charge, status, plan | `GET …/subscription` | P4 | ✅ |
| See dunning state and when a downgrade will happen | `billing_state` in the same response; Billing tab | P4 | ✅ |
| See charges and refunds | `GET …/payments` | P4 | ✅ |
| **Refresh from the provider** | `POST …/subscription/sync` | P5 | ✅ |
| **Cancel** the subscription | `POST …/subscription/cancel` | P5 | ✅ |
| **Change the plan the member pays for** | `GET …/subscription/plans` + `POST …/subscription/change-tier` | P5 | ✅ |
| **Refund** a charge (full or partial) | existing `POST /money/charges/{id}/refund` | P5 | ✅ |
| Set the **entitled** plan directly (no billing effect) | existing `PUT /memberships/{ref}/tier` ([06](06-members-feature.md) §6.3.2) | P2 | ✅ |
| Apply a discount code / change billing amount / change billing date | BS11 wrappers | P5b | ⏸ optional |
| Create a subscription, add/replace a card | needs the member's payment authentication | — | ❌ |
| Re-point a member to another processor / DP swap | `direct_payments` swap tooling has its own audit | — | ❌ |
| Run or re-run the dunning enforcement | a scheduled server task | — | ❌ (visibility only) |

## 7.3 The Subscription tab

Source: `GET /portal/api/v1/brain/staff/members/{ref}/subscription`. It must render **every** state without an error screen,
because real data has holes ([BA12](01-current-state-audit.md)):

| `resolution.path` / state | What the tab shows |
|---------------------------|--------------------|
| `none` | Empty state: "No subscription found for this member." Explains what was checked ("no payer", "payer has no customer", "no subscription for the customer") from `resolution`, and — if `member.membership > 0` — "Plan was set manually or before billing existed." No action buttons except the manual **Change plan** ([06](06-members-feature.md) §6.3.2) |
| `governing` / `payer_link` | Normal card |
| `payer_customer` (+ warnings) | Normal card **plus** a yellow note: "Found through the payer's customer; the link row has no payer reference." Actions are enabled |
| `status = incomplete` | Card with "Checkout was never completed at the provider" — no cancel/change (the server's `allowed_actions` omits them); **Sync** is offered |
| `status = active/trialing` | Normal card |
| `status = past_due` | Red banner "Payment failed" + the dunning block (below) |
| `canceled_at` set | Grey card: "Cancelled <date>. Access ends <access_ends_at>." No cancel button |
| `cancel_at_period_end` | "Ends at period end <date>" |
| `last_sync_error` set | Amber: "Last sync failed: …" + Sync button |

The normal card: plan name and tier level; status chip; **next charge** (date, amount, currency); provider chip + a
**SANDBOX / LIVE** chip from the processor's mode (the link carries `processor_mode`, [04](04-server-additions.md) §4.8.3); the
processor name; the provider subscription id (copyable); "Last synced <time>".

The **dunning block** (when `billing_state` exists): state chip (`current` / `past_due` / `downgraded`), `past_due_since`,
`downgrade_after` with "in N days", `tier_paid → tier_effective`, `last_notified_step`. When `billing_state` is null the block
says "Not on the dunning ladder." When the server reports `enforcement.enabled = false` (BS9) the block adds "Enforcement is
off on this server — states will not change on their own" (it is off in lab2).

Buttons at the bottom, driven by `allowed_actions`: **Sync now**, **Change plan**, **Cancel subscription**.

## 7.4 Cancel

`POST …/subscription/cancel { reason }`.

1. Confirm sheet: "Cancel **Pro** for **james1** at Stripe? This is immediate at the provider and cannot be undone from here."
2. Shows the **policy outcome** the server will apply: access ends *immediately* or *at the end of the paid period (<date>)* —
   read from `access_ends_policy` in the detail (the Direct Payment's `cancellationAccessEnds` setting). The app does not decide this.
3. **Reason** required (3–500).
4. On `production`: type the member's username to enable the button.
5. Call; the button shows progress for up to the ×3 timeout. The response is the refreshed detail; the card re-renders
   as cancelled. **A `2xx` means the provider accepted the request**, not that every downstream effect has happened — the
   webhook still dispatches `subscription_canceled` (so the app says "Cancelled — the member's notification email is sent
   by the webhook"), exactly the rule `14-subscription-actions` §14.3 sets.
6. Timeout / unreachable → `unknownOutcome` ([05](05-app-architecture.md) §5.6): re-`GET` the subscription; if `canceled_at` is now set,
   report success; if not, report failure and re-offer. **Never auto-retry.**
7. Cancel is **idempotent** server-side; a second tap returns the same state.

Cancelling does **not** change `brain_memberships.membership`. The member keeps their entitlement until access ends and the
dunning/enforcement path or an operator changes it. The sheet says so, and offers a shortcut to **Set plan directly** for the case
where the operator wants the entitlement dropped now.

## 7.5 Change plan (billing) vs. set plan (entitlement)

Two buttons that sound alike do different things; the UI names them differently and shows the difference.

| | **Change plan** (Subscription tab) | **Set plan directly** (Details tab) |
|-|-----------------------------------|-------------------------------------|
| Request | `POST …/subscription/change-tier` | `PUT /memberships/{ref}/tier` |
| Provider | **Changes the subscription at Stripe/Square/PayPal** (price changes, proration) | Not touched |
| `brain_memberships.membership` | Updated by the `SubscriptionPlanChanged` listener | Updated; `tier_source = manual` |
| Audit event | `staff_subscription_tier_changed` | `admin_tier_change` |
| Shown when | A subscription exists and `allowed_actions` has `change_tier` | Always (except for the acting member) |

**Change plan flow:** fetch `GET …/subscription/plans` → list the tiers (name, level, price/interval; the current one disabled;
non-selectable ones greyed with the server's reason, "not priced on this processor") → pick → show the **proration** choice
(`create_prorations` default; `none`) under "Advanced" → **Reason** (3–500) → confirm → call. The success response may carry
`warning` (the membership-column write failed *after* the provider was charged — `SubscriptionPlanChanged::$warning`); the app
shows it prominently as "Plan changed at the provider, but the member's entitlement could not be updated — use *Set plan directly*"
because the provider has already moved and the state must be reconciled by hand.

PayPal cannot change plans (`PlanChangeNotSupportedException`); the server omits `change_tier` for it, so the button is absent, not
broken.

## 7.6 The Payments tab and refunds

Source: `GET …/payments` (BS8). Rows: amount, status chip, date, provider, "Refunded $x" when partial. Tapping a row opens a charge
sheet: ids (copyable), gross / refunded / net, a **Refund** button when `status = succeeded` and `refundable_cents > 0`.

**Refund:** `POST /portal/api/v1/money/charges/{id}/refund { amount_cents?, reason? }` — empty amount = full refund.

- The amount field is validated `0 < amount ≤ refundable_cents` before sending (the web does the same check).
- **Reason** is required by the app (the server accepts it optionally; refunds are money movement, so the app is stricter).
- Production: typed confirmation of the amount ("type 15.00").
- Success → the ledger row (`data.charge`) replaces the row; failures show the server's `message` (a `400` with the provider's error).
- Timeout → `unknownOutcome`: re-fetch payments and compare `refunded_cents` before offering a retry — the one place a blind retry
  would double-refund.
- Refunds go through `RefundService`, the same path the browser's Financials → Money screen uses; the app does **not** use the
  unauthenticated `processors/billing/refund`.

A **Ledger** view (all charges, filter by status/provider/date/Direct Payment) uses `GET /money/ledger` and lives under the Billing tab
([10](10-ui-and-navigation.md) §10.5). It has no per-member filter ([BA11](01-current-state-audit.md)); the per-member list is the Payments tab.

## 7.7 The Billing tab (overview)

Source: `GET …/billing/overview` (BS9): enforcement on/off and its settings, counts per state, and the "needs attention" list
(non-`current` members by `downgrade_after`). Tapping an entry opens that member's Subscription tab. Pull-to-refresh. In lab2 it
shows "Enforcement off · 0 past due" (verified: `brain_billing_settings.enabled = 0`, `brain_membership_billing_state` empty).

## 7.8 Optional P5b — pricing wrappers

If wanted later, `subscription_pricing` capability (BS11) adds **Apply discount**, **Change amount**, **Change billing date** to the
Subscription tab, each with a reason, each audited, each resolving processor and provider customer **server-side** from the member
(the web version sent them from the client). Held back because changing a recurring amount silently re-prices a paying customer and
the browser's version of these forms never worked ([BA8](01-current-state-audit.md)), so there is no proven behaviour to mirror.

## 7.9 Safety rails (all subscription writes)

1. `portal.level99` + `brain.only` server-side; the app additionally hides everything when `capabilities.subscription_staff` is false.
2. **Reason required** on cancel, change plan, refund (and every set-plan/override elsewhere).
3. **Typed confirmation on production** for cancel and refund; plain confirm on lab.
4. **Self-action refused** by the server (`BS_E_SELF_ACTION`) and hidden by the app.
5. **One in-flight write at a time** per member (the Cubit ignores a second tap; the button is disabled).
6. **No client retries** of writes; ambiguous outcomes are resolved by re-reading.
7. **Sandbox/Live chip** on every subscription and charge, so a lab sandbox action never looks like a live one.
8. Every write appears in the Activity tab with `source admin_mobile`, the device label, the actor and the reason.

## 7.10 Verifying it in the lab (what is and is not possible)

lab2's processors are all `sandbox`; there are **0 charges and 0 refunds**; the only subscription is a Stripe `incomplete` one whose
link row has no payer. So:

- P4 can be verified immediately against real data: `james1`/`james2` resolve to **`none`** or **`payer_customer`** and must render
  without error; `appfactorystudio` has no subscription.
- P5 needs a **completed sandbox subscription**: create one by running the members checkout in the lab with a Stripe **test** card
  (done by you in a browser, since it types card data), then use it for sync / change plan / cancel / partial and full refund.
  Before any provider-calling test, confirm the processor is `sandbox` (the app's chip shows it).
- Provider-level behaviour is covered in server tests with the `SubscriptionActionsProvider` seam faked; the app's tests use fixtures
  captured from those sandbox responses ([12](12-rollout-and-testing.md) §12.4). No test in this plan calls a live provider.

## 7.11 Acceptance for this file

- Every `resolution.path` and status in §7.3 renders from a fixture without an error screen.
- Cancel / change plan / refund each: refuse without a reason; send nothing on a cancelled dialog; show the server's refreshed state;
  are blocked for the acting member; and an induced timeout results in a re-read, never a second request.
- A cancelled subscription's card shows `canceled_at` and `access_ends_at` and hides Cancel; `brain_memberships.membership` is unchanged by cancel.
- A change-plan response carrying `warning` is shown, not swallowed.
- The app never issues a request to any `processors/billing/*` path (a test asserts the client's path table contains none).

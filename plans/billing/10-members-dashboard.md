# 10 — The Members Billing Dashboard

`members/resources/js/controllers/BillingApp.ts` — the member-facing surface for everything
[22](../www/direct_payments/22-subscription-state.md)–[29](../www/direct_payments/29-money-and-refunds.md) builds on the server.

## 10.1 What it does today

`BillingApp` is 1342 lines rendering four sections — subscription, payment methods, services,
purchase history — plus a plan-change modal and a cancel button. Structurally it is close to
finished. Behaviourally, **the two most consequential actions are fake.**

| # | Finding | Consequence |
|---|---|---|
| **B18** | Lines 278/343: `nextChargeDate: member.next_charge_date \|\| member.current_period_end` — neither column exists on `brain_memberships` | Always `undefined`; the next-charge line has never rendered |
| **B20** | The subscription is *inferred* from invoice history via `findActiveSubscriptionMatch()`, with a `member.membership_id` fallback and a `console.warn` when both miss | No query reads the member's actual subscription — there was no column to key on before [22](../www/direct_payments/22-subscription-state.md) §4.2.2 |
| **B27** | `cancelSubscription()` makes **no network call** — writes `localStorage`, toasts *"Subscription cancelled."* | The provider is never told; the member keeps being charged ([28](../www/direct_payments/28-subscription-actions.md)) |
| **B37** | **`selectPlan()` makes no network call either.** It sets `this.currentSubscription = plan`, writes `localStorage`, and toasts *"Successfully switched to the {plan} plan"*. Its own comment admits it: *"In a real app we'd charge for pro-rated amount or next cycle — Here we just note it"* | A member can "upgrade" to Enterprise, see it confirmed, be charged nothing and receive nothing. On another device they are back on their real plan |

**B27 and B37 share one root cause: `localStorage` is treated as billing state.**
`saveCurrentSubscription()` writes it and `loadCurrentSubscription()` reads it back on init,
so the lie survives reloads and is convincing. Every fix below depends on deleting that pair
outright — not on making the two actions call the server while the cache remains.

## 10.2 The one architectural rule

**Every action goes through `dp.subscription.*`** ([28](../www/direct_payments/28-subscription-actions.md)). The
dashboard never calls `/portal/api/v1/processors/*` directly, and never names a Stripe price,
a PayPal plan, or a provider at all.

That is not tidiness. It is what lets this screen survive
[27](../www/direct_payments/27-provider-retry-policy.md)'s three-way provider divergence:
`dp.subscription.cancel()` behaves identically on Stripe, PayPal and Square because the
encapsulation absorbed the differences. **The moment `BillingApp` branches on `provider`, the
normalisation upstream is incomplete** — fix it there, not here.

```ts
const dp = window.Portal.directPayments.create(directPaymentRef);
await dp.initializeDirectPayment();

await dp.subscription.get();                            // §10.4
await dp.subscription.update({ tier_ref });             // §10.6
await dp.subscription.cancel();                         // §10.7
await dp.subscription.setPaymentMethod({ method_id });  // §10.8
```

## 10.3 Information architecture

| Section | Shows | Actions |
|---|---|---|
| **Status banner** | Dunning or cancellation state, when either applies | Update payment method / Reactivate |
| **Current subscription** | Plan, price, next charge **or** access-ends date, payment method | Change plan, Cancel |
| **Plan grid** | The Direct Payment's own `laravel_direct_payment_tiers` | Select |
| **Payment methods** | Saved **cards** | Add, set default, remove |
| **Billing history** | Charges **and refunds** | View receipt |

The demo `initializeServices()` block (**B19**) is **removed** in this pass rather than left
flagged. It renders four hardcoded fake products ("Premium Support", $49.99) beside real
billing data, and the entire point of this document is that a member can trust what this
screen says.

## 10.4 Reading the subscription

Replaces the inference (**B20**) and the dead `member.next_charge_date` read (**B18**):

```ts
/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/billing/10-members-dashboard.md §10.4.
 *
 * Reads the member's subscription from the server instead of inferring it from
 * invoice history. The previous path existed because nothing linked a Direct
 * Payment or a member to a subscription (plans/www/direct_payments/09 B1/B2/B20);
 * laravel_direct_payment_subscriptions now does (plans/www/direct_payments/11 §4.2.2).
 *
 * next_charge_at is NORMALISED across Stripe, Square and PayPal
 * (plans/www/direct_payments/11 §4.3). Do not branch on provider here — §10.2.
 */
const sub = await dp.subscription.get();
```

`findActiveSubscriptionMatch()` stays as a **fallback only**, behind a comment naming it the
pre-link-table path, deletable once `direct_payments:backfill-subscriptions`
([22](../www/direct_payments/22-subscription-state.md) §4.7) reports zero unlinked subscriptions. Removing it in the
same pass would break any member whose backfill was ambiguous.

**`loadCurrentSubscription()` and `saveCurrentSubscription()` are deleted**, and a stale
`localStorage.currentSubscription` from a previous build is cleared on init rather than read.

## 10.5 The subscription card

```
┌──────────────────────────────────────────────────────────────┐
│  Professional                                    $49.99/mo   │
│                                                              │
│  Next charge   12 September 2026 · $49.99 USD                │
│  Payment       Visa •••• 4242                                │
│                                                              │
│  You'll get an email 7, 3 and 1 days before each renewal.    │
│                                                              │
│  [ Change plan ]  [ Update payment method ]  [ Cancel ]      │
└──────────────────────────────────────────────────────────────┘
```

Three states, three different truths:

| State | Date line | Source |
|---|---|---|
| Active | **Next charge** | `next_charge_at` |
| Cancelled through this app | **Features active until** | `access_ends_at` ([28](../www/direct_payments/28-subscription-actions.md) §14.3) |
| Cancelled at the provider, out of band | **Ends** | `next_charge_at`, with `cancel_at_period_end` true |

The middle row is the one a naive implementation gets wrong. After cancelling, billing has
already stopped and the provider has very likely nulled `next_charge_at`; the date the member
cares about is `access_ends_at`, which is ours.

The notice line comes from `dp.notifications.get('upcoming_charge')`
([25](../www/direct_payments/25-direct-payments-api.md) §7.2) and is **omitted entirely** when no config exists —
never rendered as "0 days before", and never as a promise the deployment has not configured.

## 10.6 Changing plan

Replaces the fake `selectPlan()` (**B37**) with `dp.subscription.update({ tier_ref })`
([28](../www/direct_payments/28-subscription-actions.md) §14.4).

The plan grid is built from the Direct Payment's own tiers, so a member is only ever offered
something that DP actually sells. `tier_ref` is a `laravel_direct_payment_tiers.reference_num`
— the dashboard never names a provider price.

**The confirm dialog must say what happens to their money**, which today's does not:

```
Switch to Business — $99.99/mo

  You'll be charged a prorated amount today for the rest of
  this billing period. From 12 September, $99.99/mo.

  [ Cancel ]  [ Confirm change ]
```

Proration is the provider's arithmetic ([00](00-overview.md) §3), so the dialog states the
*shape* of the outcome rather than a number the server has not confirmed. Where the provider
returns a preview, show the amount; where it does not, say "a prorated amount" and never
invent one.

On success, render from the **returned subscription**, not from the tier the member clicked.
`dp.subscription.update()` returns the refreshed normalised subscription for exactly this
reason.

A downgrade that reduces allocations should say so — *"Business includes 10 websites; you
currently have 12."* `members` can compute that (it owns storage and reads the registry), and
a member discovering it afterwards is a support ticket.

## 10.7 Cancelling

`dp.subscription.cancel()`. The dialog reflects the **D16** model — billing stops now, access
continues:

```
Cancel your Professional subscription?

  Billing stops immediately — you won't be charged again.
  Your Professional features stay active until 12 September 2026.

  [ Keep subscription ]  [ Cancel subscription ]
```

Under `cancellation_access_ends = immediately` ([26](../www/direct_payments/26-advanced-policy.md) §12.3)
the second line becomes *"Your Professional features end immediately."* The dialog **reads the
resolved policy** rather than assuming `period_end` — the two say opposite things to the
member, and guessing wrong is the difference between a clean cancellation and a refund
request.

**No success toast before the server confirms.** Today's toast is unconditional; the
replacement renders only what `cancel()` returned and shows an error on failure (**B27**).

## 10.8 Payment methods — cards only

Add, set default, remove. Unlike cancel and plan change these are **real** — the handlers
already call `Portal.processors.updatePaymentMethod` / `deletePaymentMethod` — but they are
wired to the endpoints [28](../www/direct_payments/28-subscription-actions.md) §14.5 fixes (**B30**, **B31**). Point
them at `dp.subscription.setPaymentMethod()` so "set default" changes the field Stripe
actually retries against.

Three member-visible consequences of [28](../www/direct_payments/28-subscription-actions.md) §14.6:

- The add-card element mounts with **card only**. A member is never offered a bank account or
  wallet that would be refused after they filled it in.
- On PayPal, "change payment method" is **not a form**. It links out to PayPal, because the
  funding instrument lives there (`DP_E_PM_CHANGE_NOT_SUPPORTED`).
- Removing the card a live subscription depends on **warns first**, naming the subscription.

## 10.9 Billing history — including refunds

Today's "purchase history" renders Direct Payment invoices. It must also show **refunds**
([29](../www/direct_payments/29-money-and-refunds.md) §15.2):

| Column | Notes |
|---|---|
| Date | |
| Description | Plan name, or the item for a single payment |
| Amount | **Net**, with the refunded amount shown when one exists |
| Status | `paid` \| `refunded` \| `partially refunded` \| `failed` |

Backed by `dp.subscription.history()` ([25](../www/direct_payments/25-direct-payments-api.md) §7.3.2) — the
member-scoped read of the same data [29](../www/direct_payments/29-money-and-refunds.md) §15.5 gives operators,
filtered **server-side** to the caller's own charges and stripped of operator-facing
fields.

A member who was refunded and sees only the original charge will contact support to ask
where their money went.

Receipts link to the provider's own hosted receipt where one exists rather than being
re-rendered here.

## 10.10 The dunning banner

Driven by `billing_state` from `dp.subscription.get()` ([25](../www/direct_payments/25-direct-payments-api.md) §7.3),
returned only when the resolved caller is the member themselves.

| State | Banner |
|---|---|
| `current` | none |
| `past_due` | ⚠ **amber** — "We couldn't charge your card on 25 August. Please update your payment method — your plan drops to Free on 8 September (14 days)." + `[ Update payment method ]` |
| `downgraded` | 🔴 **red** — "Your plan was changed to Free on 8 September because a payment couldn't be completed. Your storage buckets 1–3 are read-only and will be deleted on 8 October." + `[ Reactivate ]` |

Three rules, in priority over everything else on the page:

1. **State the date and the consequence.** "Payment problem" tells a member nothing. The
   `downgraded` copy names the storage deadline because that is the irreversible one, and
   `members` can compute the real date from `BucketUsage.purge_after` rather than restating a
   policy constant.
2. **Dismissible for the session, never permanently.** A member who dismisses and forgets
   loses data.
3. **The CTA goes to the payment-method flow already in this file**, not a new screen. The
   fastest path from "your card failed" to "here is a new card" is the entire point.

The `downgraded` banner's storage sentence renders **only** when the member actually has
retired buckets. Threatening deletion of files that do not exist destroys trust in every other
message the system sends.

## 10.11 What the member cannot do

Worth stating, because the dashboard is where someone will eventually try to add them:

| Not here | Where |
|---|---|
| Issue a refund | Operator-only ([29](../www/direct_payments/29-money-and-refunds.md) §15.4). A member requests; a human decides |
| See or set the grace period | Operator-only ([26](../www/direct_payments/26-advanced-policy.md)) |
| Configure notification schedules | Operator-only ([23](../www/direct_payments/23-notification-config.md)); the member sees the schedule read-only (§10.5) |
| Change their tier directly | Only by changing subscription — never by writing `membership` |

The last row is the one to guard. `brain_memberships.membership` is written by
[08](08-brain-enforcement.md) and by nothing else. A members-side "change my plan" that touched
it directly would create a second writer and desynchronise the tier from what the member is
actually paying for.

## 10.12 Notification preferences — deliberately not here

No per-member "email me N days before" control. The schedule is the operator's
(`laravel_direct_payment_notifications`), and a member-facing override needs a preferences
surface, a storage location and an opt-out honoured by
`NotificationDispatcher::resolveRecipients()` ([24](../www/direct_payments/24-notification-scheduler.md) §6.10). All
three are absent. Showing a read-only "you'll be emailed 7, 3 and 1 days before" line is
honest; showing a control that does not persist is not.

## 10.13 What is not touched

- The payment-method modal chrome, the plan-grid layout and the toast helper.
- No change to `members`' server side beyond consuming [25](../www/direct_payments/25-direct-payments-api.md) §7.3 —
  `QuotaService`, `RetentionService` and the three `storage:*` sweeps keep reading
  `brain_memberships.membership` directly, which after [08](08-brain-enforcement.md) is
  finally correct.
- Typing. `members/resources/js/checkpointHelper/static/portal.d.ts` — its **own copy** of
  the externals declaration ([25](../www/direct_payments/25-direct-payments-api.md) §7.5) — is refreshed so this
  file's `(window as any).Portal` casts become real typed calls. Worth doing in the same
  pass, but a typing change, not a behavioural one.

  **`BillingApp.ts` never imports from `www/resources/js/external/`.** It reaches the SDK
  only through `window.Portal.*` at runtime and the copied `.d.ts` at compile time. An
  `import` from the externals source would bundle a second copy of the SDK into `members`.

## 10.14 Acceptance

- **No action renders an outcome the server did not return.** Cancel and plan change with the
  network offline both show errors, not success toasts (**B27**, **B37**).
- `localStorage.currentSubscription` is **gone**, and a stale entry from a previous build is
  cleared rather than read.
- A member with an active Stripe subscription sees a real next-charge date and amount; the
  same member on Square and then PayPal sees the same card with **no provider-specific code**
  in `BillingApp.ts`.
- A cancelled member sees **"Features active until {access_ends_at}"** — not a charge date and
  not a blank.
- Under `cancellation_access_ends = immediately`, the cancel dialog says so before confirming.
- Changing plan issues the call, renders the **returned** subscription, and the member's tier
  resolves from the new tier on the next `billing:enforce-membership --dry-run`.
- A downgrade that reduces allocations warns with the member's actual usage.
- The add-card element offers **only** cards; PayPal links out rather than showing a form.
- Removing a card a live subscription depends on warns and names it.
- A refunded charge appears in billing history as refunded, with the net amount.
- `past_due` renders the amber banner with the correct `downgrade_after`; `downgraded` renders
  the storage sentence **only** when retired buckets exist.
- A member with no subscription sees no card and no banner — not an error, not a spinner.
- The demo services block is gone (**B19**).

# 14 — Subscription Lifecycle Actions

Cancel, update, and change the payment method — encapsulated in the Direct Payments API,
restricted to cards, and each one able to send an operator-configured email.

## 14.1 What exists today

Nothing. Not partially — **nothing**.

The five findings are **B27–B31**, defined in [20](20-current-state-audit.md) §2.5b
alongside every other finding in this plan. In summary: cancel is a `localStorage` write that
never reaches the provider (**B27**); there is no cancel endpoint to call anyway (**B28**);
there is no plan or tier change (**B29**); `updatePaymentMethod()` silently no-ops on Square
and PayPal while returning `success: true` (**B30**); and on Stripe it updates the customer's
default payment method rather than the subscription's — the field retries actually use
(**B31**).

**B27 should be fixed first, ahead of everything else in this plan.** It is not a missing
feature; it is the application telling members a falsehood about their own money.

## 14.2 The encapsulation

All three actions are reached **only** through the Direct Payments API. The processor layer
gains the provider mechanics and stays DP-agnostic ([22](22-subscription-state.md) §4.2);
the DP layer resolves which subscription is being acted on and owns the public surface.

```
dp.subscription.cancel() / .update() / .setPaymentMethod()
        │
        ▼
POST /portal/api/v1/directpayments/{dpRef}/subscription/{action}
        │   resolve DP → laravel_direct_payment_subscriptions → provider_subscription_id
        ▼
processor layer — provider mechanics, no knowledge of the DP
        │
        ▼   confirmed 2xx, or provider webhook
NotificationDispatcher → BillingMailer      (§14.7)
```

Every action returns the **refreshed, normalised** subscription
([22](22-subscription-state.md) §4.3) rather than a bare `{success: true}`, so a caller
never has to re-fetch to find out what actually happened — and so a client can never again
render an outcome the server did not confirm (**B27**).

## 14.3 Cancel — the provider stops the money, the application ends the access

```js
await dp.subscription.cancel();
```

No `mode` argument. **Cancellation always takes effect immediately at the provider**, on all
three, and when the member's *benefits* end is a Direct Payments configuration
([26](26-advanced-policy.md) §12.3) rather than something the provider is asked to
model.

### Why the two are separate

The provider controls **billing**. It does not control **features** — this application does.
Conflating them is what made the earlier draft of this section awkward: it tried to express
"keep access until the period ends" as `cancel_at_period_end` on the provider, which Stripe
supports, Square supports differently, and PayPal does not support at all. That asymmetry was
never really about entitlement; it was about three billing systems having three opinions on a
question that is not theirs.

Splitting them removes the problem rather than working around it:

| | Who decides | When |
|---|---|---|
| **Billing stops** | The provider | **Immediately**, uniformly, on cancel |
| **Access ends** | This application | Per the Direct Payment's `cancellation_access_ends` policy |

Three consequences worth stating:

- **PayPal's missing period-end cancel stops mattering.** Immediate is the one thing all
  three do the same way. The previous draft's choice between emulating a scheduled cancel and
  telling PayPal members something different is gone — there is nothing to emulate.
- **No scheduled provider call has to fire months later.** The earlier "emulate" option
  needed a job that must run exactly once, long after the fact, on a task that has never had
  to do that. Now the only thing that survives the cancel is a **date in our own database**,
  read by a sweep that already runs every fifteen minutes.
- **A member's remaining access no longer depends on a webhook arriving.** It is a column.

### What happens on cancel

1. Cancel at the provider, immediately — Stripe `DELETE /v1/subscriptions/{id}`, PayPal
   `POST /v1/billing/subscriptions/{id}/cancel`, Square
   `POST /v2/subscriptions/{id}/cancel`. *(Verify each against current provider docs; the
   retry semantics in [27](27-provider-retry-policy.md) §13.2 were verified, these were
   not.)*
2. On confirmation, write to `laravel_direct_payment_subscriptions`
   ([22](22-subscription-state.md) §4.2.2):
   - `canceled_at = now()`
   - `access_ends_at` = per policy — `now()` for `immediately`, else the
     `next_charge_at` that was already on the processor row when the cancel was issued
3. Fire `subscription_canceled` (§14.7), with `access_ends_at` in the template variables so
   the email can say *"your access continues until 12 September"* rather than something
   vague.
4. `billing:enforce-membership` (in `members`, applied by `www`) drops the tier when `access_ends_at` passes
   ([08](../../billing/08-brain-enforcement.md) §8.4.2) and fires `subscription_access_ended`.

**Read `next_charge_at` before cancelling, not after.** Cancelling at the provider is very
likely to null or move it, and the paid-through date is the whole basis of the member's
remaining entitlement. Capture it first, in the same request.

### `access_ends_at` is a floor, not a ceiling

If the member resubscribes before `access_ends_at`, the new subscription's own tier takes
over and the old row's date stops mattering — enforcement resolves `tier_paid` from the
active subscription ([08](../../billing/08-brain-enforcement.md) §8.3), and a canceled row is not one.
Nothing has to reconcile the two.

### The `immediately` policy takes money for time not given

A Direct Payment configured with `cancellation_access_ends = immediately` ends access the
moment a member cancels — while the provider has already collected the current period. That
is defensible for some products and indefensible for a monthly membership, and this plan does
**not** silently pair it with a refund: refunds are a stated non-goal
([00](../../billing/00-overview.md) §3), and `ProcessorBillingController::refund()` already exists as a
separate operator action.

So the admin UI warns on selecting `immediately`, naming the consequence, and `period_end` is
the default. An operator who wants immediate revocation *and* a refund has to perform the
refund deliberately — which is the right amount of friction for giving money back.

## 14.4 Update

```js
await dp.subscription.update({ tier_ref: 'tier_abc', proration: 'create_prorations' });
```

`tier_ref` is a `laravel_direct_payment_tiers.reference_num` — a **Direct Payments** tier,
not a provider price id. The DP layer resolves it to the tier's `processor_tier_id` and
hands *that* down. A caller never names a Stripe price or a PayPal plan.

| Provider | Mechanism |
|---|---|
| **Stripe** | `POST /v1/subscriptions/{id}` — replace `items[0][price]`, with `proration_behavior` ∈ `create_prorations` \| `none` \| `always_invoice` |
| **PayPal** | `POST /v1/billing/subscriptions/{id}/revise` with the new `plan_id` |
| **Square** | Plan swap support is limited; may require cancel-and-recreate. **Verify** — and if it does require that, `update()` must either refuse on Square with a clear code or perform the two steps atomically enough that a failure cannot leave the member with nothing |

On success the DP layer updates `laravel_direct_payment_subscriptions.direct_payment_tier_reference_num`
— the column [08](../../billing/08-brain-enforcement.md) §8.3 step 1 reads to resolve `tier_paid`. **A plan
change that does not update that column silently leaves enforcement working from the old
tier**, which is exactly the kind of drift the column was introduced to prevent.

Proration is passed through, not decided here. [00](../../billing/00-overview.md) §3 lists proration
arithmetic as a non-goal, and that stands — we hand the provider a proration mode and let it
do the maths.

## 14.5 Change the payment method

```js
await dp.subscription.setPaymentMethod({ method_id: 'pm_123' });
```

Fixing **B30** and **B31**:

| Provider | What must happen |
|---|---|
| **Stripe** | Set **`subscription.default_payment_method`** — the field retries actually use — via `POST /v1/subscriptions/{id}`. Setting `customer.invoice_settings.default_payment_method` as well is fine and sensible for future subscriptions, but it is **not** a substitute (**B31**) |
| **Square** | `PUT /v2/subscriptions/{id}` with the new `card_id`. Not a no-op, not an acknowledgement |
| **PayPal** | **Not possible by API.** The funding instrument belongs to the payer's PayPal account and is changed by the payer at PayPal. The endpoint must return a specific code — `DP_E_PM_CHANGE_NOT_SUPPORTED` — and the UI must link the member to PayPal rather than showing a form that cannot work |

PayPal returning "unsupported" is the correct outcome and must be surfaced as such. The
current code's habit of returning `success: true` for providers it does not handle
(**B30**) is what this step exists to stop.

## 14.6 Cards only

Credit and debit, nothing else. Enforced at three layers, because any one alone leaks:

1. **Read** — `fetchStripePaymentMethods()` already queries `?type=card`; Square and PayPal
   need the equivalent filter so a non-card instrument never reaches the UI to be selected.
2. **Write** — before attaching or setting a method, fetch it and reject unless
   `type === 'card'`. Code `DP_E_PAYMENT_METHOD_NOT_CARD`. This is the layer that matters:
   the read filter only hides things, and `method_id` arrives from a client.
3. **Mount** — the payment element is created with card as the only payment method type, so
   the member is never offered an option that would be refused after they filled it in.

On Stripe, `type=card` covers **both credit and debit** — debit is a card with
`card.funding = 'debit'`. So the requirement is exactly `type=card`, and the things
deliberately excluded are `us_bank_account`, `sepa_debit`, `link`, `cashapp`, `paypal` and
every other non-card type.

**Do not filter on `card.funding`.** It reports `credit`, `debit`, `prepaid` or `unknown`,
and `unknown` is common on non-US cards. A funding-based filter would reject legitimate
cards in exactly the markets where the data is thinnest.

## 14.7 The action events

**No new table.** These reuse `laravel_direct_payment_notifications`
([23](23-notification-config.md) §5.2) — the same per-DP row, the same
`mail_handler_reference_num` dropdown ([23](23-notification-config.md) §5.5), the same
`recipient_mode`, the same send ledger, the same `NotificationDispatcher` → `BillingMailer`
path ([24](24-notification-scheduler.md) §6.7). `event` is `string(64)`, so **new values need
no migration.**

Added to the [23](23-notification-config.md) §5.3 vocabulary:

| Event | Fires from | Why there |
|---|---|---|
| `subscription_cancel_scheduled` | **Webhook** — `customer.subscription.updated` where `cancel_at_period_end` flipped false→true | The **out-of-band** case only: an operator cancelling at period end directly in the provider's dashboard. This application's own cancel never sets that flag (§14.3) |
| `subscription_access_ended` | **Scheduler** — `billing:enforce-membership` (`members`) when `access_ends_at` passes; the notice is sent by `www` ([08](../../billing/08-brain-enforcement.md) §8.4.2) | The tier drop is ours, so the confirmation is ours. No provider is involved |
| `subscription_canceled` *(exists)* | **Webhook** — `customer.subscription.deleted`, `BILLING.SUBSCRIPTION.CANCELLED`, Square's subscription update | The confirmed end, not the request |
| `subscription_updated` | **Webhook** — `customer.subscription.updated` where the item price changed | Same reason |
| `payment_method_changed` | **Controller**, after a confirmed 2xx | See below |

### The rule for where an event fires

**Dispatch from the first point where the provider has confirmed the outcome — and from
exactly one such point.**

For cancel and update that is the **webhook**: the API call returning 200 means the request
was accepted, not that the state changed the way we asked. A provider can alter or reject
it afterwards, and emailing "your subscription is cancelled" off a 200 that later did not
hold is precisely the class of lie **B27** already tells.

For **payment method change** there is no reliable webhook across all three: Stripe has
`payment_method.attached` and `customer.updated`, Square and PayPal are weaker or silent. So
that one dispatches from the controller after a confirmed 2xx, and the docblock says why —
otherwise the next reader "fixes" the inconsistency by moving it into a webhook that never
arrives on two providers.

Never both. The ledger's unique index would dedupe a double dispatch
([24](24-notification-scheduler.md) §6.5), but relying on the index to paper over a
two-source design is how the second source ends up being the only one that fires after a
refactor.

Webhook-sourced action events write `days_before = 0` and `period_end` = the event
timestamp truncated to the minute, exactly as [24](24-notification-scheduler.md) §6.5
specifies — which is also what deduplicates a webhook delivered twice.

## 14.8 Admin UI

No new panel. The notifications card from [23](23-notification-config.md) §5.7 gains the
four events in its **Event** dropdown, each with its own mail handler, recipients and active
switch. An operator configuring "email from `billing@` when someone cancels" uses the same
row shape they already use for the upcoming-charge notice.

`days_before` stays hidden for all four — these are action events, not scheduled ones
([23](23-notification-config.md) §5.3).

## 14.9 Migrations in this step

**None.** The events are new `string` values in an existing column, and the DP↔subscription
link table already carries `direct_payment_tier_reference_num`
([22](22-subscription-state.md) §4.2.2) for §14.4 to update.

That is worth noticing rather than glossing: a feature this size adding no schema is a sign
the notification mechanism in [23](23-notification-config.md)/[24](24-notification-scheduler.md)
was factored correctly. If a later action event does need a column, check first whether it is
really an event or a policy — policy belongs in [26](26-advanced-policy.md).

## 14.10 Acceptance

- **`BillingApp.cancelSubscription()` issues a network call**, and renders only what the
  server returned. Cancelling with the network offline shows an error — it does **not** show
  a success toast (**B27**).
- `localStorage.currentSubscription` is **gone**, in both directions. A stale entry from a
  previous build does not resurrect the fake state.
- Cancel on each of the three providers reaches the provider, and the subscription's status
  in `laravel_processor_subscriptions` reflects it within one sync.
- Cancel stops billing **immediately** on all three providers; `canceled_at` is set.
- With `cancellation_access_ends = period_end`, `access_ends_at` is the `next_charge_at`
  captured **before** the provider call — not whatever the provider reports after it.
- The member's tier is unchanged until `access_ends_at`; the sweep then drops it once,
  fires `subscription_access_ended`, and is a no-op on every later tick.
- Cancel then resubscribe before `access_ends_at` costs the member nothing.
- A cancelled-at-period-end subscription receives **no** upcoming-charge notice
  ([24](24-notification-scheduler.md) §6.4).
- Changing tier updates `direct_payment_tier_reference_num`, and
  `billing:enforce-membership --dry-run` immediately resolves the **new** `tier_paid`.
- Stripe payment-method change sets **`subscription.default_payment_method`**; assert
  against the Stripe fixture that the subscription — not just the customer — changed
  (**B31**).
- Square payment-method change actually calls Square; PayPal returns
  `DP_E_PM_CHANGE_NOT_SUPPORTED` and the UI links out (**B30**).
- Attaching `us_bank_account`, `sepa_debit` or `link` returns
  `DP_E_PAYMENT_METHOD_NOT_CARD` at the **write** layer, not merely absent from the list.
- A debit card with `card.funding = 'unknown'` is **accepted** (§14.6).
- Each of the four events, with a mail handler configured, sends exactly one email — and
  cancelling twice sends one.
- With no notification configured for an event, the action still succeeds and sends nothing.

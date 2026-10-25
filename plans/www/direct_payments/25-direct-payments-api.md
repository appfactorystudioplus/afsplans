# 07 — The Direct Payments API Surface

What the external SDK (`window.Portal.directPayments`) exposes once the server side of
[22](22-subscription-state.md)–[24](24-notification-scheduler.md) exists.

## 7.1 Scope, and what stays off the client

The request is to *"add to the direct payments API the functionality to configure
notifications and events on actions"*. Configuration is an **operator** act, performed in
the portal admin ([23](23-notification-config.md) §5.7) against
`/portal/api/v1/directpayments`, which sits behind `portal.admin.auth`.

The external SDK is loaded on **public customer-facing pages** with no authentication
beyond a Direct Payment reference. It therefore gets:

- **read** access to the notification *schedule* (so a checkout page can honestly say
  "we'll email you 3 days before each renewal"),
- **read** access to the caller's own subscription state,
- **subscribe** access to client-side lifecycle events,

and **no write access to configuration at all.** A `dp.notifications.save()` on the public
SDK would let anyone holding a DP reference repoint a deployment's billing email. That is
the reasoning; it is written down so it is not "simplified away" later.

## 7.2 `dp.notifications` — read-only

```js
var dp = window.Portal.directPayments.create('eq4W10iF8');
await dp.initializeDirectPayment();

await dp.notifications.list();
// [ { event: 'upcoming_charge', days_before: [7,3,1], active: true },
//   { event: 'payment_failed',  days_before: null,    active: true } ]

await dp.notifications.get('upcoming_charge');
// { event: 'upcoming_charge', days_before: [7,3,1], active: true }
```

Backed by `GET /portal/api/v1/directpayments/{dpRef}/notifications` (public, CORS via
`portal.cors`), which returns **only** `event`, `days_before` and `active`.

Never returned publicly: `mail_handler_reference_num`, `recipient_emails`,
`recipient_mode`, `send_window_hour`. Those name internal mail configuration and operator
addresses, and no checkout page has any use for them.

## 7.3 `dp.subscription` — the member's own state

```js
await dp.subscription.get();                        // caller resolved server-side
await dp.subscription.get({ member_ref: 'm_abc123' }); // operator form; see below
// {
//   status: 'active',
//   next_charge_at: '2026-09-12T14:00:00Z',
//   next_charge_amount_cents: 4999,
//   currency: 'usd',
//   cancel_at_period_end: false,
//   trial_ends_at: null,
//   plan_name: 'Professional',
//   provider: 'stripe',
//
//   // Present ONLY when the resolved caller is the member themselves —
//   // never for an operator reading someone else's subscription.
//   billing_state: 'past_due',              // current | past_due | downgraded
//   downgrade_after: '2026-09-08T00:00:00Z',
//   days_until_downgrade: 14
// }
```

`GET /portal/api/v1/directpayments/{dpRef}/subscription`.

**The argument is optional**, and `members`' `BillingApp` omits it entirely
([10](../../billing/10-members-dashboard.md) §10.4) — the server already knows who is calling.

**The identity rule.** `member_ref` is a *hint*, never an authorisation. The endpoint
resolves the caller server-side — from the Checkpoint bearer when one is present
(`members` always sends one), else from the processor customer id bound to the session —
and returns that member's subscription. A `member_ref` that does not match the resolved
caller returns `403`, it does not return the other member's billing data. This is the
ecosystem's standing rule ([../../AFS-ECOSYSTEM-ARCHITECTURE-AND-DESIGN.md](../../AFS-ECOSYSTEM-ARCHITECTURE-AND-DESIGN.md)
§10: *"Identity is derived server-side, never accepted from the client"*), and billing is
the last place to bend it.

Unresolvable caller → `401`, not an empty object. An empty object would render as "no
subscription" in `BillingApp` and look like a cancellation.

This endpoint is what [10](../../billing/10-members-dashboard.md) points `BillingApp.ts` at, replacing
the `member.next_charge_date` read that has never worked (**B18**).

### 7.3.1 Subscription actions

`dp.subscription.cancel()`, `.update()` and `.setPaymentMethod()` are specified in
[28](28-subscription-actions.md) — cancel and tier change and card replacement, all
encapsulated here rather than exposed as processor calls, and all returning the refreshed
normalised subscription rather than a bare `{success: true}`.

### 7.3.2 `dp.subscription.history()` — the member's own charges and refunds

```js
await dp.subscription.history();
// [ { date, description, amount_cents, refunded_cents, currency,
//     status: 'paid' | 'refunded' | 'partially_refunded' | 'failed',
//     receipt_url } ]
```

`GET /portal/api/v1/directpayments/{dpRef}/subscription/history`.

The **member-scoped** read of the same charge-and-refund data
[29](29-money-and-refunds.md) §15.5 exposes to operators — same tables, different filter and
a much smaller projection. It exists because [10](../../billing/10-members-dashboard.md) §10.9 needs
refunds visible to the member, and the operator ledger is not reachable from `members`.

Scoped **server-side to the resolved caller's own charges**, by the identity rule in §7.3.
Never by a `member_ref` in the query, and never by a client-supplied charge list.

Returned per row: date, description, amount, refunded amount, status, and a `receipt_url`
pointing at the **provider's** hosted receipt where one exists. Deliberately **not**
returned: `charge_id`, `provider_refund_id`, `connected_account_id`, the refund `reason`, or
`initiated_by`. Those are operator-facing — a member needs to know they were refunded and how
much, not which staff member pressed the button or which internal id it carries.

## 7.4 Client-side events on actions

The second half of "events on actions": a page can react without polling.

```js
dp.on('subscription:created',  e => { … });   // after confirmSubscriptionPayment resolves
dp.on('subscription:failed',   e => { … });   // provider declined at creation
dp.on('payment:succeeded',     e => { … });   // single-pay confirm resolved
dp.on('payment:failed',        e => { … });
dp.on('paymentmethod:added',   e => { … });
dp.on('paymentmethod:removed', e => { … });

var off = dp.on('payment:failed', handler);
off();                       // unsubscribe
dp.off('payment:failed', handler);
```

These are **local** emissions from the existing methods in `api.ts` — `charge()`,
`confirmPayment()`, `_confirmSubscriptionPaymentInternal()`, `addPaymentMethod()`,
`deletePaymentMethod()`. No transport, no server round-trip, no relationship to the
server-side email events beyond sharing a vocabulary.

Two rules, both learned the hard way in event emitters that ship inside SDKs:

1. **A throwing listener never breaks the payment flow.** Each handler runs in its own
   `try/catch`; a thrown error is `console.error`'d and the loop continues. A merchant's
   analytics bug must not abort a confirmed charge.
2. **`destroyPayment()` clears listeners** registered against the mounted element's
   lifecycle, so re-mounting against a changed cart does not double-fire. Listeners
   registered on the `dp` instance itself persist — that distinction is documented on the
   method.

## 7.5 Documentation

`www/resources/js/external/direct_payments/README.md` gains a **Subscription lifecycle**
section covering §7.2–7.4, in the voice the file already uses, plus a table row per new
method in the existing `ExternalDirectPaymentsAPI` method table.

`www/resources/js/external/processors/PROCESSOR_DOCUMENTATION.md` gains a short section on
the normalised subscription fields ([22](22-subscription-state.md) §4.3) — specifically
that `next_charge_at` is the only date a consumer should read, and that `current_period_end`
is a provider-native mirror kept for debugging.

### `portal.d.ts` is the only thing a consumer may link to

**No consuming TypeScript file ever imports from `resources/js/external/**`.** The externals
API is reached at runtime through `window.Portal.*` and at compile time through
`www/resources/js/external/portal.d.ts` — an ambient declaration that is **copied** into each
consuming project, at the established path
`<app>/resources/js/checkpointHelper/static/portal.d.ts` (already present in coordinator,
dream_graph, flow, notes, pdf_editor, market and members).

The externals API is bundled and served independently (its own `webpack.config.cjs`).
Importing its source would pull a second copy into the consuming app's bundle and couple two
build graphs that are deliberately separate. The `.d.ts` gives compile-time types with zero
runtime linkage.

**So `portal.d.ts` is a deliverable of this step, not a side effect.** It is hand-maintained,
not generated: a method added to `api.ts` and not declared there is invisible to every
consumer's build, and the consumer falls back to `(window as any)` — which is exactly why
`BillingApp.ts` casts everywhere today.

Every surface this plan adds must land in it in the same pass:

| Added | Declares |
|---|---|
| `dp.notifications` | `list()`, `get(event)` ([§7.2](#72-dpnotifications--read-only)) |
| `dp.subscription` | `get()`, `history()`, `cancel()`, `update()`, `setPaymentMethod()` (§7.3–§7.3.2, [28](28-subscription-actions.md)) |
| `dp.on` / `dp.off` | The event map (§7.4) |

And the copies must be refreshed. `members` is the consumer this plan actually needs
([10](../../billing/10-members-dashboard.md)); the other six are unaffected by these additions but will
drift if the copy step is forgotten, so **P5 updates `members`' copy and leaves the rest
alone** rather than silently touching seven apps.

## 7.6 A worked playground page

`www/public/playground/direct-payments/subscription_lifecycle/`, following the conventions
of the existing `single_charge/`, `connect_setup/` and `marketplace_split/` pages: create a
subscription, render the next charge date and amount from `dp.subscription.get()`, show the
configured notice schedule from `dp.notifications.list()`, and log every `dp.on(...)` event
to an on-page console.

It is also the manual test fixture for [30](30-subscription-notify-rollout-and-testing.md).

## 7.7 Acceptance

- `dp.notifications.list()` on a DP with no configs returns `[]`, not an error.
- The public notifications endpoint never emits a mail handler reference or an operator
  email address — assert this in a test, because it is the kind of field that gets added
  back by a well-meaning `->toArray()`.
- `dp.subscription.get()` with another member's `member_ref` returns `403`.
- `dp.subscription.history()` returns only the caller's own rows, and emits no
  `charge_id`, `provider_refund_id`, refund `reason` or `initiated_by` — assert it, for
  the same reason as the notifications endpoint.
- A listener that throws does not prevent `confirmPayment()` from resolving.

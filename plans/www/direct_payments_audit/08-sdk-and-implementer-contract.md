# 08 — SDK and Implementer Contract

What a developer integrates, what the SDK does on their behalf, and what changes. Fixes **A22**, **A23**, **A29**,
**A46**, **A47**, **A60** on the client side. Files: `resources/js/external/api/direct_payments/api.ts`,
`resources/js/external/api/processors/*`, `public/playground/externals/direct-payments/*`.

## 8.1 The contract a developer can rely on

Written into `resources/js/external/api/direct_payments/README.md` and the playground, in these words:

1. **You store a Direct Payment reference.** Optionally an invoice reference and a payer reference returned to your
   server, and a subscription reference for subscriptions. Nothing from Stripe, PayPal or Square — ever.
2. **You never choose a processor.** The website owner does, in the admin. Your code does not change when they change it.
3. **Your page may be open while the owner switches processor.** The SDK notices and re-mounts the correct payment form;
   your callbacks receive the same result shape either way.
4. **Subscriptions are addressed by their Direct Payments reference** and keep it for life, even when the owner moves
   them to another provider.
5. **Some features depend on the provider** and are reported by `dp.capabilities()` (for example, PayPal subscribers
   change their card at PayPal). The SDK hides what the current provider cannot do; your UI should not hardcode it.

The playground already integrates this way (DP reference, `initializeDirectPayment`, `setupSinglePayPayment`, tier
`level`, `charge`/`confirmPayment`, saved-method calls) — the contract formalises it and removes the escape hatches that
break it.

## 8.2 What the SDK stops doing

| Today | Change |
|---|---|
| Stores `afs_customer_id` (provider customer id) in `sessionStorage`; creates anonymous provider customers from the browser; sends `customer_id` on invoice and subscription calls (**A23**) | Removed. The server resolves the customer ([04](04-payer-customer-identity.md) §4.2). The SDK holds a **payer token** (`localStorage`, `afs_dp_payer_token:{website}`), or nothing when a signed-in identity exists |
| Caches the DP record, processor ref, provider and publishable key in `sessionStorage` and trusts them for the whole session (**A22**) | Caches them **with `binding_version`**; every checkout call sends the version; a `DP_E_BINDING_CHANGED` reply replaces the cache (§8.3) |
| Honours `processor_override` (**A22**) | Ignored, with a one-time `console.warn` naming the contract rule. Removed from the typings in the next major version |
| Puts the resolver's payer in `user_id = "su:pay_…"` (**A29**) | Sends identity through the session/token; `user_id` keeps its own meaning |
| `currency: 'usd'` on invoice save and Square confirm (**A47**) | Sends no currency; the server uses the Direct Payment's |
| Tier display reads `original_data` per provider and shows $0 for Square/PayPal; reads a processor from `original_data.processor` (**A46**) | Reads the canonical tier fields (`amount_cents`, `currency`, `interval`, `trial_days`) the server now returns; never derives a processor from tier data |
| `ExternalProcessorsAPI.getProcessors/chargeSinglePay/chargeSubscription` call routes that do not exist (**A60**) | Removed |

## 8.3 Re-mounting on a processor change

```ts
/**
 * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/08-sdk-and-implementer-contract.md §8.3.
 * Every DP-addressed checkout call goes through this wrapper. A 409 DP_E_BINDING_CHANGED carries the new public
 * processor config; the SDK swaps its ProcessorClient (StripeClient / SquareClient / PayPalClient), re-mounts the
 * payment UI into the SAME mount target the page gave it, and re-issues the call once.
 */
private async withBinding<T>(call: (version: number) => Promise<Response>): Promise<T>
```

What the page sees: its `onProcessorChanged` callback (optional) fires, the card form is replaced in place, and the
visitor is asked to enter payment details again if they had started typing. Nothing else changes; the invoice and its
reference are the same. A page that registered no callback still works.

## 8.4 Subscriptions in the SDK

`dp.subscription` keeps `show / cancel / update / setPaymentMethod / history`, and:

- works for **any** identity the website has — Site Users, a host application's own signed-in users, or a guest holding
  a subscription-management token — through `SubscriberIdentity` ([09](09-extension-points.md)); today it works only
  for one host application's users (**A50**). The `member_ref` option is removed ([13](13-naming-and-separation.md));
- returns the contract `reference` (stable), `status` (including `migrating`), the next charge date and the provider of
  the leg that will take it;
- gains `reauthorise(token, { mountTarget })` for developers who host the renewal step on their own page instead of the
  hosted renewal page ([07](07-subscription-continuity.md) §7.6);
- returns `action_required: 'payment_details'` while a provider change is waiting for this subscriber, with the token the
  inline form needs, so a website can show a one-line "update your payment details" banner
  ([07](07-subscription-continuity.md) §7.4);
- hides `setPaymentMethod` when `capabilities().subscriptionCardChange` is false (PayPal), returning a link to manage it
  at PayPal instead.

Subscription start becomes one server call (`…/subscriptions/start`, [06](06-swap-engine-v2.md) §6.3): the SDK no longer
creates the provider subscription and then separately writes the link, which is how subscriptions end up unknown to
Direct Payments today (**A31**).

## 8.5 Server-side helpers for developers

`public/playground/externals/direct-payments/server/` (the reference `PaymentGateway`, `validate-order.php`,
`show-purchase.php`, `reconcile-sweep.php`) gains:

- `subscription(string $subscriptionRef)` on the reference gateway — status and access dates from the contract;
- a note that a developer's own database must key subscriptions on the Direct Payments reference, with a short example.

## 8.6 Compatibility and rollout

| Change | Old SDK builds |
|---|---|
| Server stops trusting `processor_reference_num` / `customer_id` from the browser | Old builds keep charging, routed to the active binding (§6.3's shim); they just cannot re-mount on a swap |
| `processor_override` ignored | Pages that relied on it now pay the owner's chosen processor — the intended behaviour |
| Customer endpoints removed (after one release) | Old builds fail to pre-create an anonymous customer; the server creates one anyway |

`window.Portal.directPayments` bumps its minor version when §8.2–§8.4 land and logs once if a page pins an older build.

## 8.7 Playground updates

Every sample (`single_charge`, `subscription`, `marketplace_split`, `connect_setup`, `dashbaord`, `server/`) is updated
with TODOS P2.2 in the same pass, plus a new **"switch the processor while this page is open"** walkthrough: open the
single-charge sample, swap the DP's processor in the admin, press Pay — and see the form re-mount.

## 8.8 Acceptance

- `grep -rn "afs_customer_id\|provider_customer_id\|processor_override" resources/js/external` → only the deprecation
  warning (gate G1).
- With a page open on processor A, a swap to B, then Pay: the request carries the old version, gets
  `DP_E_BINDING_CHANGED`, re-mounts B's form, and the invoice is paid on B.
- A Square- or PayPal-backed subscription DP shows correct tier prices.
- `dp.subscription.show()` works for a signed-in Site User on a tenant website.

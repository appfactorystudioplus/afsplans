# 04 — Payer and Customer Identity

> Owner requirement: *"the one factor that connects Stripe, PayPal and Square is a customer id, which Direct Payments
> translates to a `payer_reference_num` … come up with a way to track multiple customer ids to the same
> `payer_reference_num` so that when a switch happens the `payer_reference_num` translates to the provider-specific
> customer id."*

The table that does this exists and is correct: `laravel_direct_payment_payer_customers`, one row per (payer, processor),
exactly one active per processor, superseded rows kept forever. What is wrong is **who writes it** and **who else holds
provider ids**. Fixes **A23–A29**, **A39**, **A44**, **A59**.

## 4.1 The map, as it should be read

```
payer pay_x7k2m9q4bt3a  (never changes; e-mail and name are snapshots)
 ├─ processor 2uDJGCr  stripe  live  cus_Nx8f…      superseded  2026-03-02  reason: processor_swapped
 ├─ processor 2uDJGCr  stripe  live  cus_Q1aa…      superseded  2026-05-10  reason: provider_missing   (recreated)
 ├─ processor 2uDJGCr  stripe  live  cus_R7bc…      active
 ├─ processor Pp4LvQ1  paypal  live  8XK2…(vault)   active
 └─ processor Sq9Tt0a  square  live  JDKY…          active      source: imported
```

- **FORWARD** (charge now): `customerFor(payer, RoutedProcessor)` → the active row for the routed processor, created at
  the provider on first use.
- **BACKWARD** (whose was this charge / this subscription leg): any row, active or superseded, found by
  `(processor_reference_num, provider_customer_id)` — already unique (`dp_payer_cust_customer_uq`).
- A swap writes **nothing** here. The first payment on the new processor creates that processor's row lazily
  (`../direct_payments/02` §2.3's decision, kept).

## 4.2 One writer

`PayerService` becomes the **only** code that creates provider customers for Direct Payments and the only writer of
`payer_customers`. Concretely:

| Today | Target |
|---|---|
| Browser calls `POST /processors/customers/create-anonymous` / `find-or-create`, stores the id in `sessionStorage` (**A23**) | The server resolves the customer inside intent / setup / subscription creation (`customerFor()`); the browser never sees a provider customer id. The three endpoints stay one release for old SDK builds, then are removed |
| `saveInvoice` "records" whatever `customer_id` the browser sent, superseding the payer's real mapping (**A24**) | `saveInvoice`'s replacement (TODOS P0.1) never takes a customer id. The record-existing path is used only by server code that itself just created or verified the customer at the provider, and by the import command |
| `ProcessorSubscriptionController::store()` creates a `ProcessorCustomer` from any client `customer_id` (**A27**) | Subscription creation takes a payer (from the payer token or signed-in identity) and gets its customer from `customerFor()` |
| `recreateStripeCustomer()` overwrites the id in place (**A25**) | `PayerService::replaceCustomer(payer, processor, newId, reason: 'provider_missing')` — supersedes the old row, inserts the new one, updates the processor-layer cache. The old id stays resolvable for history |

`laravel_processor_customers` (**A27**) stays, as the processor layer's cache, written only through `PayerService` (one
call writes both, in one transaction). Its readers are listed in [11](11-rollout-and-testing.md) §11.5 gate G6; dropping
it is not part of this plan.

New columns on `payer_customers`:

| Column | Values | Why |
|---|---|---|
| `source` | `created` (we created it), `recorded` (server verified an existing one), `imported` (vault migration map), `recreated` (provider lost the old one), `migrated` (created during a subscription migration) | Blast radius of a wrong heuristic stays queryable |
| `superseded_reason` | `processor_swapped`, `provider_missing`, `vault_imported`, `payer_merged` (future), `operator` | "Why does this person have three Stripe customers?" has an answer |

## 4.3 Who is paying — payer resolution without trusting the browser

| Caller | Resolution | Saved cards offered? |
|---|---|---|
| **Signed-in Site User** | Their payer through `SiteUserPayer` (existing link), via the `SubscriberIdentity` chain ([09](09-extension-points.md)) | Yes — the active binding's customer only (§4.6) |
| **Signed-in user of a host application** | Their payer through that application's own `SubscriberIdentity` implementation ([09](09-extension-points.md)) | Yes, same rule |
| **Returning guest, same browser** | A **payer token** the server issued at their last checkout: `HMAC(website, payer_ref, issued_at)` with an expiry (default 180 days), kept in `localStorage`. Not a provider id, not guessable, revocable by rotating the signing key | **Yes** — owner decision **OQ8**: the token proves it is the same browser that saved the card. Another device → enter a card again |
| **Guest with an e-mail, no token** | Match **only guest payers** (`created_by = 'checkout'`) on that e-mail; never a payer created for a signed-in identity. Fixes **A28** | No — an e-mail is not proof of identity |
| **Guest with nothing** | Fresh anonymous payer | No |

The SDK sends the payer token or relies on the signed-in session; it never sends a raw `payer_reference_num` that the
server would trust (today it sends neither — **A29**). The server returns the payer reference in responses so a module's
server code can store it (the façade contract), and a fresh payer token for the browser.

## 4.4 PayPal gets a real customer (A26, A44)

PayPal's Orders v2 and Vault v3 APIs do have a customer: the vault `customer.id` that every saved PayPal or card token
hangs off. The plan uses it instead of `"paypal:{email}"`:

1. A payer has **no PayPal row** until they first save a payment method or buy with vaulting on a PayPal processor
   (lazy, like the other two).
2. The first vaulting call (setup token, or an order with `store_in_vault: ON_SUCCESS`) is made without a customer id;
   PayPal returns one; `PayerService` records it (`source = created`).
3. Later calls pass that id, so saved cards and PayPal wallets list and charge exactly as Stripe and Square customers do.
4. The `"paypal:{email}"` code path is removed; there are no existing rows to convert (development, no data).

Whether PayPal accepts a merchant-chosen id is deliberately not relied on. One-time PayPal orders that do not vault keep
working with no customer row; the payer still owns the invoice.

The PayPal client id is read from one place — `publishable_key` — and `extra_config.paypal_client_id` is migrated into
it where they differ (**A45**, [05](05-provider-parity-and-portability.md) §5.4).

## 4.5 E-mail and name changes

`PayerService::updateEmail()` exists and is tested but has no caller (`../direct_payments/complete/05`). It is wired to:
Site Users e-mail change (when that flow exists), a host application's own e-mail change (through a public Direct Payments call it makes), and
the checkout when a guest payer's e-mail is filled in after an anonymous start. It updates every **active** customer at
its provider; superseded customers are left as they were — they are history.

## 4.6 Saved payment methods belong to the payer, through the Direct Payment

New payer-scoped endpoints replace the open processor-level ones (**A59**):

| Method | Path | Rule |
|---|---|---|
| `GET` | `/portal/api/v1/directpayments/{dpRef}/me/payment-methods` | Payer from identity/token; processor from `forNewBusiness($dpRef)`; lists **only** that processor's active customer (**A39**) |
| `POST` | `…/me/payment-methods/setup` | Starts the provider's own save-card flow (Stripe SetupIntent, Square card-on-file token, PayPal setup token) |
| `POST` | `…/me/payment-methods/{methodId}/default` | `methodId` must be in the payer's own list |
| `DELETE` | `…/me/payment-methods/{methodId}` | Same ownership check — the one `deletePaymentMethod()` lacks today |

Site Users' dashboard keeps its own routes (`SiteUsersPaymentsController`), changed to list only customers on processors
that are the active binding of a connected Direct Payment, with a separate "cards on accounts this site no longer uses"
section that only allows delete. The old processor-level payment-method routes (unauthenticated today) are removed after one
release.

## 4.7 What a swap does to all this

| Swap kind | Customers | Cards |
|---|---|---|
| Same account (key rotation) | Not a swap; nothing changes | Nothing changes |
| Stripe → Stripe, vault migrated by Stripe | `direct_payments:import-customer-map` (exists) maps old → new ids, `source = imported`. **Extended** to carry payment-method ids (Stripe's map file includes them) into `laravel_direct_payment_default_methods` so each payer's default card is still the default | Usable immediately |
| Stripe → Stripe without vault migration, or any cross-provider swap | Created lazily on first payment, or by a subscription migration ([07](07-subscription-continuity.md)) with `source = migrated` | Collected once, through the provider's own form |

## 4.8 Acceptance

- No SDK code path stores or sends a provider customer id (grep gate G1, [11](11-rollout-and-testing.md)).
- A guest typing a Site User's e-mail gets a guest payer, not the user's; the user's provider customer is untouched.
- A forged `customer_id` on any public endpoint changes no `payer_customers` row.
- Stripe's "customer missing" recovery leaves the old id superseded with `provider_missing`, the new one active.
- After a Stripe → PayPal swap, a returning signed-in payer's first PayPal vault creates exactly one PayPal row for the
  same `payer_reference_num`; their Stripe history still resolves to them.
- `GET …/me/payment-methods` after a swap lists nothing from the old processor.

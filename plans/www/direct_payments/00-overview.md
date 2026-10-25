# 00 — Overview

## 1. What this is for

A Direct Payment names a processor, and a processor is a Stripe, Square or PayPal account. A
module — an ecommerce store, an event, an appointment calendar — names a Direct Payment and
charges through it. The module is supposed to know nothing about the provider, so that the
tenant can change accounts, or change providers entirely, and nothing downstream notices.

[`../ecommerce/07`](../ecommerce/07-direct-payments-integration.md) §7.2 states the promise
plainly:

> *"Switching processor is a dropdown change. A tenant moving from Stripe to Square points the
> store at a different Direct Payment; no product changes, no code changes, no re-keying."*

**That promise is not true today, and this plan is what makes it true.** Today the dropdown
change is a single field write that orphans every customer, silently re-points every live
subscription at an account it does not exist in, and leaves refunds on past charges
unreachable ([01](01-current-state-audit.md) **D1**, **D2**, **D5**).

## 2. What portability means, precisely

"No disruption" is four different claims, and they are not equally achievable. Being explicit
about which is which is most of the value of this document.

| Claim | Achievable? | How |
|---|---|---|
| **A module's code and data do not change** | **Fully** | The module holds a DP reference and a DP-issued payer reference. Neither is provider-derived, so neither moves ([02](02-payers-and-bindings.md)) |
| **A customer's purchase history stays intact and readable** | **Fully** | Every money object snapshots its processor and is read back through that snapshot ([04](04-history-refunds-and-webhooks.md)) |
| **Refunds on past charges keep working** | **Fully** | The refund resolves its processor from the charge, never from the Direct Payment ([04](04-history-refunds-and-webhooks.md) §4.3) |
| **Stored cards keep working** | **No** | A card token belongs to the provider account that vaulted it ([03](03-the-swap.md) §3.5) |
| **Live subscriptions keep billing on the new provider** | **No** | The mandate belongs to the old provider ([03](03-the-swap.md) §3.6) |

The last two are not engineering shortfalls to be solved later. A card token is meaningless
outside the account that created it, and a recurring mandate is an agreement between the
customer and that provider. Any plan promising otherwise is promising something no
implementation can deliver.

So the design goal is stated as: **a swap costs the tenant a re-collection of payment
details, and costs the implementer nothing.**

## 3. The diagnosis

The abstraction leaks in exactly one place, and everything else follows from it:

```
  ecommerce order  ─────▶  provider_customer_id = "cus_Nx8fK2..."
                                      │
                                      └── minted by Stripe account A.
                                          Meaningless to Stripe account B.
                                          Meaningless to PayPal.
```

A module stores a value the provider issued. When the provider changes, the value is dead, and
there is nothing in the estate that knows it should be replaced or what to replace it with.
Every other symptom in [01](01-current-state-audit.md) is a consequence:

- customers are keyed per processor, so a swap orphans all of them (**D3**);
- subscriptions have no processor of their own and inherit the Direct Payment's current one,
  so a swap silently redirects them (**D2**);
- refunds resolve their processor from the request rather than the charge (**D5**).

**The fix is to give the module something Direct Payments issues instead.** That is the payer
([02](02-payers-and-bindings.md) §2.2) — a stable reference that maps, inside Direct Payments
where provider knowledge belongs, to whichever provider customer is current, with every
previous one retained.

## 4. Non-goals

- **Not migrating stored cards.** Stripe will migrate vaulted card data between its own
  accounts through a manual, compliance-reviewed process; that is a conversation with Stripe,
  not code, and it does not exist at all across providers ([03](03-the-swap.md) §3.5).
- **Not migrating live subscriptions.** [03](03-the-swap.md) §3.6 defines dual-running and
  re-authorisation instead.
- **Not a new provider integration.** Stripe, Square and PayPal are what exist; adding a
  fourth is unaffected by this plan and easier after it.
- The rest of the Direct Payments documents (steps 20–29, plus their rollout doc) **have now
  moved** out of [`../../billing/`](../../billing/README.md) — see the README's "Why this
  folder exists" note. This plan's own scope (portability) was unaffected either way.
- **Not changing the façade contract.** A selling module still stores three payment references
  and no money ([`../ecommerce/AUDIT`](../ecommerce/AUDIT-direct-payments-decoupling.md)).
  This plan changes *which* customer reference is one of them.

## 5. Glossary

| Term | Meaning |
|---|---|
| **Provider** | Stripe, Square or PayPal — the company |
| **Processor** | One account at one provider, in one mode. `laravel_processors.reference_num`, 7 chars |
| **Direct Payment** (DP) | The tenant's payment configuration a module points at. `laravel_direct_payments.reference_num`. **The stable handle** |
| **Binding** | A Direct Payment's association with a processor, over a period of time. New in [03](03-the-swap.md) |
| **Payer** | A DP-issued identity for the person paying. `pay_` + 12. **Never changes.** New in [02](02-payers-and-bindings.md) |
| **Provider customer** | What the provider calls the payer — `cus_…`, a Square customer id, a PayPal payer id. One per payer per processor |
| **Implementer** | A module that sells through a Direct Payment: ecommerce, events, appointments |
| **Swap** | Changing which processor a Direct Payment is bound to |

## 6. The decisions

| # | Decision | Why |
|---|---|---|
| **P1** | **The Direct Payment reference is the stable handle; the processor behind it is what changes.** A swap re-points an existing DP; it never asks a module to point at a new one | The module stores the DP reference. Issuing a new DP would be the disruption this plan exists to avoid. [03](03-the-swap.md) §3.2 |
| **P2** | **Direct Payments issues a payer reference; a module stores that and never a provider id** | A provider-issued value cannot survive the provider. Two stored values either way — the difference is who mints the second. [02](02-payers-and-bindings.md) §2.2 |
| **P3** | **A DP's processor is a binding row with a lifetime, not a column** | One active, any number retired. A column cannot express "this subscription was taken on the account we left in March". [02](02-payers-and-bindings.md) §2.4 |
| **P4** | **Every money object snapshots the processor it was created on** | Invoices and charges already do; subscriptions do not, which is **D2**. Fixing that is the precondition for a safe swap. [02](02-payers-and-bindings.md) §2.5 |
| **P5** | **A provider call about an existing object uses that object's snapshot, never the DP's current binding** | The single invariant that makes history survive. It is what turns a refund-after-swap from a bug into a non-event. [04](04-history-refunds-and-webhooks.md) §4.1 |
| **P6** | **Cards and live subscriptions do not move, and the plan says so everywhere a tenant might assume otherwise** | Naming an impossibility in the admin UI is cheaper than a tenant discovering it mid-migration with live subscribers |
| **P7** | **A retired binding stays readable, and its webhooks stay accepted, until its last obligation ends** | Deactivating the old processor on swap day drops refunds, disputes and subscription events for money already taken. **D7** |
| **P8** | **A swap is an explicit, audited operation with a dry run** — not a field write | Today any authenticated caller can re-point a DP through `saveDirectPayment()` with no warning. **D1**, **D8** |
| **P9** | **A payer is scoped to a website, not to a Direct Payment** | One person buying from a store and booking an appointment on the same site is one payer, and should reuse one provider customer per processor rather than accumulating one per DP. [02](02-payers-and-bindings.md) §2.3 |

## 7. What "done" looks like

A tenant running a Stripe store decides to move to a different Stripe account.

1. In the Direct Payment's admin they choose **Change processor**, pick the new one, and press
   **Preview**. They are told: 1,204 payers will be re-created on first charge, 38 saved cards
   will stop being usable, 12 live subscriptions will keep billing on the old account until
   each is re-authorised or ends, and 3 years of order history is unaffected.
2. They confirm. The Direct Payment keeps its `reference_num`. The old binding becomes
   `retiring`; the new one becomes `active`.
3. **The store's code and database do not change.** Every order still names the same Direct
   Payment and the same payer references it always did.
4. A returning shopper checks out. Direct Payments finds no provider customer for their payer
   on the new processor, creates one, records it, and charges it. Their saved card is gone and
   they enter a new one; their order history is complete and unchanged.
5. An operator refunds an order from last year. It refunds against the **old** account,
   because the charge says so.
6. Six months later the last old subscription ends. The tenant retires the old binding, and
   the old processor's webhooks stop being expected.

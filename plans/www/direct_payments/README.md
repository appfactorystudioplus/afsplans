> **Superseded vocabulary (2026-10-17).** This plan predates the separation rule in [`../direct_payments_audit/13-naming-and-separation.md`](../direct_payments_audit/13-naming-and-separation.md):
> Direct Payments and processor code now use only Direct Payments words (no *billing*, *member*, `brain_*`), and the code that was marked
> `AFS_BILLING_MODIFIER_ADDITION` there was **re-tagged once** to `AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION` (owner decision OQ15, a one-time exception to the
> "never replace a marker" rule). Do not restore the old marker. Documents here are records of what was done and are not rewritten.

# direct_payments — Planning Index

> **Scope rule.** Tenant / Direct Payments infrastructure is planned here and its commands use the
> `direct_payments:` namespace (e.g. `direct_payments:subscription-notify`). appfactory-studio-
> specific work (dunning, membership downgrade) is planned in [`../../billing/`](../../billing/README.md).

**Direct Payments** sits between a payment provider — Stripe, Square or PayPal — and the
modules that sell: ecommerce, events, appointments, and anything added later. Its whole
purpose is that the module never learns which provider took the money, so a tenant can change
provider without their store, their calendar or their events noticing.

This folder plans **provider portability**: making that promise true.

> ### Why this folder exists
>
> Every other `www` module has a plan folder — appointments, builder, connections,
> discussions, ecommerce, events, forms, site_users, subscribers. **The payment engine all of
> them depend on used to have none.** Its documents were originally written inside
> [`../../billing/`](../../billing/README.md) because Direct Payments was extended during the
> billing effort — but billing is a different product: the platform charging *its own
> members* (`appfactory studio`'s own `brain_memberships.membership` tier) — cron,
> subscription state, dunning, tier enforcement, the Money ledger. Direct Payments is a
> tenant-facing abstraction that **every** tenant's website shares, not something specific to
> appfactory studio's own billing, so it belongs here beside the other tenant-facing modules.
>
> **Moved 2026-09-22.** Steps [20](20-current-state-audit.md)–[29](29-money-and-refunds.md)
> below are exactly the documents that used to live in `billing/02`, `03`–`07` and `12`–`15` —
> content unchanged (including their internal `§N.x` section numbers, which still read as
> they did under their old step number — the filename is the current reference, not the
> section prefix inside it), moved and renumbered into this folder because every one of them
> is generic Direct Payments infrastructure any tenant's website can use, not something
> specific to appfactory studio's own membership system. What stayed behind in `billing/` —
> `08-brain-enforcement.md`, `09-entitlement-integration.md`, `10-members-dashboard.md`,
> `16-allocation.md`, `17-self-service-tier-changes.md` — is the part that is genuinely
> appfactory-studio-only: everything that reads or writes `brain_memberships.membership`, or
> that is fetched via the Brain's own membership API regardless of which deployment runs it
> (`16-allocation.md`'s per-domain processor/DP limits are exactly this — sourced from
> `brain_memberships`, not from anything Direct-Payments-specific). See
> [`../../billing/README.md`](../../billing/README.md) for that side.
>
> The façade contract every seller follows is
> [`../ecommerce/AUDIT-direct-payments-decoupling.md`](../ecommerce/AUDIT-direct-payments-decoupling.md).

> ### Status (2026-09-12)
>
> **Planning only. No code has been written.**
>
> [01](01-current-state-audit.md) records ten findings, **D1–D10**. The one that decides the
> shape of everything else: **`laravel_direct_payment_subscriptions` carries no processor
> reference.** A subscription's provider is resolved *through its Direct Payment*, so the
> moment a tenant re-points that Direct Payment, every live subscription resolves to an
> account it does not exist in — and `saveDirectPayment()` accepts that re-point today as a
> plain field write, with no warning and no record.

Read in this order:

| Step | Document | Contents |
|---|----------|----------|
| — | [README.md](README.md) | This index |
| 00 | [00-overview.md](00-overview.md) | What portability means, what it cannot mean, and the decisions **P1–P9** |
| 01 | [01-current-state-audit.md](01-current-state-audit.md) | How the layers are keyed today, and the ten findings **D1–D10** |
| 02 | [02-payers-and-bindings.md](02-payers-and-bindings.md) | The **payer** — a DP-issued identity the provider cannot invalidate — and a Direct Payment's processor as a binding over time |
| 03 | [03-the-swap.md](03-the-swap.md) | The three kinds of swap, the procedure, and the two things that genuinely cannot move |
| 04 | [04-history-refunds-and-webhooks.md](04-history-refunds-and-webhooks.md) | Reading, refunding and receiving webhooks for money taken on a processor the tenant has left |
| 05 | [05-implementer-contract.md](05-implementer-contract.md) | The two values a module stores, the SDK, and what ecommerce, events, appointments and Site Users each change |
| 06 | [06-rollout-and-testing.md](06-rollout-and-testing.md) | Phases **DP0–DP6**, the test matrix, rollback |
| 07 | [07-tier-bindings.md](07-tier-bindings.md) | **Subscription pricing has the same problem as customers, plus one underneath it** — a tier's identity does not survive an ordinary save, and its price is one column with no processor of its own |
| 08 | [08-saved-card-defaults.md](08-saved-card-defaults.md) | Not a portability document — found auditing [`connections/10`](../connections/10-api-contracts.md): no saved card, for any of Stripe/Square/PayPal, ever showed as *the* default, because even Stripe's own field isn't what this app's subscription billing reads. An internal pointer this app owns, uniformly, for all three |

**Moved from `billing/` 2026-09-22** — subscription lifecycle, notifications and money, generic
across every tenant. Each keeps the internal `§N.x` numbering it had under its old step number
(noted at the top of the file); only the filename and folder changed:

| Step | Document | Was | Contents |
|---|----------|-----|----------|
| 20 | [20-current-state-audit.md](20-current-state-audit.md) | `billing/02` | The 41-finding audit (**B1–B41**) behind steps 21–29: subscription persistence, the mail-handler send path, the members dashboard, signup, money and refunds, allocation |
| 21 | [21-cron-bootstrap.md](21-cron-bootstrap.md) | `billing/03` | `commands.sh --update-cron-task`, the idempotent crontab edit, and the `saveDirectPayment` hook that calls it |
| 22 | [22-subscription-state.md](22-subscription-state.md) | `billing/04` | Schema changes + the provider sync service that puts a trustworthy `next_charge_at` in the database, and the DP↔subscription link table every later step reads |
| 23 | [23-notification-config.md](23-notification-config.md) | `billing/05` | The Direct Payments notification/event configuration — tables, validation, admin API |
| 24 | [24-notification-scheduler.md](24-notification-scheduler.md) | `billing/06` | `direct_payments:subscription-notify` — the 15-minute task, its dedupe ledger, and the mail-handler send path |
| 25 | [25-direct-payments-api.md](25-direct-payments-api.md) | `billing/07` | The external SDK surface: `dp.notifications`, `dp.subscription`, and the events an integrator can hook |
| 26 | [26-advanced-policy.md](26-advanced-policy.md) | `billing/12` | Per-Direct-Payment advanced policy (grace period, dunning ladder shape) — schema deliberately carries no `brain_` coupling; only *who may write it* is restricted, and only for now |
| 27 | [27-provider-retry-policy.md](27-provider-retry-policy.md) | `billing/13` | What Stripe, PayPal and Square each do about a failed subscription charge, and the per-provider configuration objects for the one that lets you change it |
| 28 | [28-subscription-actions.md](28-subscription-actions.md) | `billing/14` | Cancel, update, change payment method — encapsulated in the Direct Payments API, cards only, each with its own configurable email |
| 29 | [29-money-and-refunds.md](29-money-and-refunds.md) | `billing/15` | **Financials → Money** — one ledger for every money movement, refunds for single/subscription/Connect charges, and an aggregate-only projection into afs-connect |

| 31 | [31-code-layout-consolidation.md](31-code-layout-consolidation.md) | Code layout: Direct Payments domain code lives under `App\Externals\DirectPayments` (subscriptions, notifications, money, dunning policy, events); `App\Services\Billing` holds only appfactory-specific code; `direct_payments:` is the single command prefix. Executed 2026-09-25 — see [`complete/31`](complete/31-code-layout-consolidation.complete.md) |

`billing/11-rollout-and-testing.md`'s generic phases (P1a–P6f, covering 21–29 above) moved
alongside them into their own document,
[`30-subscription-notify-rollout-and-testing.md`](30-subscription-notify-rollout-and-testing.md).
`billing/11` itself keeps only the appfactory-specific phases (P0, P6, P7, P8) and now points
here for the prerequisite ones.

## The one-sentence version

A Direct Payment's `reference_num` is the stable handle a module holds forever; the processor
behind it becomes a **binding** that can be retired and replaced; the customer becomes a
DP-issued **payer** that outlives any provider; and the two things that cannot follow a
swap — stored cards and live subscriptions — are named as such and handled deliberately
rather than promised away.

## What a module stores, before and after

```
BEFORE                                   AFTER
  direct_payment_reference_num             direct_payment_reference_num      ← unchanged
  provider_customer_id  cus_Nx8...         payer_reference_num  pay_x7k2m9q4bt3a
        │                                        │
        └─ minted by Stripe.                     └─ minted by Direct Payments.
           Dies when the provider changes.          Survives every provider change.
```

Two values before, two values after. The difference is who issues the second one, and
therefore whether it can survive the thing this plan exists to make survivable.

## Completion documents

Each numbered document gets a completion document in [`complete/`](complete/) when its work
lands — source basename plus `.complete.md`. Include status, summary, files changed,
verification steps and follow-up work.

## Code documentation standard

Document all new and changed code thoroughly using block comments — PHPDoc for PHP, JSDoc for
TypeScript. Every file, exported symbol, public function, non-obvious private helper, route
handler, migration and non-trivial type must explain purpose, parameters, return values, side
effects and plan references.

New and changed code carries `AFS_BILLING_MODIFIER_ADDITION` (see
[../../markers.md](../../markers.md)) — the marker Direct Payments and processor code already
uses estate-wide — followed by the plan reference, e.g.
`AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/02-payers-and-bindings.md §2.3`.
Where a file already carries a marker, **add alongside; never replace.**

## Three rules that shape everything here

**A provider-issued value may never be a module's join key.** Anything Stripe, Square or
PayPal mints — a customer id, a card token, a subscription id — dies the day the tenant
changes account. A module that stores one has a dependency on a provider it was designed not
to know about ([00](00-overview.md) §3).

**Every money object records the processor it was created on, and is always read back through
that record.** Not through the Direct Payment's current binding. This single invariant is
what makes history survive a swap, and the one table that breaks it today is the reason
[01](01-current-state-audit.md) **D2** exists.

**Say what cannot move.** Stored cards and live subscriptions cannot follow a tenant to a new
provider — not through better engineering, but because the card token and the payment mandate
belong to the provider. A plan that implies otherwise sets a tenant up to discover it during
a migration ([03](03-the-swap.md) §3.5).

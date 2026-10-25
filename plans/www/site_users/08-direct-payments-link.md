# 08 — Linking Site Users to Direct Payments

Equating a processor `customer_id` with a site user, so a charge — subscription or
single-pay — is attributable to an account.

## 8.1 The problem

Every Direct Payment charge requires a provider customer. Today that customer is created
anonymously per checkout (`_createAnonymousCustomer()` in the Direct Payments SDK), and
`laravel_processor_customers` has no link to any account. So:

- A signed-in shopper's second purchase creates a **second** provider customer.
- Saved cards do not follow the account — they belong to whichever anonymous customer record
  happened to be minted that session.
- A subscription cannot be shown on "my account" because nothing joins it to an account.
- Refunding or supporting a customer means searching by email and hoping.

The ask is to make that link explicit and optional.

## 8.2 The link is its own table (**N8**)

> **Superseded in part by [`../direct_payments/05`](../direct_payments/05-implementer-contract.md)
> §5.5 (2026-09-12).** This table folds into `laravel_site_user_payers` — one row per user
> holding a Direct Payments **payer** reference, instead of one row per user per processor
> holding a provider-issued customer id. Site Users then stores no provider identifier at all,
> which is what this document's own §8.2 layering argument always wanted. The three rules in
> §8.5 are unchanged; they match a user to a payer instead of to a customer.
>
> **Extended by [13](13-identity-and-email-change.md) §13.5 (2026-09-12).** `user_id` becomes
> `site_user_reference char(12)`, and the table's role is promoted: it is **the only join from
> a purchase to a user for anything Direct Payments charged without a façade record** — a
> donation, a DP block, a subscription. It keys on `provider_customer_id`, never on email,
> which is precisely why it survives the email change §13.4 introduces.

Not a `customer_id` column on the user, and **not** a `user_id` column on
`laravel_processor_customers`. Both directions are wrong, for the same reason the billing
plan keeps Direct Payments and the processor layer separate
([../direct_payments/22-subscription-state.md](../direct_payments/22-subscription-state.md) §4.2):

- A **processor customer** is a payments-layer fact, scoped to one processor.
- A **site user** is an identity-layer fact, scoped to one module.
- A user legitimately has **several** provider customers — one per processor, and Stripe test
  and live are different customers again.

`laravel_site_user_customer_links` — owned by Site Users, because Site Users is the
layer that knows about users:

| Column | Type | Notes |
|---|---|---|
| `id` | `id` | |
| `site_users_id` | `string(32)` index | |
| `user_id` | `unsignedBigInteger` index | → `users.id` |
| `processor_reference_num` | `string(7)` index | Which processor this customer lives on |
| `provider` | `string(20)` | `stripe` \| `square` \| `paypal` |
| `provider_customer_id` | `string(255)` index | The provider's own id |
| `linked_by` | `string(20)` | `checkout` \| `admin` \| `email_match` — §8.5 |
| `linked_at` | `timestamp` | |
| `timestamps` | | |

```php
$table->unique(['processor_reference_num', 'provider_customer_id'], 'su_customer_links_customer_uq');
$table->index(['site_users_id', 'user_id'], 'su_customer_links_user_idx');
```

**The unique index is on the customer, not the user.** One provider customer maps to at most
one site user; one site user may have many provider customers. That asymmetry is the
whole data model, and getting it backwards means a shared customer record across accounts —
which means one shopper's saved card charging another's order.

**Nothing in the processor or Direct Payments layer changes.** No column, no model relation,
no import. This table reads *down* into them by id, the allowed direction.

## 8.3 Linking at checkout

The Direct Payments SDK already resolves a customer. When a site user is signed in, the
DP layer records the link — it is the only party holding both the DP context and the session:

```
su.isAuthenticated()            → the signed-in user
dp.charge() / setupSubscription → provider customer id
        │
        ▼
POST /api/siteusers/public/me/customers
     { processor_reference_num, provider_customer_id }
     (session guard; user resolved server-side)
```

**The user is never in the request body.** It comes from the session
([05](05-authentication.md) §5.2) — a client-supplied `user_id` here would let anyone attach
their card to someone else's account.

Idempotent: re-posting the same `(processor, customer)` is a no-op. If it is already linked
to a **different** user, the endpoint returns `409` and **does not** relink. Silent relinking
is how a saved card moves between accounts.

## 8.4 What the link enables

| Capability | How |
|---|---|
| Saved cards follow the account | Reuse the linked customer instead of `_createAnonymousCustomer()` |
| "My subscriptions" on a tenant site | `user → links → laravel_processor_subscriptions` via `customer_id` |
| Order history across sessions | Same join, through `laravel_processor_charges` |
| Operator support | The Money ledger's Customer column resolves to an account ([../direct_payments/29-money-and-refunds.md](../direct_payments/29-money-and-refunds.md) §15.5) |
| Attributable refunds | Same |

The first row is the one users notice, and it is also the one that requires care: reusing a
linked customer means a **signed-in** checkout must not fall back to an anonymous customer
when the link lookup fails, or a member's cards silently disappear and reappear. Fail the
lookup loudly in development; in production, log and proceed anonymously **without** creating
a link, so the failure is recoverable rather than producing a wrong one.

## 8.5 Linking existing customers — `email_match`

For customers that predate the link, an admin-run reconciler matches
`laravel_processor_customers.email` against `users.email` within a module.

Three rules, because email matching is exactly where this goes wrong:

1. **Only matches a `email_verified_at`-verified user.** An unverified address is a claim,
   not an identity — the same rule as SSO linking ([06](06-sso.md) §6.5).
2. **Only when the match is unique on both sides.** One customer, one user. Anything else is
   reported, not resolved.
3. **`linked_by = 'email_match'`**, so these are distinguishable forever from links a user
   actually made. If the heuristic turns out to be wrong, the affected set is one query.

Run as `siteusers:link-customers --dry-run` first. It reports matched, ambiguous and
unverified counts and writes nothing.

**Not scheduled.** Attaching payment identities to accounts on a timer, unattended, is not
something to switch on and forget.

## 8.6 Optionality

The link is **off unless the module opts in** — `modules.link_payment_customers`, default
`false`.

A tenant using Site Users purely for a discussion board has no processor and no reason for
this table to have rows. A tenant running a shop turns it on and gets accounts that remember
their cards.

When off: nothing is written at checkout, and the reconciler refuses to run for that module.

## 8.7 Where this meets the billing plan

The billing plan's Money ledger ([../direct_payments/29-money-and-refunds.md](../direct_payments/29-money-and-refunds.md)
§15.5) has a **Customer** column showing `customer_email`, linked to the member on the Brain
where one resolves.

On a **tenant**, there are no members — but there may now be site users. So the same
column resolves through `laravel_site_user_customer_links` instead, and an operator
looking at a charge on a tenant site sees the account that made it.

That is an additive read in the billing plan's ledger query, gated on the module existing.
It is noted here rather than built here, because [15](../direct_payments/29-money-and-refunds.md)
owns that surface — and it should only be wired once **both** plans have landed their
respective tables.

## 8.8 Acceptance

- A signed-in user's second purchase reuses the same provider customer.
- Their saved cards appear on the next visit, on a different device.
- Posting a `user_id` in the link request body changes nothing — the session decides (§8.3).
- Linking a customer already linked to another user returns `409` and leaves both alone.
- Re-posting an existing link is a no-op, not a duplicate row.
- `siteusers:link-customers --dry-run` reports without writing; ambiguous and unverified
  matches are reported and **not** linked.
- With `link_payment_customers = false`, checkout writes no link and the reconciler refuses.
- `grep -rn "SiteUser\|siteusers" app/Http/Controllers/Externals/Processor/ app/Models/Externals/Processor/`
  is **empty** — the processor layer learns nothing about users (§8.2).

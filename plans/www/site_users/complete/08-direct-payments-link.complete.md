# 08 — Linking Site Users to Direct Payments (complete)

**Plan:** `plans/www/site_users/08-direct-payments-link.md`
**Rollout phase:** P8
**Repo:** `www`
**Date:** 2026-08-31, completed 2026-09-05

## §8.2 — the link table (N8)

`laravel_site_user_customer_links`, owned by Site Users. Model
`App\Models\Externals\SiteUsers\SiteUserCustomerLink`.

```php
$table->unique(['processor_reference_num', 'provider_customer_id'], 'su_customer_links_customer_uq');
$table->index(['site_users_id', 'user_id'], 'su_customer_links_user_idx');
```

**The unique index is on the customer, not the user** — one provider customer maps to at most
one site user; one user may have many customers. Nothing in the processor / Direct Payments
layer changed: no column, no relation, no import (`grep -rn "SiteUser" app/Models/Externals/Processor/`
is empty).

## §8.3 — linking at checkout

`POST /portal/api/siteusers/public/me/customers` (session guard):

- **The user is never in the request body** — resolved from the session. A `user_id` in the
  body is ignored (`SiteUsersCustomerLinkTest`).
- Idempotent — re-posting the same `(processor, customer)` is a no-op.
- Already linked to a **different** user → `409`, no relink.
- `linked_by = 'checkout'`.

## §8.6 — optionality

`modules.link_payment_customers`, default `false`. When off, the endpoint returns
`{ linked: false, skipped: 'link_payment_customers_off' }` and **writes nothing**
(`SiteUsersCustomerLinkTest::test_link_is_skipped_when_the_module_opts_out`).

## §8.5 — the reconciler (new 2026-09-05)

`php artisan siteusers:link-customers {module} [--dry-run]`
(`App\Console\Commands\SiteUsersLinkCustomers`):

1. Only matches an `email_verified_at`-verified user.
2. Only when the email is unique **on both sides** — one customer, one user.
3. Writes `linked_by = 'email_match'`.

Refuses to run for a module with `link_payment_customers = false`. **Not scheduled.**
`--dry-run` reports matched / ambiguous / unverified / already-linked counts and writes
nothing.

## Acceptance — §8.8

| Criterion | State |
|---|---|
| `user_id` in the body changes nothing | ✅ `SiteUsersCustomerLinkTest` |
| Customer linked elsewhere → 409, both untouched | ✅ |
| Re-post is a no-op, not a duplicate | ✅ |
| `--dry-run` reports without writing; ambiguous/unverified not linked | ✅ command logic |
| `link_payment_customers = false` → nothing written, reconciler refuses | ✅ |
| Processor-layer grep finds no `SiteUser` reference | ✅ |
| Signed-in second purchase reuses the same customer; saved cards follow the account | ⚠️ **the DP-layer read side** (reuse the linked customer instead of `_createAnonymousCustomer()`) is Direct Payments' work — this plan owns the table and the write endpoint; §8.4's consuming reads are noted, not built here |

## Deferred

| # | Work |
|---|---|
| 1 | Direct Payments SDK: reuse the linked customer at a signed-in checkout instead of minting an anonymous one (§8.4). Fail loud in dev, log-and-proceed-anonymously in prod. |
| 2 | The billing Money-ledger `Customer` column resolving through `laravel_site_user_customer_links` on a tenant (§8.7) — owned by `direct_payments/29`, wired once both plans have landed. |

## Verification

```bash
cd www
php artisan test tests/Feature/SiteUsers/SiteUsersCustomerLinkTest.php
```

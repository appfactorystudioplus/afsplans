# 00 — Overview

## The problem, stated once

`brain_memberships` — the row created for every person who signs up for the AFS platform itself
(Free/Pro/Business/Organization/Enterprise) — has a `customer_id varchar(128)` column holding a
raw provider customer id (`cus_…` for Stripe, and whatever Square/PayPal's equivalents are),
written straight from `w/www/resources/js/afs/`'s signup form. It has no index and no unique
constraint. If AFS's own Direct Payment (`vfPSdk2xj`) ever moves processor — the exact scenario
[`../www/direct_payments/`](../www/direct_payments/) exists to make safe — every member's stored
`customer_id` stops resolving to anything, and there is no way back to that member from a new
provider event because nothing indexes the column in the first place.

The fix is the one direct_payments/05 already prescribes for every other module that charges
people: stop storing what the provider calls the customer, and store what Direct Payments calls
the payer instead.

## Who this plan is for

Two artifacts, both real application code (not planning documents):

1. **`brain_memberships`** (MySQL, `app/Models/Membership.php`) — the row itself.
2. **`w/www/resources/js/afs/`** (`create.ts`, `main.ts`, `memberships/index.ts`) — the public,
   unauthenticated signup bundle that populates it, and the one piece of AFS-owned code that
   calls the Direct Payments client SDK directly for AFS's own product rather than a tenant's.

Plus the one server-side consumer that writes `brain_memberships` from that bundle's payload:
`app/Http/Controllers/Portal/Admin/MembershipController.php` (`store()` and `update()`).

## Where brain_memberships sits relative to everything else in this session

Every other implementer this session has touched — ecommerce, events, appointments, Site Users —
is a **tenant's** module, optionally connected to a **tenant's** Site Users instance, charging a
**tenant's** customer through a **tenant's** Direct Payment. `brain_memberships` is none of that:
it is AFS's own product billing its own members, through AFS's own single Direct Payment
instance. There is no tenant, no connection to make optional, no Site Users to connect to — it is
the platform charging its own users for the platform.

That makes it structurally the *simplest* of the five implementers, not a sixth special case:

| | ecommerce / events / appointments (05 §5.4) | Site Users (05 §5.5) | brain_memberships (here) |
|---|---|---|---|
| Rows per person | many (one per order/booking) | one, in a **separate** link table | one, **on the member's own row** |
| Why a separate link table | N/A — the order already exists once per purchase | Site Users itself has nothing 1:1 with a payer until one is linked | not needed — `brain_memberships` already has exactly one row per person |
| What's added | `payer_reference_num` column | `laravel_site_user_payers` table | `payer_reference_num` column |

So this plan reuses the **first** shape (05 §5.2's "one column, one write, one read"), not the
Site Users link-table shape. No new table is created.

## Glossary

| Term | Meaning here |
|---|---|
| `brain_memberships` | The MySQL table (model `Membership`) — one row per AFS platform member |
| `customer_id` | **Legacy.** The raw provider customer id, e.g. `cus_1N…`. Being removed |
| `payer_reference_num` | **New.** The stable, DP-issued reference from [`direct_payments/02`](../www/direct_payments/02-payers-and-bindings.md) §2.2. Never changes across a processor swap |
| `AFS_DIRECT_PAYMENT_ID` | The Direct Payment reference identifying AFS's own membership product (today, the literal `vfPSdk2xj`) — distinct from any tenant's Direct Payment |
| `MembershipCreationPayload` | The TS interface `memberships/index.ts` builds and POSTs to `/portal/api/v1/memberships/register` |

## Non-goals

- Changing which tier a member is on, or how that's resolved (`billing/`'s and
  [`direct_payments/07`](../www/direct_payments/07-tier-bindings.md)'s job — see README).
- Changing Checkpoint (SSO) registration.
- Adding Site Users to AFS's own signup flow — AFS members are not Site Users of anything, and
  nothing here creates that relationship.
- Changing `brain_membership_billing_state` / `brain_membership_events` — already compliant
  (§1.6 of the audit).

## Decisions

- **BI1 — `payer_reference_num` is a direct column on `brain_memberships`, not a link table.**
  One member, one payer, forever; a join buys nothing a column doesn't already give.
- **BI2 — email updates for AFS members move server-side.** Today `create.ts` calls the DP SDK's
  `updateCustomerEmail()` directly from the browser, twice, for two different code paths that
  exist only because of a Stripe-Checkout redirect quirk (§1.3). Under the payer model this
  becomes one call, `PayerService::updateEmail()`, made once by `MembershipController::store()`
  after the row is created — covering both paths uniformly and removing a payer-mutating method
  from the public SDK surface entirely (§3.3).
- **BI3 — `customer_id` is deprecated, not repurposed.** It follows the same
  add → dual-write → read → stop-write → drop shape used for every other column migration this
  session ([`../www/site_users/13`](../www/site_users/13-identity-and-email-change.md)'s
  `reference_num`, [`../www/connections/03`](../www/connections/03-database-schema.md)'s
  `site_user_id`). Detailed in [02](02-database-and-server.md) §2.2.
- **BI4 — sequencing.** This plan's Phase 1 cannot start until `direct_payments/02`'s
  `laravel_direct_payment_payers` table and `direct_payments/05`'s SDK changes are actually built
  — see [04](04-rollout-and-testing.md) §4.1.

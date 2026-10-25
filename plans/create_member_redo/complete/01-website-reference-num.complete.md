# 01-website-reference-num — completion record

**Plan file:** [`../01-website-reference-num.md`](../01-website-reference-num.md)
**Repos:** `w/www`, `w/members`, `lab2`, `packages/afsserver`
**Date:** 2026-10-15
**Status:** ✅ Complete. Nothing is pushed.

## What shipped

| Plan section | What | Where (commit) |
|---|---|---|
| 3.1 | the job JSON's `website` carries the real `reference_num` | `members` `41026d0b` |
| 3.2 | `app_install.sh` seeds `website_reference_num`, drops the random `reference_num` property | `members` `41026d0b` |
| 3.5, 5.1, 5.2, 5.4 | payer belongs to the tenant, not a website: `PayerService`, unique email, `laravel_direct_payments.website_reference_num` removed, ownership check removed, `FormSiteResolver` workaround removed | `www` `39bc013d2`, `lab2` `aa5b2cc` |
| 5.3 | the column, its indexes and uniques, model fields, write/filter code, seeders, scripts and tests removed from forms, events, ecommerce, appointments, analytics rollup, sponsorships, site-user modules | `www` `98ebaa463`, `lab2` `965fb17` |
| decision 1 | `resolveForSiteUser()` always creates a payer; email taken means the new payer has no email | `www` `b12b985bf` |
| decision 2 | free-signup payer-by-email lookup kept in `MembershipController::store()` | `www` `39bc013d2` |
| extra | `transfer_tenants.sh` keeps `website_reference_num` when a tenant moves | `www` `b12b985bf`, `members` `172be10c` |

## Deviations from the plan text

* `brain_dns_zones.website_reference_num` and `members_website_deployment_progress.website_reference_num` stay: those Brain tables hold rows for many websites, so the column is not redundant there.
* Access checks (`forSites()`, `*AuthorizesSites`, the dashboard) stay as the gate (no site scope returns nothing); only the per-row column filter is gone.
* Tests that proved cross-site isolation now prove the empty-site-scope gate.

## How it was checked

* `php -l` on every changed file.
* Full suite on a throwaway local MySQL 8.0.36 (`AFS_TEST_WIPE=1`): 1582 tests, 0 failing, 21 skipped (3 need GNU sed, 18 were already skipped). Re-run after the last change for Direct Payments, Site Users and Membership: 303 of 303.
* The fixes the suite forced (stale tests, plus two app bugs: `InfraWriter` writing generated columns, `BuilderBackfillModuleRefs` missing `events`) are in `www` `c5899a5a0`.

## Not verified

* `app_install.sh`, `DomainController` and `transfer_tenants.sh` have no tests and were not run; a real domain deploy and a real tenant transfer on dev would prove them. `bash -n` cannot parse these scripts on the Mac's bash 3.2.

## Residue

* Older SQL dumps in `lab2/databases` (full, v1, v2) still name the removed columns; only `production/testdb_appfactorystudio.data-only.sql` is current.

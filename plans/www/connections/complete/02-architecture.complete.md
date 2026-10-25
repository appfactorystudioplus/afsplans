# 02-architecture — completion record (K1/K2 slices only)

**Plan file:** [`../02-architecture.md`](../02-architecture.md)
**Rollout phase:** §2.2 is K1, §2.3/§2.5 are K2 (per the rollout table; §2.5's own section
header says "(K6)" — see Residue); §2.4, §2.6–§2.10 are later phases or narrative context with
no code of their own
**Repo:** `w/www`
**Date:** 2026-09-13
**Status:** 🟡 Partial by design

## What shipped

### §2.2 — a connection is a row (K1)

`laravel_site_user_connections` + `SiteUserConnection` — see
`03-database-schema.complete.md`.

### §2.3 — resolution precedence (K2)

`SiteUsersConnectionRegistry::resolve()`'s instance → wildcard → null precedence, and the two
invisibility rules (a disconnected/absent row, a connection whose Site Users module is
inactive) — built in K1 as part of the container-binding proof, given a dedicated correctness
test in K2: `tests/Unit/SiteUsers/SiteUsersConnectionRegistryTest.php`.

### §2.5 — module isolation, the `currentUserFor()` half only

```php
public function currentUserFor(Request $request, string $siteUsersId): ?SiteUser
```

Added to `SiteUsersGateway` verbatim. `SiteUsersConsumerRegistry::attributableUserReference()`
calls it with the resolved connection's `site_users_id`, which is what makes "a session from
another module is a guest here" true for every connectable module's checkout gate as of this
phase.

**Not shipped from §2.5: the X27 half.** The section's own text is explicit that
`SiteUsersApiGuard` and `BuilderPublishedController::show()` are two *separate* surfaces that
carry a `site_users_id` and don't compare it yet — that fix is named as K6's in the rollout
table's K6 row ("SiteUsersApiGuard and BuilderPublishedController enforce their own
site_users_id (X27)") and is untouched here. Until K6, "members-only" on those two surfaces
still means "any account on any Site Users module on this deployment," exactly as §2.5
describes it today.

## Not implemented

- §2.4's truth table is enforcement behaviour (checkout/registration/booking refusing or
  allowing) — that's K3's "the three modules' ungated writes" and the 401 standardisation, not
  this document's own code.
- §2.5's X27 half — K6 (above).
- §2.6–§2.10 are architecture narrative / identifier rules already covered by their owning
  documents (13-identity-and-email-change, 03-database-schema) — no separate code to report
  here.

## Files

**Modified:** `app/SiteUsers/SiteUsersGateway.php` (`currentUserFor()`)

**New (tests):** `tests/Unit/SiteUsers/SiteUsersConnectionRegistryTest.php`

## Acceptance

| Criterion | Status | Evidence |
|---|---|---|
| Instance beats wildcard beats null | ✅ | `SiteUsersConnectionRegistryTest::test_precedence_instance_beats_wildcard_beats_null` |
| A session from another module is a guest, not an error | ✅ | `SiteUsersConsumerRegistryTest::test_module_isolation_a_module_b_session_on_a_module_a_store` (in the doc-04 completion record — `attributableUserReference()` is the consumer of `currentUserFor()`) |

## Verification

```bash
cd w/www
php artisan test tests/Unit/SiteUsers/SiteUsersConnectionRegistryTest.php tests/Unit/SiteUsers/SiteUsersConsumerRegistryTest.php
```

## Residue

- **§2.5's section header says "(K6)"; the rollout table lists `currentUserFor()` under K2.**
  Resolved by building the trivial, self-contained method (`currentUserFor()`) now per the
  rollout table's explicit line item, while leaving the actual isolation ENFORCEMENT on the two
  named surfaces (guard, published-page controller) to K6, matching the section's own narrative
  about what X27 fixes. Not a contradiction once split this way — just two different phase
  labels describing two different pieces of one section.

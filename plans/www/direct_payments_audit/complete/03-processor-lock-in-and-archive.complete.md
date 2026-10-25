# 03 — Processor Lock-in and Archive (complete)

## Status

**Complete 2026-10-17.** The archive half of this step (§3.1–§3.3, §3.5, §3.6, and the archive texts of §3.7) was built earlier as part of
[step 16](16-safety-rails-implementation.complete.md). This record covers the other half, built today: **the account lock** (§3.4) —
verifying keys with the provider, storing an account fingerprint, locking provider / mode / account once a processor is in use, and
letting keys rotate only to the same account. Nothing is committed.

Verification: on a real MariaDB 11.4.5 (the permanent local install in `C:\Users\mrmit\mariadb`), `tests/Feature/{DirectPayments,Processor,
SiteUsers,Connections,Ecommerce,Events,Membership}` = **522 tests, 2085 assertions, all green** (`AFS_TEST_WIPE=1`,
`EXTERNALS_CORS_MODE=off`); `tsc --noEmit` over `w/www` clean.

## Phases

| Phase | What shipped | Findings |
|---|---|---|
| P0 — archive, no delete, one lookup, restrict keys, webhook rule, texts | Done in step 16 (see its record) | A1, A2, A4, A5, A9, A55 |
| P1 — schema | `laravel_processors` + `provider_account_id` (indexed), `account_verified_at`, `identity_locked_at`, `currency` (char 3), straight into the create migration | A8 |
| P2 — verification | `ProcessorAccountVerifier`: Stripe `GET /v1/account`, Square `GET /v2/merchants/me` (+ the location must exist), PayPal token call (REST app id) + the merchant id the tenant enters, compared with PayPal's own `payer_id` where the app may read it (OQ7). A Stripe test key on a live processor (or reverse) is refused before anything is sent. Captures the account's default currency | A3, A8 |
| P3 — the lock | `ProcessorAccountGuard`: create verifies and fingerprints (secret key now required); update: provider never changes; mode never changes once locked; any change to keys or to account-level `extra_config` (`currency`, `default_currency`, `location_id`, `app_id`, `paypal_client_id`, `paypal_merchant_id`) re-verifies, and on a locked processor must resolve to the same account (`PROC_E_KEYS_OTHER_ACCOUNT`); behaviour-only edits never call the provider; a locked processor with no fingerprint refuses key replacement (`PROC_E_ACCOUNT_UNKNOWN`); a rotation writes an `rotate_keys` audit row. Server-owned `paypal_app_id` can neither be set nor removed by a request | A3, A7 |
| P4 — when it locks | `Processor::lockIdentity()` is called by `ProcessorLookup::forNewBusiness()` (every intent, session, order, subscription, customer route — the public config read opts out) and by `BindingRegistry::ensureActive()` / `swap()` | — |
| P5 — server-issued references | `store()` ignores any `reference_num` in the request; the admin no longer generates one | A6 |
| P6 — swap reads the fingerprint | `SwapCompatibility::sameAccount()` (fingerprint, falling back to publishable key only when a processor has none) drives `SWAP_E_SAME_ACCOUNT` and the preview's `kind`; the processor's own `currency` column wins over the free-text config key in the currency rule | A8 |
| P7 — admin | PayPal Merchant ID field (create modal and detail page); environment select disabled once locked; the account id and the §3.7 key-rotation text shown on the detail page; i18n in all five locales; the TypeScript record gained `provider_account_id`, `identity_locked`, `currency` | OQ5, OQ7 |
| P8 — tests | `ProcessorAccountLockTest`, 18 tests | — |

## Files

**New:** `app/Externals/Processor/{ProcessorAccountVerifier,ProcessorAccountGuard,ProcessorAccountException}.php`,
`tests/Feature/DirectPayments/ProcessorAccountLockTest.php`.

**Changed:** `database/migrations/2026_05_21_000001_create_processor_tables.php`; `Processor` (casts, `isIdentityLocked()`, `lockIdentity()`, admin array);
`ProcessorAdminController`; `ProcessorLookup`; `ProcessorClientController`; `BindingRegistry`; `SwapService`; `SwapCompatibility`;
`ProcessorsService.ts`, `ProcessorsAdminView.ts`, `ProcessorDetailView.ts`, `processors.html`, `processor-details.html`, `i18n/{en,fr,de,es,zh}.json`;
`tests/Support/CreatesPaymentsSchema.php` (the older hand-built schemas get the four new columns).

## Acceptance (§3.8)

| Criterion | Result | Evidence |
|---|---|---|
| `DELETE` → 405; model delete throws; raw delete blocked by a foreign key; connected accounts / transfers / endpoints survive | ✅ | step 16: `ProcessorArchiveTest`, `ProcessorForeignKeyTest` (MariaDB green) |
| Update of a locked processor's `mode` → 422 | ✅ | `test_mode_and_provider_cannot_change_once_locked` |
| Keys of another account → `PROC_E_KEYS_OTHER_ACCOUNT`, nothing stored | ✅ | `test_keys_of_another_account_are_refused_and_nothing_is_stored` |
| New key of the same account → succeeds and writes an audit row | ✅ | `test_a_rotation_to_new_keys_of_the_same_account_succeeds_and_is_audited` |
| Archiving a DP's active processor → `PROC_E_ARCHIVE_ACTIVE_BINDING` | ✅ | step 16 |
| After archive, every new-business route → `PROC_E_ARCHIVED`; refund, cancel, signed webhook still work | ✅ | step 16: `ProcessorLookupPrecedenceTest`, `WebhookCandidateTest` |
| Keys verified at creation; bad keys never saved; wrong environment refused | ✅ | `ProcessorAccountLockTest` (Stripe, Square, PayPal) |
| Behaviour edits do not call the provider; archived processors can still rotate | ✅ | `test_behaviour_edits_never_call_the_provider`, `test_an_archived_processor_can_still_rotate_its_keys` |
| Run on MySQL/MariaDB | ✅ | 522 tests green on MariaDB 11.4.5 |
| Browser check of the new fields | ➖ | not done |

## Residue and notes

- **PayPal merchant check is best-effort by design.** PayPal apps without the identity scope cannot read their own merchant, so the entered ID is taken as given and pinned in the fingerprint; a later rotation is then held to the same merchant. Where PayPal does answer, a mismatch is refused.
- **Stripe restricted keys** that cannot read `GET /v1/account` are refused as invalid keys — use a key with account read access.
- **Public config restriction** (§3.6, "answers only for the active binding of a non-archived Direct Payment") is explicitly marked in the step as coming with server-side routing ([06](../06-swap-engine-v2.md)); it is not part of this step.
- **Existing processors:** none are back-filled (development, no data). A processor without a fingerprint can be edited freely until it is used; once locked its keys cannot be replaced (`PROC_E_ACCOUNT_UNKNOWN`) — re-add it.
- **Square access-token vs. application id:** the fingerprint is the merchant behind the access token; the application id stays an account-level config key and follows the same rotation rule.

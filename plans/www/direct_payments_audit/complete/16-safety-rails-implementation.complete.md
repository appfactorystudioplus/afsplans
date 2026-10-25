# 16 — Safety Rails: Implementation (complete)

## Status

**Complete 2026-10-17.** Built, tested on a real MariaDB 11.4.5 and type-checked. Every phase below is written
and every changed PHP file passes `php -l`. Nothing was committed (per the repo rule). What was checked, and what was left out:

1. **Tests, run on a real database (done later the same day).** The lab MySQL was unreachable, so a portable MariaDB 11.4.5
   was unpacked in `C:\Users\mrmit\mariadb-test` (port 3307, not a service, since shut down). `tests/Feature/{DirectPayments,
   Processor,SiteUsers,Connections,Ecommerce,Events,Membership}` ran with `AFS_TEST_WIPE=1 EXTERNALS_CORS_MODE=off`:
   **504 tests, 2011 assertions, all green.** The first run found three defects in the new tests (8-character processor
   references in a 7-character column, a hand-built invoices table without `is_split`, a signing secret named after the old
   reference); all were test-side and are fixed. The earlier SQLite errors in `SiteUsers*` / `Membership*` were SQLite artefacts.
   `EXTERNALS_CORS_MODE=off` is needed for the existing suite to pass in this environment at all; it is unrelated to this step.
2. **TypeScript:** `tsc --noEmit` over `w/www` (TypeScript 5.9.3) passes with no errors, so the admin changes compile. The vite
   bundle build (`npm run build`) was not run and the processor list has not been looked at in a browser.

## Phases

| Phase | What shipped | Findings closed |
|---|---|---|
| P1 — schema | Archive columns, binding `kind`, audit log and disputes tables, `onDelete('cascade')` → `restrictOnDelete()` ×3, restrict foreign keys from bindings / payer customers / tier bindings to `laravel_processors`, all straight into the two create migrations. `bound_by` widened 64 → 191 so an operator identity fits | A2 |
| P2 — archive and lookup | `ProcessorLookup` (`forNewBusiness` / `forExisting` / `byReference`) replaces every `orWhere('id')` lookup; `Processor::deleting` throws; `DELETE` answers 405; archive / unarchive endpoints, `ProcessorArchiver`, audit rows; archived processors cannot be bound; webhook removal refused while still needed | A1, A4, A5, A9 |
| P3 — Direct Payment archive and save | `DirectPaymentArchiver` (used → archived, never used → deleted), unarchive, archived DP refuses saves / invoices / items, `pay_type` locked once money moved, processor column never blanked, items upserted and archived, tiers archived (and a save without `tiers` leaves them alone), item reference ownership check, all in transactions | A10, A11, A16, A17, A18 |
| P4 — swap safety and Go live | `SwapCompatibility` (one rulebook for first processor, change and Go live), atomic `BindingRegistry::swap` with revert reuse, `SwapService::execute` in one locked transaction, operator identity from the login, `GoLiveService`, two Go live endpoints | A12, A13, A14, A15, A19 |
| P5 — subscriptions and webhooks | Link endpoint never re-keys or re-tiers, tier checked against the plan, foreign-processor subscriptions not linked; provider-ended subscriptions mirrored through a `ProcessorSubscription` observer; every processor is a webhook candidate; orphans written before the rate limiter | A32, A33, A36, A37 (partly), A55, A56 |
| P6 — disputes | `ProcessorDispute`, `DisputeRecorder` for Stripe, Square and PayPal, wired into the three webhook handlers; `canRetire()` and the archive warnings read open disputes | A58 |
| P7 — admin | Processor list: lifecycle badge, Archive / Unarchive instead of Delete, "Show archived" toggle, archive reason; processor detail "Delete" became "Archive"; hint texts on both forms; the Direct Payment processor dropdown lists active processors only and locks on an existing DP; i18n keys in all five locales | OQ5 |
| P8 — tests | `tests/Support/CreatesPaymentsSchema.php`; 13 new classes; existing tests adjusted (below) | — |

## Files

**New (app):** `app/Externals/Processor/{ProcessorLookup,ProcessorUnavailable,ProcessorArchiver,ProcessorArchiveRefused,ProcessorDeletionForbidden}.php`,
`app/Externals/Processor/Disputes/DisputeRecorder.php`, `app/Externals/DirectPayments/{DirectPaymentArchiver,SwapCompatibility,GoLiveService}.php`,
`app/Externals/DirectPayments/Support/OperatorIdentity.php`, `app/Externals/DirectPayments/Subscriptions/{SubscriptionEndSync,ProcessorSubscriptionObserver}.php`,
`app/Models/Externals/DirectPaymentAuditLog.php`, `app/Models/Externals/Processor/ProcessorDispute.php`.

**Changed (app):** both create migrations; `Processor`, `DirectPayment`, `DirectPaymentItem`, `DirectPaymentTier`, `DirectPaymentProcessorBinding`;
`ProcessorAdminController`, `ProcessorIntentController`, `ProcessorSubscriptionController`, `ProcessorClientController`, `ProcessorSquarePaymentController`,
`ProcessorCustomerController`, `ProcessorSyncController`, `ProcessorWebhookAdminController`, `ProcessorWebhookController`;
`DirectPaymentsController`, `DirectPaymentSwapController`, `DirectPaymentSubscriptionController`; `BindingRegistry`, `SwapService`,
`Support\DirectPaymentReference`; `AppServiceProvider`; `routes/portal.php`; `plans/markers.md` (new marker `AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION`).

**Changed (admin):** `Processors/ProcessorsAdminView.ts`, `Processors/ProcessorsService.ts`, `controllers/processors/ProcessorDetailView.ts`,
`controllers/direct_payments/directpayments.ts`, `processors.html`, `processor-details.html`, `directpayments-form.html`, `i18n/{en,fr,de,es,zh}.json`.

## Deviations from the written step (and why)

| Step said | What was done | Why |
|---|---|---|
| Convert the 19 hand-built test schemas to `createPaymentsSchema()` | 31 test files that hand-build payment tables got a one-line call to `addSafetyRailsSchema()` at the end of `setUp` (adds only the step-16 columns and tables); the **new** tests use `createPaymentsSchema()` (production-shaped, from the real migrations) | The suite could not be run on MySQL here; replacing 31 hand-built schemas with the stricter real ones blind would have produced failures nobody could see. The conversion is still worth doing once a MySQL run is available |
| `ProcessorAdminController` list gains `?status=` filter | Not added | The admin filters client-side (the list was already `all` by default); nothing consumed it |
| Detail page keeps "Delete" | Detail page "Delete Processor" became "Archive Processor" | `DELETE` now answers 405, so the old button would have shown an error. The step only named the list page. The detail page no longer releases the domain's processor allocation (the processor still exists) — whether archived processors count against the allowance is an appfactory decision |
| Compatibility check on first processor only | Also run (Connect rules only) when an existing Direct Payment is saved as `CONNECT` | `guardConnectPayType()` checked every `CONNECT` save; deleting it would have dropped that protection |
| Step's `syncItems` validated inside the loop | References are checked for all items **before** the first write | The first run of the new test showed a refusal half-way committed the earlier items (the transaction closure returned a response instead of throwing) |

## Existing tests changed (assertions)

| Test | Change | Reason |
|---|---|---|
| `SwapServiceTest` | `SwapService` built with `SwapCompatibility`; helper gives processors a webhook secret (PayPal: `webhook_id`); "already the active processor" asserts the code `SWAP_E_ALREADY_ACTIVE` | A15 now blocks a target with no webhook; blockers carry codes |
| `DirectPaymentSwapControllerTest` | helper sets a webhook secret | same |
| `SaveDirectPaymentTest` | creates a minimal `laravel_direct_payment_invoices` table; the "removing a tier deletes it" test now asserts it is **archived** | the compatibility check asks about split invoices; A18 |
| `WebhookOrphanRecordingTest` | `…is_not_enough_once_deactivated` became `…inactive_unbound_processor_still_authenticates` | A55 reversed that rule on purpose |

## Acceptance

| Area | Result | Evidence |
|---|---|---|
| Processor archive, no delete, restrict keys | ✅ (SQLite) / ⚠️ MySQL | `ProcessorArchiveTest`, `ProcessorForeignKeyTest` green on SQLite; foreign-key behaviour needs the MySQL run |
| A4 regression, every new-business route | ✅ (SQLite) | `ProcessorLookupPrecedenceTest` |
| Direct Payment archive, save fixes | ✅ (SQLite) | `DirectPaymentArchiveTest`, `SaveDirectPaymentSafetyTest` |
| Compatibility rulebook, atomic change, revert, Go live | ✅ (SQLite); also 18 pure-rule checks ran with no database at all | `SwapCompatibilityTest`, `SwapAtomicityTest`, `SwapRevertTest`, `GoLiveTest` |
| Subscription link, provider-ended subscriptions | ✅ (SQLite) | `SubscriptionLinkIntegrityTest`, `ProviderEndedSubscriptionTest` |
| Webhook candidates, orphans, disputes | ✅ (SQLite) | `WebhookCandidateTest`, `DisputeRecorderTest` |
| Grep gates G2, G3, G9 | ✅ empty | run in `w/www` |
| Test run on a real MySQL-family database | ✅ | MariaDB 11.4.5, 504 tests green |
| Type check of the admin code | ✅ | `tsc --noEmit` clean |
| Vite bundle build, browser check | ➖ not done | not asked for |
| Dev-server walkthrough of §8.4 / `migrate:fresh` on dev | ➖ left out | owner: do not worry about the dev database |

## Residue and notes

- **Left out on purpose:** `migrate:fresh` on dev (owner said not to worry about it), the vite bundle build and a browser look at the processor list.
- The SQLite smoke run also showed errors in `SiteUsers*` and `Membership*` tests (duplicate index names, MySQL generated columns). They happen while creating tables, before any payment code, and are SQLite artefacts, not caused by this step — but they were not compared against a clean checkout.
- A subscription link for a subscription the processor layer has not yet written now answers `subscription_unlinked` (previously a link was written from the DP's active processor). The backfill command adopts it later, as the step intends.
- `ProcessorAdminController::store()` still accepts a client-chosen `reference_num` (A6) — deferred to the account-lock step, as the step says.
- Not committed. All changes are uncommitted in the one repo `w/www` (67 modified files plus the new files listed above), and `plans/markers.md` in `afsserver`.

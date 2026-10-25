# 11 — Rollout and Testing (complete — everything that can be proved without the remote server is proved; the sandbox scenarios and browser checks are not)

## Status

**Complete 2026-10-17.** Nothing is committed. This record closes the plan: the phases in §11.1 are built (steps 03–16), the test matrix of §11.2 is covered, the grep gates of §11.5 are run, and the whole local test suite was run on MariaDB. What cannot be done from this machine is listed under "Not verified" and is the owner's list for the remote server.

## Phases PA0–PA9 and DE

| Phase | Built in | State |
|---|---|---|
| PA0 safety rails | 16, 03 | built, tested |
| PA1 account lock | 03 | built, tested (`ProcessorAccountLockTest`) |
| PA2 server routing, scheduled changes | 06 | built, tested (`ProcessorRouterTest`, `ScheduledChangeTest`) |
| PA3 payer and customer | 04 | built, tested |
| PA4 catalog and parity | 05 | built, tested (requests recorded, not sent to a provider) |
| PA5 contracts and legs | 07 | built, tested |
| PA6 migration engine | 07 | built, tested with faked providers |
| PA7 separation | 09, 13, 14 | built; vocabulary gate enforced by `VocabularyGateTest` |
| PA8 admin | 03, 06, 15 | built, type-checked; **not looked at in a browser** |
| PA9 SDK, playground, clean-up | 08, 10, 13 | built; SDK type-checked and its request layer tested; **never run in a browser** |
| DE default emails | 15 | built; sent through `MailHandlerSender` only against a recording transport |

## Test matrix (§11.2) — where each row lives

Some rows have a different file name from the plan; the behaviour is covered.

| Plan row | Covered by |
|---|---|
| PA0 (14 tests) | `ProcessorArchiveTest`, `DirectPaymentArchiveTest`, `ProcessorLookupPrecedenceTest`, `ProcessorForeignKeyTest`, `SwapCompatibilityTest`, `SwapAtomicityTest`, `SwapRevertTest`, `SaveDirectPaymentSafetyTest`, `GoLiveTest`, `SubscriptionLinkIntegrityTest`, `ProviderEndedSubscriptionTest`, `WebhookCandidateTest`, `WebhookOrphanRecordingTest`, `DisputeRecorderTest` |
| PA1 `ProcessorAccountLockTest` | same name |
| PA2 `BindingVersionTest`, `RetiringEnforcementTest` | `ProcessorRouterTest` (version mismatch gives `DP_E_BINDING_CHANGED`, shim) and the new-business refusal tests in `ProcessorLookupPrecedenceTest` / `ProcessorArchiveTest` |
| PA2 `ScheduledChangeTest` | same name |
| PA3 `PayerCustomerSingleWriterTest`, `PayPalVaultCustomerTest` | same names |
| PA4 `TierProvisioningTest`, `CanonicalDiscountTest` | same names (+ `ProviderSubscriptionCreatorTest`, `ProviderParityTest`) |
| PA5 `SubscriptionLegsTest` | `SubscriptionContractTest`, `SubscriptionLinkIntegrityTest` |
| PA6 `Migration*Test` (vault copy, re-authorise x3, safety, no-action, deadline) | `SubscriptionMigrationTest` (32 tests: S1, S2 per provider, early-start cancel, double submit, no two renewing legs, deadline, reactivation, scheduler) and `SubscriptionMigrateCommandTest` |
| PA7 `SubscriberIdentityChainTest` | `ExtensionPointsTest`, `PayerIdentityTest` |
| PA7 `VocabularyGateTest` | same name (`tests/Unit/DirectPayments`) |
| DE `DefaultEmailsTest` | same name (18) |
| PA9 `SdkContractTest` | same name (PHP) and the node `request_context.test.ts` |

## Grep gates (§11.5), run 2026-10-17

| Gate | Result |
|---|---|
| G1 no provider customer id / `processor_override` in the SDK | only the deprecation (type, one warning, the check that reads it) and README |
| G2 no hand-written processor lookups outside the router | **not empty — about 70 exact-reference reads remain.** Reviewed: none uses `orWhere`, so the A4 precedence defect cannot recur; they read a reference the caller already holds. A bulk rewrite to `ProcessorLookup` was attempted and abandoned (the script failed; no file changed). Left as a follow-up |
| G3 no processor deletes | no hit; the processor model refuses deletes (`ProcessorArchiveTest`) |
| G4 BACKWARD questions never ask the binding | one hit, a comment |
| G5 no appfactory dependency | empty; also enforced by `ExtensionPointsTest` |
| G6 customers written only by `PayerService` | empty |
| G7 readers of the contract's cached provider columns | about 70 hits, expected while the columns exist (the plan says "before they are dropped") |
| G8 no `uniqid()` idempotency keys | empty |

## Whole-suite run (local MariaDB, every test folder, 2026-10-17)

| Folder | Result |
|---|---|
| `tests/Unit` | 343 tests; 13 errors, all `LaravelCronScriptTest` — a typed property read before initialisation in a cron-shell script test; not Direct Payments code |
| `Feature/DirectPayments` | 456 tests; 4 failures found and fixed (`SaveInvoiceRecordsPayerTest` hand-built a table subset without the processor-bindings table) |
| `Feature/Processor`, `SiteUsers`, `Connections`, `Ecommerce`, `Events`, `Membership`, `Externals`, `Observers`, `Admin`, `Forms`, `Appointments`, `Builder` | all pass; `Members` passes with 2 deprecation notices |
| `Feature/Brain` (run file by file; the whole folder exceeds PHP's 600 s limit) | all pass except `BrainDeviceTokenCommandTest` (4 errors, 1 failure: the printed-token line is not captured on Windows; unrelated to this work) and `BrainStaffSubscriptionTest`, whose 2 failures were **real** (the staff controller read `$event->warning`, removed with the reply channel in step 09) and are **fixed**: the Brain now checks the member's tier itself; 34/34 |
| `npm run test:external-api` | 17/17 |
| `tsc --noEmit` (app and external SDK) | clean |

**Final re-run after the last edits (17:20–17:56):** `Feature/Membership` 30/30, `Feature/Processor` 30/30, `Feature/DirectPayments` 456/456 (2,440 assertions), `Unit/DirectPayments` 10/10, `Unit/Mail` 1/1 — all green.

## Not verified (needs the remote server, a provider, an inbox or a browser)

* The end-to-end scenarios of §11.2 on dev with sandbox accounts (Stripe test clocks, PayPal and Square sandboxes), and every provider call — all provider traffic in the tests goes to fakes.
* A real e-mail through `--transform-email-mjml` (step 15) and the four processor-change e-mails.
* Every admin screen, the hosted renewal page, the SDK re-mount and the members dashboard in a browser; the Vite bundles were not rebuilt.
* The dev server runs none of this code yet (checked read-only over SSH on 2026-10-17: Laravel 12.36, webhook log lines in the old format). Deploying needs a fresh create-migration run, which the owner must approve.

## Definition of done (§11.4)

Met in code and fakes: switching a live Direct Payment between Stripe, PayPal, Square and another Stripe account; new payments to the new provider and refunds on the account that took them; subscriptions migrating by card copy or one customer action with no gap and no double charge in the engine's tests; no processor deletable, archived ones still answering; Connect confined to Stripe; gates clean except G2 and G7 as above. Not demonstrated against a real provider.

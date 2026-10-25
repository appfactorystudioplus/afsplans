# membership_change — handoff (2026-10-20, end of the step 13 session)

Read this first. It says what is done, what is half-done, what is left, and how to run things without freezing the PC. Nothing in `w/` is committed (commit only when the owner says; `w/` is ten separate repos — say which repo a change landed in).

## 0. Where things stand

| Step | State |
|---|---|
| 06–10 | built, recorded in `complete/` |
| 11 new member welcome + alerts | **complete** — `complete/11-new-member-welcome-and-alerts.complete.md` |
| 12 phone: add member + alerts | **complete 2026-10-20** - `complete/12-phone-add-member-and-alerts.complete.md` (uncommitted; not seen on a device) |
| **13 staff member lifecycle (server)** | **complete 2026-10-20** (closed out; unverified on real Stripe and in a browser) - `complete/13-staff-member-lifecycle-server.complete.md` |
| 14 phone + dashboard UIs | **complete 2026-10-20** - `complete/14-phone-and-dashboard-member-lifecycle.complete.md` (uncommitted; not seen on a device/browser/Stripe) |
| M8 retirement | not started |

The owner's last question was "are you done with everything with this step besides the test?" The honest answer: no. Section 3 lists exactly what is left.

## 1. Owner decisions on record (steps 11–14)

- The Memberships module is **Brain-only** (registered only when `config('brain.is_brain')`; needs `IS_BRAIN=true`).
- Every signup rings the Brain's bell. Admin-created member password: set now / send a link / both.
- URLs come from `.env` via `w/www/config/memberships.php`.
- Phone alerts: level A only (in-app; no background, push or Firebase).
- Stripe is always the processor, but **all billing goes through Direct Payments' normal flow** — never call Stripe directly; refunds and the ledger must work normally.
- Free period menu: 7d / 14d / 1m / 3m / 6m / 12m. Free period with no card → the subscription **pauses**, then a **7-day grace**, then downgrade. The chosen plan is active immediately. "Complimentary" is wanted. The welcome e-mail mentions the free period and its end date.
- Tax is used wherever Stripe Tax works (processor setting; applies to public signup too once on).
- **No member can ever be level 99 — only `appfactorystudio`.** No level control anywhere; plans say "plan", not "level".
- Work happens in the `development` environment, not the vagrant lab.

## 2. Step 11 (done, summary only)

Full detail is in its completion report. Shape: `MemberProvisioning` finishes both create paths; the `memberships.member_created` Activity (welcome e-mail to the member, bell for every signup); Checkpoint (`w/auth`) gained the reset-password page, a password-setup e-mail and a system-key route; dead `store2()` / `contructMailHandler()` removed. Tests: 288 www + 7 auth passed; tsc clean; admin tests 81/81. Not seen on a server or in a browser.

## 3. Step 13 — what exists

All in `w/www` (uncommitted). Plan: `13-staff-member-lifecycle-server.md`. Draft report: `complete/13-staff-member-lifecycle-server.complete.md` (written, has one placeholder — see 3.3).

### 3.1 Code written

- **Start options** (Direct Payments holds an administrator-set, one-use, 24 h "start later / pause at the end"): migration `2026_10_20_000001_create_direct_payment_start_options.php`, `app/Models/Externals/DirectPaymentStartOption.php`, `app/Externals/DirectPayments/StartOptions.php`.
- **Stripe creator / start:** `ProviderSubscriptionCreator::stripe` (`end_behavior` → `trial_settings`, only for a deferred start with no card; `automatic_tax`; `stripeCustomerAddress()`), `ProcessorSubscriptionController` (takes the option by payer + tier, sets `starts_at`, `end_behavior`, tax from `extra_config.automatic_tax`; releases the option on failure), `ServerCustomerResolver::payerForRequest`.
- **Paused:** `StripeSubscriptionReader` (maps `paused`, no next charge), `StripeSubscriptionActions::setPaymentMethod` resumes a paused subscription (raw curl), `MembershipTierResolver::GOVERNING_STATUSES` includes `paused`, `DunningService::advancePaused` (7-day grace via `memberships.paused_grace_days`, no failed-payment mails, then the ladder's own downgrade; restore path unchanged), `BrainMembershipEvent` constants. DP event `subscription_paused` (module, default-on, `dp/defaults.json`, `dp/subscription_paused.mjml`, webhook routes `customer.subscription.paused|resumed`).
- **Tax setting:** `processors:set-automatic-tax {reference} {on|off}` (`ProcessorsSetAutomaticTax`).
- **Level 99:** `Membership::PLATFORM_OWNER_USERNAME` + a `saving` guard (throws `\DomainException`), command `memberships:check-levels`; `phpunit.xml` pins `MEMBERSHIPS_SINGLE_LEVEL_99=false` for older tests that build several level-99 staff.
- **Billing service:** `app/Services/Brain/Memberships/Billing/{MemberBilling,FreePeriod,BillingException}.php`, `BrainBillingSession` + migration `2026_10_20_000002`. Modes: `none | complimentary | card_now | free_period_card | free_period`. "Saved" is never believed — the Brain re-reads the subscription (`SubscriptionSyncService::syncOne`) and requires it to belong to this member's payer. The card page uses a payer token with a 1-hour remaining life.
- **Controllers / routes:** `Brain/Staff/StaffMemberCreateController` (`POST api/v1/brain/staff/members`, billing choice checked before anything is created), `StaffMemberBillingController` (open / start / show), `Brain/BillingSessionController` (card page + public `complete`), routes in `routes/portal.php` and `routes/web.php` (`GET /brain/card/{session}`), `StaffCapabilities` (`member_create_billing`, `member_billing`), `StaffCreateOptionsController` (`free_periods`, `billing_modes`, `password_modes`; `processors` kept for the current phone app), `BrainStaffSubscriptionController::cancel` accepts `access = immediately|period_end`, `StaffMemberController::show` returns `billing_setup`.
- **Card page:** `resources/js/afs/card.ts` (new), `resources/views/afs/card.blade.php`, `card-closed.blade.php`, `vite.config.afs.js` input.
- **Config:** `config/memberships.php` gained `card_page_url`, `paused_grace_days`, `single_level_99`.
- **Fix made this session:** removed `BillingSessionController@complete` from `app/Externals/Security/RoutePolicy.php::PUBLIC_WRITES` (that list only covers externals routes; the Brain route is `brain.only` + throttled).

### 3.2 Tests

New files, all passing (last run: `MemberBillingTest`, `StaffMemberCreateBillingTest`, `SingleLevel99Test` = **39 tests, 113 assertions OK**):
`tests/Unit/Memberships/FreePeriodTest`, `tests/Feature/DirectPayments/StartOptionsTest` (7), `ProviderSubscriptionCreatorTest` (+4), `tests/Feature/Membership/{PausedSubscriptionGraceTest,SingleLevel99Test,MemberBillingTest,StaffMemberCreateBillingTest}`. The earlier targeted run (Unit/Memberships, Feature/Membership, StartOptions, creator, notification-module test) had 134 tests with only the failures in the three files above, since fixed.

Older files re-run one at a time and passing: `BrainStaffSubscriptionTest` (34), `BrainStaffWhoamiTest` (7), `BrainStaffWriteTest` (14), `DirectPaymentSubscriptionUpdateMembershipTest` (4), `TierResolverFollowsSubscriptionTest` (7), `RoutePolicyTest` (3, after the fix), `Unit/DirectPayments/LayeringTest` (2), `ActivityCatalogTest` (8), `WebhookCandidateTest` (3), `ProviderEndedSubscriptionTest` (6), `DirectPaymentCheckoutPriceTest` (6), `NotificationRulesApiTest` (24), `ActivityPublisherTest` (17).

Test-writing lessons from this session: a private helper named `post`, `options`, `get`, `put`, `json`… in a test class clashes with Laravel's `TestCase` and fatals the whole run — pick another name. A `Processor` row needs `publishable_key`. A mocked `StaffAudit::log` must return a `BrainMembershipEvent`.

### 3.3 Close-out of step 13 (DONE 2026-10-20: items 1-6 finished; only the commit, item 7, waits for the owner)

1. **Re-run `tests/Feature/DirectPayments/DefaultEmailsTest.php`** (one file). I changed its `assertCount(14, $events)` to 15 because `subscription_paused` added a default e-mail; the edit is on disk but I never saw the file pass. Its sibling `DirectPaymentsNotificationModuleTest` was updated earlier to 14 events in its own terms — check both counts agree with `DirectPaymentDefaultEmail::events()`.
2. **Compile and test the TypeScript:** `scratchpad/tsc_run.ps1` (members/www `tsc --noEmit`) and `npm run test:admin`. `card.ts` is new and has never been compiled. House rule: no optional chaining, no `any`.
3. **Fix the completion report** `complete/13-staff-member-lifecycle-server.complete.md`: replace the `RESULT_PLACEHOLDER` "wide run" row. State plainly that the whole-directory suites (Direct Payments, Processor, Brain, Notifications, Externals) were **not** completed — two attempts hit a 600 s limit and took hours — and list the per-file reruns in 3.2 instead. Add the two older-test fixes (RoutePolicy list entry, DefaultEmails count). Add the TS result from step 2.
4. **README:** update row 13 in `README.md` (currently "Plan only") and the status banner; add the `AFS_MEMBERSHIP_MODIFIER_ADDITION` marker note if the plan folder uses one.
5. **Memory:** update `C:\Users\mrmit\.claude\projects\C--projects\memory\membership-welcome-alerts.md` (step 13 built, unverified; the test-name pitfalls above) and keep the one-line pointer in `MEMORY.md` accurate.
6. Confirm no `php`, `mariadbd`, `node` or `electron` is left running (currently none; MariaDB was shut down).
7. Do **not** commit. When the owner says to, commit per repo and **exclude the owner's own `MembershipController` hunk**.

### 3.4 Known deviations (already in the draft report)

No DP invoice is saved for the no-card free-period path (the first charge's invoice arrives via Direct Payments' normal webhook); card brand/last four not shown; `processors` kept in create-options until step 14; `MemberBilling` replaces the planned `StaffSubscriptionStarter` plan name; no `PayerService::updateAddress` (uses `stripeCustomerAddress`); `access` override on cancel; `subscription_paused` notice added and on by default; Stripe `resume` uses raw curl so it is not testable with `Http::fake`; the staff HTTP tests call controllers directly with collaborators mocked (middleware and routes are covered by `route:list` and the existing staff-auth tests).

### 3.5 Unverified (must be said to the owner)

Nothing has run against real Stripe: pause at the end of a free period, resume on a new card, `automatic_tax`, and the `paused|resumed` webhooks. The card page has not been opened in a browser. Run `processors:set-automatic-tax <ref> on` only after Stripe Tax is confirmed on the development Stripe account (it also applies to public signup once on). Env to set (none are in `_env/*/.env` yet): `IS_BRAIN=true`, `MEMBERSHIPS_LOGIN_URL`, `MEMBERSHIPS_CARD_PAGE_URL`, optional `MEMBERSHIPS_PAUSED_GRACE_DAYS`, `CHECKPOINT_AFTER_RESET_URL` (auth). Deploy order: `auth` before `www`; migrations `2026_10_20_000001` and `…000002` before the code; build the `afs` bundle for `card.ts`. `LaravelCronScriptTest` has 18 known unrelated errors.

## 4. After step 13

- **Step 12** (phone: add member + alerts, level A in-app only) and **step 14** (phone + dashboard screens that send `password_mode`, `send_welcome`, `billing.mode`, `billing.free_period`, open the card page in a web view, show `billing_setup`, and drop the `processors` dependency) are planned, not built. The phone app is `afsbrain_flutter` (no `dart format`; check with analyze only); the dashboard is `w/www/resources/js/portal/admin/controllers/brain`.
- **Step M8** (retirement) not started.

## 5. How to run things without freezing the PC

- One heavy process at a time, below-normal priority; kill leftovers (`php`, `mariadbd`, `node`, `electron`) when done. The PC froze twice before.
- **Whole directories are far too slow** on MariaDB (each test rebuilds the schema; roughly 3 hours for ~1,650 tests). Run **single files**, with a cap, e.g. `scratchpad/affected2.sh` (reads file paths from `/tmp/f.txt`, 420 s cap each, writes `scratchpad/affected2.out`). Never use `--no-progress`; background it and watch the log.
- Start the DB: `bash scratchpad/startdb.sh` (portable MariaDB `C:\Users\mrmit\mariadb`, port 3307; create `testdb_phpunit` if missing). Run: `bash scratchpad/runt.sh <logname> <phpunit paths>` (sets `DB_*`, `AFS_TEST_WIPE=1`, `APP_ENV=testing`). Shut down: `mariadb-admin.exe -uroot -h127.0.0.1 -P3307 shutdown` from `C:\Users\mrmit\mariadb\mariadb-11.4.5-winx64\bin`.
- Scratchpad: `C:\Users\mrmit\AppData\Local\Temp\claude\C--projects\efb40551-75a8-492e-a5d4-c3770bbf5f41\scratchpad`.
- Do not rewrite production code for SQLite compatibility; tests run on the real MySQL-family database.
- Shell pitfalls: Bash heredocs eat backslashes and apostrophes — write PHP/regex-heavy code with Write/Edit; locales.json edits need anchored regexes and a diff against a baseline.

## 6. House rules that bite in this plan folder

Step = a file, phase = work inside it (P0/P1/P2); never say "unit". One completion doc per plan file, named after it, none in `complete/` README. Off-plan fixes go in `complete/00_extras.md` as dated E* sections. TypeScript: no optional chaining, no `any`. No `app()` service location — inject. Never log in as the agent. Never change an app `.env` automatically. Never `config:cache`. Ignore `packages/wwwafsportal_v1`. Say PHP vs TypeScript and link the file when explaining.


## 7. After steps 12 and 14 (2026-10-20)

- Built: phone Alerts (`afsbrain_flutter/lib/features/alerts`), phone wizard + billing (`features/billing_setup`, `features/members/ui/add_member_wizard.dart`), dashboard wizard/subscription/payments (`w/www/resources/js/portal/admin/controllers/brain/brain_member_*.ts`, `services/memberStaff*.ts`). Server: `create-options` gained `plans[].paid`, `free_periods[].ends_on`, `paused_grace_days`; `whoami` gained `member_alerts`.
- Results: Flutter whole suite 1,862 (the one failure fixed), `test:admin` 135/135, `tsc` clean, PHP touched files 26/26. **The whole PHP suite was never completed** (it died on a 600 s limit, now lifted in `tests/TestCase.php`; it still needs over an hour because each test rebuilds the tables). The other membership PHP files were not re-run this turn.
- Never leave a process running (AGENTS.md "Background processes"): run a process check as the last step. One whole-suite run here sat in the background for hours; do not repeat that.
- Check before relying on alerts: the Brain's externals security mode must be `enforce` (not checked).
- Nothing is committed. Commit per repo (`w/www`, `afsbrain_flutter`) only when the owner says, and exclude the owner's own `MembershipController` hunk.

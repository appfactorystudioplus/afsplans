# 12 — Rollout and testing

## Completion document

Not yet carried out. When it is: `complete/12-rollout-and-testing.complete.md`.

Vocabulary: a **step** is a numbered file in this folder; a **phase** (`P0`…) is a division of work. Each phase is installable on the
phone and checkable against lab2 on its own, and ends with an **exit check** — a statement that is true or false.

## 12.1 Phase table

| Phase | Name | Server (BS#, [04](04-server-additions.md)) | App | Depends on |
|-------|------|--------------------------------------------|-----|-----------|
| **P0** | Foundations | BS1 token command + table, BS2 `whoami` (identity + capabilities), BS12 config | Create project; `config.dart`; `.env` scheme; `use-env`/`check-env`; lab CA; `BrainApiClient`; error model; `SessionCubit` handshake; env badge; failure screens | — |
| **P1** | Members: read | BS3 list filters, BS4 detail | List (search/filter/sort/paging/cache); detail with Details + Activity | P0 |
| **P2** | Members: write | BS5 address edit, BS7 create-options, BS10 events | Add member; edit profile; set plan; set status; stale handling | P1 |
| **P3** | Remove (interim) | — | R1: suspend / mark for deletion sheet | P2 |
| **P3b** | Remove (real) | *(account_close's own routes)* | R2: soft/hard delete UI, capability-gated | **P3 + `account_close` shipped** |
| **P4** | Subscriptions: read | BS6 `GET`, resolver, BS8 payments, BS9 overview | Subscription tab, Payments tab, Billing destination, ledger | P1 |
| **P5** | Subscriptions: act | BS6 sync/cancel/plans/change-tier, `SubscriptionActionsFactory` extraction | Sync, cancel, change plan, refund, with reasons and typed confirms | P4, a sandbox subscription ([07](07-subscriptions-direct-payments.md) §7.10) |
| **P5b** | Pricing wrappers *(optional)* | BS11 | Discount / amount / billing date | P5 |
| **P6** | Allocations | — | Usage list, overrides list/add/remove | P1 |
| **P6b** | Counter adjust *(optional)* | — | Single-row adjust with per-row conflict | P6 |
| **P7** | More | — | Checkpoint errors, afs-connect | P2 |
| **P8** | Release hardening | — | App lock, `FLAG_SECURE`, production build, signing, docs | P1–P7 as built |
| **P9** | Apps control plane *(optional)* | — | `brain/apps` client | P8 |
| **P10–P16** | Brain infrastructure | BS13–BS25 | the Infra destination: read-only console, fleet writes, config writes, DNS writes, tenant transfer and DNS apply jobs, Apps (P9 moves here as P15), Storage | defined, with their exit checks, in [13](13-brain-infrastructure.md) §13.7 and §13.8 |

Order of delivery is **P0 → P1 → P2 → P3 → P4 → P5 → P6 → P7 → P8**, with P3b/P5b/P6b/P9 inserted when their prerequisites exist.
P4 may run in parallel with P2 (different screens, different server files). Nothing in P1–P7 changes if `account_close` ships late.

## 12.2 Exit checks

| Phase | Exit check (all against lab2, on the phone) |
|-------|--------------------------------------------|
| **P0** | A freshly minted lab device token in `_env/lab2/.env`, `use-env lab2`, `flutter run` → the app shows **LAB2**, signs in as `appfactorystudio` (level 99) with **no login form**, and lists capabilities in Settings. Each failure screen is reachable by tampering with the `.env` (empty token; bad token; a non-Brain user's token; wrong `BRAIN_MEMBER_REF`; host down; no CA). `check-env.py` passes; the hard-coded-URL gate ([§12.3](#123-gates-run-before-every-phase-is-called-done)) is clean. One bearer `PUT` probe against a throwaway field succeeds ([BA19](01-current-state-audit.md)) |
| **P1** | The three lab members list; search `james` returns two; plan filter works; detail opens for each; Activity renders (empty is valid); the first page paints from cache on a cold start with Wi-Fi off |
| **P2** | Create → appears in the browser SPA and `GET /brain/member`; duplicate username → field error, no Checkpoint call; edit city → visible in the browser; plan change with reason → `brain_membership_events` row with `source admin_mobile` and the device label; stale edit → conflict state; no write possible for `appfactorystudio` |
| **P3** | Suspend and mark-for-deletion set status 2/3 and are audited; the sheet states nothing was deleted |
| **P3b** | *(When `account_close` ships.)* Soft delete → restore → hard delete on a throwaway member, preview-token expiry honoured, progress polling survives backgrounding |
| **P4** | `james1`/`james2`/`appfactorystudio` Subscription tab renders `none` or `payer_customer` states with no error; Payments shows the empty state; Billing shows "Enforcement off · 0"; a fixture for every status/resolution renders |
| **P5** | With a sandbox Stripe subscription: sync updates `last_synced_at`; change plan updates the link tier **and** `brain_memberships.membership`; cancel sets `canceled_at`/`access_ends_at` and does **not** change the membership; full and partial refunds update the ledger; each refuses without a reason; an induced timeout re-reads and never re-sends; the self-service endpoints' PHPUnit suite is green after the factory extraction |
| **P6** | `james1` Usage lists real rows (or the clear "ledger unavailable"); override add/remove round-trips with the browser SPA; reason < 10 never sends |
| **P7** | Checkpoint errors list/resolve; afs-connect read/save, with the coordinator-down state shown honestly |
| **P8** | `production` build (once production exists): red badge, typed confirmations, app lock, no screenshots, `INTERNET`(+`USE_BIOMETRIC`) only, `adb backup` yields nothing; lab build unaffected |

## 12.3 Gates (run before any phase is called done)

A small script `afsbrain_flutter/tool/gates.sh` (written in P0; **no installs**, only `grep`/`flutter analyze`/`flutter test`):

| Gate | Check |
|------|-------|
| **G1 no hard-coded host** | `grep -rnE "https?://|sslip\.io|appfactory|10\.0\.0\.|/portal/api" lib/` finds nothing outside doc comments |
| **G2 no forbidden routes** | `grep -rn "processors/billing" lib/ test/` is empty; `api_paths.dart` contains none of `login`, `billing/`, `directpayments/{…}/subscription` |
| **G3 token hygiene** | `grep -rniE "print\(.*(token|password|checkpoint_session)" lib/` empty; the `redact()` unit test passes; no code reads `checkpoint_session` |
| **G4 env parity** | `python packages/afsserver/bin/check-env/check-env.py` passes for all five modes |
| **G5 analyze** | `flutter analyze` clean (`flutter_lints`); no `dynamic` in model files; no `late` in models |
| **G6 tests** | `flutter test` green |
| **G7 gitignore** | `git check-ignore afsbrain_flutter/.env afsbrain_flutter/_env/lab2/.env android/key.properties` all ignored (only meaningful once you `git init`; until then the file list is checked by hand) |

Server gates: PHPUnit for the new controllers/command green; the existing `DirectPaymentSubscriptionController`, memberships and allocation tests green;
`php artisan route:list` shows every route in [04](04-server-additions.md) under the right middleware.

## 12.4 Test strategy

### App

- **Unit:** every model's `fromJson` against **real captured lab2 responses** stored in `test/fixtures/` (one JSON file per contract in
  [04](04-server-additions.md), plus the existing routes' responses). Fixtures are captured by a documented manual step with the device token
  (so the agent never needs to hold it) and have PII and tokens redacted; each includes the odd shapes: `null` payer, `incomplete` status,
  `canceled_at` set, empty arrays.
- **Error model:** a table-driven test feeding each wire shape of [05](05-app-architecture.md) §5.5 to the parser and asserting the kind;
  404-from-level-99 vs plain 404.
- **Cubits (`bloc_test`)** with fake repositories: list paging + stale-response dropping; detail refresh; every write's
  `saving → saved | stale | validation | unknownOutcome → (re-read) → saved | failed` path; handshake state machine.
- **Widget tests:** failure screens contain no `TextField`; destructive sheets disable confirm until reason/typed text valid; the env badge
  colour per `IS_PRODUCTION`/`ENV_LABEL`; capability-driven absence of tabs/buttons; a TalkBack semantics check on the usage bar.
- **Client:** `MockClient` asserts the bearer header, the timeout multiplier on provider routes, no retry on writes, one retry on a read,
  redirect-to-another-host refusal, and that the registry fetch sends **no** `Authorization`.
- **Integration (on the phone, manual script):** the exit checks above, run in order, recorded in the phase's completion document.

### Server

PHPUnit against the real MySQL schema (never SQLite): the command, `whoami`, filters (with a golden for the unchanged default response),
detail aggregation, address edit, the resolver's four paths using shapes copied from lab2, the three subscription actions with the
provider seam faked, payments/overview. No test calls Stripe, Square, PayPal, Checkpoint or Parse live.

### Data for testing

lab2 has three members and almost no billing data. Test members are created through the app's own Add flow with an obvious prefix
(`zz_bf_…`), used, and removed again (R1 → and, once available, R2). Anything a test creates is listed in that phase's completion
document under "left behind". The one seeded sandbox subscription is `incomplete` and is not used for P5.

## 12.5 Risks

| # | Risk | Mitigation |
|---|------|-----------|
| R1 | The device token (full Brain power) sits in the APK asset | Finite lifetime in production, `--revoke-all`, `allowBackup=false`, app lock, gitignored env files ([02](02-authentication-device-token.md) §2.7) |
| R2 | Editing production believing it is the lab | Always-on coloured badge, SANDBOX/LIVE chip, typed confirmations on production ([10](10-ui-and-navigation.md) §10.2) |
| R3 | A write succeeds but the response is lost (cancel, refund, create) | `unknownOutcome` + re-read, never auto-retry ([05](05-app-architecture.md) §5.6) |
| R4 | Cancel/plan change moves money at a provider | Reason + typed confirm + sandbox chip; provider mode visible; server audit with device label |
| R5 | Refactoring `actionsFor()` regresses the members' self-service cancel | Extraction without behaviour change; existing tests as the gate; P5 exit check |
| R6 | Member→subscription resolution disagrees with enforcement | The resolver's first rule **is** `MembershipTierResolver` ([04](04-server-additions.md) §4.8.1) |
| R7 | `account_close` ships with different routes than its plan | P3b is built from its *completion document*, not its plan; capability-gated so the app is never ahead of the server |
| R8 | Lab unreachable from the phone (Wi-Fi, VM down, CA not installed) | Distinct Unreachable/TLS screens naming the host and the fix ([03](03-environments-and-config.md) §3.6) |
| R9 | Server error shapes drift | One normaliser, table-driven tests, fixtures from real responses |
| R10 | The Brain's own member accidentally edited from the phone | App hides actions; server `BS_E_SELF_ACTION`; allocation self-edit already refused |

## 12.6 Rollback

The app is a client. **Server changes are additive** (new routes, one new table, new optional query parameters, one extraction): rolling
back is not deploying them, or `php artisan brain:device-token --revoke-all` to cut the phone off instantly. The browser SPA never depended on
any of them. `migrate:rollback` drops only `brain_device_tokens`.

## 12.7 Documentation produced when work lands

- One completion document per **finished step file**, named after it, in `complete/` (e.g. `02-authentication-device-token.complete.md`), with a
  phase table and the phase's exit-check results, **written only when the file's work is finished**.
- Off-plan fixes (a bug found in `www` while doing this) go in `complete/00_extras.md` as dated `E*` sections, not in new numbered files.
- `plans/markers.md` gains `AFS_BRAIN_FLUTTER_MODIFIER_ADDITION`.
- `afsbrain_flutter/README.md` (written in P8): what it is, how to switch environment, how to mint a token, how to build and install —
  the commands of [11](11-android-build-and-deploy.md) §11.5 verbatim.
- `w/ENVIRONMENTS.md` §4 gains `afsbrain_flutter` in the list of switched client apps and says `check-env` covers it.
- `plans/TODOS.md` receives the "observed, not fixed" list of [01](01-current-state-audit.md) §1.9.

## 12.8 Definition of done (the whole plan)

1. The phone opens straight into the Members list as `appfactorystudio`, with no login, on lab2 and on production.
2. You can see every member, add one, edit one, set their plan and status, and remove one (R1 now, R2 when `account_close` exists).
3. You can see any member's subscription and billing state, sync it, change its plan, cancel it and refund a charge, all through Direct
   Payments, with every action audited as `admin_mobile`.
4. You can see usage and grant or revoke a limit override.
5. No URL, host or API prefix is in the code; switching environment is `use-env` + a rebuild.
6. Every gate in §12.3 is green and every exit check in §12.2 is recorded.

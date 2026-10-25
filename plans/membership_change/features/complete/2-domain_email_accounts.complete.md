# Feature 2 — `domain_email_accounts` (Email Accounts) — Execution Plan

> Renumbered 2026-09-22: this was step 05 in `membership_change/`'s main sequence
> (`05-feature-email.md`/`05-feature-email.complete.md`). It moved to
> `membership_change/features/` and is now numbered by its `TODO-member-resources.md` entry
> (#2) instead — see `../../README.md`. "Step 04"/"Step 05"/"Step 06" mentions below are
> historical narrative from when this and storage were steps in that sequence; left as
> written rather than rewritten throughout a long document for a cosmetic renumbering.

## Status

**Update 2026-10-05 — code written for most of the remaining items; NONE of it has been run** (no PHP, Node or
Flutter on the machine that wrote it). Everything below is "written, unrun" until the tests are run on a lab computer.

Built (in plan order):

| Plan item | Where | Notes |
|---|---|---|
| Band assignment on create (§F2.4) | `members` `EmailRetentionService::bandCapacities()` / `assignBand()`; `POST /api/internal/www/v1/email/assign-band`; `www` `MembersEmailClient::assignBand()`, `EmailAccountController::createEmailAccount()` | New account lands in the lowest band with a free slot (slots `[2,8,40,200,∞]` from `[2,10,50,250,-1]`). If the only room is above the plan it starts `disabled`. If `members` cannot answer, the account is still created (band 0, active) and the backfill can fix it. **This was missing entirely: every account was band 0, so a downgrade could never retire any.** |
| `band` / `status` on the model; shared check | `www` `UsersEmail` (`$fillable`, `$casts`, `isActive()` / `canSend()`) | |
| Send gate (§F2.3.2) | `www` `MailMuaController::sendEmail()` | Reads the mailbox row on every send (not the 120-minute cookie); `403 ACCOUNT_DISABLED`; a mailbox that no longer exists cannot send. Inbound untouched. |
| `update()` takes `band` / `status` with a free-slot check (§F2.5) | `www` `EmailAccountController::update()` + `bandRefusal()`; `members` `moveAccount()` / `setAccountStatus()`; `POST .../email/move`, `.../email/status`; `www` `MembersEmailClient::moveAccount()` / `setAccountStatus()` | Codes per 03-api-contracts §3.2 (`BAND_FULL` 413, `BAND_NOT_OWNED` 403, `BAND_RETIRED` 423, `SUSPENDED` 423, ...). Moving an account OUT of a retired band into a kept one re-enables it (the evacuation). Re-enabling an account in a retired band is refused. |
| Backfill of bands for existing accounts | `members` `php artisan email:backfill-bands [--dry-run] [--domain=] [--force]` | Spreads a domain oldest-first by slot size; touches only domains that look unbanded (all in band 0 and more than band 0 holds) unless `--force`; sets accounts above the plan `disabled`; reconciles after. A members command, not a `www` migration as this plan first said, because slot sizes come from the registry at run time. |
| Band / Status columns, move and disable controls (§F2.5) | `www` `resources/js/portal/admin/views/email.ts`, `html/views/email.html`, `types/email.ts`, `store/DataStore.ts` | Band select (bands 0..plan; a retired band is shown and disabled), Status badge with Disable / Enable. **Needs `npm run build` in `w/www`.** |
| Platform-critical addresses pinned to band 0 (F2.13 / OQ-G, **decided 2026-10-05**) | `members` `config/email_retention.php`, `EmailRetentionService` (`pinnedUsernames`, `isPinnedUsername`, `healPinned`, `assignBand($username)`, `moveAccount`, `setAccountStatus`, `canDeleteAccount`), `POST .../email/can-delete`, backfill; `www` `MembersEmailClient::canDeleteAccount()`, `EmailAccountController::destroy()` | Default names `postmaster`, `abuse`, `no-reply`, `noreply` (the invitation mail is sent from `no-reply`); `EMAIL_PINNED_USERNAMES` replaces the list. Always band 0 and active; cannot be moved, disabled or deleted (`ACCOUNT_PINNED` 409); retire / restore / purge skip them; `reconcile()` puts a misplaced one back. Delete fails closed if `members` cannot answer. **Open:** other addresses the platform sends from (the mail-handler `from` values) are not discovered automatically; add them to `EMAIL_PINNED_USERNAMES`. |
| Delete really deletes (`DataStore.deleteEmailAccount`) | `www` `store/DataStore.ts`, `views/email.ts` | It used to remove the row from the local cache whatever the server answered, so a refused or failed delete looked successful. Now only a successful delete removes it, and the refusal is shown. |
| Notice before purge (OQ-D) | `members` `EmailRetentionService` (`notifyRetired`, `sendPurgeWarnings`, `sendNotice`), `EmailRetentionSweep`, `config/brain_manifest.php` (events `email.band_retired`, `email.purge_warning`; version `2026.10.05-1`), `EmailBandUsage`; `www` migration `2026_10_05_000001_add_notice_columns_to_members_email_band_usage` | Mail goes through the Brain (`Brain::mail`), so this app renders and transports nothing. One "your accounts were disabled" mail per domain per downgrade (T+0), and one "deleted in about a week" reminder (T-7). Each is marked on the ledger (`retired_notified_at`, `purge_warned_at`) only once the Brain accepts the mail, so a refused one is retried by the next sweep; restoring a band clears the markers. Not `essential` (plans/www_brain/05 §5.5: no fallback mailer exists), and the Brain's fallback body takes no variables. **Needs `php artisan brain:register-manifest` in `members` after deploy, and the `www` migration.** |
| Bands panel and "see what would be affected" (§F2.5, D10) | `www` `GET email-accounts/state` and `/preview`, `MembersEmailClient::stateEmail()`; `members` signed `POST email/state`, `state()` now reports `capacity` per band and an honest `can_evacuate` / `evacuation_headroom` (it was hard-coded `true`); `email.ts` / `email.html` "Plan and bands" card | Shows used / capacity per band, which are retired and when they are deleted, whether anything can be rescued, and a plan picker that previews a change. The numbers are `members`'. |
| `canUse` fails closed (M13) | `www` `email.ts` | The save path used to skip the check when `allocV2` or the domain was not set up; it now refuses ("Unable to verify email account quota"). It still refuses on an error and on `available: false`. |
| Sweep first-row gap | `members` `EmailRetentionService::knownDomains()`, used by `EmailRetentionSweep` | A domain with mailboxes and an active zone in `brain_dns_zones` is found cold, so it gets a ledger row without anyone viewing the page. Closes the "no domain→member lookup" gap. |
| Cross-repo search for other senders | search only (§F2.3.2 open item) | No other repo under `w/` uses `users_email`. In `www`, `EmailManagerController::send_email()` also sends through a mailbox's SMTP login and `create_email2()` inserts `users_email` rows, but it is called as a bare global `send_email(...)` (not `$this->`) and is not routed: dead legacy code, not gated, flagged. |

Tests written (unrun): `members/tests/Feature/Email/EmailBandAssignmentTest.php` (capacities, assignment, move, status,
`knownDomains`, backfill, pinning, notices, state capacity and `can_evacuate`), `members/tests/Feature/Www/EmailBandRoutesTest.php` (the signed routes), `www/tests/Feature/Members/MembersEmailClientBandsTest.php`, `www/tests/Unit/UsersEmailCanSendTest.php`. Pinning cases are in the same files.

**Also removed 2026-10-05:** the server-side quota code written earlier the same day outside this plan
(mailbox-count check, monthly send reservation). The forward edge stays `CheckpointAllocV2` at the call sites.

**Still open for this feature:**
- **Login gate: DECIDED 2026-10-05, none.** A disabled mailbox can log in and read but not send (D4). `login()` is unchanged.
- **Mail-handler `from` addresses** are not discovered automatically for pinning; add them to `EMAIL_PINNED_USERNAMES`.
- **Cascade from a retiring domain** (blocked on `member_domains_count` #1 having retention).
- **Tests are written but not yet run**; only the cascade tests are missing (blocked on #1).


**PHASES 1-3 COMPLETE. E1 and E4 FIXED, adapter relocated (2026-09-21).** Database schema ready, `EmailRetentionAdapter` built, cascade logic updated. `list()`, `disable()`, and `enable()` were querying `domain_id`/`local_part`/`created_at`, columns that don't exist — fixed to use the real schema (`domain`/`username`/`date`), verified against `create_users_email_table.php` and `php -l`. No callers existed yet (nothing wires this adapter up — see §F2.14 E2/E3, still open), so the fix touched only this one file. Still unexercised against a real database — no test covers it.

**Relocated 2026-09-21, per direct instruction:** `EmailRetentionAdapter.php` moved from
`www/app/Services/Email/` to `members/app/Checkpoint/Email/` (namespace `App\Checkpoint\Email`)
— see [complete/25-storage_files_size_kb.complete.md](25-storage_files_size_kb.complete.md)'s Status
section for the full reasoning: `members` is now the one server-side implementation of the
checkpoint system, `www` (and everyone else) a caller, never a second copy. `users_email` is
read/written directly via query builder either way, since `members` and `www` share the
underlying database.

**OQ-B resolved 2026-09-20 — no mail-transport work needed at all.** Inbound delivery is
never touched by this feature at any lifecycle stage: once an account exists, it keeps
receiving mail permanently. Login and send are both gated by application-side checks against
the `users_email.status`/`band` columns — login wherever the account authenticates, send
wherever outbound mail is handed off for processing. **E4 fixed as part of the move above**:
the stale `// TODO: Postfix ...` comments from the earlier reading of OQ-B were deleted, not
implemented, when the file was rewritten at its new location.

**Commits:** Phase 1-3 implemented, one commit in each of the **`www`** and **`members`** repos (`w/` holds ten independent git repositories, not one monorepo — see `w/AGENTS.md` §2).

**Orchestration layer built 2026-09-22, per direct instruction, after a full-plan audit found
it missing entirely.** `EmailRetentionAdapter` correctly implemented `InventoryAdapter`, but
nothing called it — no controller, no command, not even itself. Storage's equivalent
(`QuotaService`/`RetentionService`) had no email counterpart at all. Built:

| File | Purpose |
|---|---|
| `www/database/migrations/2026_09_22_000001_create_members_email_retention_tables.php` | `members_email_band_usage` (the per-(domain, band) ledger — email's `BucketUsage` equivalent) and `members_email_retention_events` (its event log) |
| `members/app/Models/EmailBandUsage.php`, `EmailRetentionEvent.php` | Models for the two new tables |
| `members/app/Checkpoint/Email/EmailRetentionService.php` | **New.** `reconcile()`, `retire()`, `restore()`, `suspend()`/`unsuspend()`, `sweepPurge()`, `summaryFor()` — the layer that actually calls `RetentionEngine::effectiveState()` and dispatches to the adapter |
| `members/app/Checkpoint/Email/EmailRetentionAdapter.php` | `list()` gained an optional `band` filter — needed per-band lists, which the original whole-domain listing couldn't give |
| `members/app/Console/Commands/EmailRetentionSweep.php` | `php artisan email:retention-sweep`, scheduled hourly in `routes/console.php` |
| `members/tests/Feature/Email/EmailRetentionServiceTest.php` | **New.** 7 tests, 33 assertions, all passing on first run |

**A real, unsolved architectural gap, documented rather than papered over:** unlike storage
(member-scoped, so `BucketUsage` rows directly enumerate every member), `domain_email_accounts`
is per-domain, and **there is no domain→member lookup table anywhere in this codebase** —
`laravel_domains` is referenced as a comment in
`www/database/migrations/2026_05_15_000003_create_members_website_tables.php` but never
actually created. This is the exact same "inventory unknown" status
`TODO-member-resources.md` already records for `member_domains_count` (#1). Worked around the
way storage's own sweep works around not having a separate member registry: `reconcile()`
takes `$domain` **and** `$memberRef` explicitly, and `EmailRetentionSweep` enumerates
`EmailBandUsage::distinct('domain')` — i.e. domains that already have a ledger row. **This
means the sweep only reconciles domains something else has already touched at least once.**
Nothing creates that first row yet — the account-create/view path was explicitly kept out of
this build's scope (see "Still not done" below) — so on a fresh install this sweep is
currently a no-op every hour until that wiring exists.

**Cross-repo wiring built and verified live 2026-09-22, per direct instruction ("wire it into
EmailAccountController so reconcile() actually gets called").** `EmailAccountController` (www)
and `EmailRetentionService` (members) are separate Laravel apps — same class of problem as the
earlier `RetentionEngine` cross-repo reference bug, this time unavoidable since the controller
and the service genuinely can't live in the same app. Resolved with a new signed internal HTTP
channel, mirroring the existing `MarketSignature`/`AfsConnectSignature` pattern:

| File | Repo | Purpose |
|---|---|---|
| `app/Services/Www/WwwSignature.php` | members | Sign/verify HMAC-SHA256 (dual-key rotation via `key`/`key_next`) |
| `config/www_internal.php` | members | `WWW_INTERNAL_API_KEY`/`_NEXT` |
| `app/Http/Middleware/VerifyWwwInternalSignature.php` | members | Rejects unsigned/bad-signature requests with 401, logs `members.www_internal.signature_rejected` |
| `app/Http/Controllers/Www/EmailReconcileController.php` | members | `POST /api/internal/www/v1/email/reconcile` — validates `domain`/`member_reference_num`, calls `EmailRetentionService::reconcile()` then `summaryFor()` |
| `routes/www_internal.php`, `bootstrap/app.php` | members | Route registration under the `www.internal` middleware alias |
| `app/Services/Members/MembersSignature.php` | www | Sign side, mirrors `AfsConnectSignature` |
| `config/members_internal.php` | www | `MEMBERS_INTERNAL_API_URL`/`_KEY`/`_APP_NAME`/`_REQUEST_TIMEOUT_SECONDS` |
| `app/Services/Members/MembersEmailClient.php` | www | `reconcileEmail($domain, $memberRef)` — fire-and-forget, all failures logged and swallowed (returns `null`), never thrown |
| `app/Http/Controllers/Portal/Admin/EmailAccountController.php` | www | `index()` now calls `MembersEmailClient::reconcileEmail()` at the top, resolving `$domain`/`$memberRef` from `getSubdomainProperties()`'s `'Domain'`/`'Username'` keys (`'Username'` holds the member's `reference_num`, confirmed directly — `'SystemUser'` is an unrelated Linux account name, not this) |

**Verified with a real, live signed HTTP round trip in the vagrant lab (2026-09-22)**, not just
`php -l`/unit tests: generated the shared secret (`WWW_INTERNAL_API_KEY`/`MEMBERS_INTERNAL_API_KEY`,
same 64-char hex value, real `.env` values in both `w/members/.env` and `w/www/.env` — no
`.env.example`), regenerated members' optimized composer classmap
(`composer dump-autoload -o`, confirmed all 4 new classes present, avoiding a repeat of the
PSR-4 casing bug from `25-storage_files_size_kb`), then called
`MembersEmailClient::reconcileEmail()` from a `www` tinker session against the running lab.
Confirmed via `members`' own `storage/logs/laravel.log`
(`/home/appfactorystudio/websites/members/storage/logs/laravel.log` on `web`, not the shared-folder
path — Apache's vhost serves from `/home/appfactorystudio/websites/members/public`, not
`/vagrant/afs-eco/w/members` directly) that the request reached
`EmailRetentionService::reconcile()` and correctly no-opped with
`[EmailRetentionService] reconcile skipped: no membership row` for a made-up test domain/member —
proving DNS, TLS, HMAC verification, routing, and the service's own F1 fail-closed behavior all
work correctly end to end.

**Two unrelated environment defects found and fixed while chasing this down** (both blocking,
neither part of this feature's own code):
- `C:\Users\mrmit\.config\herd-lite\bin\php.ini`'s `curl.cainfo`/`openssl.cafile` pointed at
  `C:\laragon\etc\ssl\cacert-with-laragon.pem`, which no longer exists on this host — broke
  *every* outbound HTTPS call from PHP CLI, not just this one. Repointed at a new combined
  bundle (Git for Windows' public CA bundle + the lab's `mkcert-rootCA.pem`, concatenated into
  `cacert-combined.pem` next to `php.ini`) so both public HTTPS and `*.appfactory.test1` verify.
- `www/database/migrations/2026_09_22_000001_create_members_email_retention_tables.php` (built
  alongside the service layer, same day) had never actually been run against the lab database —
  `members_email_band_usage` didn't exist, so the live call 500'd until the migration was run on
  `web` via `vagrant ssh`.

**M9 fixed 2026-09-22, per direct instruction ("wire release() into EmailAccountController::destroy()").**
`EmailAccountController::destroy()` (www) now calls a new `App\Services\Checkpoint\CheckpointAllocClient::release()`
after deleting the row, so the `domain_email_accounts` quota the frontend's `allocV2.consume()`
(resources/js/portal/admin/views/email.ts) increments on create is actually given back on delete —
previously it only ever ratcheted up.

**This surfaced a real, separate, pre-existing infrastructure gap: the `allocConsume`/`allocRelease`
write path had never been reachable from this lab at all.** The first attempt used
`App\Http\Controllers\Checkpoint\AllocationV2Controller`'s `/checkpoint/alloc/v2/consume` and
`/release` HTTP routes (on `auth`) — these turned out to be **deliberately removed** from
`auth/routes/checkpoint.php` (dated comment there, `plans/alloc-v2-parse-direct/03 §3.8 step 6`):
orphaned once the frontend moved to calling Parse directly, and insecure (trusted a caller-supplied
`tier` with no lookup). The correct, current mechanism is the `allocConsume`/`allocRelease` **Parse
Cloud Functions** (`auth/public/checkpoint/parse/cloud/functions.ts`), authenticated for a
server-side caller (no user token, exactly `destroy()`'s situation) via a shared `ALLOC_SERVICE_KEY`
— the same pattern `coordinator`'s `App\Services\AfsConnect\Allocation\ParseAllocationGateway`
already uses. `CheckpointAllocClient` was rewritten to call the Cloud Function directly, and
`config/services.php`'s `parse` block gained an `alloc_service_key` entry.

**Verifying that live then surfaced a second gap: no Parse server was reachable from this vagrant
lab at all** — `www`'s `.env` pointed `PARSE_SERVER_URL` at the remote, shared
`dashboard15-registry.appfactory.studio`, whose actual running `ALLOC_SERVICE_KEY` doesn't match
what's checked into any local repo (`{"code":119,"error":"Invalid service key."}`). Per direct
instruction, stood up a real Parse Server + Dashboard inside the lab instead of chasing the
remote server's configuration:

| File | Purpose |
|---|---|
| `auth/public/checkpoint/parse/.env.vagrant` | New env file for this mode. Fixed three values that would have silently broken it: `APP_ID` had a stray `"22"` suffix not matching every app's real Parse app ID; `CHECKPOINT_BASE_URL` was `https://auth.appfactory.test` (missing the lab's `1`); `ALLOC_SERVICE_KEY` was still the `CHANGE_ME` placeholder — set to the real value already shared by `coordinator` |
| `auth/public/checkpoint/parse/build.sh` | Added a `--vagrant` mode alongside the existing `--local`/`--production`/development modes — same shape as `--local`, but trusts the lab's own mkcert root CA (`/vagrant/certs/mkcert-rootCA.pem`) instead of Laragon's, for validating `https://auth.appfactory.test1` |
| `dns/test1.zone` | Added `dashboard-registry.appfactory.test1 → 192.168.56.11` |
| `provision/deploy-parse.sh` | **New.** Confirms MongoDB (already installed and running — `mongodb-org 8.0.32`, listening on `127.0.0.1:27017`, needed no changes), resolves nvm's node/npm/pm2, runs `build.sh --vagrant`, waits for `/parse/health`, then writes the nginx vhost (`/parse` → `:1340`, `/` → `:1341`, on the shared lab wildcard cert) |
| `Vagrantfile` | Registered `deploy-parse.sh` as a `parse` provisioner on `web`, `run: "never"` like `egress` — explicit via `vagrant provision web --provision-with parse`, not part of the default `vagrant up` |
| `www/.env` | `PARSE_SERVER_URL` switched from the remote studio host to `https://dashboard-registry.appfactory.test1/parse`; added `ALLOC_SERVICE_KEY` (real value, matching `auth`/`coordinator`) |

**Verified live, twice over:** first a direct `allocConsume`→`allocRelease` round trip against the
new lab Parse server (`used` went 0→1→0), then `CheckpointAllocClient::release()` itself called
through `www`'s real, unmodified `.env` (no runtime overrides) — no failure logged, matching the
success path. `php artisan test --filter=Email` (48 tests) and the full `www` suite (529 passed,
11 pre-existing failures unrelated to this work — hardcoded `https://www.test` hostname
assumptions in Appointments/Builder/Ecommerce/Events/Forms tests, none touching Email or
Checkpoint) both ran clean.

**Extended 2026-09-22, per direct instruction, to `members` and `coordinator`:** both repos'
`.env` `PARSE_SERVER_URL` switched to the lab Parse server the same way as `www`'s (their
`PARSE_APP_ID`/`PARSE_MASTER_KEY` and, for `coordinator`, `ALLOC_SERVICE_KEY` were already
correct — verified byte-for-byte, not assumed). Both confirmed reachable via a live `hello`
Cloud Function call.

**`coordinator`'s `ALLOC_USE_CLOUD_FUNCTIONS` flipped to `true`, per direct instruction, and
this surfaced a second, separate, pre-existing bug — `BrainMembershipLookup`'s HTTP fallback
(used since `coordinator`'s own DB has no `brain_memberships` table) called `www`'s
`GET /portal/api/v1/brain/member`, which sits behind `portal.admin.auth` (a browser session) —
a server-side caller with no user token could never pass that, so every allocation was denied
with `no_registry_object` regardless of the cloud-functions flag.** Fixed by switching that
fallback to `GET /portal/api/v1/brain/entitlement/{ref}`
(`BrainEntitlementController::show()` — its own docblock: "what a caller should ask instead
for anything security-relevant"), signed with the same HMAC (`AfsConnectSignature`,
`afs_connect.service` middleware) `WwwBrainClient` already uses successfully for a different
Brain API in this same namespace. Verified live: a real member resolves correctly
(`{"membership":4,"account_status":0}`, source `brain_api`), a genuinely missing member
fails closed (`null`, source `brain_api_not_found`, not conflated with a transport error), and
the full `ParseAllocationGateway::consume()`/`release()` public API round-trips correctly
through the real Cloud Functions this way. This also fixed a real test regression the flag
flip caused: `ParseAllocationGatewayTest` exercises the *other* branch (direct Parse REST
writes via `Http::fake()`) and has no `.env.testing` to isolate it from the real `.env`'s now-
`true` flag — fixed by pinning `Config::set('afs_connect.use_cloud_functions', false)` in that
test's own `setUp()`, not by touching the flag. Coordinator's full suite: 121 passed.

**Still not done** — none of these were in scope for "wire it into EmailAccountController" and
remain open exactly as previously documented: `UsersEmail::$fillable` gaining `band`/`status`;
`MailMuaController::login()`/`sendEmail()` gating on `status`; `email.ts` showing Band/Status
columns; the backfill migration for existing accounts; platform-critical address pinning; the
cross-repo search for other send call sites. The bootstrapping gap noted above (nothing creates
the first ledger row for a domain before `index()` is first viewed) is now closed for the "one
member views their email page" path specifically — `reconcile()` is called opportunistically on
every `index()` load — but the hourly sweep still only touches domains something has already
touched at least once; a domain nobody ever views still never gets a first ledger row. Every item
under "Success Criteria" below is still unchecked except where noted.

## Strategic Complexity

Email differs from storage in three critical ways:

| Aspect | Storage | Email |
|--------|---------|-------|
| **Scope** | Member | Per-domain (3 domains = 3 independent band sets) |
| **Retiring item** | Inert bytes | An access-control flag on a row (`status`/`band`) — inbound routing is never touched, so there is no live infrastructure to reach outside the database (resolved 2026-09-20, was previously assumed otherwise) |
| **Cascade depth** | None | Child of `member_domains_count` (domain retirement cascades to all its mailboxes) |
| **Evacuation** | Move files to another bucket | Move accounts to kept band (or delete during grace window) |
| **Irreversible effects** | Deletion (recoverable: restore within grace) | None — login/send are reversible flags; inbound is never suppressed in the first place |

## The Cascade Model (§F2.12)

Email mailboxes retire for **two independent triggers**:

1. **Direct:** Domain's email allowance drops (plan 3→1 = 250→10 accounts)
   - Member chooses which survive
   - Inherit the email plan's grace_days (30)

2. **Cascade:** Domain itself is retired (member_domains_count dropped)
   - Every mailbox retires regardless of email allowance
   - Inherit the domain's purge_after (cascade rule)
   - UI must offer domain-level rescue (upgrade/move domain), not mailbox chooser

**This is why per-feature code is impossible** — only the shared engine sees the full cascade tree.

## Assumptions (OQ-B — RESOLVED 2026-09-20)

**"Disabled" email account means:**
- **Inbound:** Accept and file, permanently, at every lifecycle stage — never touched by this
  feature, not even at purge
- **Read:** Allowed, gated by an `active`/status check in the application's login path
- **Send:** Blocked, gated by a check against `status`/band at the point outbound mail is
  handed off for processing — inside the application, not the mail-transport layer

**Why this option:**
- Reversible (a DB flag flips back; nothing was ever bounced)
- Complies with D3/D4 (readable and evacuable during grace)
- Matches storage semantics (can't use, but can recover)
- **Requires no mail-transport change of any kind** — settled directly by the user
  (2026-09-20), correcting this document's earlier assumption that send-blocking needed a
  Postfix-level relay restriction. It doesn't: the check happens wherever the application
  itself processes an outbound send request, before that request reaches any mail-transport
  layer.

**No alternative under consideration** — bounce-on-inbound was the option this design
explicitly avoids; it isn't on the table.

## Prerequisites (Must Address)

| # | Defect | Impact | Solution |
|---|--------|--------|----------|
| ~~M7~~ | ~~`--delete-email-account` stub~~ | **Not needed.** Purge never touches inbound routing, so there's no shell-level delete to build for this feature | — |
| ~~M8~~ | ~~`destroy()` leaves vmailbox route~~ | **Not a defect under the resolved model.** Mail is *supposed* to keep being delivered | — |
| ~~M9~~ | ~~`release()` never called~~ | **Fixed 2026-09-22.** `EmailAccountController::destroy()` now calls `CheckpointAllocClient::release()` | — |
| M10 | No status column | Can't record "disabled" | Add `status` column to users_email — **done**, see Phase 1 |
| M11 | No band assignment | Can't track which band owns which account | Add `band` column to users_email — **done**, see Phase 1 |
| M13 | `canUse()` fails open | Doesn't respect `block` overage | Fix to fail closed on add path |

## Files to Create/Modify (corrected 2026-09-21 — see §F2.3.2/§F2.3.3 of the main doc)

Every row below is in the **`www`** repo unless noted — the earlier version of this table put
several in `members`, which was wrong: `users_email` and its model both live in `www`.

| File | Type | Purpose |
|------|------|---------|
| `members/app/Checkpoint/Email/EmailRetentionAdapter.php` | Exists, moved 2026-09-21 | `list()`/`disable()`/`enable()` fixed (E1); stale Postfix TODOs deleted (E4) |
| `app/Http/Controllers/Portal/Mail/MailMuaController.php` | Modify, not yet done | Add the `status` check to `login()` (55-89) and to `sendEmail()` (188-235) — this is the located home of both checks, not a new controller |
| `app/Http/Controllers/Portal/Admin/EmailAccountController.php` | Modify, not yet done | `destroy()` (121-129) needs the `release()` call; `update()` (91-115) needs `band`/`status` added to its fillable-field list plus free-slot validation on a `band` change |
| `app/Models/UsersEmail.php` | Modify, not yet done | Add `band`/`status` to `$fillable`/`$casts`; add an `isActive()`/`canSend()` method as the one shared implementation of the check |
| `database/migrations/...create_users_email_table.php` | Already modified | `band`/`status` columns already folded in (Phase 1) |
| `resources/js/portal/admin/views/email.ts` | Modify, not yet done | Add Band/Status columns to `renderEmailAccounts()` (621) and move/disable controls — **this is the only UI file**, replacing the previous (incorrect) plan for a separate `EmailBandManager.ts` |
| `members/app/Checkpoint/RetentionEngine.php` | Moved from `www` 2026-09-21 | `cascadeRetirement()` for per-domain + per-store scopes |

**Not created, and no longer planned as separate files:** `EmailManagementController.php`,
`EmailBandManager.ts`, and a `members`-repo `EmailRetentionService.php` — none of these match
where the actual code lives once the login/send/UI locations were confirmed directly. A backfill
migration for existing accounts' bands is still needed and would belong in `www/database/migrations/`,
not `members/`.

## Implementation Progress

### Phase 1: Database Schema ✓ COMPLETE (corrected 2026-09-20)
- ✓ `band` (tinyint, 0-4) and `status` (enum: active|disabled|purging) columns exist on `users_email`
  — **not** via a standalone `add_band_to_users_email_table` migration in `members/`, as this
  document originally claimed. They were folded directly into
  `w/www/database/migrations/2026_05_11_000001_create_users_email_table.php`, which is the
  migration that owns this table. Verified by reading the migration file — its own inline
  comment says "folded from add_band_to_users_email_table".
- ✓ Indexes exist, but on **`(domain, band)`** and **`(domain, status)`** — the actual column is
  `domain` (a string), not `domain_id`. `EmailRetentionAdapter` was written against a
  `domain_id` column that was never created (§F2.14).
- ⏸ Backfill bands: marked for Phase 1b (separate data migration) — still not written.

### Phase 2: EmailRetentionAdapter ✓ COMPLETE, list() BROKEN (§F2.14)
- ✓ Implements `InventoryAdapter` interface
- ✗ `list(domain_id)` — queries `domain_id`, `local_part`, `created_at`; the real schema has
  `domain`, `username`, `date`. **This throws a SQL error on first call.** Never exercised.
- ✓ `move(email_id, toBand)` — update band column (atomic); uses `id`, which exists, so this
  one works
- ✓ `retire()` — set status='disabled'. **The `// TODO: Postfix outbound block` comment is
  stale** (§F2.14 E4) — under the resolved §F2.3 there is nothing further to implement here;
  the status flip *is* the whole mechanism, once something reads it at send time
- ✓ `restore()` — set status='active'. Same correction: no Postfix restore needed
- ✓ `purge()` — delete users_email row. No mailbox deletion, no route removal — inbound is
  never touched by design
- ✓ `disable()` / `enable()` — per-account control. Stale Postfix TODOs, same correction

### Phase 3: Cascade Support ✓ COMPLETE
- ✓ Updated engine `cascadeRetirement(retiredScopeType, retiredScopeKey, resources)` 
- ✓ Returns `{ direct: [...], children: [...] }` for transitive closure
- ✓ Per-domain resource handling (email accounts retire when domain retires)
- ✓ Per-store resource handling for future store-scoped resources

### Phase 4: Email Band Manager — DEFERRED
- [ ] Preview endpoint: what would change on a downgrade
- [ ] Band visualization: which accounts in which band, how many slots used
- [ ] Move UI: drag accounts between bands, validate constraints
- [ ] Cascading preview: show affected accounts if domain were retired

### Phase 5: Platform-Critical Addresses — DEFERRED
- [ ] Pin `postmaster@`, `abuse@`, laravel_mail_handlers address to band 0
- [ ] Validation: these addresses cannot be moved or deleted
- [ ] Constraint: band 0 must always have room for critical addresses

### Phase 6: Integration with RetentionEngine — DEFERRED
- [ ] Email adapter registered with engine
- [ ] Sweep includes email retirement
- [ ] Events logged (email_account_disabled, email_account_restored, email_account_purged)

### Phase 7: Tests — DEFERRED
- [ ] Direct retirement: email allowance drops
- [ ] Cascade retirement: domain is retired
- [ ] Restoration: upgrade restores accounts
- [ ] Suspension: account_status=2 freezes all bands
- [ ] Preview accuracy: shows cascade subtree
- [ ] Evacuation headroom: correct calculation
- [ ] Platform-critical addresses: pinned to band 0

## OQ-B — RESOLVED (was "Blocking Issue")

| Component | Status | Notes |
|-----------|--------|-------|
| Database schema | ✓ Done | band + status columns ready |
| Adapter interface | ✓ Done | retire/restore/purge methods defined |
| retire() implementation | ✓ Done | Sets `status='disabled'`. No mail-transport call needed — see §F2.14 E4 for the stale comment still in the file |
| restore() implementation | ✓ Done | Sets `status='active'`. Same — no mail-transport call needed |
| purge() implementation | ✓ Done (DB side) | Deletes the row. Inbound routing untouched, permanently, by design |
| Login gate | ⏸ Not yet located | Needs the actual authentication path for `users_email` identified and the `status` check added there |
| Send-time gate | ⏸ Not yet located | Needs the actual outbound-processing path identified and the `status`/band check added there |

**Resolution (2026-09-20, direct instruction):** inbound is never blocked, at any point,
because an account "will always exist" once created. Login and send are both ordinary
application-side checks against `users_email.status`, enforced at whatever code paths already
handle authentication and outbound send — this document does not yet know where those paths
are, and that's the actual remaining work, not any mail-transport integration.

## Open Questions (remaining)

- **OQ-G:** Do system addresses get pinned to band 0? (Assumed: yes for platform stability —
  still relevant, since a pinned address's *send* would otherwise get blocked on downgrade)
- **OQ-H:** Can member delete a disabled account? (Assumed: yes, encouraged)

## Risk & Mitigations

| Risk | Mitigation |
|------|-----------|
| Cascade interaction with domain retirement | Engine cascadeRetirement() handles; separate UI messaging |
| Platform mail undeliverable on downgrade | Critical addresses pinned to band 0 so their send is never blocked |
| Mailbox inaccessibility during grace | Read access allowed (test in Phase 7) |
| Concurrent account actions during retirement | Atomic band updates; status change is operation |

## Success Criteria

- [x] Email retention works identically to storage (band transitions, grace clock) — **for
      the service layer, 2026-09-22**: `EmailRetentionService` mirrors `RetentionService`'s
      transitions and clock behavior, tested directly (7 tests, 33 assertions). **Now also
      true end-to-end for the "view email page" path, 2026-09-22**: `EmailAccountController::index()`
      (www) calls `reconcile()` (members) over the new signed HTTP channel, verified with a live
      round trip in the vagrant lab. Still not true for any other trigger — nothing calls
      `reconcile()` on a plan downgrade itself, only on next page view, and the bootstrapping
      gap above still stands for domains nobody ever views
- [ ] Cascade from domain→email works (purge_after inherited) — blocked on
      `member_domains_count` (#1) having its own cascade machinery at all, which it doesn't;
      unrelated to today's build
- [ ] Preview shows accurate consequences (cascade subtree)
- [ ] Band management UI allows pre-downgrade planning
- [ ] Critical addresses always in band 0
- [x] Tests pass: direct + suspension — **2026-09-22**, 7/7. Cascade and evacuation
      scenarios remain untested — cascade because it doesn't exist yet (above), evacuation
      because there's no move-between-bands UI to test
- [ ] Login gate and send-time gate located and wired to `status`
- [ ] No live defects: M9 fixed 2026-09-22 (M7/M8 no longer apply — §F2.9 of the main doc). M13 (`canUse()` fails open) is still open — out of scope for today's "wire release() into destroy()" instruction

## Files Modified/Created (Phases 1-3)

| File | Type | Size | Purpose |
|------|------|------|---------|
| `www/database/migrations/2026_05_11_000001_create_users_email_table.php` | Modified | — | `band`/`status` columns and indexes folded into the table's own create migration (**not** a separate `members/` migration — corrected 2026-09-20; no such file exists in `members/`) |
| `members/app/Checkpoint/Email/EmailRetentionAdapter.php` | Moved from `www` 2026-09-21 | 313 L | InventoryAdapter for email (list, move, retire, restore, purge). `list()` fixed (E1); Postfix TODOs deleted (E4) |
| `members/app/Checkpoint/RetentionEngine.php` | Moved from `www` 2026-09-21 | +28 L | cascadeRetirement() for per-domain + per-store scopes |
| `membership_change/complete/2-domain_email_accounts.complete.md` | This | — | Execution plan |

## Commits (2 total)

1. **`www/` repo:** Phase 1-3 (schema migration, adapter, cascade)
2. **`members/` repo:** Phase 1 (database migration touching a different table — no `users_email`-related migration exists in this repo; the claim of a Phase-1 `members/` commit for this feature could not be verified against the actual git history)

## §F2.14 — Defects found on re-audit (2026-09-20)

Found while answering "is this actually done?" — verified against the live repos, not the plan text.

| # | Defect | Evidence | Impact |
|---|--------|----------|--------|
| **E1** | ~~`EmailRetentionAdapter::list()` queries `domain_id`, `local_part`, `created_at`~~ **FIXED 2026-09-20** | `users_email`'s actual columns are `domain`, `username`, `date` (`create_users_email_table.php`) | `list()`, `disable()`, `enable()` now query the real columns; scope key is the domain string (`$scope['key']`), matching `InventoryAdapter`'s documented contract. No caller exists yet, so this was never exercised against a database — still needs a test |
| **E2** | This document's Phase 1 claimed a standalone migration at `members/database/migrations/.../add_band_to_users_email_table.php` | No such file exists in the `members` repo; the columns are in `www`'s `create_users_email_table.php` instead | Documentation-only — the columns exist, just not where claimed. Anyone using this doc to find the migration would look in the wrong repo |
| **E3** | ~~`release()` for `domain_email_accounts` is still unwired~~ **FIXED 2026-09-22** | This was about the checkpoint quota counter (`MemberResource`/`allocRelease`), not the `InventoryAdapter`'s own `release()` — that interface has no such method (see `InventoryAdapter.php`); the real gap was `EmailAccountController::destroy()` (www) never releasing the unit `allocV2.consume()` had claimed on create | `EmailAccountController::destroy()` now calls `App\Services\Checkpoint\CheckpointAllocClient::release()`, verified with a live `allocConsume`→`allocRelease` round trip |
| **E4** | ~~`EmailRetentionAdapter`'s `retire()`/`restore()`/`purge()`/`disable()`/`enable()` still carry `// TODO: Postfix ...` comments~~ **FIXED 2026-09-21** | Left over from the pre-2026-09-20 reading of OQ-B, before it was resolved to "no mail-transport work at all" | Deleted when the file was rewritten at its new `members/app/Checkpoint/Email/` location, not implemented |

## What's Next (Phases 4-7)

**Prerequisites resolved:** Database schema and InventoryAdapter infrastructure complete. Cascade logic updated. OQ-B resolved — no mail-transport work is a prerequisite anymore.

**Remaining work — locations now confirmed (2026-09-21), nothing left to research to start:**
1. Delete the stale Postfix TODO comments in the adapter (E4)
2. `MailMuaController::login()` (55-89) — add the `status` check
3. `MailMuaController::sendEmail()` (188-235) — add the `status`/band check
4. Search the other nine `w/` repos for direct `users_email`-based sends; `www`'s
   `sendEmail()` is the only confirmed call site so far, and each repo is independent code
5. `app/Models/UsersEmail.php` — add `band`/`status` to `$fillable`/`$casts` (currently
   excluded from both, so mass assignment silently drops them today), add a shared
   `isActive()`/`canSend()` method
6. `EmailAccountController::destroy()` (121-129) — wire `release()`
7. `EmailAccountController::update()` (91-115) — add `band`/`status` to the endpoint's
   fillable-field list (settled 2026-09-21: extend this existing endpoint, not a separate
   one), **and** validate a `band` change against the target band's free-slot count before
   applying it — not a later add-on, without it an admin could move an account into an
   already-full band and quietly break §F2.5's "choose what survives" premise
8. `email.ts` — Band/Status columns in `renderEmailAccounts()`, move/disable controls (the
   only UI file — no new file)
9. Platform-critical address pinning: postmaster@, abuse@, etc. (still matters — protects
   *send*, not a mail route)
10. Integration tests: direct + cascade + suspension scenarios
11. Sweep integration: email adapter registered with engine
12. Add the `AFS_ALLOC_EMAIL` marker to every file touched by 2-9 above (§F2.3.3 of the main doc)

**Nothing is blocking anymore.** All of the above is ordinary application work, confirmed to
live in `www` (plus a cross-repo search for item 4); there's no open design question left —
item 7 was the last one and is now settled.

## Summary

**Phases 1-3 lay down the infrastructure layer, but `list()` was broken until 2026-09-20 and nothing above it has run.** Email's cascade model is registered in the shared engine and the band/status columns are real. What this document called "Postfix integration" turned out not to be needed at all: inbound is never touched, and login/send are ordinary database checks in application code — resolved directly by the user on 2026-09-20 after this document incorrectly treated mail-transport-level changes as unavoidable.

Email proves the cascade *design*, not yet the cascade *implementation*:
- Storage: member-scoped, no cascade — genuinely working (step 04)
- Email: per-domain scoped, cascade wired into the engine, adapter present but unverified end to end
- Future resources: extend the same pattern, but confirm each adapter against the real schema before marking it done, and don't assume external-system integration is required without checking — this step is why both checks matter

The shared engine handles both — email's complexity validates the design.

---

**Step 05 foundational infrastructure complete. OQ-B resolved, no longer blocking. Remaining work (Phases 4-7 plus locating the login/send-time check points) is ordinary application code with no external dependency.**

---

## Deploy and verify (2026-10-05 build, nothing here has been run)

None of the code in the "Update 2026-10-05" table has been executed; this Mac has no PHP or Node.

1. Sync `w/members` and `w/www` into the lab2 VMs.
2. `www`: run the new migration `2026_10_05_000001_add_notice_columns_to_members_email_band_usage.php`.
3. `members`: `php artisan brain:register-manifest` (manifest version `2026.10.05-1`, adds events `email.band_retired` and `email.purge_warning`).
4. `www`: `npm run build` in `w/www` (the email console view changed).
5. `members`: `php artisan email:backfill-bands --dry-run`, read the result, then run it without `--dry-run`.
6. Run the written tests: `tests/Feature/Email/EmailBandAssignmentTest.php` and `tests/Feature/Www/EmailBandRoutesTest.php` in `members`; `tests/Feature/Members/MembersEmailClientBandsTest.php` and `tests/Unit/UsersEmailCanSendTest.php` in `www`.

Left open: cascade from a retiring domain (depends on feature #1), auto-discovering mail-handler `from` addresses for pinning, and the dead, ungated `EmailManagerController::send_email()` in `members`.

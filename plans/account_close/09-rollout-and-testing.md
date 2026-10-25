# 09 — Rollout and testing

## Completion document

Not yet carried out. When it is: `complete/09-rollout-and-testing.complete.md`, with the pre-flight
answers ([§9.1](#91-pre-flight--verify-before-writing-code)), the phase-by-phase verification, and
the final acceptance table with pass/fail per row.

## 9.1 Pre-flight — verify before writing code

This plan was written **without running anything.** `DEVELOPMENT_MODE.md` is `vagrant`, so the lab
is reachable and every item below can be *answered* rather than assumed
(`AGENTS.md §1`: verify, do not infer). Record each answer in the P0 completion doc.

| # | Question | How (read-only) | Resolves |
|--:|----------|-----------------|----------|
| 1 | How many live members are at `account_status` 2 or 3, and does anything depend on them? | `mysql -h 192.168.56.11 -u root testdb_appfactorystudio -e "SELECT account_status, COUNT(*) FROM brain_memberships GROUP BY 1"` — access per `AFS-DEPLOYMENT-VAGRANT.md §4` | OQ-I |
| 2 | Does anything outside `www` read `account_status`? Re-check `coordinator`'s `BrainMembershipLookup.php`, `members`, `market` | `grep -rn "account_status" coordinator/app members/app market/app` (the earlier grep timed out) | [AC4](01-current-state-audit.md) |
| 3 | Which script set does **`members`** run on the server — does `core/scripts2` exist, and where does tenant/DNS teardown belong? | On the `web` VM: `ls /home/*/websites/*/core/scripts /home/*/websites/*/core/scripts2`; `grep -n "create-member-storage\|delete-email-account"` in `members`' `commands.sh` | OQ-A |
| 4 | Is `/mnt/node1/{ref}` the same tree as `/mnt/node1/{systemuser}`? | `ls /mnt/node1` on a lab member | OQ-B |
| 5 | Is `schedule:run` on cron for **`members`**? (`www`'s cron is irrelevant; there is no queue worker — [D17](00-overview.md)) | The command in [07 §7.4](07-notifications-and-scheduling.md) | OQ-H |
| 6 | Is `POST /checkpoint/auth/register` callable other than by `CheckpointServerClient`? Is `checkpoint.system` enforced on `/checkpoint/member/init`? | Read `auth/routes/checkpoint.php` and the middleware; then `curl` the route unauthenticated in the lab | [02 §2.7](02-state-model-and-enforcement.md), [08 §8.1](08-security-and-safety.md) |
| 7 | Delight's `Status` constants (the banned value) | `auth/vendor/delight-im/auth/src/Status.php` | [02 §2.4.3](02-state-model-and-enforcement.md) |
| 8 | Does MyISAM `ext_member_users` reuse a deleted maximum id? Are `email`/`username` collations case-insensitive? | `SHOW CREATE TABLE`; a throwaway insert/delete/insert in a scratch DB | [08 §8.7](08-security-and-safety.md), [02 §2.7](02-state-model-and-enforcement.md) |
| 9 | The *current* shape of `laravel_direct_payment_subscriptions` and `_notification_sends` — `member_reference_num` was dropped by `2026_09_23_000001` | `SHOW COLUMNS` | [03 §3.6](03-database-schema.md) |
| 10 | Where does each remote command run — Brain host or a tenant server over SSH? | Read `--create-domain-on-server` (801), `--admin-transfer-tenants` (868) | [05 §5.5](05-purge-engine.md) |
| 11 | Enumerate every scheduled command and job that touches `Membership::` / `member_reference_num` | grep `routes/console.php`, `app/Console/Commands`, `app/Jobs` in **`www` and `members`**; and the other apps' schedules | [05 §5.7](05-purge-engine.md) |
| 12 | **Operator action, not a plan step:** rotate the root MySQL password embedded in `delete_member.expect` (present in **both** `www` and `members`) | — |
| 13 | Which of the credentials in [07 §7.5](07-notifications-and-scheduling.md) does `members` already hold? Names only — never print values | `grep -o -E '^(CHECKPOINT|WWW|MARKET|COORDINATOR|AFS_CONNECT)[A-Z_]*' members/.env` | OQ-P | [AC1](01-current-state-audit.md), [08 §8.6](08-security-and-safety.md) |

## 9.2 Phases

Each phase is independently shippable, ends with a verified lab run, and is **several commits in
several repos, each needing its own approval** (`AGENTS.md §1`). Nothing is committed, branched or
pushed by the work itself.

### P0 — Schema and enforcement (no user-visible feature)

Safe to ship first because **nobody is at status 4 or 5** — the new gate changes nothing until the
first closure exists.

| Repo | Work |
|------|------|
| `www` | The migrations ([03](03-database-schema.md)) — the `members_*` closure tables and the legal-hold columns; narrow `updateStatus` / `adminStore` ([02 §2.8](02-state-model-and-enforcement.md)); teach `direct_payments:subscription-notify`, `NotifyMembersOfTierChange` and `SyncConnectAccountJob` to skip 4/5 ([05 §5.7](05-purge-engine.md)); the `www` billing enforcement command's `candidates()` excludes 4/5 ([billing/08](../billing/08-brain-enforcement.md); `members` only triggers it); the closure-safe signup checks for tombstones (dormant until a tombstone exists) |
| `auth` | `assertAccountOpen` + `AUTH_ACCOUNT_CLOSED` in `attachMember`, `refreshToken`, the SSO-cookie decode; the Delight `onBeforeSuccess` veto; the watermark writer made callable. **A kill switch** `ACCOUNT_CLOSE_ENFORCE` (default on) in `auth`'s env so the gate can be turned off without a deploy if it misbehaves |
| `members`, `market` | The per-request `account_status` read with the ≤30 s cache ([02 §2.5](02-state-model-and-enforcement.md)); `members`' own retention sweeps (the billing enforcement run on `www` skips 4/5 too — [billing/08](../billing/08-brain-enforcement.md)) learn to skip 4/5 |

**Verified by:** setting a *throwaway* lab member to status 4 by hand and observing A1–A4, A9; then
back to 1. **Exit:** A1–A4, A9, A32 pass.

### P1 — Admin soft delete, and the engine's skeleton

This is where the engine first exists in `members`, so it is also where every channel it needs is
built — small, but each is a new dependency ([D11, D17, D18](00-overview.md)).

| Repo | Work |
|------|------|
| `members` | `MembershipLifecycle` (soft delete, restore, cancel, block only); the closure models; the closure calls on the existing `BrainSurfaceClient`, and `CheckpointSystemClient`; the `www.internal` admin routes and `AccountCloseAdminController`; **`account-close:tick` registered in `routes/console.php`**, running only the boundary and reconcile steps for now ([07 §7.2.3](07-notifications-and-scheduling.md)) |
| `www` | The signed `members`→`www` channel and its verification middleware; the Brain-surface `status`, `legal-hold` and `notices/send` endpoints; `AccountCloseMailer` (the `soft_deleted_by_admin` notice and its fallback); `MembershipClosureController` (the level-99 **proxy**) and `MembersClosureClient`; protected accounts; event constants; the Danger-zone UI, the soft-delete modal, the closures view |
| `auth` | `POST /checkpoint/member/access` |
| env | The credentials of [07 §7.5](07-notifications-and-scheduling.md) — `CHECKPOINT_SYSTEM_KEY` and the `members`→`www` key pair (`WWW_BRAIN_SURFACE_KEY`, already in the lab profiles) — **before** any of this is exercised (OQ-P) |
| — | **L3 (maintenance page) stays off** until a remote `--set-maintenance` command exists (P2's command work); the modal shows it disabled until then |

**Gate:** `schedule:run` is on cron for **`members`** ([07 §7.4](07-notifications-and-scheduling.md)).
Soft delete itself needs no scheduler, but the tick registered here does nothing until it runs, and
everything after P1 depends on it.

**Exit:** A5–A11, A30 (soft-delete half), A31, A32.

### P2 — The purge engine and admin hard delete

The largest phase; land it in this order, each independently verifiable, and **keep the hard-delete
button disabled** (`hard_delete_enabled` setting, default `false`) until every eraser in
[03 §3.6](03-database-schema.md) is registered — a half-populated engine would be a leak with a
confirmation dialog.

| Sub-phase | Work | Verified by |
|-----------|------|-------------|
| **P2a** | In `members`: `MemberEraser`, the ledger, `PurgeRunner`, `RemoteCommandRunner`, the local erasers, and the four remote commands in `members/core/scripts/commands.sh` — **`preview` and `--dry-run` only**. In `www`: the Brain-surface `erase/preview`. The preview endpoint and preview table | A16 (preview half), A34 — a preview against a lab member matches reality counted by hand; `account-close:tick --dry-run` reports and writes nothing |
| **P2b** | `auth`: `erase` + `erase/preview` + the `memberPurge` Parse function + the status-5 veto; the satellite apps' erasers and signed routes; `market` anonymize + blockers; `www`: `BillingEraser` and the `brain_*` erasers, with the **ledger check** before the member-row delete | A18, A19, A13 |
| **P2c** | Enable `run` and `verify`; hard delete against a **throwaway lab member built for the purpose** (with a site, a mailbox, files, a note, a subscription in test mode) | A12, A14, A15, A16, A17, A26 |
| **P2d** | Flip `hard_delete_enabled` in each environment, one at a time, after the lab run passes | — |

**Exit:** A12–A19, A26, A29, A33, A34, A30 (purge half).

### P3 — Self-service closure

| Repo | Work |
|------|------|
| `members` | The close-account section, preview, re-authentication, banner; `AccountClosureController`; the tick's reminder and grace→closed steps; the settings row and `self_service_enabled` |
| `auth` | `POST /checkpoint/auth/reauthenticate` |
| `www` | The remaining notices in `AccountCloseMailer`; nothing else — the channel and endpoints already exist from P1 |

`self_service_enabled` starts **`false`**; turn it on in the lab first, drive a closure through the
whole ladder with shortened windows (test values in the lab settings row), then enable per
environment.

**Exit:** A20–A25, A27, A28.

### P4 — Hardening and follow-ups

Fix `--remove-website` ([08 §8.6](08-security-and-safety.md)); delete **both** copies of
`delete_member.expect`; a `?status=` filter on the members list; secondary-user notices (OQ-L); the
export hook (OQ-G); close `membership_change` OQ-E / MM11 and `TODO-member-resources` B7 by link to
this folder; write the `complete/` documents and add the marker to `plans/markers.md`.
## 9.3 Tests

The convention in these repos is PHPUnit against an in-memory connection built with
`Schema::create` in `setUp()` (see `EcommerceReceiptServiceTest` and the Site Users tests) and
`Carbon::setTestNow` for time. Remote commands and other apps are **faked** — no test shells out
or reaches a real server.

### `members` — the engine

| Suite | Covers |
|-------|--------|
| `MembershipLifecycleTest` | Every row of [02 §2.9](02-state-model-and-enforcement.md) — allowed transitions succeed, **every forbidden one throws**; each asks `www` for the status write (a fake) and writes the closure row; a second open closure violates `open_key`; **step 2 failing rolls the closure back; step 3 failing compensates** ([02 §2.9.1](02-state-model-and-enforcement.md)) |
| `AccountCloseAdminControllerTest` | Reachable **only** with a valid `www.internal` signature — a browser call and a member token are refused; soft delete/restore semantics (A6, A8); the preview token — stale, mismatched, missing (A17); wrong typed username; a self-close by the acting admin refused; protected / hold / blocker refusals with the exact codes (A11) |
| `PurgeRunnerTest` | Order; **halt on failure**; resume from the first unfinished step (A14, A15); backoff and the fifth-attempt stop; the lock (one runner); `MORE_WORK` vs `DONE`; finalize's two halves — (a) then (b), and (b) completing on the next tick if it failed |
| One `…EraserTest` per local eraser | `preview` = `run` counts and `verify` = zero leftover (A16); **run twice, second changes nothing** (idempotency); **a second member's rows in the same tables are untouched (A19)** |
| `RemoteCommandRunnerTest` | **Every argument is allowlisted before a command line is built** — a hostile `$shParams` never reaches the wrapper; the timeout; the typed result; secrets go by stdin, not the command string |
| `AccountCloseTickTest` | Grace → 4 and retention → 5 with `setTestNow`; **an admin soft delete (`purge_after` null) is never purged (A7)**; reminders send once (A27); a hold stops the purge; `--dry-run` writes nothing (A28); an Active member is never touched; **a status 3/4/5 with no open closure alerts and changes nothing** |
| `TickRegistrationTest` | **`members/routes/console.php` registers `account-close:tick`, and `www/routes/console.php` has no account-close entry** — asserted by reading both files; the regression guard for [D17](00-overview.md) |
| `RetentionSweepsSkipClosedTest` | `members`' own storage/email sweeps skip 4/5 ([05 §5.7](05-purge-engine.md)) |
| `PurgeLogTest` | No plain email or username anywhere in the log or closure after completion (A30) |
| `BrainSurfaceClientTest` | Signs every call; fresh nonce per call; never reuses the inbound `WWW_INTERNAL_API_KEY` *(the signing and the enforcement trigger are already covered by `BrainSurfaceEnforcementTriggerTest`)* |

### `www` — the Brain surface and the admin door

| Suite | Covers |
|-------|--------|
| `MembershipStatusEndpointTest` | `updateStatus` 422s on 3/4/5; `adminStore` rejects an out-of-range status (A32) |
| `MembershipClosureControllerTest` | **Level-99 only (A10)**; it is a **proxy** — asserts it forwards the actor and the acting staff user's member reference and contains no closure logic; relays `members`' status and body unchanged |
| `BrainSurfaceAuthTest` | Rejects unsigned, replayed, skewed calls and a path/body member mismatch (A31); **the inbound `WWW_INTERNAL_API_KEY` does not authenticate this direction** |
| `BrainSurfaceStatusTest` | The status write flips the column, derives `account_close_date` and writes the `brain_membership_events` row **in one transaction**; **an illegal transition (e.g. 5 → 1) is refused (422)** |
| `MemberRowDeleteTest` | The final `brain_memberships` delete is **refused unless the ledger shows every other step `done`** and the closure is `purging` |
| One `…EraserTest` per `www` eraser | Billing (provider cancel through a fake; anonymize leaves no identifying column — A13; blockers), tenant/DNS rows, `WwwTablesEraser` — with the same `preview` = `run`, idempotency and **no-collateral (A19)** assertions |
| `IdentifierReservationTest` | Closed/Closing identifiers fail `validate-*` and `store` in every case variant (A5); a tombstone blocks by HMAC; **released by default (A26)** |
| `ScheduledJobsSkipClosedTest` | `direct_payments:subscription-notify`, tier-change and sync jobs skip 4/5; the **billing enforcement command skips a 4/5 member** (no dunning write, no mail) and **does not recreate `brain_membership_billing_state`** (A29). |
| `AccountCloseMailerTest` | Handler set → sent through `BillingMailer`; handler unset or failing → **fallback sent** and the failure reported back to `members` (A27) |
| **`PurgeInventoryCompletenessTest`** *(the guardrail)* | Parses `database/migrations/*` for every column named `member_reference_num`, `payer_reference_num`, `*_member_reference_num`, `super_user_id`, `owner_reference_num`… and asserts each table is either **claimed by an eraser** (in `members` or `www`) or on an **explicit allowlist with a reason** (A33). A future migration that adds a member-keyed table **fails this test until someone decides what a purge does with it.** Lives in `www` because `www` owns every migration. Same idea as `MigrationOwnershipTest`: each assertion prevents a leak that would otherwise ship silently |
| `MigrationOwnershipTest` (existing) | Passes untouched — the new migrations obey `AGENTS.md §5`, and the closure tables are `members_*` created by `*_create_members_*.php` |
### `auth`

`AccountClosedGateTest`: **valid credentials + status 4/5 → `AUTH_ACCOUNT_CLOSED`** on login,
username login, SSO exchange, 2FA completion, `/token/verify` and `/token/validate` (A1); **wrong
password on a closed account → the ordinary invalid-credentials error, indistinguishable from an open
one** (A2); refresh blocked (A1); a secondary user under a closed primary blocked (A3); a
pre-close token refused after close **and still refused after restore**, while a fresh login works
(A4); `ACCOUNT_CLOSE_ENFORCE=false` disables the gate. `MemberEraseTest`: **refuses unless status 5
or the row is gone** (A18); deletes every child table by `user_id` and the parent last, leaving
nothing; the Parse call is asserted through a fake.

### `members`

The self-service surface: owner-only — a **secondary token gets 403**; wrong password or wrong 2FA is
rejected by `auth`'s re-authentication (A22); the banner renders during Closing; the verifier's status
read refuses a closed member's cached token within the TTL (A9). *(The engine's own suites are above.)*

### Satellite apps

Per app: the eraser removes **every** row of the target member and **none** of anyone else's (A19);
`market` anonymizes rather than deletes and reports the blockers of [08 §8.4](08-security-and-safety.md).

### Remote commands (shell)

Run against a scratch directory tree, never a real root: invalid arguments exit **2** and touch
nothing; a path outside the allowed roots, an empty or `/` path, and a symlink leaving the root are
refused; a second run of a removal exits **0** (idempotent); `--dry-run` removes nothing; no secret
appears in `argv` (A34).

### The lab run (`vagrant` mode)

The end-to-end proof, against a member created for the purpose through `adminStore`, given a
tenant site, a mailbox, files, a note and a test-mode subscription:

1. **Soft delete** → the login fails at Checkpoint with the closed error; the old token dies; every
   inventory table's row count is **identical** to before (A6); the email and username fail the
   availability check; **restore** → login works, the old token is still dead.
2. **Preview** → counts match a hand count (`SELECT COUNT(*)` per table, `du` per path).
3. **Hard delete** → every *delete* table returns zero rows for the member; every *anonymize* table
   has no identifying column left; `/mnt/node1/{ref}` is absent; the DNS zone answers `NXDOMAIN`;
   the Parse classes hold nothing for the member; the `brain_memberships` row is gone; the purge log
   is present and contains no plain email or username (A12, A13, A30).
4. **Interrupt** a purge mid-step (stop the runner) and resume it (A14).
5. **Self-service** with shortened windows: request → status 3 and login still works → let the
   grace lapse → login blocked → let retention lapse → the purge runs untouched (A20, A24).

## 9.4 Acceptance criteria

| # | Scenario | Expected |
|---|----------|----------|
| **A1** | A member at status 4 or 5 presents **valid credentials / a refresh token / an SSO cookie / a 2FA completion / a token to `/token/validate`** | Every one fails with `AUTH_ACCOUNT_CLOSED` on the next uncached verify |
| **A2** | The same member presents a **wrong password** | The ordinary invalid-credentials error — no distinction from an open account |
| **A3** | A secondary (team) user acts under a closed primary | Refused |
| **A4** | A token issued before the close, after the close, and after a restore | Refused, refused; and after restore it is **still** refused while a fresh login succeeds |
| **A5** | Sign up with a soft-deleted member's email or username, in any letter case | Availability says taken; `store` refuses |
| **A6** | Admin soft delete | Row counts in every inventory table identical before and after |
| **A7** | An admin soft delete, left indefinitely | `purge_after` is `NULL`; the sweep never purges it |
| **A8** | Restore | Status 1; disabled mailboxes re-enabled; **subscription not resurrected**; old tokens still dead |
| **A9** | A closed member's cached token in `members`/`market` | Refused within ≤ 30 s |
| **A10** | Non-level-99 staff calls any closure endpoint | 403 |
| **A11** | A protected account, a legal hold, or a blocker | Refused with `AC_E_PROTECTED` / `AC_E_HOLD` / `AC_E_BLOCKED` (+ `blockers[]`); nothing changed |
| **A12** | Hard delete completes | No rows for the member in any *delete* table; no `/mnt/node1/{ref}`; no `ext_member_users` row; no `brain_memberships` row |
| **A13** | The same | *Anonymize* tables retain their rows with no identifying column |
| **A14** | A purge interrupted after any step | Resumes from the first unfinished step and completes |
| **A15** | A step fails | Later steps do not run; the failure and error are visible; retried with backoff; `failed` after five attempts and alerts |
| **A16** | Preview vs run | The preview's counts equal the run's; `verify` finds nothing left |
| **A17** | Hard delete with a stale (>15 min) or mismatched preview token, a wrong typed username, or no reason | Refused |
| **A18** | `auth` `erase` for a member not at status 5 | Refused |
| **A19** | Any erase | A second member's rows in every touched table are unchanged |
| **A20** | Self-service request | Status 3; login still works; banner shown; subscription cancelled; `closure_requested` sent |
| **A21** | Cancel before `login_block_at` | Status 1; subscription **not** restarted; confirmation sent |
| **A22** | Request with a wrong password/2FA, or with a secondary token | Refused |
| **A23** | Request while protected / held / blocked | Refused with the specific code, shown to the member |
| **A24** | Grace elapses; then retention elapses | Login blocked; then the purge runs with no operator action |
| **A25** | A blocker or hold appears before the timed purge | The purge stops and an operator is alerted |
| **A26** | Hard delete, default vs. "retire identifiers" | Default: the email/username can register again. Retired: they cannot (HMAC match) |
| **A27** | Notices | Each sends exactly once; a missing/failing handler uses the fallback and alerts |
| **A28** | The sweep | Never touches an Active member; `--dry-run` writes nothing |
| **A29** | Dunning / tier-change / sync jobs | Skip 4/5; `brain_membership_billing_state` is not recreated |
| **A30** | Audit | Every action has an event with actor and reason; the purge log holds no plain email or username |
| **A31** | `members` → `www` internal call unsigned, replayed, or with a path/body member mismatch | Rejected |
| **A32** | `PUT /status` with 3, 4 or 5; `adminStore` with an out-of-range status | 422 |
| **A33** | `PurgeInventoryCompletenessTest` | Green — no unclaimed member-keyed table |
| **A34** | A remote command with a bad argument, an out-of-root path, or a secret in `argv` | Exit 2, nothing touched; no secret in the process list |

## 9.5 Risk and rollback

| Risk | Mitigation |
|------|------------|
| The P0 gate misfires and locks out live members | **Kill switch** `ACCOUNT_CLOSE_ENFORCE` in `auth`; and 4/5 are unreachable until P1 ships, so the gate is inert until then |
| A purge erases something it should not | The preview; the typed username; the preview token; `auth`'s status-5 veto; the blockers; the protected set; `PurgeInventoryCompletenessTest` for the opposite error (leaks). **There is no undo** — the mitigation is entirely before the click |
| An eraser is missing and leaks | `hard_delete_enabled` stays `false` until every eraser exists (P2d); the completeness test |
| `members`' scheduler is not running (its own file says "not yet confirmed configured") | Confirmed in pre-flight #5; **nothing past P1 ships without it** ([07 §7.4](07-notifications-and-scheduling.md)). Soft delete needs no scheduler; a hard delete's first slice runs in the admin's request but its later slices need the tick |
| A notice does not send | Fallback mailable + operator alert ([07 §7.3](07-notifications-and-scheduling.md)) |
| Migration mistakes | Additive only — new tables and three nullable columns; a plain `migrate --force`; **never `migrate:fresh`** |

**Soft delete is fully reversible; a purge is not.** Everything before P2d is undoable by an admin.
That is why P2d is a separate, per-environment switch and not a side effect of merging.

## 9.6 Documentation and bookkeeping

- `complete/` documents per file, named `<basename>.complete.md`, stating what was built, files
  changed **per repo** (`AGENTS.md §7`: say which repo each change landed in), and the verification.
- Add `AFS_ACCOUNT_CLOSE_MODIFIER_ADDITION` to `plans/markers.md` with the first phase.
- Update `plans/www/MAILHANDLER_IMPLEMENTATION.md` to list `AccountCloseMailer` and its fallback as
  the deliberate exception to "no handler configured, nothing sends".
- Close by link, not by edit here: `membership_change/00-overview.md` OQ-E,
  `membership_change/08-member-management.md` MM11, `TODO-member-resources.md` B7.
- Update `DATABASE-SCHEMA-AUDIT.md` with the new `brain_*` tables.

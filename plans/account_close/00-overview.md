# 00 — Overview

## Completion document

Not yet carried out. When it is: `complete/00-overview.complete.md`, stating status, summary,
files changed per repo, verification steps, and follow-ups.

## 1. The problem

### 1.1 A member cannot be removed at all

There is no delete of any kind for a Brain member. `MembershipController` has no `destroy()`
(`www/app/Http/Controllers/Portal/Admin/MembershipController.php`, methods listed at lines
74–1058), `routes/portal.php` registers no `DELETE` under `api/v1/memberships`
(lines ~1177–1230), and there is no Artisan command, job, or schedule entry that removes a
member (`routes/console.php`). The tenant side has none either: `DELETE` exists for DNS servers
and HTTP servers, never for a tenant or a member's zone.

### 1.2 The only "close" signal is decoration

`brain_memberships.account_status` has three documented values — 1 active, 2 suspended,
3 marked for deletion (`database/migrations/2026_05_15_000001_create_brain_tables.php:250`,
`membership_change/00-overview.md:54`) — and **nothing reads it to gate anything.** Login, token
verification, entitlement and billing never consult it (audit finding [AC4](01-current-state-audit.md)).
An admin can set a member to "Marked for deletion" today and that member logs in as normal.

### 1.3 A member cannot close their own account

The members dashboard has no close, delete, deactivate or cancel-membership flow
(`members/resources/js/views.ts` routes; a grep of `members` and `www` found none). A member who
wants to leave must ask an operator, and the operator has no tool to do it.

### 1.4 What does exist is dangerous

`delete_member.expect` connects to a hard-coded host over root SSH and runs
`DELETE FROM users_member` and `DROP DATABASE`, targeting a legacy table the Brain no longer
uses — and **it contains a plaintext root MySQL password.** **There are two copies**:
`www/core/scripts/delete_member.expect` and `members/core/scripts/delete_member.expect` (the latter
is the engine's own repo). It must not be reused, and that password should be treated as
compromised and rotated. ([AC1](01-current-state-audit.md))

## 2. Goals

- **G1** A Brain admin can **soft delete** a member: login and every token stop working, the
  data stays, the email and username remain unavailable, and it is reversible.
- **G2** A Brain admin can **hard delete** a member: every store that belongs to them is erased
  — `brain_memberships`, the Checkpoint identity, tenant websites and databases, DNS, mailboxes,
  files, satellite-app data, Parse ledger rows — as an ordered, resumable, audited process.
- **G3** A member can **close their own account** from the members dashboard through a
  progressive ladder: a grace period in which they can change their mind, then blocked login with
  data retained, then purge.
- **G4** `account_status` is finally **enforced** at the single place every login already
  passes through, so the states mean something.
- **G5** Every step is **observable**: what was deleted, by whom, why, and what is left if it
  stopped half-way.
- **G6** Nothing here can delete the wrong person, an operator account, or a member who is
  under a legal hold or owes the platform or another party something.

## 3. Non-goals

- **Enforcing `account_status = 2` (suspended) is not part of this plan.** It shares the same
  chokepoint ([02 §2.4](02-state-model-and-enforcement.md)) and would be a one-line addition, but
  suspension has different product semantics (D5 in `membership_change`: freeze, do not
  downgrade) and is decided elsewhere. See OQ-J.
- **Deleting a tenant site's own end-customers individually** (`laravel_site_users`, orders,
  form submissions on a member's website) — they go *with the tenant*, wholesale. Erasing one
  site-user inside a live site is the `site_users` plan's concern.
- **A general GDPR "data subject request" workflow** (export, rectification, portability). A
  data export before closure is flagged (OQ-G) and scoped only as a hook.
- **Deleting staff (`admin_users`) or Brain operators.** Different tables, different plan.
- **Erasing backups.** Stated plainly in [08 §8.9](08-security-and-safety.md): a purge removes
  live data; backups age out on their own schedule.

## 4. Glossary

| Term | Meaning |
|------|---------|
| **Member** | A row in `brain_memberships`, keyed `reference_num` (`m1x` + 10 chars, `MembershipController::generateMemberReferenceNum`, line 872). Also called `member_reference_num` everywhere else. |
| **Checkpoint identity** | The member's row in `ext_member_users` (Delight-Auth; `id` = `brain_memberships.user_id`) and its satellites. **There is no table named `ext_members`.** |
| **Soft delete** | Access revoked, data and identifiers retained, indefinitely, reversible by an admin. Status **4 Closed**, no purge timer. |
| **Hard delete / purge** | Irreversible erasure of everything the member owns. Status **5 Purging**, ending with the `brain_memberships` row itself gone. |
| **Closure** | One attempt to close an account, of either kind or self-service. A row in `members_account_closures` ([03 §3.3](03-database-schema.md)). |
| **Grace period** | Self-service only: the window after the member asks to close, in which login still works and they can cancel. Status **3 Closing**. |
| **Retention window** | Self-service only: the window after login is blocked and before purge, in which data still exists and an operator can reactivate. |
| **Eraser** | A per-store unit of purge logic: it reports what it would remove (dry run), removes it idempotently, and records the outcome ([05 §5.3](05-purge-engine.md)). |
| **Tombstone** | A hashed record that an email or username was retired, so it cannot be re-registered, without keeping the plain value ([03 §3.5](03-database-schema.md)). |
| **Watermark** | A row in `checkpoint_primary_logout_watermarks`: every token issued before `invalidated_at` is refused. The existing revocation primitive. |

## 5. The decisions

| # | Decision | Choice | Rationale |
|---|----------|--------|-----------|
| **D1** | Lifecycle states | Keep 1 Active, 2 Suspended. **3 = Closing** (grace; login works), **4 = Closed** (login blocked, data retained), **5 = Purging** (in flight; login blocked). | 3 already means "marked for deletion" and is unmodelled — this gives it a precise, non-breaking meaning (login still works, so existing rows set to 3 today are unaffected). 4 and 5 are new. |
| **D2** | Who owns the state machine | **`members`** owns the closure states, the purge engine, the schedule and the self-service flow — `MembershipLifecycle`, `PurgeRunner`, the erasers for everything `members` can reach, and the `members_*` closure tables. **`www` stays the Brain surface** ([D18](#5-the-decisions)). `auth` and the satellite apps expose narrow erasers; they never decide a transition. | The instruction: Appfactory-studio engine and worker work belong in `members`, which runs once, not `www`, whose schedule runs on every tenant. `members` already runs the storage and email retention state machines (`RetentionService`, `EmailRetentionService`) and already provisions websites itself (`DomainController`). `AGENTS.md §5`: only `members` writes `members_*` and only the Brain writes `brain_*` — so the closure tables are `members_*`, and every `brain_*` change is *requested of* `www`. |
| **D3** | Where access is actually denied | **`auth`'s `MemberLookupService::fetchByUserId`** — the one query every login, SSO exchange, 2FA completion, token verify and secondary-user path already runs — plus the same check in `refreshToken()` and the SSO-cookie decode, a **watermark** row on close, and Delight `status` as defence in depth. `members` and `market` add a per-request status read because their verifier caches a good token up to an hour. | [02 §2.4–2.5](02-state-model-and-enforcement.md). One chokepoint, not eleven. |
| **D4** | Soft delete and time | An **admin soft delete has no timer** and never auto-purges. Only a self-service closure or an explicit hard delete ever schedules erasure. | The request: "the data still remains on the server and in the database." |
| **D5** | Identifier reservation | **Soft delete keeps the rows**, so the unique indexes on `brain_memberships.email`, `.username` and `ext_member_users.email` keep the values taken. **Hard delete releases them by default**, with an admin option to *retire* them into a hashed tombstone. | The request asks for reservation on soft delete only. Retiring on hard delete is a policy call (OQ-D), so it is an explicit switch rather than an accident. |
| **D6** | Hard delete is a process, not a statement | An **ordered, idempotent, resumable** sequence of steps recorded in a ledger table; external stores first, the identity next, the `brain_memberships` row **last**. | MyISAM `ext_*` tables and remote filesystems cannot be rolled back; a half-done purge must be visible and re-runnable, never guessed at. |
| **D7** | Hard delete implies soft delete | Step 0 of every purge is to block access (status 5, watermark, Delight ban). An admin cannot hard-delete a member whose login still works. | No window in which a member logs in mid-purge and re-creates data. |
| **D8** | Dry run first | A **preview endpoint** returns what would be removed and what would *block* the purge; the UI cannot enable "Permanently delete" until a preview has loaded for that member. | The action is unrecoverable; the operator must see the size of it. |
| **D9** | Delete vs anonymize vs retain | Each store is classified **delete / anonymize / retain** in [03 §3.6](03-database-schema.md). Records that involve *other parties* or a legal retention duty (invoices, payouts, market orders and ledger entries) are **anonymized**, not deleted, pending OQ-E and OQ-F. | Deleting a seller's ledger corrupts a buyer's records. |
| **D10** | The self-service ladder | **Closing** (grace, default **14 days**; login works; cancel any time) → **Closed** (default **30 days**; login blocked; data kept; only an operator can reactivate) → **purge**. Both windows are settings, not constants. | "Progressive soft delete with a grace period." Two windows separate "changed my mind" from "please recover my data." OQ-C covers the exact semantics. |
| **D11** | The channels | **`www` → `members`** (admin actions): `www` authorizes the staff user, then forwards a signed request to `members`' existing `www.internal` surface (`routes/www_internal.php`, `api/internal/www/v1/…`) — the channel `www` already uses for `tier-changed` and `email/reconcile` (`MembersEmailClient`, `MembersSignature`). **`members` → `www`** (the Brain surface, [D18](#5-the-decisions)): a new signed channel built on the existing-but-unused `WwwSignature::sign()`. **`members` → `auth` and → each satellite app**: direct, system-key or signed, as `members` already does for `market` (`MarketClient`) and `coordinator` (`AfsConnectSignature`). | The self-service flow is native to `members` and needs no hop for its own state. **The `members`→`www` channel already exists**, built for billing enforcement ([billing/08 §8.2](../billing/08-brain-enforcement.md): `POST /api/internal/brain/billing/enforce`, group `internal/brain`, `brain.only` + `brain.surface`, key `BRAIN_SURFACE_KEY_MEMBERS`); account closure adds routes to it. Each direction uses its **own key pair**, so a key that authenticates `www`→`members` (`WWW_INTERNAL_API_KEY` today) can never be replayed as `members`→`www`. |
| **D12** | Notices must not vanish | Closure notices are wording an operator edits in the mail-handler builder (handler references held in `members_account_closure_settings`), **sent by `www`** (the mail-handler system is Brain infrastructure, [D18](#5-the-decisions); `members` has no mail sender) through the hardened **`BillingMailer`**, on a signed request from the engine — **with a guaranteed built-in fallback** when a handler is unset or a send fails. | `MAILHANDLER_IMPLEMENTATION.md §6`: no configured handler means nothing sends, silently. Acceptable for a receipt; not for "your account will be deleted in 24 hours." [07 §7.3](07-notifications-and-scheduling.md) |
| **D13** | Narrow the existing status route | `PUT /memberships/{ref}/status` (`updateStatus`, lines 639–673) accepts only 1 and 2 afterwards; 3, 4, 5 are reachable **only** through the closure endpoints. `adminStore` (line 562) gains the same validation. | Today `in:1,2,3` lets an admin type 3 with no process behind it, and `adminStore` accepts any integer. |
| **D14** | Refuse rather than proceed | **Protected accounts** and **blockers** ([08 §8.3–8.4](08-security-and-safety.md)) make a deletion refuse with a specific code. A **legal hold** blocks purge outright. | The cost of an unwanted delete is unbounded; the cost of a refused one is one more click. |
| **D15** | Remote commands | New, dedicated shell commands with strict argument validation and **a real exit status** ([05 §5.5](05-purge-engine.md)). Never reuse `--remove-website` (`rm -rf $directory`, unquoted) or `delete_member.expect`. | `members`' `RunCommandBG` is `nohup … &` and returns only a pid; its synchronous `RunCommand` does return an exit code but builds the command by **string concatenation** into a double-quoted argument (`members/app/Http/Controllers/FunctionsController.php:28–48`) — so the engine gets its own safe runner ([05 §5.5](05-purge-engine.md)). A purge cannot verify what it cannot observe, and must not inject what it cannot trust. |
| **D16** | Schema | All new tables and columns are **created by `www` migrations** — `members` ships none (`AGENTS.md §5`) — named `*_create_members_*.php`: the closure tables carry the **`members_*` prefix** (they are `members`-owned); **one** additive column set goes on `brain_memberships` (the legal hold, which the Brain owns). Named indexes, `hasTable` guards. | `AGENTS.md §5`: www owns every migration for the shared database and creates members' annex tables; `MigrationOwnershipTest` enforces it. |
| **D17** | Where the schedule, the engine and the worker live | **`members`, entirely.** `members/routes/console.php` registers `account-close:tick` (every five minutes); it runs the reminders, the boundaries and the purge slices **in-process** in `members`. `www/routes/console.php` gets **no** account-close entry. There is **no queue job and no queue worker**: the purge advances in time-budgeted slices, and the first slice of an admin hard delete runs in that request's own PHP process after the response (`dispatchAfterResponse`). | `www/routes/console.php` is evaluated on **every tenant clone** (its own header says so, line 14); `billing:enforce-membership` needed a runtime `BrainGuard` just to stay Brain-only — which is why the billing plan has since moved it to `members` too ([billing/08](../billing/08-brain-enforcement.md), billing D18). A purge must never be one configuration slip from running on a tenant. `members` runs once and already hosts every platform sweep (`storage:*`, `email:retention-sweep`). |
| **D18** | What stays in `www` — the Brain surface | Exactly four things, each behind the signed `members`→`www` channel: **(1)** every write to a **`brain_*`** row — the `account_status` gate, the `brain_membership_events` audit row, `brain_http_server_tenants`, `brain_dns_*`, `brain_alloc_*`, and the final `brain_memberships` delete; **(2)** **provider billing** — Stripe/PayPal/Square cancel and the payer/invoice anonymize (`SubscriptionActionsProvider`, `laravel_direct_payment_*`, `laravel_processor_*`); **(3)** **the notices** — the mail-handler/SendGrid system and `BillingMailer`; **(4)** **the admin portal** — the staff UI, level-99 authorization and the actor identity, which then proxy to `members` ([D11](#5-the-decisions)). | Each is something `members` cannot do without copying Brain code or breaking an ownership rule: its `composer.json` has no Stripe/Square/PayPal SDK, its code has **no mail sender at all** (no `Mail::`, no MailHandler), and it writes `brain_*` only through the Brain API — `DomainController` does exactly that for DNS zones. OQ-O covers the one alternative. |

## 6. Success criteria

Full list A1–A34 with scenarios is in [09 §9.4](09-rollout-and-testing.md). The ones that define
"done":

| # | Acceptance criterion |
|---|----------------------|
| **A1** | A soft-deleted member's login, token refresh, SSO exchange, 2FA completion and `/token/validate` all fail with one distinct error code, on the next uncached verify. |
| **A5** | A soft-deleted member's email and username fail the signup availability checks and the signup itself. |
| **A12** | A hard delete leaves **no row** naming the member in any inventory table classified *delete*, and no path under `/mnt/node1/{ref}`. |
| **A14** | A hard delete interrupted after any step resumes from that step and completes. |
| **A20** | A member who requests closure can still log in during the grace period, and cancelling restores full normal access. |
| **A24** | After the grace period, login is blocked; after the retention window, the purge runs without operator action. |
| **A30** | Every deletion, restore, cancel and purge step appears in the audit trail with actor, reason and time. |

## 7. Repository map

Which repo receives what. Each is a separate commit set and each needs its own approval.

| Repo | Receives | Detail |
|------|----------|--------|
| **`www`** | **The Brain surface** ([D18](#5-the-decisions)): the migrations (the `members_*` closure tables; the legal-hold column); the signed `members`→`www` endpoints — status-and-event write, the `brain_*` erasers, billing erase, notice send; **the admin portal** — level-99 authorization, a thin `MembershipClosureController` that forwards to `members`, and the Danger-zone UI; skip-4/5 guards in `billing:enforce-membership` and the other `www` jobs. **No `routes/console.php` entry and no engine** | 03, 04, 08 |
| **`auth`** | Enforcement in `MemberLookupService`, `refreshToken`, SSO-cookie decode; a system-key **member erase** endpoint for Checkpoint tables; a Parse cloud function for the ledger; the watermark helper made callable. **The caller is now `members`**, which needs `CHECKPOINT_SYSTEM_KEY` in its env profiles (OQ-P) | [02 §2.4](02-state-model-and-enforcement.md), [05 §5.4](05-purge-engine.md) |
| **`members`** | **The engine.** `MembershipLifecycle`, `PurgeRunner`, the erasers for everything `members` reaches (storage, communications, email band/retention, `members_websites`, `users_email`, the shell steps), the models for the `members_*` closure tables, the sweep, **`account-close:tick` in `routes/console.php`**, the self-service flow (UI, endpoints, re-authentication), the `www.internal` admin routes, the clients for `www`, `auth` and the satellite apps, and the per-request status check in `VerifyCheckpointToken` | [04](04-admin-soft-and-hard-delete.md), [05](05-purge-engine.md), [06](06-member-self-service-closure.md), [07](07-notifications-and-scheduling.md) |
| **`coordinator`, `notes`, `flow`, `dream_graph`, `pdf_editor`** | One `MemberEraser` each, called by `members` through a signed internal route | [05 §5.4](05-purge-engine.md) |
| **`market`** | Eraser with **anonymize** semantics for orders and ledger, blockers for open work; status check in its verifier | [05 §5.4](05-purge-engine.md), [08 §8.4](08-security-and-safety.md) |
| **`packages/checkpoint-auth`** | Nothing required; its 60-second success cache is accepted as the lag ([02 §2.5](02-state-model-and-enforcement.md)) | — |
| **`packages/afsserver/plans`** | Close `membership_change` OQ-E / MM11 and `TODO-member-resources` B7 by link when built | README |

## 8. Open questions

Each is a decision the plan cannot make from the code. The plan proceeds on the stated default;
changing the answer changes the named section.

| # | Question | Default assumed | Affects |
|---|----------|-----------------|---------|
| **OQ-A** | **Where do the new delete commands go, and does `scripts2` exist?** `members` runs **its own** `core/scripts/commands.sh` (`FunctionsController::RunCommand` builds `/home/appfactorystudio/websites/$SUBPATH/core/scripts/…`). That copy has `--create-email-account`, `--delete-email-account` (a stub) and `--create-member-storage` — **not** `--create-afs-member-storage`, which exists only in `www`'s copy. And `DomainController` provisions through `RunCommandBG2` → `core/scripts2/commands.sh`, a directory **absent from this checkout**. | New commands go in `members/core/scripts/commands.sh`; confirm on the server whether `scripts2` exists and which script set owns tenant and DNS teardown | 05 §5.5 |
| **OQ-B** | Is `/mnt/node1/{member_reference_num}` (made by `www`'s `--create-afs-member-storage`) the same tree as `members`' `PathResolver::STORAGE_ROOT` and its `--create-member-storage`, and is either the same as `/mnt/node1/{systemuser}` (mailboxes, module data)? `cloud-storage/03-architecture.md:96-112` assumes separate keys. | Separate; the purge removes every tree it can name | 05 §5.5 |
| **OQ-C** | During **Closing** (grace): may the member log in fully? Are their websites still served? Is billing already stopped? | Login **works** fully; websites served; **subscription cancelled at request** (D16 of `billing`: cancel stops billing at the provider at once) | 06 |
| **OQ-D** | On hard delete, are the email and username **released** for re-registration or **retired**? | **Released**, with the admin `retire_identifiers` switch | 02 §2.7, 03 §3.5 |
| **OQ-E** | Financial records — invoices, payments, payer rows — **delete** or **anonymize**? Any statutory retention period? | **Anonymize** (drop PII, keep amounts and dates) | 03 §3.6 |
| **OQ-F** | Market obligations: what blocks deleting a seller or buyer? | Any open order, undelivered contract, pending payout, or open dispute blocks | 08 §8.4 |
| **OQ-G** | Should a **data export** be offered before closure completes? | Out of scope; a hook (`export_requested_at`) only | 06 §6.9 |
| **OQ-H** | Is **`schedule:run` on cron for the `members` app**? Its own `routes/console.php` says this is "not yet confirmed configured". *(There is no queue worker to confirm — D17 uses none.)* | Assume not, and **verify before P2** — this is now the single scheduler precondition | 07 §7.4 |
| **OQ-I** | Do **live rows already hold `account_status` 2 or 3** that an enforcement change would affect? | Unknown — query before P0. Status 3 stays login-allowed, so it is safe either way | 09 §9.1 |
| **OQ-J** | Enforce status **2 (suspended)** at login too, now that the chokepoint exists? | No — out of scope | 02 §2.4 |
| **OQ-K** | Parse `MemberResource` class-level permissions are still open (`CLP_OPEN_TODAY`, `auth/public/checkpoint/parse/config.ts:107-187`). Does a purge need them tightened first? | The eraser uses the master key regardless; tightening is separate | 05 §5.4 |
| **OQ-L** | A member's **secondary users** (team) share the primary's lookup and will lose access when the primary is closed. Notify them? Offer ownership transfer? | They lose access with the primary; no transfer in this plan | 06 §6.7 |
| **OQ-M** | A tenant site has its **own customers** (site users, orders, form submissions). Deleting the site destroys their data. Is a notice to those customers owed? | Not automated; the closure notice to the member states it | 06 §6.4 |
| **OQ-N** | The legacy `users_member` table is still read/written in `FunctionsController` (lines 385, 3422–3571, 4885, 5244, 6675–6681, 7405). Purge it or leave it? | Purge the row if present | 03 §3.6 |
| **OQ-O** | May `members` write `brain_memberships.account_status` **directly**? The shared database permits it (`Membership::$fillable` carries the column), it would save a hop per transition and let the gate flip inside the engine's own transaction — but it breaks "only the Brain writes `brain_*`" (`AGENTS.md §5`) and skips the `brain_membership_events` row and actor the Brain writes with every status change. | **No** — through the Brain surface ([D18](#5-the-decisions)) | 02 §2.6, 05 §5.4 |
| **OQ-P** | Does `members` hold the credentials the engine needs? Its env lists `WWW_INTERNAL_API_KEY(_NEXT)` (the **inbound** `www`→`members` pair), `MARKET_INTERNAL_API_KEY(_NEXT)` and `CHECKPOINT_BASE_URL`/`SERVER_URL`/`APP_ID` — but **no `CHECKPOINT_SYSTEM_KEY`**, no key for the `members`→`www` direction, and none for `notes`/`flow`/`dream_graph`/`pdf_editor`. | Add each to the `_env/<mode>/.env` profiles through `use-env` (`AGENTS.md §6`) — values never in code or in this plan | 07 §7.5, 05 §5.4 |

# 05 — The purge engine

## Completion document

Not yet carried out. When it is: `complete/05-purge-engine.complete.md`, listing every eraser
built, the shell commands added and where, and the per-step results of a lab run against a
throwaway member.

## 5.1 Principles

A hard delete removes data from **MyISAM tables that cannot roll back** ([AC14](01-current-state-audit.md)),
from **servers whose commands return no exit status** ([AC16](01-current-state-audit.md)), and
from **several apps that share nothing but a database and a signature**. So it cannot be a
transaction and cannot be a single function. It is an **ordered, idempotent, resumable sequence of
independently verified steps**, and these rules hold for every step:

1. **Idempotent.** Removing what is already gone is success, not an error. Every step can be run
   twice and the second run changes nothing.
2. **Resumable.** State is in the ledger ([03 §3.4](03-database-schema.md)), not in memory. A
   crash, a timeout or a deploy mid-purge leaves a closure a runner can pick up.
3. **Preview equals run.** The dry run and the real run enumerate the *same* inventory. What the
   admin was shown is what is deleted — nothing more, and (checked by `verify`) nothing less.
4. **Verified.** After `run`, a `verify` re-runs the enumeration and expects nothing left. Leftover
   rows or paths turn the step to `failed`, so "done" means *observed gone*, not *asked to go*.
5. **Halt on failure; never skip silently.** A failed step stops the purge. Later steps may need
   what an earlier one would have removed to find it (e.g. the tenant credentials), and continuing
   would strand data no one can find any more.
6. **External first, identity late, the member row last.** So an interrupted purge is always still
   *findable* — by its `brain_memberships` row until the very last step, and always by its closure
   and its ledger in `members_*` (the sweep iterates closures, never members, so it does not need
   the member row to find an unfinished purge).
7. **Every step is isolated.** One failing eraser never masks another's result.

## 5.2 The order, and why

| # | Step | Why here |
|--:|------|----------|
| 0 | `block` | Nothing may log in mid-purge and create data ([D7](00-overview.md)). Also the whole of a soft delete. |
| 1 | `billing` | **Stop the money first.** If a later step fails the member is not still being charged, and this step *reads* local rows it would later anonymize. |
| 2 | `tenants` | Remote, slow, the likeliest to fail — fail early, while nothing irreversible about the *identity* has happened. Reads `brain_http_server_tenants.json` credentials, which must still exist. |
| 3 | `dns` | After the tenants (a zone with no site is harmless; a site with no zone is unreachable anyway). |
| 4 | `mail` | Independent of the above; needs `users_email` rows to find the mailboxes. |
| 5 | `storage` | Large; removes `/mnt/node1/{ref}` and the `members_storage_*` tables. |
| 6 | `communications` | Recording files are under storage — after it. |
| 7 | `app:*` | Each satellite app erases itself; `market` last among them because it has blockers and anonymizing. |
| 8 | `parse` | The allocation ledger; nothing above needs it. |
| 9 | `www_tables` | The Brain's own rows, after everything that read them. |
| 10 | `checkpoint` | The identity, deliberately **late**: `user_id` and everything needed to erase it is already captured in the closure, and until now the row kept the purge auditable. |
| 11 | `finalize` | Two halves, in this order: **(a)** the Brain-surface delete of the `brain_memberships` row — **last of all the deletes**, and `www` first checks the ledger shows every other step `done` ([§5.4](#54-per-store-erasers)); **(b)** in one `members` transaction, write the purge log and any tombstones, complete the closure, null the snapshots ([03 §3.8](03-database-schema.md)). If (b) fails after (a), the closure stays `purging` with `finalize` unfinished and the next tick completes it — (b) needs nothing the deleted row held, because `user_id` and the snapshots were captured in the closure at request time. |

## 5.3 The eraser contract

In `members`, an interface every step implements:

```
interface MemberEraser
{
    public function key(): string;                                          // 'tenants', 'app:notes', …
    public function preview(Membership $m, AccountClosure $c): StepPreview; // what WOULD go, and any blockers
    public function run(Membership $m, AccountClosure $c): StepResult;      // idempotent; counts of what went
    public function verify(Membership $m, AccountClosure $c): bool;         // true iff nothing of ours remains
}
```

(`AccountClosure` is the `members_account_closures` model — not PHP's own `Closure`. `Membership` is
`members`' existing read-only `App\Models\Brain\Membership`.)

- `preview` is **read-only** and cheap enough to call while an admin waits (paths counted with a
  `--dry-run` remote command, rows with `COUNT`). Its result is stored in `steps.preview`.
- `run` returns counts (rows, files, bytes); stored in `steps.result` and copied to the purge log.
- `verify` re-uses `preview`'s enumeration and returns "nothing left" — [principle 4](#51-principles).
- **Blockers** are reported by `preview` and *also re-checked by `run`* — a blocker that appears
  between preview and confirmation (a new pending payout) stops the purge at that step rather than
  proceeding on stale information.

Three kinds of eraser implement it, distinguished only by *who owns the data* ([03 §3.6](03-database-schema.md)):

| Kind | Owns the data | The eraser |
|------|---------------|------------|
| **Local** | `members` — `members_*` tables, `/mnt/node1`, the servers via `members`' own shell runner | Does the work itself |
| **Brain-surface** | `www` — `brain_*` rows, provider billing | A thin adapter: one signed call to `www`, whose own eraser does the work |
| **Remote** | `auth`, the satellite apps | A thin adapter: one call to that app's own eraser |

## 5.4 Per-store erasers

### Local — `members`

| Step | Class | Notes |
|------|-------|-------|
| `tenants` (part) | `TenantShellEraser` | Reads the tenant credentials (through the Brain API — `brain_http_server_tenants` is a Brain table), drives the remote `--purge-tenant-site`, then deletes `members_websites`, `members_website_deployment_progress`. The `brain_http_server_tenants` row is the Brain-surface half below |
| `dns` (part) | `DnsShellEraser` | `--purge-dns-zone` + `--reload-named`. The `brain_dns_*` rows are the Brain-surface half |
| `mail` | `MailEraser` | The implemented `--delete-email-account`; then `users_email` (through `EmailRetentionAdapter::purge`, which **already deletes rows** — [AC25](01-current-state-audit.md)) and the two `members_email_*` tables |
| `storage` | `StorageEraser` | `--purge-member-storage`; then the `members_storage_*` tables and `members_user_files`. Builds on `RetentionService::sweepHardDelete` (`members/app/Checkpoint/Storage/RetentionService.php:294`) — the model to follow, not a dependency, since that one is the retention ladder |
| `communications` | `CommunicationsEraser` | Deletes the rooms cascade; **nulls** (does not delete) rows where the member is a participant in *another* member's session |
| `block` (part) | `BlockAccessEraser` | Orchestrates the [02 §2.6](02-state-model-and-enforcement.md) revocation: the Brain-surface status call, `auth`'s `access`, the coordinator token disable, and `users_email` disable per the closure options |
| `finalize` (part) | `FinalizeEraser` | Writes the purge log and any tombstone, completes the closure, nulls the snapshots — **after** the Brain-surface delete of the member row ([§5.2](#52-the-order-and-why)) |

### Brain-surface — `www` ([D18](00-overview.md))

`www` exposes a small signed surface for exactly the four things only it can do. The channel
— `members`→`www` — **already exists**, built for billing enforcement
([billing/08 §8.2](../billing/08-brain-enforcement.md)): `members/app/Services/Www/BrainSurfaceClient.php`
and `BrainSurfaceSignature.php`, configured by `members/config/brain_surface.php`
(`WWW_BRAIN_SURFACE_URL`, `WWW_BRAIN_SURFACE_KEY`); verified on `www` by the `brain.only` +
`brain.surface` middleware on the `internal/brain` route group in `www/routes/api.php` (served under
`/api/internal/brain/…`), key `BRAIN_SURFACE_KEY_MEMBERS` (+ `_NEXT` for rotation). It uses its own
key pair — never `WWW_INTERNAL_API_KEY`, which authenticates the opposite direction ([D11](00-overview.md),
OQ-P). Account closure adds routes to that group and methods to that client; the routes below are
relative to `/api/internal/brain`:

| Route (`www`) | Does |
|---------------|------|
| `POST /internal/brain/members/{ref}/status` `{ status, event, actor, reason, closure_ref, expected_from }` | Flips `brain_memberships.account_status`, derives `account_close_date`, and writes the `brain_membership_events` row — **one transaction** ([04 §4.9](04-admin-soft-and-hard-delete.md)). **Validates the transition against the [02 §2.9](02-state-model-and-enforcement.md) table** and refuses an illegal one (e.g. 5 → 1) with 422, so a bug in `members` cannot un-purge a member |
| `POST /internal/brain/members/{ref}/erase/{preview\|run\|verify}` `{ step, closure_ref }` for `step ∈ { billing, tenant_rows, dns_rows, www_tables, member_row }` | The `www` erasers: `BillingEraser` (`SubscriptionActionsProvider::cancel`, then anonymize the payer/invoice rows; **blockers** — an unresolved dispute, a processor migration in progress), the `brain_http_server_tenants` and `brain_dns_*` rows, the `WwwTablesEraser` rows of [03 §3.6](03-database-schema.md), and the final `brain_memberships` delete |
| `POST /internal/brain/notices/send` `{ notice_key, to, variables }` | `AccountCloseMailer` → `BillingMailer` with the built-in fallback ([07 §7.3](07-notifications-and-scheduling.md)) |
| `POST` / `DELETE /internal/brain/members/{ref}/legal-hold` | Writes the three legal-hold columns on `brain_memberships` ([03 §3.2](03-database-schema.md)) |
| `POST /api/internal/brain/billing/enforce` | **Not an account-close route** — the billing enforcement trigger ([billing/08 §8.2](../billing/08-brain-enforcement.md)), on the same `internal/brain` group and the same `brain.only` + `brain.surface` middleware, with the same per-app key. Listed here because **the enforcement run must skip a member at `account_status` 4/5** (no dunning mail, no `brain_membership_billing_state` row for a closed member), which is what keeps enforcement from undoing a purge — `candidates()` in the `www` command gains that exclusion when account closure is built |

**Two `www`-side safeguards** — `www` is the sole writer of `brain_*` and does not take the engine's
word for everything:

- **`member_row` (the last delete) checks the ledger.** `www` reads `members_account_closures` and
  `members_account_purge_steps` (same shared database) and refuses to delete the `brain_memberships`
  row unless the named closure is in `purging` and **every other step is `done`**. The data
  `members` says it erased is confirmed erased before the row that anchors it goes.
- **`status` refuses unknown values and illegal transitions** (above).

### `auth` — Checkpoint and Parse

`auth` owns the identity ([AC14](01-current-state-audit.md), `AGENTS.md §5`), so **neither `members`
nor `www` deletes `ext_*` or `checkpoint_*` directly.** `auth` gains three system-key routes, on the
same `checkpoint.system` middleware that already guards `/checkpoint/member/init`. **The caller is
`members`**, holding `CHECKPOINT_SYSTEM_KEY` (it does not today — [AC27](01-current-state-audit.md), OQ-P):

| Route | Purpose |
|-------|---------|
| `POST /checkpoint/member/access` `{ user_id, action: block\|unblock }` | Writes/keeps the watermark, sets the Delight status, bumps `force_logout` ([02 §2.6](02-state-model-and-enforcement.md)). Makes the private `setPrimaryLogoutWatermark` reachable ([AC10](01-current-state-audit.md)). |
| `POST /checkpoint/member/erase/preview` `{ user_id, member_reference_num }` | Counts rows per table and Parse classes |
| `POST /checkpoint/member/erase` `{ user_id, member_reference_num }` | Deletes children first, `ext_member_users` last ([03 §3.6](03-database-schema.md)); calls the Parse purge; returns counts |

`members` gets a small client for these — `members/app/Services/Checkpoint/CheckpointSystemClient.php`
(`blockUser`, `unblockUser`, `erasePreview`, `erase`), beside the existing
`CheckpointTokenVerifier`. It **does not reuse** `www`'s `CheckpointServerClient`
(`www/app/Services/Checkpoint/CheckpointServerClient.php`), which is a different app's class and
has nothing that can delete or disable anyway ([AC13](01-current-state-audit.md)).

**`auth` refuses to erase a live member.** `erase` first reads
`brain_memberships.account_status` for the `user_id` and proceeds **only** if it is **5** or the
row is already gone. So a bug in `members` — or a leaked system key — cannot erase an active member;
the identity's own service holds the last veto.

**Parse.** A new cloud function `memberPurge` in `auth/public/checkpoint/parse/cloud/functions.ts`
deletes the member's `MemberResource` rows, the `MemberRegistry` anchor and `CheckpointAlloc_Log`
rows (there is no delete function today, [AC19](01-current-state-audit.md)). `auth`'s `erase` calls
it with the master key; it is **not** exposed to clients. The open class-level permissions
(`CLP_OPEN_TODAY`, OQ-K) are unchanged by this and remain a separate problem.

### The satellite apps

`coordinator`, `notes`, `flow`, `dream_graph`, `pdf_editor` each expose the same three-verb
surface (`preview`, `erase`, `verify`) behind a signed internal route, and each implements **its
own** `MemberEraser` over **its own** tables ([03 §3.6](03-database-schema.md)). `members` supplies
`member_reference_num` and `user_id`; the app decides how to delete.

- **Signing.** `members` **already** has two outbound signed clients — `MarketClient`/`MarketSignature`
  and, for `coordinator`, `AfsConnectSignature`/`CoordinatorHttp` ([AC20](01-current-state-audit.md)) —
  so `market` and `coordinator` need only their eraser routes. `notes`, `flow`, `dream_graph` and
  `pdf_editor` have **no inbound signed channel from `members`** and none was found; the generic
  route and signature check are best shipped **once** in the shared `packages\checkpoint-auth`
  package that those four (and `coordinator`) already share, so each app writes only its eraser
  class. *(A design option, decided in P2 — the package's current purpose is token validation.)*
- **`market` is special.** Its `preview` can return **blockers** (open orders, undelivered
  contracts, pending payouts, open disputes — OQ-F), and its `erase` **anonymizes** the records
  that involve other people (orders, ledger entries, reviews, entitlements) instead of deleting
  them ([D9](00-overview.md)). Assets with entitlements cannot be deleted at all — the existing
  rule (`market/complete/05…:38`) — so they are anonymized to "removed by owner" and their files
  purged by the existing `market:purge-deleted-asset-files` after its grace period.
## 5.5 Remote commands — what has to be written

The servers do the removals, and today **nothing exists to do them safely** ([AC17](01-current-state-audit.md)).
All of the following are new, in **`members/core/scripts/commands.sh`** — the copy `members`' own
`RunCommand` executes (`/home/appfactorystudio/websites/$SUBPATH/core/scripts/commands.sh`,
[AC18](01-current-state-audit.md)). The handlers they mirror are `--create-member-storage` (956),
`--create-email-account` (767) and the stub `--delete-email-account` (810) in that same file, and
`www`'s `--create-afs-member-storage` (`www/core/scripts/commands.sh:332–352`). **OQ-A** still needs
answering on the server: whether the `core/scripts2` set `DomainController` provisions with
(`RunCommandBG2`) exists, and whether tenant and DNS *teardown* belongs there beside the
*provisioning* it reverses.

### The commands

| Command | Does |
|---------|------|
| `--purge-member-storage <member_ref> [--dry-run]` | Removes `/mnt/node1/{ref}/` including `.staging/` and `.trash/`. `--dry-run` prints file count and bytes only. |
| `--purge-member-mailboxes <systemuser> <domain> <user>…` | **Implements** the stub `--delete-email-account` (`members/core/scripts/commands.sh:810`): removes the `vmailbox` entry, runs `postmap`, removes the Maildir. |
| `--purge-tenant-site <systemuser> <subdomain> [--dry-run]` | Stops the pm2/`--stop-stripe-server` processes, removes the vhost and web root, drops the tenant database. **DB credentials arrive on stdin or in the environment — never `argv`**, which any local user can read from the process list. |
| `--purge-dns-zone <domain>` | Removes the zone file and its `named` config stanza; the caller runs `--reload-named` after. |

### Rules every one of them follows ([D15](00-overview.md))

- **Validate before anything.** `member_ref` must match `^m1x[A-Za-z0-9]{10}$`; `systemuser`
  `^[a-z][a-z0-9_-]{2,31}$`; `subdomain` and `domain` a strict hostname pattern. A failed
  match exits **2** and touches nothing.
- **Canonicalize and confine.** The target path is resolved with `realpath`; it must lie **strictly
  under** an allowed root (`/mnt/node1`, `/home/<systemuser>/websites`) and must **not equal** the
  root. A path that is empty, `/`, `.`, contains a symlink that leaves the root, or resolves
  elsewhere aborts with exit 2. Every expansion is double-quoted; `--` ends option parsing.
- **Real exit status.** 0 = removed or already absent (idempotent), 2 = refused by validation,
  1 = failed. Stdout carries the counts.
- **Dry-run parity.** `--dry-run` runs the identical resolution and validation and prints what it
  would remove — that is what `preview` reports.
- **Never `--remove-website`.** It exists in `www`'s `commands.sh` and runs `rm -rf $directory`
  with the path **unquoted and unvalidated** (`www/core/scripts/commands.sh:1073–1086`, called from
  `www`'s `FunctionsController.php:10079`) — a shell injection waiting on any caller that passes a
  hostile directory. It is left alone by this plan but **flagged for its own fix**
  ([08 §8.6](08-security-and-safety.md)), and neither copy of `delete_member.expect` is ever reused
  ([AC1](01-current-state-audit.md)).

### Getting an answer back

`members`' `FunctionsController` already has a **synchronous** path — `RunCommand` `exec()`s the
wrapper and returns `output` and `return_var` — and background ones (`RunCommandBG`, `RunScriptBG`,
`RunCommandBG2`) that `nohup … &` and return only a pid ([AC16](01-current-state-audit.md)). The
purge needs the exit status, so it would use the synchronous one — **except that it builds the
command by string concatenation with no validation**:
`…/bin/afs-website "<commands.sh> <params>"`. The `afs-website` wrapper takes **one string** that a
shell then interprets, so a hostile `$shParams` is command injection.

So the engine gets **its own** runner, `RemoteCommandRunner` in `members`, and leaves
`FunctionsController` alone (it is used by provisioning). It:

- **allowlists every argument in PHP first** — each is matched against the same pattern the shell
  side enforces (`^m1x[A-Za-z0-9]{10}$`, the systemuser and hostname patterns above) and the call is
  **refused before any command line is built**;
- invokes the same `…/afs-website commands.sh <command> <args>` entry point, but waits, captures the
  exit code and the last lines of output, applies a **timeout**, and returns a typed result;
- passes secrets (the tenant DB credentials) by stdin or environment, never in the command string.

The background runners are not modified and are not used by the purge.

*Unverified, to confirm per command when written:* whether a given command executes on the Brain
host or reaches a tenant server over SSH (the create-side `--create-domain-on-server` at line 801
and `--admin-transfer-tenants` at 868 suggest some do). The runner takes a **target**; each eraser
states which.

## 5.6 Running, failing, resuming

**The runner.** A plain service, `PurgeRunner::advance(int $closureId, int $budgetSeconds = 50)`
— **not a queued job** ([D17](00-overview.md); there is no worker). It runs **in `members`** and is
called from two places, both in `members`: the admin hard-delete request that `www` proxied (after
the 202 is sent, `dispatchAfterResponse`) and `account-close:tick`
([07 §7.2](07-notifications-and-scheduling.md)). It claims the closure with
`Cache::lock("purge:{ref}")` and `lockForUpdate` on the closure row (the cache store is
database-backed, which supports locks — [AC22](01-current-state-audit.md)), then:

```
for each step in ordinal order where status ∈ {pending, failed}:
    status ← running; attempts++
    result ← eraser.run()
    if eraser.verify():  status ← done;  store result
    else:                status ← failed; store last_error; STOP
    if time budget (~50 s) exhausted: return MORE_WORK   # the next tick continues
finalize
return DONE
```

`MORE_WORK` is not a failure: the closure stays at status 5 with the next unfinished step still
`pending`, and the next tick calls `advance` again.

- **Failure** stops the purge, sets `closure.last_error`, writes `EVENT_PURGE_FAILED`, and leaves
  status **5**. Nothing later runs.
- **Auto-retry.** The `members`-scheduled tick ([07 §7.2](07-notifications-and-scheduling.md)) retries a
  `failed` step with backoff (15 min, 1 h, 6 h, 24 h, 24 h) — **five attempts** — then leaves it `failed` for an
  operator, who sees it in the Danger zone and can press **Resume** ([04 §4.3](04-admin-soft-and-hard-delete.md)).
- **`skipped`** exists for "nothing to do" (a member with no websites) and always records *why* in
  `result`. A step is never skipped because it errored.
- **Crash recovery.** A step left `running` for longer than its timeout is treated as `failed` by
  the sweep and retried — safe *because* of principle 1.

**Concurrency.** One runner per closure (the lock). Two admins pressing **Resume** get one runner
and one no-op.

## 5.7 What else must learn that a member is gone

A closed or purging member is still a row that **other scheduled work keeps finding**. Each of
these must skip status 4/5 or it will send mail to a closed member, or — worse — **recreate rows a
purge just deleted**:

| Existing work | Where | Why it matters | Change |
|---------------|-------|----------------|--------|
| `direct_payments:subscription-notify` (every 15 min; `www`, Brain and every tenant) | `www/routes/console.php`; `BillingSubscriptionNotify` | Reminder mail to a closed member | Skip `account_status ∈ {4,5}` (the tenant-side run only matters where the member is the Brain's own; the Brain run must skip) |
| `billing:enforce-membership` (every 15 min; scheduled in **`members`**, run on `www`, [billing/08](../billing/08-brain-enforcement.md) §8.0) | `members/routes/console.php`; `www` `BillingEnforceMembership` | Dunning mail to a closed member; **recreates `brain_membership_billing_state`** | The `www` command's `candidates()` excludes `account_status ∈ {4,5}` (the members trigger cannot know) |
| `NotifyMembersOfTierChange`, `SyncConnectAccountJob` | `www/app/Jobs` | Mail / Connect sync for a member who is being erased | Skip |
| `storage:retention-sweep`, `storage:tombstone-sweep`, `storage:session-sweep`, `email:retention-sweep` | `members/routes/console.php` — **the same app as the engine** | Racing the `storage` and `mail` steps over the same directories | Skip members at 5. Being in one codebase, this is a shared guard (`Membership::isClosing()`), not a cross-app contract |
| `market:purge-deleted-asset-files` | `market` | Same race over asset files | Skip members at 5 |
| `CoordinatorSettingsClient` | `www` | Already forwards `brain_account_status` (`:47`) — the coordinator must **stop syncing** for 4/5 | Re-check `coordinator`'s `BrainMembershipLookup.php` ([AC4](01-current-state-audit.md)) |

The list is a **checklist for P2**, not a claim it is complete: grep every scheduled command and
job for `Membership::`/`member_reference_num` and decide skip-or-not per hit.

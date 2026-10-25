# 01 — Current State Audit

> **STUB, FOR AUDIT.** Everything below was read from the repository, not assumed. Findings
> are numbered `M#` so later documents can cite them.

> **Note on OQ numbering:** §§1.1-1.5 were written before `checkpoint_registry_v4.json`
> existed and cite the first draft's OQ1-OQ8. Four of those are now answered by registry
> fields — see [00-overview.md](00-overview.md) §6 for the current list. The findings
> themselves are unchanged and still hold.

## 1.1 What storage built, and where it lives

`members/app/Services/Storage/` — the implementation this plan generalises.

| Class | Role |
|---|---|
| `QuotaService` | `planFor()` (clamp 0–4, missing ⇒ 0), `bucketCapacityBytes()` (registry, KB→bytes), `totalCapacityBytes()` (**cumulative**), `freeBytes()`, `routeBucket()` (the ascending cascade), `quotaExceededPayload()` |
| `RetentionService` | The state machine: `reconcile()`, `retire()`, `restoreFromRetired()`, `restoreFromPurging()`, `sweepPurge()`, `sweepHardDelete()`, `summaryFor()` |
| `UsageLedger` | The authoritative used/reserved counters |
| `RegistryClient` | Registry fetch + cache |
| `PathResolver` | `bucketRoot()`, `stagingDir()`, `trashPath()` |

Models: `BucketUsage` (the per-band ledger row with `state`, `retired_at`, `purge_after`),
`RetentionEvent` (the audit log), `StorageNode`.
Command: `members/app/Console/Commands/StorageRetentionSweep.php`, intended hourly.
Client: `members/resources/js/storage/` — `ApiClient`, `BucketPanel`, `StorageApp`,
`UploadEngine`, `IndexedDbStore`, `errors`.

Constants: `GRACE_DAYS = 30`, `TRASH_HOLD_DAYS = 7`.

| # | Finding | Consequence for this plan |
|---|---|---|
| **M1** | The whole implementation is **`members`-only** — different app, different database, different route prefix (`/api/v1/storage`) from email's (`/portal/api/v1/email-accounts`) | Email cannot call any of it. This is **OQ4**, and it is the plan's central structural problem |
| **M2** | `QuotaService::totalCapacityBytes()` sums `0..P` — **cumulative** is already shipped behaviour for storage | Whatever **OQ1** decides for email, changing *storage* to absolute would be a live behaviour regression for existing members |
| **M3** | `RetentionService`'s own docblock records that the member-facing notification ladder (§6.3's T+7/T+21/T+28) was **never built** — "no email/notification integration exists for this to hook into" | **OQ6.** That integration now exists (billing's `BillingMailer`) |
| **M4** | Storage's `BucketUsage` row is keyed on `member_reference_num` + `band` | A per-domain feature needs a different key. The shared ledger must be scope-aware — [02](02-architecture.md) §3.3 |
| **M5** | ~~`QuotaService` has no concept of `account_status` — `planFor()` reads `membership` only~~ **FIXED 2026-09-21** | Suspension (**D4**) turned out to be genuinely new machinery, exactly as this row predicted — built in `RetentionService::suspend()`/`unsuspend()` (not `QuotaService`), as a `suspended_at` marker orthogonal to `state` rather than a fourth enum value; see [features/complete/25-storage_files_size_kb.complete.md](features/complete/25-storage_files_size_kb.complete.md) §F25.10 F6 |
| **M6** | alloc_v2 reporting (cloud-storage §7) was correctly deferred to its Phase 5 and is **not built** | Storage's local ledger is authoritative today by default as much as by design. Relevant to **OQ2** |

## 1.2 What email does today

Client: `www/resources/js/portal/admin/views/email.ts` (931 lines).
Server: `www/app/Http/Controllers/Portal/Admin/EmailAccountController.php`.
Model: `App\Models\UsersEmail` → table `users_email`.
Shell: `www/core/scripts/commands.sh` → `--create-email-account`, `--delete-email-account`.

### The create path works and has real system side effects

`createEmailAccount()` writes the `users_email` row, then:

```
FunctionsController::RunCommandBG("--create-email-account {SystemUser} {domain} {username}")
  → mkdir -p /mnt/node1/{systemuser}/mailbox/{domain}/{username}/Maildir
  → chown -R vmail.vmail
  → append "{username}@{domain}  {domain}/{username}/Maildir/" to /etc/postfix/vmailbox
  → /usr/sbin/postmap /etc/postfix/vmailbox
```

So an email account is **live mail infrastructure**, not a database row. That single fact is
why [email](features/2-domain_email_accounts.md) is harder than storage and why **OQ3** exists.

### The limit is enforced client-side, at the moment of save, and only forward

`email.ts` calls `allocV2.canUse(DOMAIN_EMAIL_ACCOUNTS, 1, { domain })` before creating, and
`allocV2.consume(...)` after. That is the entire enforcement.

| # | Finding | Consequence |
|---|---|---|
| **M7** | **`--delete-email-account` is a stub.** The block sets `systemuser`/`domain`/`directory`, creates a log directory, echoes a header — and does nothing else. No `vmailbox` removal, no `postmap`, no Maildir deletion | **The mechanism this plan needs for "disable" does not exist at the shell level.** It has to be built regardless of which option **OQ3** picks |
| **M8** | **`EmailAccountController::destroy()` deletes the DB row only.** No shell call at all, so the `vmailbox` entry survives | **Mail keeps being delivered to accounts the UI says are deleted.** A live defect, independent of this plan |
| **M9** | **Email never calls `allocV2.release()`.** `consume()` on create, nothing on delete | The quota is a one-way ratchet — the same defect class as Direct Payments' **B43** in [../www/direct_payments/20-current-state-audit.md](../www/direct_payments/20-current-state-audit.md) §2.9. A member at their limit who deletes accounts still cannot create one |
| **M10** | **`users_email` has no status/disabled column.** Columns are `username, password, domain, userid, home, type, level, category, verified, backupEmail, firstname, lastname, token, expiry, uid, gid, date`. `type` is public/private (1/0); `level`/`category`/`verified` carry unrelated meanings | Disabling requires a schema change. There is nothing to overload safely |
| **M11** | **No band assignment exists.** Nothing records which tier band an account belongs to | Without it, a downgrade has no principled way to choose *which* accounts to disable — see **M12** |
| **M12** | Account ordering is by `id`/`date` only | If accounts are not assigned to bands, the only available rule is "disable the newest N", which takes the choice away from the member — precisely what **D3**/**D10** exist to prevent |
| **M13** | `canUse()` failures are swallowed: the "add" button's handler `console.error`s and leaves the form open; the save handler alerts and aborts | Fails **open** on the add path, closed on save. `domain_email_accounts` is `overage: block`, so **D6** says both should fail closed |
| **M14** | The quota panel (`renderEmailQuota()`) renders only for the `appfactory_studio` provider; every other provider shows *"Quota data is unavailable for the current provider."* | A member on SendGrid sees no limit information at all, so a downgrade would be entirely invisible to them |
| **M15** | Every quota call is scoped `{ domain: quotaDomain }`, and the view refuses to load unless the browser is on that domain | Confirms **OQ7** is real: the holder is a domain, not a member |

## 1.3 The registry — verified values

`auth/public/checkpoint/checkpoint_registry_v3.json`:

| Resource | Scope | Overage | Unit | Tiers |
|---|---|---|---|---|
| `storage_files_size_kb` | `member` | `block` | kilobytes | `524288, 2097152, 10485760, 52428800, -1` |
| `domain_email_accounts` | `per_domain` | `block` | accounts | `2, 10, 50, 250, -1` |
| `domain_email_sends_monthly` | `per_domain` | `block` | messages | `500, 5000, 25000, 150000, -1` |

Tier names: `free, starter, professional, business, enterprise`. All five tiers `enabled`
for all three resources. `-1` is the unlimited sentinel.

`brain_memberships.membership`'s own column comment labels them
`0=Free, 1=Pro, 2=Business, 3=Organization, 4=Enterprise` — **different labels, same
index**. **D5**/§4's "positional, never by name" rule exists because of exactly this.

## 1.4 The two scopes do not match

| | Storage | Email |
|---|---|---|
| Registry scope | `member` | `per_domain` |
| Ledger key today | `member_reference_num` + `band` | *(none)* |
| Enforcement | local ledger, server-side | `alloc_v2`, client-side |
| Authority | local (**D4** of cloud-storage) | alloc_v2 |
| Unit | bytes — continuous | accounts — discrete |
| Item | file/folder node | `users_email` row + live Postfix entry |
| Band is | a real directory | *(does not exist yet)* |

Every row of that table is a place the shared machinery has to be parameterised rather than
assumed. [02](02-architecture.md) §3 is the design for that.

## 1.5 Summary — what must be true before email can work

1. A band assignment exists for email accounts (**M11**) — chosen by the member, not by
   insertion order (**M12**). **Done** — `band`/`status` columns exist on `users_email`
   (2026-09-20).
2. ~~A disable mechanism exists at the DB *and* Postfix level (**M7**, **M10**).~~ **Corrected
   2026-09-20: no Postfix level exists or is needed.** Resolved that inbound mail is never
   touched at any lifecycle stage — disable is a DB-only status flip, gated wherever the
   application itself checks login/send. M10 (status column) is done; M7 (the Postfix-level
   shell stub) is no longer a prerequisite for this plan.
3. ~~`destroy()` stops leaving live mail routes behind (**M8**).~~ **No longer a prerequisite
   (2026-09-20)** — under the resolved model above, mail is *supposed* to keep being delivered
   to a deleted account forever, so there is no "live route" defect to fix.
4. `release()` is called on delete (**M9**). **Done, 2026-09-22** — `EmailAccountController::destroy()`
   now calls `CheckpointAllocClient::release()`, verified live. See
   [features/complete/2-domain_email_accounts.complete.md](features/complete/2-domain_email_accounts.complete.md).
5. The holder is settled: per-domain, and what that means for a multi-domain member
   (**OQ7**, **M15**). **Settled** — per-domain, per `02-architecture.md`.
6. Authority is settled: local ledger or alloc_v2 (**OQ2**). Both, for different halves: `alloc_v2`
   remains the forward-edge quota authority (unchanged); `EmailRetentionService`/`RetentionEngine`
   (`members`) is the backward-edge retention authority — the two don't conflict, since one
   answers "may I create one more" and the other "what happens when I hold too many."

---

## 1.6 The `alloc_v2` half — added in the v4 rewrite

Source: [../allocation.md](../allocation.md) §12.1's manual estate sweep, and
[../TODO-member-resources.md](../TODO-member-resources.md).

| # | Finding | Consequence |
|---|---|---|
| **M16** | **3 of 29 resources have any call site at all.** Processors, stores, forms, events, subscribers, notes, notebooks, PM projects, kanban boards, graphs, PDF documents, VoIP numbers, secondary users and `afs_connect` have none | 26 limits the registry declares and nothing enforces — in *either* direction. A pricing page claim with no mechanism behind it |
| **M17** | **`release` is wired nowhere** (G6) | Quota is a one-way ratchet estate-wide. Under downgrade this is worse than general: a member who deletes things to get under the new limit still cannot create |
| **M18** | **`canUse` fails open** (G12) — `email.ts:288`/`:384` catch and fall through to create | Both wired resources are `overage: block`. Inverted, not missing |
| **M19** | **Two counter representations** (G13) — `ac_*` flat counters are the authority, hand-editing surfaces write the nested projection | Correcting a counter through the Brain admin JSON editor changes nothing the member experiences |
| **M20** | **No reconciler for any resource** (G9) | Counts drift and nothing detects it. A band ledger built on a drifting count inherits the drift |

## 1.7 Scope nesting — the finding that reshaped this plan

The first draft assumed two scopes (member, domain). The registry has **seven**, and they
nest — `conventions.scope_graph` in v4 encodes it.

| # | Finding | Consequence |
|---|---|---|
| **M21** | **`member_domains_count` is `[1, 3, 10, 25, -1]`.** Plan 3 → plan 1 retires **7 domains**, each carrying ten `per_domain` resources, one of which nests a `per_store` resource | Retirement must cascade transitively. A per-feature implementation cannot see this — email's code has no idea a domain above it is retiring. **This is why the engine must be registry-driven** |
| **M22** | **`domain_ecommerce_items_per_store` is `scope: per_store` but `parse_member_query.json` stores it flat on the domain** | Every store in a domain shares one counter, despite the registry saying per-store. Fixed in `parse_member_query_v4.json` by nesting a `stores[]` container |
| **M23** | **10 of 29 resources are missing from the Parse member object entirely** — notes, notebooks, graphs, PDF docs, design projects, secondary users, `afs_connect`, and the containers for `per_graph`/`per_document`/`per_design_project` | The initial member object cannot hold usage for a third of the registry. Fixed in v4 |
| **M24** | **`conventions.tier_resolution` in v3 says "Tiers are resolved by name"** — but tier entries carry **no name field**, and every plan in the estate states the opposite rule | The registry contradicted both its own data and every consumer. Corrected in v4 |

## 1.8 Summary — the shape of the work

- **1 resource** has both edges (`storage_files_size_kb`).
- **1 more** is complete by construction (`afs_connect`, binary).
- **4** need no backward edge (`retention: none`, the period resources) — but have no
  forward edge either.
- **23** need everything.
- **25 of 29** do not yet have their inventory identified, and nothing can be retired until
  they do.

Per-resource state is tracked in [../TODO-member-resources.md](../TODO-member-resources.md).

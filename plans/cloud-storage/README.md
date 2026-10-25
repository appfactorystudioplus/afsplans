# cloud-storage — Planning Index

**Cloud Storage** gives every member a real, quota-governed file store on AFS
infrastructure at `/mnt/storage1/{member_reference_num}/storage/{bucket}`, reachable from
three clients — the **members website** (`members15.appfactory.studio`), the **Flutter
mobile app**, and the **Electron desktop app** — through one upload protocol, one
filename-validation rule set, and one sync model.

It also introduces **Storage Retention**: the member's plan number selects how many
storage buckets they may use, each bucket's capacity comes from
`checkpoint_registry_v3.json → resources.storage_files_size_kb.tiers[]`, uploads cascade
into the next bucket when one fills, the member can move data between the buckets they
own, and a plan downgrade retires the buckets above the new plan.

> ### ⚠ Step 01 is a prerequisite for everything else — and stands on its own
>
> Planning this feature surfaced that the members app has **no uniform server-side
> authentication**. Login is entirely client-side (`main.ts` → `ClientHandler`), no Laravel
> session is ever created, and most API routes resolve the caller from a **client-supplied
> query parameter** — `?user_id=`, `?member_reference_num=` — or, in storage's case, from a
> hardcoded admin login. Any caller can read and modify any member's data.
>
> [01-server-authentication.md](01-server-authentication.md) fixes that. It is a live
> cross-tenant exposure across most of the members API, it is not caused by storage, and it
> should ship whether or not the rest of this plan is scheduled.

> ### ⚠ Storage location: extended by [storage-sharding](../storage-sharding/README.md)
>
> This plan's single mount (`/mnt/storage1`, one machine) is being replaced by a **fleet of
> storage devices**, placed and reached over HTTP rather than written to directly by members.
> As of 2026-09-27, storage-sharding steps 01–10 are built (backend + web client; desktop and
> mobile clients and true multi-device fan-out are not). Everything below this line describes
> the *original*, still-partly-current design — bucket model, quota, name validation, the sync
> protocol shape, and (until placement is turned on) the actual runtime behaviour. Read
> [storage-sharding/00-overview.md](../storage-sharding/00-overview.md) first for what changed,
> and see the note at the top of 03, 04, 05, 08, 09–12 below for what each of those specifically
> no longer describes on its own.

This directory contains the full design. Read in this order:

| Step | Document | Contents |
|---|----------|----------|
| — | [README.md](README.md) | This index |
| 00 | [00-overview.md](00-overview.md) | Problem statement, goals, non-goals, glossary, the nine decisions |
| **01** | [01-server-authentication.md](01-server-authentication.md) | **Start here.** How Checkpoint auth works, why most routes are unauthenticated today, and the server-side identity chain every later step depends on |
| 02 | [02-current-state-audit.md](02-current-state-audit.md) | What storage code exists today and what is wrong with it — S1 (hardcoded admin), C1 (mangled filenames), and 20 more |
| 03 | [03-architecture.md](03-architecture.md) | Bucket model, on-disk layout, identity chain, component map across the four repos |
| 04 | [04-database-schema.md](04-database-schema.md) | New tables in the members DB; the usage ledger that is the authoritative quota number |
| 05 | [05-api-contracts.md](05-api-contracts.md) | `/api/v1/storage/*` — the session-based chunked upload/download protocol every client speaks |
| 06 | [06-name-validation.md](06-name-validation.md) | The Linux/portable filename rule set, the preflight scan, and the shared fixture corpus that keeps three implementations honest |
| 07 | [07-sync-and-dedupe.md](07-sync-and-dedupe.md) | Three-way sync, the delta cursor, duplicate prevention, and why a collision is never a silent overwrite |
| 08 | [08-storage-retention.md](08-storage-retention.md) | **The Storage Retention feature** — the formula, cascade routing, bucket moves, downgrade retirement |
| 09 | [09-web-client.md](09-web-client.md) | members: Laravel backend + Tools → Cloud Sync frontend |
| 10 | [10-desktop-client.md](10-desktop-client.md) | afsdesktop5: `src/main/cloud/`, reusing the `src/main/sync/` engine shape |
| 11 | [11-mobile-client.md](11-mobile-client.md) | afsmobile_flutter: repository, isolate hashing, background-upload limits |
| 12 | [12-security.md](12-security.md) | Path containment, tenant isolation, abuse limits — builds on step 01 |
| 13 | [13-rollout-and-testing.md](13-rollout-and-testing.md) | Phases, migration of existing data, the test matrix |

## Completion documents

Each numbered document above gets a completion document in [`complete/`](complete/) when
its work lands. Name it with the source basename plus `.complete.md` — for example
`05-api-contracts.complete.md`. Include status, summary, files changed, verification
steps, and follow-up work.

## Code documentation standard

Document all new and changed code thoroughly using block comments — JSDoc for
TypeScript/JavaScript, PHPDoc for PHP, `///` doc comments for Dart. Every file, exported
symbol, public function, non-obvious private helper, IPC handler, route handler, and
non-trivial type must explain purpose, parameters, return values, side effects, and plan
references. Inline `//` comments are for brief local clarification only — they do not
replace block documentation.

## One-paragraph summary

Members authenticates entirely in the browser and, on the server, mostly does not
authenticate at all — so **step 01 builds the server-side identity chain first**: every
route inside `checkpoint.verify`, the caller resolved by asking `auth15.appfactory.studio`
to verify the bearer token, and `member_reference_num` plus the plan tier attached to the
request where no client can forge them. On that foundation, storage replaces today's
implementation — which writes to `/mnt/node2/users/{sharedAdminId}/storage`, mangles
`Q4 Report.pdf` into `q4-report_65f1a2b3.pdf`, and has no quota, dedupe, or resume — with a
**session-based chunked protocol** (`manifest → offsets → ranged PUT → commit`) whose
resume state is the length of the `.part` file on the server, so a dropped connection costs
one chunk. The same protocol serves all three clients; the desktop already contains a
working implementation of this exact state machine in `src/main/sync/` (built for LAN
transfer in `afsdesktop5/plans/random/08`) and the cloud client is that engine pointed at
HTTPS. Files land under their **real names, at their real relative paths**, validated
against one portable-filename rule set compiled into all three clients and re-checked
server-side. **Storage Retention** layers the plan economics on top: buckets `0..4` mirror
the registry's five tiers, uploads cascade upward as each fills, members redistribute data
between the buckets they own, and a downgrade puts the orphaned buckets into a 30-day
read-only grace period before deletion — never an instant, unannounced `rm -rf`.

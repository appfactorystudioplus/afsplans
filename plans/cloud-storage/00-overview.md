# 00 — Overview

## Completion document

When this plan step is completed, write a completion document in this directory's
`complete/` folder, named `00-overview.complete.md`. Include status, summary, files
changed, verification steps, and any follow-up work.

## Code documentation

Document all new and changed code thoroughly using block comments (JSDoc for
TypeScript/JavaScript, PHPDoc for PHP, `///` for Dart). Every file, exported symbol,
public function, non-obvious private helper, IPC handler, route handler, and non-trivial
type must explain purpose, parameters, return values, side effects, and plan references.

---

> **Extended by [storage-sharding](../storage-sharding/00-overview.md)** (2026-09-27): the single
> `/mnt/storage1` mount this plan designs around is being replaced by a fleet of storage devices.
> The goals, decisions and acceptance criteria below are still the baseline everywhere
> storage-sharding does not explicitly change them (quota, bucket cascade, name validation, the
> sync protocol's shape). Where it does — the physical storage location (D2 below), and the
> desktop/mobile clients (repo map §7), which storage-sharding has not yet updated — the newer
> plan's decisions win.

## 1. The problem

A member has files. They want those files on AFS infrastructure, reachable from whichever
of the three AFS clients they happen to be sitting in front of, in the same shape they
left them — same names, same folder structure — and they want to know how much room they
have and what happens to their data if they change plan.

None of that is true today. The audit in [02-current-state-audit.md](02-current-state-audit.md)
is the evidence; the short version is that the website writes every member's files into
**one shared admin account**, renames `Q4 Report.pdf` to `q4_report_65f1a2b3c4d5e.pdf`,
has no concept of a quota, and neither the mobile nor the desktop app has any storage
code at all.

### 1.1 The problem underneath the problem

Planning this feature surfaced something larger: **the members server does not
authenticate most of its own API.**

Login happens entirely in the browser (`resources/js/main.ts:131–132`, a Checkpoint SSO
exchange against `auth15.appfactory.studio`); no Laravel session is ever created. Server
side, one block of routes verifies the bearer token properly — and everything else
resolves the caller from a **client-supplied query parameter** (`?user_id=`,
`?member_reference_num=`) or, in storage's case, from a hardcoded admin login.

Storage cannot be built on that. Neither can quota, retention, or tenant isolation — every
one of them is a sentence beginning "for this member", and there is no trustworthy answer
to "which member?".

So [01-server-authentication.md](01-server-authentication.md) is **step 1**, it is a
prerequisite for every other step in this plan, and it is worth shipping on its own merits:
it closes a live, unauthenticated cross-tenant data exposure that has nothing to do with
storage.

## 2. Goals

| # | Goal | Measured by |
|---|---|---|
| G0 | **The server knows who is calling.** Every authenticated route resolves the member from a verified Checkpoint token, never from the request body or query string. | [01-server-authentication.md](01-server-authentication.md); acceptance AU1–AU12 |
| G1 | **Files land as-is.** Byte-identical content, original filename, original relative path. A folder `start/` with files in it becomes `/mnt/storage1/{ref}/storage/{n}/start/<files>`. | §5 of [07-sync-and-dedupe.md](07-sync-and-dedupe.md); acceptance A1 |
| G2 | **One protocol, three clients.** Web, mobile, and desktop speak the same `/api/v1/storage/*` surface. No client-specific server path. | [05-api-contracts.md](05-api-contracts.md) |
| G3 | **Resumable and efficient.** A 40 GB upload interrupted at 39 GB resumes at 39 GB. 10 000 small files do not cost 10 000 round trips. | §5 of [05](05-api-contracts.md); acceptance A6–A8 |
| G4 | **Every upload is name-scanned** on the client before bytes move and on the server before bytes are accepted, against one shared rule set. | [06-name-validation.md](06-name-validation.md) |
| G5 | **A duplicate is never a silent error.** A name collision is reported and resolved explicitly by the member. | §4 of [07](07-sync-and-dedupe.md); acceptance A4 |
| G6 | **Real sync**, not one-way upload: changes on either side converge, deletions propagate, conflicts surface. | [07-sync-and-dedupe.md](07-sync-and-dedupe.md) |
| G7 | **Storage Retention** — plan-driven bucket capacity, cascade routing, member-controlled placement, and a survivable downgrade path. | [08-storage-retention.md](08-storage-retention.md) |
| G8 | **Tenant isolation.** No code path can read or write another member's bucket. | [12-security.md](12-security.md) |

## 3. Non-goals

- **Third-party providers.** `CloudController.ts` has Linode/AWS/DigitalOcean tabs. Those
  stay as they are. This plan is the **AFS** provider only.
- **Server provisioning.** `AFSController.ts` mocks VM creation. Unrelated; untouched.
- **File preview, editing, or sharing links.** Storage first. Sharing is a follow-on and
  its permission model is deliberately not designed here.
- **Block-level / rsync-style delta within a single file.** Resume is chunk-granular
  (§5.4 of [05](05-api-contracts.md)); re-writing 8 MiB is acceptable, and rolling-hash
  delta is a Phase 5 option, not a Phase 1 requirement.
- **Cross-member deduplication.** Content hashing is per-member only. Global dedupe leaks
  the existence of other members' files through timing and is out of scope.
- **LAN device-to-device transfer.** That is `afsdesktop5/plans/random/08-sync-rework.md`
  and `09-mobile-sync.md`. Cloud storage reuses that engine's *shape*, not its transport.

## 4. Glossary

| Term | Meaning |
|---|---|
| **member_reference_num** | The member's stable identifier, resolved by the `checkpoint.verify` middleware from a Checkpoint token. The only tenant key. Never a numeric user id. |
| **bucket** | One of five directories `0`–`4` under a member's storage root. Bucket `n` corresponds to registry tier index `n`. |
| **plan number / tier index** | `brain_memberships.membership`, an integer 0–4 passed straight through. 0 = free … 4 = enterprise. Never resolved by name (see [11-billing-and-allocation.md](../afs-connect/11-billing-and-allocation.md) §11.3). |
| **relPath** | A file's POSIX-style path relative to its bucket root, e.g. `start/docs/notes.txt`. The wire format is always `/`-separated regardless of client OS. |
| **session** | A server-side upload transaction covering one manifest of files. Holds `.part` staging files until commit. |
| **manifest** | The list of `{relPath, size, sha256?}` a client declares before uploading. Where validation, quota, and conflict decisions happen — all of them, before any byte moves. |
| **scan** | The preflight pass over a manifest that flags names violating [06](06-name-validation.md). |
| **usage ledger** | `storage_bucket_usage` rows in the members DB — the authoritative byte count, maintained transactionally, reconciled against disk. |
| **retirement** | What happens to buckets above the member's plan after a downgrade: read-only, then deleted after a grace window. |

## 5. The nine decisions

These are settled unless a document below overrides them explicitly.

| # | Decision | Choice | Rationale |
|---|---|---|---|
| **D0** | Token verification | **Ask auth over HTTP; do not verify the JWT locally** | Local verification would need auth's HS256 signing secret copied into members — which lets members *mint* tokens, not just verify them, so any app compromise becomes a full identity compromise. It also cannot honour revocation and would re-implement auth's secondary-user resolution. See [01](01-server-authentication.md) §3. |
| **D1** | Tenant key | **`member_reference_num`**, from `checkpoint.verify` | Already the identity anchor for the one route group that authenticates correctly (`VerifyCheckpointToken.php:65`). Step 01 extends it to every route. The current `getAuthenticatedUserId()` is broken beyond repair (audit S1). |
| **D2** | Upload transport | **Session + `Content-Range` chunked PUT**, not multipart-per-file | Survives `post_max_size`, gives byte-exact resume, gives real progress, and collapses many-file overhead. Already proven in `afsdesktop5/src/main/sync/`. |
| **D3** | Filename policy | **Store as-is; reject what cannot be stored** | G1 is explicit. Sanitising silently (today's `Str::slug` + `uniqid`) destroys the member's naming and makes sync impossible — a file can never be matched to its local twin. |
| **D4** | Authoritative quota number | **Local `storage_bucket_usage` table**, mirrored to alloc_v2 for reporting | Parse `MemberRegistry` CLPs are public ([afs-connect/11](../afs-connect/11-billing-and-allocation.md) §11.4) so its numbers are untrusted, and a per-chunk round trip to Parse is untenable. Entitlement still reads the registry JSON, which is static and safe. |
| **D5** | Quota model | **Cumulative cascade.** Plan `P` grants buckets `0..P`; capacity = Σ `tiers[0..P].value` | Directly specified: "if storage folder 0 hits its max and the member is on plan 1 then they can continue to upload data to the plan 1 folder". See [08](08-storage-retention.md) §2 — this is *not* the same as "plan 3 = 50 GB" and the difference is called out there. |
| **D6** | Downgrade behaviour | **30-day read-only grace, then delete** — not instant deletion | Instant deletion on a billing event is irreversible data loss triggered by something as mundane as a failed card. §6 of [08](08-storage-retention.md) specifies the notification ladder and the export path. |
| **D7** | Conflict handling | **Decided at manifest time, per session, by the member** | Never mid-stream, never silent. The server returns the full conflict set before the first byte; the member answers once. |
| **D8** | Client engine | **One state machine, three ports** — TypeScript (web), TypeScript/Node (desktop), Dart (mobile) | The protocol is deliberately plain HTTP so all three are thin. The shared asset is the *rule set and fixture corpus* ([06](06-name-validation.md) §7), not shared code. |

## 6. Success criteria

The feature is done when all of these hold:

| # | Acceptance criterion |
|---|---|
| A0 | Every authenticated route resolves the caller from a verified Checkpoint token. No controller reads `user_id` or `member_reference_num` from `query()`, `input()`, or `session()` — enforced by a test. The full set is AU1–AU12 in [01](01-server-authentication.md) §8. |
| A1 | A folder `start/` containing `a.txt` and `sub/b.txt`, uploaded from any of the three clients, appears at `/mnt/storage1/{ref}/storage/{n}/start/a.txt` and `.../start/sub/b.txt` with unchanged names and bytes. |
| A2 | No request can read, write, or list a path outside the authenticated member's storage root. The traversal corpus in [12](12-security.md) §4 is rejected in full. |
| A3 | Every name in the traversal/illegal corpus is rejected identically by the TypeScript, Dart, and PHP validators — verified by the shared fixture test in CI. |
| A4 | Uploading the same folder twice reports every colliding path and requires an explicit member choice. Nothing is overwritten or silently duplicated. |
| A5 | The DB unique index on `(member_reference_num, bucket, rel_path)` makes a duplicate row impossible even under concurrent uploads. |
| A6 | A 4 GB upload interrupted at 60 % resumes within one chunk of 60 %. |
| A7 | 10 000 × 20 KB completes in ≤ 200 HTTP requests. |
| A8 | Peak server memory per upload connection stays flat regardless of file size (streamed, never buffered whole). |
| A9 | A member at their bucket-0 limit on plan ≥ 1 continues uploading into bucket 1 automatically; a member at their limit on plan 0 receives an explicit quota error, not a generic 500. |
| A10 | The Cloud Sync UI shows per-bucket usage, total capacity, the cascade formula, and the exact consequence of a downgrade. |
| A11 | A member on plan 3 can move a file from bucket 2 to bucket 0 and back, and the usage ledger and disk agree afterward. |
| A12 | A downgrade from plan 3 to plan 0 marks buckets 1–3 retired and read-only, notifies the member, and deletes nothing before the grace window expires. |
| A13 | Deleting a file decrements the usage ledger; a reconciliation sweep finds zero drift between ledger and disk. |
| A14 | No client-supplied string reaches a filesystem call without passing the server-side validator. |

## 7. Repository map

| Repo | Path | Role |
|---|---|---|
| `apps/members` | `app/Http/Middleware/`, `app/Services/Checkpoint/`, `routes/web.php` | **Step 01** — the server-side identity chain. |
| `apps/members` | `app/Http/Controllers/Storage/`, `app/Services/Storage/`, `resources/js/controllers/` | The server. Owns the API, the ledger, the buckets, and the web client. |
| `apps/auth` | `routes/checkpoint.php`, `app/Http/Controllers/Checkpoint/`, `app/Services/Checkpoint/` | The Checkpoint IdP. Verifies tokens and resolves membership. **Read-only for this plan** — no edits proposed, only calls. |
| `apps/auth` | `public/checkpoint/checkpoint_registry_v3.json` | Owns `storage_files_size_kb.tiers[]`. **Read-only for this plan** — no edits proposed. |
| `afsdesktop5` | `src/main/cloud/`, `src/renderer/components/cloud/` | Desktop client. Reuses the `src/main/sync/` engine shape. |
| `afsmobile_flutter` | `lib/repositories/storage_repository.dart`, `lib/pages/storage/` | Mobile client. |
| `afsserver` | `plans/cloud-storage/` | This plan. Also the host whose `/mnt/storage1` layout the buckets live on. |

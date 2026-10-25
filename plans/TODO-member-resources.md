# TODO — Member Resources

> Working checklist for taking all **35** registry resources from
> *"may I create one more"* to *"and what happens when the tier drops"*.
> Generated from `auth/public/checkpoint/checkpoint_registry_v4.json`.
> Go **one resource at a time**; each section below is self-contained.

## "Unlimited" is not infinite

Wherever this document (or the registry) says *unlimited*, `∞` or `-1`, read it as **capped
only by the hardware**. Nothing is truly unlimited: `-1` means the registry sets no cap of its
own, so the real ceiling is the actual capacity of the underlying device or infrastructure
(e.g. free space on the storage disks). Same wording as `description` and
`conventions.unlimited_meaning` in `checkpoint_registry_v4.json`.

## How to use this

Each resource has two halves that must both be true before it is done:

| Half | Question | Mechanism |
|---|---|---|
| **Forward edge** | May I create one more? | `alloc_v2` — `canUse` / `consume` / `release` |
| **Backward edge** | I hold more than allowed — which ones lose? | retention — bands, grace, purge |

A resource wired forward-only is **not done**: a downgrade leaves everything the member
already has in place, permanently, while the pricing page says otherwise.

**Legend:** `DONE` · `TODO` · `BROKEN` (wired but wrong) · `n/a`

## Summary

| # | Resource | Scope | Retention | canUse | consume | release | Retirement | Inventory |
|---|---|---|---|---|---|---|---|---|
| 1 | `member_domains_count` | member | banded | TODO | TODO | TODO | TODO | **unknown** |
| 2 | `domain_email_accounts` | per_domain | banded | BROKEN (fails open) | DONE | DONE | TODO | www/users_email |
| 3 | `domain_email_sends_monthly` | per_domain | none | TODO | TODO | n/a | n/a (period resource) | n/a |
| 4 | `domain_forms_count` | per_domain | banded | TODO | TODO | TODO | TODO | **unknown** |
| 5 | `domain_forms_submissions_monthly` | per_domain | none | TODO | TODO | n/a | n/a (period resource) | n/a |
| 6 | `domain_subscribers_count` | per_domain | banded | TODO | TODO | TODO | TODO | **unknown** |
| 7 | `domain_ecommerce_stores` | per_domain | banded | TODO | TODO | TODO | TODO | **unknown** |
| 8 | `domain_ecommerce_items_per_store` | per_store | banded | TODO | TODO | TODO | TODO | **unknown** |
| 9 | `domain_processors_count` | per_domain | banded | TODO | TODO | TODO | TODO | www/laravel_processors |
| 10 | `domain_direct_payments_count` | per_domain | banded | DONE | DONE | TODO | TODO | www/laravel_direct_payments |
| 11 | `domain_events_count` | per_domain | banded | TODO | TODO | TODO | TODO | **unknown** |
| 12 | `pm_projects_count` | member | banded | TODO | TODO | TODO | TODO | **unknown** |
| 13 | `pm_project_kanban_boards` | per_project | banded | TODO | TODO | TODO | TODO | **unknown** |
| 14 | `pm_team_users` | member | banded | TODO | TODO | TODO | TODO | **unknown** |
| 15 | `notes_items_count` | member | banded | TODO | TODO | TODO | TODO | **unknown** |
| 16 | `notes_notebooks_count` | member | banded | TODO | TODO | TODO | TODO | **unknown** |
| 17 | `dreamgraph_graphs_count` | member | banded | TODO | TODO | TODO | TODO | **unknown** |
| 18 | `dreamgraph_graph_nodes` | per_graph | banded | TODO | TODO | TODO | TODO | **unknown** |
| 19 | `pdf_editor_documents_count` | member | banded | TODO | TODO | TODO | TODO | **unknown** |
| 20 | `pdf_editor_document_pages` | per_document | banded | TODO | TODO | TODO | TODO | **unknown** |
| 21 | `design_flow_projects_count` | member | banded | TODO | TODO | TODO | TODO | **unknown** |
| 22 | `design_flow_project_assets` | per_design_project | banded | TODO | TODO | TODO | TODO | **unknown** |
| 23 | `voip_numbers_count` | member | banded | TODO | TODO | TODO | TODO | **unknown** |
| 24 | `voip_number_minutes_monthly` | per_domain | none | TODO | TODO | n/a | n/a (period resource) | n/a |
| 25 | `storage_files_size_kb` | member | banded | DONE (server-side) | DONE (server-side) | DONE (server-side) | DONE | members/storage_nodes |
| 26 | `forms_items_count` | member | banded | TODO | TODO | TODO | TODO | **unknown** |
| 27 | `forms_items_submissions_monthly` | member | none | TODO | TODO | n/a | n/a (period resource) | n/a |
| 28 | `secondary_users` | member | banded | DONE (server-side) | DONE (server-side) | DONE (server-side) | TODO | checkpoint secondary users |
| 29 | `afs_connect` | member | binary | TODO | TODO | n/a | DONE (implicit) | n/a |
| 30 | `domain_chat` | per_domain | banded | TODO | TODO | TODO | TODO | **unknown** |
| 31 | `domain_discussions` | per_domain | banded | TODO | TODO | TODO | TODO | **unknown** |
| 32 | `domain_feedback` | per_domain | banded | TODO | TODO | TODO | TODO | **unknown** |
| 33 | `domain_listings` | per_domain | banded | TODO | TODO | TODO | TODO | **unknown** |
| 34 | `domain_sponsorships` | per_domain | banded | TODO | TODO | TODO | TODO | **unknown** |
| 35 | `communications` | member | none | TODO | TODO | n/a | n/a (per-session ceiling, not held) | n/a |

**1 complete · 8 partial · 26 untouched.**

Only `storage_files_size_kb` has both edges. `afs_connect` is complete by construction
(binary, closes server-side). The 4 period resources need no retirement, so their
backward edge is `n/a` — but none of them have a forward edge either.

*Corrected 2026-08-30:* `secondary_users` was listed as untouched on all three forward
columns. It has all three, and has had them since step 60 — `SecondaryUserService` calls
`check()` at :114, `consume()` at :147 and `release()` at :473 through auth's
`AllocationGateway`. It is the one resource whose `release` edge was already wired, which
makes B1's "wired nowhere" one resource too strong. Its retirement edge is still absent, and
is the harder half: retiring a secondary user removes a **real person's** access, and that
person is not the account owner being downgraded, so they have to be notified separately.

*Infrastructure note, 2026-09-22:* `coordinator`'s `App\Services\AfsConnect\Allocation\ParseAllocationGateway`
(a different gateway than `secondary_users`' `auth AllocationGateway` above — this one talks to
the `allocConsume`/`allocRelease` Parse Cloud Functions) had **two bugs that made every call it
ever made fail**, found while wiring #2's `release()` and testing `ALLOC_USE_CLOUD_FUNCTIONS`:
its `BrainMembershipLookup` HTTP fallback called `www`'s `GET /portal/api/v1/brain/member`,
which requires a browser session no server-side caller can have (fixed — switched to the
HMAC-signed `GET /portal/api/v1/brain/entitlement/{ref}`); and no Parse server was reachable
from the vagrant lab at all (fixed — one now runs there, see
[membership_change/features/complete/2-domain_email_accounts.complete.md](membership_change/features/complete/2-domain_email_accounts.complete.md)).
Both are fixed and verified live end-to-end now. **This does not change any row above** —
`grep`ing `coordinator/app` finds no real caller of `ParseAllocationGateway` for any of the 35
resources yet, only its container binding. It is exactly the same shape as the storage/email
adapters before their orchestration services existed: working, tested infrastructure that
nothing calls. Whichever resource wires its forward edge through this gateway next inherits a
gateway that now actually works, rather than one that would have failed the same way #2's first
attempt did.

## Band capacities — the arithmetic every banded resource needs

**Settled in [handoff.md](handoff.md) §8.2 and never written down here. Nothing below can be
filled in without it.**

Each resource's checklist asks for a band assignment, and
[membership_change/features/2](membership_change/features/2-domain_email_accounts.md) §F2.4 gives the rule as *"lowest
band with a free slot"*. That needs a slot count, and `tiers[]` alone does not give one —
because the two `tier_mode` values mean different things by the same numbers:

```
  cumulative   tiers[] holds INCREMENTS      band[n] = tiers[n]
  absolute     tiers[] holds RUNNING TOTALS  band[n] = tiers[n] − tiers[n−1]
```

Worked, because getting it wrong is silent:

| Band | `domain_email_accounts` — `absolute` `[2,10,50,250,-1]` | `storage_files_size_kb` — `cumulative` |
|---|---|---|
| 0 | 2 | 512 MB |
| 1 | 10 − 2 = **8** | 2 GB |
| 2 | 50 − 10 = **40** | 10 GB |
| 3 | 250 − 50 = **200** | 50 GB |
| 4 | unlimited | unlimited |

Plan 2 owns bands 0–2 → 2 + 8 + 40 = **50** = `tiers[2]` ✓

Reading `absolute` the cumulative way gives plan 2 a ceiling of **62**, which is the answer
that was explicitly rejected for email. Verified against storage the other way: 512 MB + 2 GB
+ 10 GB = 12.5 GB, matching [cloud-storage/08](cloud-storage/08-storage-retention.md)'s
shipped table ✓

**33 of 34 resources are `absolute`** in registry v4; `storage_files_size_kb` is the only
`cumulative` one. So the delta form is the common case, and the one storage's shipped code
does *not* demonstrate.

> **Confirm `tier_mode: absolute` against pricing before building on it.** It was set for
> every non-storage resource on the reasoning that `[2,10,50,250]` is plainly a table of
> totals. That is almost certainly right and it has never been checked with the pricing page.
> Each resource's checklist carries this as its own line.

## Terminology: band, not bucket

Renamed throughout this document 2026-08-27, per [handoff.md](handoff.md) §8. A band is
**attribution** — a label recording which tier's allowance covers an item — not containment.
`bucket` implied a container, implied capacity was physical, and collided with object-storage
buckets in a codebase that ships a cloud storage product.

**`checkpoint_registry_v4.json` has now been renamed too** — `retention: "banded"` on all 29
banded resources, `conventions.retention_behaviors.banded`, and zero occurrences of `bucket`
anywhere in the file. This document and the registry now agree.

One place deliberately still says `bucket`, and should:

- **`members/` shipped storage code** — ~516 occurrences, where a bucket really is a directory
  the member browses. It keeps `bucket` inside its adapter; map band *n* → `storage/{n}`.

## Blockers that apply to every resource

Fix these once, not 34 times. Nothing below is worth starting until they are settled.

- [ ] **B1 — `release` is wired almost nowhere.** *Narrowed 2026-08-30.* Quota is a one-way
      ratchet for 32 of 34 resources. After a downgrade a member who deletes things to get
      under the new limit *still* cannot create. (`plans/allocation.md` G6)
      The exceptions are `storage_files_size_kb` and `secondary_users`
      (`SecondaryUserService:473`) — worth knowing, because they are the two working examples
      to copy rather than a reason to think the blocker is smaller than it is.
- [ ] **B2 — `canUse` fails open.** Both wired resources are `overage: block`, and a thrown
      check falls through to the create path. Inverted, not missing. (G12)
- [ ] **B3 — `planFor()` treats a missing membership row as plan 0.** *Narrowed 2026-09-21.*
      Billing gets this right (*"unknown is not zero"*, `billing/08` §8.3); storage used to
      do the opposite, and storage is the one holding files. **Fixed for storage specifically**
      — `QuotaService::planFor()` (now throws) and `RetentionService::reconcile()` (now skips
      the holder) — see
      [membership_change/features/complete/25-storage_files_size_kb.complete.md](membership_change/features/complete/25-storage_files_size_kb.complete.md)
      §F25.10 F1. This was a fix to storage's own `planFor()`, not to shared engine code, so
      it is **not** inherited by any other resource — same status as B1: a working example to
      copy, not evidence the blocker is smaller than it is. A resolve-but-empty membership
      table would still retire every band for every member in one sweep for the other 33.
- [x] **B4 — the counter has no safe home.** **CLOSED 2026-08-30.** The suspicion was
      right: the `ac_*` flat counters were never reliably written, so the nested projection
      was what actually stored the number, without atomicity.

      Storage is now two Parse classes — a `MemberRegistry` identity anchor and one
      `MemberResource` **row** per `(member, resource_id, scope_type, scope_parent,
      scope_key)`, found by a `ledger_key` of exactly that shape, with `used` as a top-level
      number. `increment()` is a real MongoDB `$inc`; five concurrent consumes produce 5.
      G13 is gone with the mirror, because there is no second representation to disagree.

      It differs from [alloc-v2-parse-direct/03](alloc-v2-parse-direct/03-parse-cloud-functions.md)
      §3.2.2 in two ways worth knowing. The class is `MemberResource`, not
      `MemberRegistryCounter`. And **the period is not part of the key** — a period counter
      carries `period_start` on the row and reads as 0 once that period has rolled over, so a
      monthly resource keeps one row for its lifetime instead of accruing one per month. The
      member object is not a display projection either; it holds no counters at all.

      Migrated onto it: `alloc_v2.ts` (all nine copies), `AllocationV2Controller`,
      `MemberInitController`, auth's `AllocationGateway`, coordinator's
      `ParseAllocationGateway`, `AllocReconcilePayments`, `BrainAllocationController`, and
      `brain_membership.ts`. `counterField()`/`scopeToken()` are deleted, not deprecated.
- [ ] **B5 — no reconciler.** Counts drift and nothing detects it. (G9)
      *Partial, 2026-08-30:* `alloc:reconcile-payments` is migrated to the row model and now
      covers `domain_processors_count` and `domain_direct_payments_count` — two of 34, and
      only for the deployment's own member. It also stops treating an unreachable Parse as
      `parse=0`, which previously made every resource look drifted and would have had `--fix`
      write the SQL count over data it never read.
- [ ] **B6 — the shared retirement machinery does not exist.** *Narrowed 2026-09-21.* This
      previously said "only storage has a state machine," which is no longer true: a shared,
      registry-driven `RetentionEngine` exists (`members/app/Checkpoint/RetentionEngine.php`,
      confirmed genuinely wired via constructor injection, not sitting unused) and is used by
      **two** resources — storage (fully working, verified by a passing test suite) and email
      (adapter registered, cascade wired, but not exercised end to end — no caller yet, no
      login/send enforcement built). The blocker is narrower than "does not exist" but the
      conclusion is unchanged: it's reachable by **2 of 34** resources, and by nothing outside
      `members` — the HTTP API `membership_change/03-api-contracts.md` specifies still doesn't
      exist, so no other app can reach it even for the 2 that use it internally.
- [ ] **B7 — `account_status` is read but never acted on.** *Restated 2026-08-27, narrowed
      2026-09-21.* This previously said "zero readers", which is no longer true and will make
      the rest of this list look stale to anyone who greps. It **is** read — afs-connect
      carries it end to end: `coordinator/.../BrainMembershipLookup`, both
      `CoordinatorSettingsClient`s (www and members), and coordinator's
      `AfsConnectSettingsController`. But `AfsConnectEntitlementService` never references it,
      so the value is **carried and discarded**. **A second, independent reader now exists**:
      `storage_files_size_kb`'s `RetentionService` reads it directly (not via afs-connect) and
      correctly freezes/resumes the purge clock — the first resource where this blocker is
      actually closed, for that one resource
      ([membership_change/features/complete/25-storage_files_size_kb.complete.md](membership_change/features/complete/25-storage_files_size_kb.complete.md)
      §F25.10 F6). The conclusion otherwise stands: afs-connect access still ignores
      suspension entirely, and so do the other 33 resources.
      [membership_change/02](membership_change/02-architecture.md) §2.4 makes it the second
      axis with a frozen grace clock, so this is the blocker that has to close before
      `suspended` means anything for any resource below.

- [x] **B8 — every member is provisioned owning six resources they do not have.**
      **CLOSED 2026-08-30.** Provisioning creates the `MemberRegistry` anchor plus exactly
      the **14 member-scoped rows**, derived from `checkpoint_registry_v4.json` directly.
      Scoped rows are created by `fetchOrCreateRow()` on first use, because at signup there
      is no domain, store, project, graph, document or design project to key them on.
      Verified live: a fresh member gets 14 rows, `retired` present only on retaining
      resources, `period_start` only on the period one, and re-calling `init()` creates
      nothing further.

      `parse_member_query_v4.json` is no longer a payload — nothing is copied into Parse from
      it. It is generated documentation of what provisioning does
      (`php artisan checkpoint:build-member-template`), guarded by
      `CheckpointTemplateConsistencyTest`, which asserts the shape rules
      `createMemberScopeRow()` implements. The original text follows.

      ~~**every member is provisioned owning six resources they do not have.**~~
      `auth/public/checkpoint/parse_member_query_v4.json` seeds each new member with
      `--Example_DOMAIN--` (containing `--Example_STORE--`), `--Example_PM_PROJECT--`,
      `--Example_GRAPH--`, `--Example_PDF_DOCUMENT--` and `--Example_DESIGN_PROJECT--`.
      `MemberInitController::init()` writes the template key-for-key and strips none of it.
      **Each placeholder carries `band`, `state` and a `resources` map, so the cascade walks
      them** — a downgrade retires a domain that never existed, and counts it toward the
      allowance first. Blocks resources **1, 7, 12, 17, 19, 21** and every `per_domain` child.
      Fix: [membership_change/07](membership_change/07-signup-flow.md) §7.7.1 — the template
      seeds empty containers.

- [ ] **B9 — per-member ceiling overrides are invisible to the engine.**
      [members_brain/05](members_brain/05-limit-overrides.md) puts support-granted overrides in
      a Brain MySQL table; [membership_change/02](membership_change/02-architecture.md)
      computes bands from `tiers[]` alone. **A member given "+5 domains" by support is retired
      back down on the next sweep**, and the override reconciles against nothing. Affects every
      banded resource, so it is settled here once rather than discovered at resource 1. Note
      the override table's scope enum (`member|domain|pm_project`) also cannot express the four
      other scopes registry v4 defines.

- [ ] **B10 — five resources were missing from this document.** *Added 2026-08-27.*
      `domain_chat`, `domain_discussions`, `domain_feedback`, `domain_listings` and
      `domain_sponsorships` are in `checkpoint_registry_v4.json` and were absent from the
      original 29 — all `per_domain`, all `banded`, all `[2,10,50,250,-1]`. They are sections
      30–34 below, and **resource 1's cascade was documented as 10 children when it is 15**.
      Two of them have no plan folder at all (`www/discussions`, and there is none for chat or
      feedback), so their inventory owner does not yet exist to ask.
- [x] **B11 — a voluntary plan change never reached the backward edge at all.** *Found
      2026-09-22, tracing whether a tier-1→tier-3 self-service upgrade takes effect
      immediately. Fixed 2026-09-22* — see
      [billing/17](billing/17-self-service-tier-changes.md) §17.3 and
      [billing/complete/17-self-service-tier-changes.complete.md](billing/complete/17-self-service-tier-changes.complete.md).
      Before the fix, every backward-edge checklist item below — retire, purge,
      restore-on-upgrade — only ever fired via `billing:enforce-membership`'s dunning ladder,
      i.e. **non-payment**; a member who deliberately downgraded through `members`'
      `BillingApp.ts` (`dp.subscription.update()`) was charged the new, lower amount and kept
      every band of the tier they left, forever, because `brain_memberships.membership` was
      never written on that path at all (§17.1, **B37**). `DirectPaymentSubscriptionController::update()`
      (`w/www`) now resolves and writes `membership` synchronously in the same request, for
      upgrade and downgrade alike — verified by a 4-case feature test
      (`DirectPaymentSubscriptionUpdateMembershipTest`), with the full `DirectPayments` suite
      (91 tests) passing alongside it.

      **What this does and does not close:** `storage_files_size_kb` and
      `domain_email_accounts` now actually start their 30-day retirement grace on a
      self-service downgrade, on their own existing sweep schedule (`storage:retention-sweep`,
      the email retention sweep) — no code change needed there, since both already read
      `membership` directly. **Still open:**
      [membership_change/09](membership_change/09-billing-tier-change-hook.md)'s observer,
      which would make `members` find out *fast* rather than on the next sweep tick, and
      [membership_change/10](membership_change/10-preview-endpoint.md), which would let the
      member see what retires *before* confirming — a self-service downgrade today starts the
      clock with no warning beyond price and proration. Neither is built yet.

---

## Resource by resource

### 1. `member_domains_count`

> Websites / Domains — Total number of domains (a website together with its per-domain modules) a member may run. Domain names themselves are not limited, banded or retired. *(Reworded 2026-10-05, per the owner; it used to say "custom domain names a member may register or connect".)*

`member` · `domains` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `[1,3,10,25,-1]`

**Decision needed:** Retiring a domain CASCADES into every per_domain resource beneath it, and per_store beneath those. The largest blast radius in the registry.

**Cascades into 15 resource(s)** when an instance is retired: `domain_chat`, `domain_direct_payments_count`, `domain_discussions`, `domain_ecommerce_stores`, `domain_email_accounts`, `domain_email_sends_monthly`, `domain_events_count`, `domain_feedback`, `domain_forms_count`, `domain_forms_submissions_monthly`, `domain_listings`, `domain_processors_count`, `domain_sponsorships`, `domain_subscribers_count`, `voip_number_minutes_monthly`

> Was documented as 10. The five module resources added to registry v4 — `domain_chat`, `domain_discussions`, `domain_feedback`, `domain_listings`, `domain_sponsorships` — are all `per_domain`, so they cascade from here too. See **B10**.

See [membership_change/features/1-member_domains_count.md](membership_change/features/1-member_domains_count.md) (the plan; nothing built).

> **Owner decisions 2026-10-05.** A *domain* here is the whole ecosystem on a domain name: the **website** and its per-domain
> modules. The domain **name** (the DNS zone, bought or connected) is **exempt**: never counted, banded, retired or purged,
> always left in DNS. Retiring a domain = **website offline only; DNS and mail stay up**. Newest websites retire first and the
> member can rearrange. The unit is one website per domain name (by the zone's `reference_num`), and a retired website is never purged automatically. Open: the `members` to Brain channel (feature doc §F1.7 D5).

> **Correction 2026-10-05.** Server-side quota code was written for this resource outside the plan (the forward edge is
> `CheckpointAllocV2`'s `canUse` / `consume` / `release` against Parse, called at the feature's own call sites). It has
> been **removed**; every status below is as it was before it. (`membership_change/00-overview.md` OQ-A; `03-api-contracts.md` §3.1.)

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **open (D6)**. *Finding 2026-10-05:* the websites are `members_websites` rows (and the Brain's `brain_http_server_tenants`), created by `members`' `DomainController::createWebsite()`; the zone table is exempt
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Cascade into child scopes implemented
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

**Blocker found 2026-10-05:** `members`' `DomainController` calls the Brain's `/dns/zones`, `/config` and `/http/*` with no credentials, but those routes need a portal login (`portal.admin.auth`), so the member's domain list and add/save/delete most likely return empty today (read from the code, not tested live).

### 2. `domain_email_accounts`

> Email Accounts per Domain — Number of individual mailboxes that can be provisioned inside a single domain.

`per_domain` · `accounts` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `[2,10,50,250,-1]`

**Resolved 2026-09-20:** Retire = disable a LIVE mailbox, but arriving mail is never touched —
accept-and-file forever, at every lifecycle stage. See
plans/membership_change/features/2-domain_email_accounts.md §F2.3.

> **Correction 2026-10-05.** Server-side quota code was written for this resource outside the plan (the forward edge is
> `CheckpointAllocV2`'s `canUse` / `consume` / `release` against Parse, called at the feature's own call sites). It has
> been **removed**; every status below is as it was before it. (`membership_change/00-overview.md` OQ-A; `03-api-contracts.md` §3.1.)

- [ ] `canUse` wired and **fails closed** — `email.ts` save path now refuses on error, on `available: false` and when `allocV2` / the domain is not set up (M13) — **written 2026-10-05, unrun**
- [x] `consume` wired on create only — DONE
- [x] `release` wired on delete — DONE, 2026-09-22. `EmailAccountController::destroy()` calls
      `CheckpointAllocClient::release()`, verified with a live `allocConsume`→`allocRelease`
      round trip. Surfaced that `AllocationV2Controller`'s `/consume`/`/release` HTTP routes
      were already dead (removed from `auth/routes/checkpoint.php`) — real write path is the
      Parse Cloud Functions; see [07-signup-flow.md](membership_change/07-signup-flow.md)'s S34
      correction
- [x] Inventory owner identified — `www/users_email` (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows — column exists (E1-E4); assignment on create and `email:backfill-bands` **written 2026-10-05, unrun**
- [x] Retire action defined and implemented — `EmailRetentionService::retire()`, 2026-09-22, tested (7 tests, 33 assertions)
- [x] Purge action defined and implemented — `EmailRetentionService::sweepPurge()`, same date/tests. Single-phase (no trash/hold window, unlike storage)
- [x] Restore-on-upgrade path — `EmailRetentionService::restore()`, same date/tests
- [ ] Member-facing preview + "choose what survives" UI — Band/Status columns, move/disable controls, a "Plan and bands" card and a plan-change preview **written 2026-10-05, unrun**
- [ ] Notification before purge — T+0 mail and T-7 reminder through the Brain **written 2026-10-05, unrun** (needs `brain:register-manifest` and the `www` migration)
- [ ] Send gate on `status` (`MailMuaController::sendEmail()`) — **written 2026-10-05, unrun**
- [x] Login gate on `status` — **decided 2026-10-05: none.** A disabled mailbox can log in and read but not send (D4)
- [ ] Platform-critical addresses pinned to band 0 — **decided 2026-10-05, written, unrun** (`members/config/email_retention.php`; default `postmaster`, `abuse`, `no-reply`, `noreply`)

**Caveat on the three checkmarks above, matching storage's own re-verification note (§25):**
the engine and adapter genuinely work, tested directly. **Updated 2026-09-22 — no longer fully
true:** `EmailAccountController::index()` (www) now calls `reconcile()` (members) over a signed
HTTP channel on every page view, verified live in the vagrant lab, which does create a
`members_email_band_usage` row the first time a member's email page is viewed. What's still
true: `EmailRetentionSweep` (scheduled hourly) only enumerates domains that already have a
ledger row, so a domain nobody ever views still never gets a first row — the "view page"
trigger and the "hourly sweep" trigger are not the same thing, and only the first one is wired.
See
[membership_change/features/complete/2-domain_email_accounts.complete.md](membership_change/features/complete/2-domain_email_accounts.complete.md)
for the full account, including the still-unsolved domain→member lookup gap this shares with
`member_domains_count` (#1).

*Update 2026-10-05:* that sweep gap is closed in code (`EmailRetentionService::knownDomains()` finds a
domain from `users_email` + an active `brain_dns_zones` row), **written, unrun**.

### 3. `domain_email_sends_monthly`

> Outbound Email Sends per Domain — Maximum number of outbound email messages that can be dispatched from a single domain within a calendar month.

`per_domain` · `messages` · `monthly` · `overage: block` · `tier_mode: absolute` · `retention: none`

Tiers: `[500,5000,25000,150000,-1]`

**Planned 2026-09-22** — see
[membership_change/features/3-domain_email_sends_monthly.md](membership_change/features/3-domain_email_sends_monthly.md).
Design only, nothing implemented yet: `sendEmail()` in `www`'s `MailMuaController` is the
located send call site (same file `domain_email_accounts` #2 already needed), `canUse`/`consume`
belong server-side via a `CheckpointAllocClient::check()`/`::consume()` not yet built, and the
monthly reset is already handled generically by the existing Cloud Functions — confirmed by
reading `functions.ts`, not assumed.

> **Correction 2026-10-05.** Server-side quota code was written for this resource outside the plan (the forward edge is
> `CheckpointAllocV2`'s `canUse` / `consume` / `release` against Parse, called at the feature's own call sites). It has
> been **removed**; every status below is as it was before it. (`membership_change/00-overview.md` OQ-A; `03-api-contracts.md` §3.1.)

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [x] `release` — n/a, the period reset is the release
- [x] Retirement — n/a, a period resource holds nothing to retire

### 4. `domain_forms_count`

> Forms per Domain — Number of published web forms that can be attached to a single domain.

`per_domain` · `forms` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `[2,10,50,250,-1]`

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **unknown, decide first** (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 5. `domain_forms_submissions_monthly`

> Form Submissions per Domain — Maximum number of form submissions accepted across all forms on a single domain within a calendar month.

`per_domain` · `submissions` · `monthly` · `overage: block` · `tier_mode: absolute` · `retention: none`

Tiers: `[100,2000,20000,150000,-1]`

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [x] `release` — n/a, the period reset is the release
- [x] Retirement — n/a, a period resource holds nothing to retire

### 6. `domain_subscribers_count`

> Subscribers per Domain — Total newsletter and mailing-list subscribers collected through a single domain.

`per_domain` · `subscribers` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `[250,2500,25000,250000,-1]`

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **unknown, decide first** (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 7. `domain_ecommerce_stores`

> Ecommerce Stores per Domain — Number of online stores that can be created inside a single domain. A store of 0 means ecommerce is not available at this tier.

`per_domain` · `stores` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `["x",1,3,15,-1]`

**Cascades into 1 resource(s)** when an instance is retired: `domain_ecommerce_items_per_store`

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **unknown, decide first** (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Cascade into child scopes implemented
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 8. `domain_ecommerce_items_per_store`

> Product Items per Ecommerce Store — Maximum number of product or SKU listings within a single ecommerce store.

`per_store` · `items` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `["x",100,1000,15000,-1]`

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **unknown, decide first** (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 9. `domain_processors_count`

> Payment Processors per Domain — Number of third-party payment processor integrations (e.g. Stripe, PayPal) that can be connected to a single domain.

`per_domain` · `processors` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `["x",1,3,10,-1]`

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [x] Inventory owner identified — `www/laravel_processors` (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 10. `domain_direct_payments_count`

> Direct Payment Links per Domain — Number of one-off checkout and direct payment links published on a single domain.

`per_domain` · `payment_links` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `["x",5,50,500,-1]`

- [x] `canUse` wired and **fails closed** — DONE
- [x] `consume` wired on create only — DONE
- [ ] `release` wired on delete — TODO
- [x] Inventory owner identified — `www/laravel_direct_payments` (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 11. `domain_events_count`

> Events per Domain — Number of scheduled events and event registration pages that can be published on a single domain.

`per_domain` · `events` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `[1,5,30,150,-1]`

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **unknown, decide first** (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 12. `pm_projects_count`

> Projects — Total number of project management workspaces a member may create.

`member` · `projects` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `[1,5,25,100,-1]`

**Cascades into 1 resource(s)** when an instance is retired: `pm_project_kanban_boards`

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **unknown, decide first** (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Cascade into child scopes implemented
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 13. `pm_project_kanban_boards`

> Kanban Boards per Project — Number of kanban boards allowed inside a single project management project.

`per_project` · `boards` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `[3,20,100,500,-1]`

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **unknown, decide first** (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 14. `pm_team_users`

> Team Members on Project Management — Number of collaborators and team seats available on the project management service for this member account.

`member` · `users` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `[1,5,30,150,-1]`

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **unknown, decide first** (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 15. `notes_items_count`

> Notes — Total number of individual note items a member may create.

`member` · `notes` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `[50,500,5000,50000,-1]`

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **unknown, decide first** (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 16. `notes_notebooks_count`

> Notebooks — Total number of notebook folders a member may create to organise notes.

`member` · `notebooks` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `[3,20,100,500,-1]`

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **unknown, decide first** (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 17. `dreamgraph_graphs_count`

> DreamGraph Canvases — Total number of DreamGraph visual graph and mind-map canvases a member may create.

`member` · `canvases` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `[3,20,100,500,-1]`

**Cascades into 1 resource(s)** when an instance is retired: `dreamgraph_graph_nodes`

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **unknown, decide first** (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Cascade into child scopes implemented
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 18. `dreamgraph_graph_nodes`

> Nodes per DreamGraph Canvas — Maximum number of nodes (cards, concepts, links) within a single DreamGraph canvas.

`per_graph` · `nodes` · `lifetime` · `overage: notify` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `[50,300,1500,8000,-1]`

**Decision needed:** The only resource with overage: notify. Retirement semantics for a notify resource are undefined — decide whether notify implies retention: none.

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **unknown, decide first** (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 19. `pdf_editor_documents_count`

> PDF Documents — Total number of PDF documents a member may create and store in the PDF Editor.

`member` · `documents` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `[10,100,1000,10000,-1]`

**Cascades into 1 resource(s)** when an instance is retired: `pdf_editor_document_pages`

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **unknown, decide first** (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Cascade into child scopes implemented
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 20. `pdf_editor_document_pages`

> Pages per PDF Document — Maximum number of pages within a single PDF Editor document.

`per_document` · `pages` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `[10,50,250,1000,-1]`

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **unknown, decide first** (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 21. `design_flow_projects_count`

> DesignFlow Projects — Total number of DesignFlow design and brand-asset projects a member may create.

`member` · `projects` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `[2,10,75,500,-1]`

**Cascades into 1 resource(s)** when an instance is retired: `design_flow_project_assets`

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **unknown, decide first** (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Cascade into child scopes implemented
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 22. `design_flow_project_assets`

> Assets per DesignFlow Project — Number of design asset files (images, templates, brand kits) stored within a single DesignFlow project.

`per_design_project` · `assets` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `[25,250,2500,25000,-1]`

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **unknown, decide first** (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 23. `voip_numbers_count`

> VoIP Phone Numbers — Total number of VoIP telephone numbers a member may provision.

`member` · `numbers` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 60d`

Tiers: `["x",1,5,25,100]`

**Decision needed:** Purging releases a real phone number back to the carrier and it CANNOT be reclaimed. Longer grace, and purge must be an explicit member action, never an automatic sweep.

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **unknown, decide first** (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 24. `voip_number_minutes_monthly`

> Call Minutes per VoIP Number — Outbound call minutes available per individual VoIP number per calendar month.

`per_domain` · `minutes` · `monthly` · `overage: block` · `tier_mode: absolute` · `retention: none`

Tiers: `["x",100,600,3000,-1]`

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [x] `release` — n/a, the period reset is the release
- [x] Retirement — n/a, a period resource holds nothing to retire

### 25. `storage_files_size_kb`

> File Storage — Total data storage available to a member account for uploads, media, documents and assets. Expressed in kilobytes (1 MB = 1024 KB).

`member` · `kilobytes` · `lifetime` · `overage: block` · `tier_mode: cumulative` · `retention: banded` · `grace: 30d`

Tiers: `[524288,2097152,10485760,52428800,-1]` (cumulative — allowance is the running sum)

**Decision needed:** The only cumulative resource, and the only continuous one. Already shipped as cumulative — changing it is a live behaviour change.

**Re-verified 2026-09-21** (not just re-asserted): actually running the integration suite
found these checkmarks had been true only in the sense that code existed under these names —
`retire`/`purge`/`restore` had **never once fired correctly**, because
`RetentionEngine::effectiveState()` had a bug that meant no downgrade ever transitioned a
band to `retired` in the first place (F5). Fixed, along with six other defects found getting
the suite from 0 passing assertions to 44 — see
[membership_change/features/complete/25-storage_files_size_kb.complete.md](membership_change/features/complete/25-storage_files_size_kb.complete.md)
§F25.10. The checkmarks below are accurate now; they were an overclaim before 2026-09-21,
which is worth remembering when trusting a `DONE` on a resource nothing has actually exercised.

- [x] `canUse` wired and **fails closed** — DONE (server-side)
- [x] `consume` wired on create only — DONE (server-side)
- [x] `release` wired on delete — DONE (server-side)
- [x] Inventory owner identified — `members/storage_nodes` (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: cumulative` confirmed with pricing
- [ ] Band capacities derived (§Band capacities)
- [x] Band assignment column + backfill of existing rows
- [x] Retire action defined and implemented — verified 2026-09-21, was broken (F5)
- [x] Purge action defined and implemented — verified 2026-09-21
- [x] Restore-on-upgrade path — verified 2026-09-21
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 26. `forms_items_count`

> Standalone Forms — Number of forms created in the global form builder that are not attached to a specific domain (embeddable / standalone forms).

`member` · `forms` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `[3,20,100,500,-1]`

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **unknown, decide first** (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 27. `forms_items_submissions_monthly`

> Standalone Form Submissions — Maximum number of submissions accepted across all standalone forms on a member account per calendar month.

`member` · `submissions` · `monthly` · `overage: block` · `tier_mode: absolute` · `retention: none`

Tiers: `[200,5000,50000,500000,-1]`

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [x] `release` — n/a, the period reset is the release
- [x] Retirement — n/a, a period resource holds nothing to retire

### 28. `secondary_users`

> Secondary Users — Number of additional user accounts (team members, collaborators) that can be invited to a member account.

`member` · `users` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `["x",3,10,50,-1]`

**Decision needed:** Retiring removes a real person’s access, not a record. They must be notified separately from the account owner.

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **unknown, decide first** (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 29. `afs_connect`

> AFS Connect — Access to AFS Connect cross-app aggregation and coordinator integration. Not available on the free tier.

`member` · `access` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: binary` · `grace: 0d`

Tiers: `["x",1,1,1,1]`

**Decision needed:** Binary: tiers[0].enabled = false means access simply closes. Nothing is deleted, so grace_days is 0 by definition.

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [x] Access closes at tiers where `enabled: false`, server-side — DONE (implicit)

### 30. `domain_chat`

> Chat — live chat widgets configured on a domain.

`per_domain` · `chats` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `[2,10,50,250,-1]`

**Added to this document 2026-08-27 (B10).** Present in `checkpoint_registry_v4.json` and absent
from the original 29. Cascades from `member_domains_count` along with the other 14 `per_domain`
resources.

**Decision needed:** Retiring a chat takes a widget off a live page. Does the widget disappear, or render disabled with a message? A visitor mid-conversation is the case to answer.

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **unknown, decide first** (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities) — `[2, 8, 40, 200, ∞]`
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 31. `domain_discussions`

> Discussions — discussion boards on a domain.

`per_domain` · `boards` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `[2,10,50,250,-1]`

**Added to this document 2026-08-27 (B10).** Present in `checkpoint_registry_v4.json` and absent
from the original 29. Cascades from `member_domains_count` along with the other 14 `per_domain`
resources.

**Decision needed:** A retired board holds member-authored content. Read-only or hidden? `www/discussions` has no plan folder yet, so the inventory owner does not exist to ask.

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **unknown, decide first** (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities) — `[2, 8, 40, 200, ∞]`
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 32. `domain_feedback`

> Feedback — feedback forms and collectors on a domain.

`per_domain` · `collectors` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `[2,10,50,250,-1]`

**Added to this document 2026-08-27 (B10).** Present in `checkpoint_registry_v4.json` and absent
from the original 29. Cascades from `member_domains_count` along with the other 14 `per_domain`
resources.

**Decision needed:** Overlaps `domain_forms_count` in purpose. Confirm these are genuinely separate resources before wiring both.

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **unknown, decide first** (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities) — `[2, 8, 40, 200, ∞]`
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 33. `domain_listings`

> Listings — directory or catalogue listings on a domain.

`per_domain` · `listings` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `[2,10,50,250,-1]`

**Added to this document 2026-08-27 (B10).** Present in `checkpoint_registry_v4.json` and absent
from the original 29. Cascades from `member_domains_count` along with the other 14 `per_domain`
resources.

**Decision needed:** Listings are a builder module block (`core/blocks/modules.ts`) with no runtime — see `plans/www/builder/01` **B1**. Retiring one has no visible effect until that is fixed.

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **unknown, decide first** (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities) — `[2, 8, 40, 200, ∞]`
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 34. `domain_sponsorships`

> Sponsorships — sponsor placements on a domain.

`per_domain` · `sponsorships` · `lifetime` · `overage: block` · `tier_mode: absolute` · `retention: banded` · `grace: 30d`

Tiers: `[2,10,50,250,-1]`

**Added to this document 2026-08-27 (B10).** Present in `checkpoint_registry_v4.json` and absent
from the original 29. Cascades from `member_domains_count` along with the other 14 `per_domain`
resources.

**Decision needed:** Sponsorships carry money (`www/app/Http/Controllers/Externals/Sponsorships/`). Retiring a placement a sponsor paid for is a refund question, not only a retention one.

- [ ] `canUse` wired and **fails closed** — TODO
- [ ] `consume` wired on create only — TODO
- [ ] `release` wired on delete — TODO
- [ ] Inventory owner identified — **unknown, decide first** (OQ-A: inventory or `alloc_v2`?)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] Band capacities derived (§Band capacities) — `[2, 8, 40, 200, ∞]`
- [ ] Band capacities derived (§Band capacities)
- [ ] Band assignment column + backfill of existing rows
- [ ] Retire action defined and implemented
- [ ] Purge action defined and implemented
- [ ] Restore-on-upgrade path
- [ ] Member-facing preview + "choose what survives" UI
- [ ] Notification before purge

### 35. `communications`

> Meetings — Access to hosting real-time video/audio meetings (WebRTC), and the
> per-session limits that come with it. Mirrors Zoom/Teams' own plan restrictions: a duration
> cap once a session has 3+ participants, a participant-count cap, and cloud-recording
> availability. Joining as a guest via a join code is **never** gated by this resource — only
> hosting is. Full design in
> [`plans/video_communications/05-feature-tiers-and-entitlements.md`](video_communications/05-feature-tiers-and-entitlements.md).

`member` · `minutes` · `lifetime` (placeholder — see below) · `overage: block` · `tier_mode: absolute` · `retention: none`

Tiers (`value` = minutes before cutoff, 3+ participants only; `-1` = unlimited): `[40,-1,-1,-1,-1]`

**Decision needed — schema shape.** This resource was deliberately kept singular
(`communications`, not a `communications_*` family) per direct instruction, which means its
tier entries carry fields beyond the standard `{value, enabled}` —
`max_participants`, `cloud_recording`, `draw_annotations`, `live_transcription`. Precedented by
`storage_files_size_kb`'s per-tier `_note` field, extended further here. Read
`plans/video_communications/05-feature-tiers-and-entitlements.md` §2 before copying this
pattern for another resource.

**Decision needed — `period`.** `lifetime`/`monthly`/`daily` (`conventions.period_meanings`)
don't describe "a ceiling re-evaluated fresh every meeting." `lifetime` + `retention: none` is
the closest existing fit (same reasoning as `domain_email_sends_monthly`: an elapsed
meeting-minute can't be un-elapsed, so there is nothing to release or retire), not an exact
one. Whoever next revises `period_meanings` should consider whether a formal `per_instance`
period is worth adding. See `plans/video_communications/05` §4.

**Decision needed — tier values are placeholders.** `[40,-1,-1,-1,-1]` for duration and the
`max_participants`/`cloud_recording` columns in the registry entry are modeled on publicly
documented Zoom/Teams free-tier figures, copy-pasted the same way `domain_sponsorships`'
tiers were — **not** a pricing decision. Confirm with pricing before treating as final.

- [x] `canUse` wired and **fails closed** — `w/communications/src/domain/entitlements.ts`'s
      `resolveHostEntitlement()` (checked at session-start token issuance,
      `plans/video_communications/05` §6 — not a continuous check)
- [ ] `consume` — n/a in the allocation sense; nothing is inventory-tracked (a meeting-minute
      isn't held)
- [x] `release` — n/a, same reasoning as every other `retention: none` period resource
- [x] Retirement — n/a, a `retention: none` resource holds nothing to retire
- [x] Duration-cap enforcement implemented server-side (`w/communications`, not client-side —
      `meetingService.enforceDeadlines()`, swept on a timer in `index.ts`;
      `plans/video_communications/05` §6)
- [x] `max_participants` enforcement implemented server-side
      (`meetingService.ts`'s `tryAdmit(participant.id, session.participantCap)` at join time,
      plus the cap is also passed to LiveKit's own `createRoom`)
- [x] `cloud_recording` gate wired to the recording start endpoint
      (`recordingService.ts` checks `session.cloudRecordingEnabled` before allowing `start()`)
- [ ] `tier_mode: absolute` confirmed with pricing
- [ ] All four tier values (duration, participants, recording, transcription) confirmed with
      pricing — not just the primary `value` column

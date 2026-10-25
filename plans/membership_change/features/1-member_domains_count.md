# Feature 1 — `member_domains_count` (Websites / Domains)

> Numbered by its `TODO-member-resources.md` entry (#1); sections use the `F1.` prefix (see
> `../README.md`). **PLAN, 2026-10-05. Nothing is built for this feature.** Findings were read from the code and
> from the lab2 database (read-only). Items marked **DECISION** are the owner's; D1 to D4 and D6 to D8 and the
> terminology below were decided by the owner on 2026-10-05; only D5 is open.

> **Correction 2026-10-05.** An earlier version of this file described a server-side domain-limit check as built. That did
> not follow the plan; the code and that description have been **removed**. The forward edge is `CheckpointAllocV2`
> (`canUse` / `consume` / `release` against Parse), called at the feature's own call sites; the backward edge is the shared
> `RetentionEngine` in `members` plus an inventory adapter (`../00-overview.md` OQ-A, `../03-api-contracts.md` §3.1).

## F1.0 Terminology (decided by the owner, 2026-10-05) — read this first

- A **domain** (in this feature and in the `domain_*` resources) is the **whole ecosystem on a domain name**: the website and
  every per-domain module hanging off it (email accounts, forms, subscribers, ecommerce, …). Those are what the plan limits.
- The **domain name itself** (the DNS zone, purchased through the platform or connected by the member) is **exempt from bands
  and restrictions**. It is never counted, never banded, never retired and never purged. It always stays in DNS for the member
  to manage, whatever their plan.

So adding, connecting or buying a domain **name** has no quota at all, and a downgrade can never touch a zone. What a downgrade
retires is a **website** (and, by cascade, that website's per-domain modules). This corrected the registry's own wording ("custom domain names a member may register or connect"), now reworded (**D7**).

## F1.1 The feature in one paragraph

A member's plan sets **how many domains (websites with their modules) they may hold**: `member_domains_count` =
`[1, 3, 10, 25, -1]`, scope `member`, `tier_mode: absolute`, `retention: banded`, `grace_days: 30` (`-1` = no cap of the
registry's own, capped only by the hardware). When the plan drops, the websites above the new allowance are **retired =
taken offline**; DNS and mail keep working (D1). Every `domain_*` resource hangs beneath this one, so it is the root of the
largest cascade in the registry (15 per-domain resources, plus a `per_store` child under ecommerce), and why #1 comes first.

## F1.2 The numbers

Absolute totals mean the bands are the differences (`TODO-member-resources.md` "Band capacities"):

| Band | Slots | Plan that owns it | Total at that plan |
|---|---|---|---|
| 0 | 1 | Free (0) | 1 |
| 1 | 2 | Starter (1) | 3 |
| 2 | 7 | Professional (2) | 10 |
| 3 | 15 | Business (3) | 25 |
| 4 | no cap of its own | Enterprise (4) | ∞ |

`tier_mode: absolute` has never been confirmed against the pricing page (checklist item, unchanged).

## F1.3 Findings (read 2026-10-05; facts, not decisions)

| Question | Finding |
|---|---|
| **Where is a website created?** | `members`: `DomainController::createWebsite()` (`POST /api/v1/domains/create-website`), started from the domain's page by `btn_create_website` in `resources/js/controllers/domains/website.ts` (`createWebsiteApi`). It writes a `members_websites` row and asks the Brain to assign a tenant; deployment then runs **asynchronously** with a progress tracker (`ProgressWidgetController`, `onComplete`). |
| **Where is a domain NAME created?** | `store()` (add/connect), `purchase()` (Name.com), `search.ts` / `purchase.ts`. **Exempt (F1.0): no quota calls.** |
| **What is a "website" in the data?** | Three overlapping records: `members_websites` (members' row: `member_reference_num`, `domain_name`, `reference_num`, `tenant_reference_num`, `active`), `brain_http_server_tenants` (the Brain's registry: `member_reference_num`, `domain_name`, `subdomain`, `reference_num`, `active`), and flags in the zone's `json` (`has_website`, `site_enabled`, `alternate_*`) written by `DomainController::update()`. For the newer flow `members_websites.reference_num` equals the tenant's `reference_num`. |
| **Test data (lab2)** | The lab data is throw-away test data and is flawed: one domain name has three tenant rows, one `members_websites` row has the domain *name* in its `domain_name_reference_num` and a null `tenant_reference_num`. The design below does not bend around it; it is cleaned or replaced when we test (D6). |
| **Existing offline switch** | The domain page already has an Enable / Disable website control that sets `site_enabled` in the zone `json` (`website.ts`). A natural place for "retire = offline" (D1) to act, via `update()`. |
| **"Delete website" only flips flags** | `btn_delete_website` sets `has_website = false`, `site_enabled = false` in the zone json. It does **not** delete the `members_websites` row or the tenant. So a delete does not lower any row count; `release` and the inventory need a real delete (**D6**). |
| **Existing `canUse` / `consume` / `release`** | None for this resource. `members` already has the factory (`checkpointHelper/static/registry.ts` → `createAllocV2`) and the id (`AllocV2Resources.MEMBER_DOMAINS_COUNT`). |
| **Counter row** | A member-scoped `MemberResource` row is provisioned at signup (`TODO-member-resources.md` B8, closed). Not re-verified against Parse in lab2. |
| **Retention columns** | None on `members_websites` or the zone table (no `band`, `state`, `retired_at`, `purge_after`). |
| **Blocker** | `members` calls the Brain's `/dns/zones`, `/config`, `/http/*` with **no credentials**, while those routes need a portal login (`portal.admin.auth`). The domain list and the Brain-side parts of `createWebsite()` most likely return empty or null today (read from the code; not exercised live). Same root cause as `plans/TODOS.md` "portal.level99 disabled" (**D5**). |

## F1.4 The template's five questions (`../04-feature-template.md`)

1. **Is retiring irreversible from the outside?** Much less than first feared: with D1, retire takes only the **website** offline;
   DNS and mail keep working. Visitors see the site go down; nothing else changes. Reversible by an upgrade or a move.
   **Purge** (deleting a site and its data) is the dangerous step, so it is never automatic (D8).
2. **Can one item fail to fit?** No. `unit_kind` is discrete: a website is one slot.
3. **Does anything the platform depends on live here?** No: the platform's own domains are not member websites, and domain
   names are exempt (D4 resolved by F1.0).
4. **Can the member rescue anything?** Yes: move a website to a band they keep, or upgrade. With 1 slot on Free the kept
   bands fill fast, so `can_evacuate: false` must be reported honestly, as email does.
5. **What does the sweep cost for a member with no websites?** One indexed read per member.

## F1.5 Forward edge plan (`CheckpointAllocV2` at the call sites, `members` web UI)

No server-side count and no new endpoint. Each step is a call to the existing client where the feature acts:

| Step | Call | Where |
|---|---|---|
| Before a website is created | `canUse(MEMBER_DOMAINS_COUNT)` — **fails closed** (`overage: block`): refuse on `available: false`, on any error, and when the client is not set up | `website.ts`, the `btn_create_website` handler, before `createWebsiteApi` |
| When the create request is accepted | `consume(MEMBER_DOMAINS_COUNT, 1)`. Taken at acceptance, not at completion: deployment is asynchronous, so waiting for completion would let a second create pass `canUse` while the first is still deploying | same handler, after `createWebsiteApi` returns a tracker |
| If the deployment fails | `release(MEMBER_DOMAINS_COUNT, 1)` so a failed deploy does not hold a slot | the progress widget's `onComplete(false)` |
| When a website is deleted | `release(MEMBER_DOMAINS_COUNT, 1)` — and the delete must become a real delete (D6) | `btn_delete_website` handler |
| Re-enabling a retired website | refused (an upgrade or a move is the way back), so "Enable website" is not a way round retirement | the enable radio, via `state()` |
| Refusal wording | "You've reached your plan's website limit" with an upgrade prompt, distinct from "we couldn't verify your plan" (no upgrade prompt on a failed check) | shared helper, as `billing/16` §16.5 |
| Staff | `bypass: level === 99` is already in the factory | none |
| **Domain names** | **none: exempt (F1.0)** | add / connect / purchase are untouched |

Known limits (recorded): the check runs in the browser and the tier is caller-supplied, so the forward edge is advisory
(`billing/16` §16.9). The retirement side is the server-enforced part.

## F1.6 Backward edge plan (`members`: shared engine + one adapter)

1. **Columns and ledger (`www` migrations).** Retention columns on the **website** record (`members_websites`: `band`, `state`,
   `retired_at`, `purge_after`), **not** on `brain_dns_zones` (zones are exempt). A per-member ledger
   `members_website_band_usage` (`member_reference_num`, `band`, `state`, `retired_at`, `purge_after`, `suspended_at`,
   `reconciled_at`, notice markers), same shape as email's.
2. **Band assignment.** A new website lands in the lowest band with a free slot; existing websites are backfilled
   **oldest first** (D2: the newest are retired first) by `php artisan websites:backfill-bands [--dry-run]`, same rules as
   `email:backfill-bands`.
3. **`WebsiteRetentionAdapter`** (`members/app/Checkpoint/Domains/`): `list`, `move`, `retire`, `restore`, `purge`, reading
   the shared database directly. **`retire` = website offline** (D1): the site's `site_enabled` goes false and the row is marked
   retired; **DNS, zones, mail and domain names are never touched**. `restore` brings it back online. `purge` is **never automatic** (D8): it only runs on an explicit member action, deleting the website (tenant and data).
4. **`WebsiteRetentionService`**: `reconcile`, `retire`, `restore`, suspend/unsuspend, `sweepPurge`, `state`, `previewChange`,
   mirroring `EmailRetentionService` (hourly sweep, opportunistic `reconcile`, honest `can_evacuate`, move and status with the
   same refusals: full band, band above plan, retired band, suspended).
5. **Cascade.** `RetentionEngine::cascadeRetirement()` exists. Retiring a website retires its per-domain modules on **one
   clock** (children inherit the website's `purge_after`); `retention: none` children are skipped. Only #2 has a retention
   adapter today, so for the other 14 the cascade is reported in the preview but cannot act, and the rule is: **abort the
   cascade for an instance whose child adapter is missing** (`../02-architecture.md` §2.9). The domain adapter comes first;
   children join as their own features land. Email's rules already fit D1 (a retired website's mailboxes keep receiving mail
   and lose sending).
6. **Preview and chooser UI** (`members` domains page): `state()` / `preview()` through `CheckpointAllocV2`, a "choose which
   websites to keep" control, honest `can_evacuate`.
7. **Notice.** One mail when a website is taken offline (through the Brain, `Brain::mail`, event declared in `members`' manifest). **No "deleted in a week" reminder**: nothing is deleted on a clock (D8). The engine's sweep must skip purge for this resource; the registry's `grace_days: 30` then has no purge meaning here, and a registry field to say so (for example `purge_mode: manual`, as VoIP numbers need too) is a small follow-up for the owner to approve.
8. **Signed `members` → Brain channel (D5):** needed for website creation to work end to end and for any Brain-side change a
   retire might cause; the adapter's own database work does not need it.

## F1.7 Decisions

**Decided by the owner, 2026-10-05**
- **D1** — Retire a website = **website offline only; DNS and mail stay up.**
- **D2** — **Newest websites are retired first; the member can rearrange** before a downgrade takes effect.
- **D3** — A domain **name**, purchased or connected, is never retired, purged or restricted (F1.0).
- **D4** — No pinning needed: domain names are exempt from bands and restrictions (F1.0).

**Decided by the owner, 2026-10-05 (later)**
- **D6 — One slot = one domain name that has an active website, identified by the domain's `reference_num`, not its name.**
  Two members can hold a domain with the same name, so the name is never the key. **Only one website can be connected to one
  domain name at a time** (a second `createWebsite` for the same zone `reference_num` is refused). The count is the number of
  distinct zone `reference_num`s with an active website (`members_websites.domain_name_reference_num` → the zone). "Delete
  website" must become a real delete (mark the row inactive) so counts and `release` agree. The flawed lab2 rows are test data and
  are replaced, not designed around.
- **D7 — Registry reworded.** `member_domains_count`'s `description` and `retention_note` now describe websites and say domain
  names are exempt (`checkpoint_registry_v4.json`; label, unit and id unchanged).
- **D8 — Purge is never automatic.** A retired website stays offline, with its data, until the member deletes it or upgrades.

**Open**
- **D5 — The `members` → Brain blocker (F1.3).** Which signed surface (like `afs_connect.service` / `brain.surface`) should
  `members` use for zone and tenant calls, so website creation works end to end?

## F1.8 Proposed order of work

1. Decide D5 (before anything that needs the Brain); D1 to D4 and D6 to D8 are decided.
2. Forward edge in `members`' domains UI (F1.5), including a real delete-website with `release`. Self-contained.
3. Migrations + backfill + band assignment (F1.6 steps 1–2).
4. Adapter + service + sweep + tests (steps 3–4), verified in lab2 before anything is taken offline.
5. Preview, chooser, notice (steps 6–7).
6. Cascade into children, one child feature at a time.

## F1.9 Success criteria

- [ ] D5 decided (D1 to D4 and D6 to D8 are)
- [ ] `canUse` (fails closed) before a website is created; `consume` on acceptance; `release` on a failed deploy and on delete; domain names untouched; a second website for the same domain `reference_num` is refused
- [ ] Delete-website is a real delete (row inactive) and releases; the count keys on the zone `reference_num`
- [ ] Retention columns on the website record, per-member ledger and backfill exist and run in lab2
- [ ] Adapter, service, hourly sweep (no automatic purge) and tests; a downgrade takes the right websites offline (newest first) and never touches a zone, DNS or mail
- [ ] Re-enabling a retired website is refused; preview and chooser UI; honest `can_evacuate`; one notice when a website goes offline
- [ ] The cascade acts on one clock, or aborts cleanly when a child adapter is missing
- [ ] Tests run in lab2, not just written

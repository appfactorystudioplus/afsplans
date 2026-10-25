# 00 — Overview

> **STUB, FOR AUDIT.** Rewritten against `checkpoint_registry_v4.json`. Four of the first
> draft's eight open questions are now answered by registry fields; §6 is what remains.

## 1. The problem

### 1.1 `alloc_v2` only ever built one edge

Every metered resource has two questions attached to it:

```
   FORWARD EDGE                          BACKWARD EDGE
   "may I create one more?"              "I hold more than I'm allowed —
                                          which ones lose?"
   canUse / consume / release            bands / grace / purge
   BUILT (3 of 29, and it fails open)    BUILT (1 of 29)
```

Both derive from the same fact: `tiers[plan]` versus what is held. They are not two
systems; they are one system where the second half was never written.

The consequence is concrete. A member on plan 3 with 250 email accounts drops to plan 1
(allowance 10). Today: **all 250 keep working**. Mail flows, logins work. The only thing
that changes is that `canUse` refuses account 251. The limit applies to the future and
never to the present.

### 1.2 Storage is the exception, and it is stranded

`cloud-storage` built the backward edge properly — bands, cascade routing, retire, 30-day
grace, two-phase purge, restore-on-upgrade. It is good, and it is in `members`, against the
members database, behind `/api/v1/storage/*`, where nothing else can reach it.

### 1.3 Scope nesting makes per-feature code impossible

The registry has **seven** scope types, and they nest:

```
member
 ├── per_domain          (member_domains_count)
 │    └── per_store      (domain_ecommerce_stores)
 ├── per_project         (pm_projects_count)
 ├── per_graph           (dreamgraph_graphs_count)
 ├── per_document        (pdf_editor_documents_count)
 └── per_design_project  (design_flow_projects_count)
```

`member_domains_count` is `[1, 3, 10, 25, -1]`. Plan 3 → plan 1 retires **7 domains**, and
each carries ten `per_domain` resources beneath it. A per-feature implementation cannot see
this: email's code does not know a domain above it is being retired.

### 1.4 Nothing reads `account_status` — **one exception now, as of 2026-09-21**

`brain_memberships.account_status` is `1 = active, 2 = suspended, 3 = marked for deletion`.
This was **zero consumers** when written. It no longer is: `storage_files_size_kb`'s
retention engine now reads it and correctly freezes/resumes the purge clock (fixed as
[features/complete/25-storage_files_size_kb.complete.md](features/complete/25-storage_files_size_kb.complete.md)
§F25.10 F6). Every other resource, and `AfsConnectEntitlementService`'s own separate
disregard of it (`TODO-member-resources.md` B7), is unaffected — suspending an account still
does nothing for afs-connect access or for the other 33 resources.

## 2. Goals

1. Extend the registry so each resource **declares** its own retention policy. *(Done —
   `checkpoint_registry_v4.json`.)*
2. Build the backward edge **once**, registry-driven, covering all seven scopes and the
   cascade between them.
3. Refactor storage onto it with **no observable behaviour change** ([storage](features/25-storage_files_size_kb.md)).
4. Apply it to email accounts ([email](features/2-domain_email_accounts.md)).
5. Model **suspension** as a freeze that does not start a deletion countdown.
6. Let the member **see the consequence before it happens** and **choose what survives**.
7. Make the remaining 27 resources a checklist, not a redesign
   ([../TODO-member-resources.md](../TODO-member-resources.md)).

## 3. Non-goals

- **Not** rewriting `alloc_v2`'s transport. That is
  [../alloc-v2-parse-direct/](../alloc-v2-parse-direct/). This plan adds an edge; it does
  not move the wire.
- **Not** period resources. The 4 `monthly` resources carry `retention: "none"` — a send
  that already happened cannot be un-sent, and the period reset **is** the release.
- **Not** the billing side. What *causes* a tier change is [../billing/](../billing/).
  This plan starts the moment the tier differs from what a resource's bands assume.
- **Not** making the tier unforgeable. `alloc_v2` still takes a caller-supplied tier
  ([../billing/09-entitlement-integration.md](../billing/09-entitlement-integration.md) §9.4).
  Retirement reads the tier server-side and is unaffected — but the forward edge is not fixed
  by this plan.

## 4. Glossary

| Term | Meaning |
|---|---|
| **Forward edge** | `canUse`/`consume`/`release` — may I create one more |
| **Backward edge** | retirement — what happens to what I already hold |
| **Plan / tier** | `brain_memberships.membership`, 0–4. **Positional.** Never by label |
| **Account status** | `brain_memberships.account_status` — orthogonal to plan |
| **Band** | Tier band `n`, unlocked by plan `n`. Physical for storage, logical for everything else |
| **Holder** | The scope instance owning the bands — a member, a domain, a store, a project… |
| **Inventory** | Where the *items* live (`registry.inventory`). **Authoritative.** The Parse counter is a projection of it |
| **Cascade** | Retiring a scope instance retires every resource scoped beneath it |
| **Retire / Evacuate / Purge** | Read-only with a clock / move items to a kept band / actual deletion |

## 5. Decisions

| # | Decision | Why |
|---|---|---|
| **D1** | **One registry drives both edges.** Retention policy is declared per resource in `checkpoint_registry_v4.json`, not implemented per feature | Cascade (§1.3) is only computable by something holding the whole resource graph. Per-feature code structurally cannot do it |
| **D2** | **The inventory is authoritative; the counter is a projection.** `registry.inventory` names where items live; Parse holds a derived total | You cannot retire from a count. It also kills the two-representations drift (G13) and the missing reconciler (G9) — the count becomes derivable rather than separately tracked |
| **D3** | **Never delete on the change itself.** Retire, grace, then purge | A tier drop fires from expired cards and accidental clicks. Instant deletion makes a payment glitch unrecoverable |
| **D4** | **A retired band stays readable and evacuable** | Otherwise the grace window is just a slower deletion |
| **D5** | **Suspension freezes; it does not downgrade.** All bands read-only, **purge clock preserved** | An administrative hold must not delete data while the member is locked out and unable to act. Encoded in `conventions.account_status` |
| **D6** | **`tier_mode` is per resource, not global** | Storage is genuinely cumulative — five real directories that coexist and fill in sequence. Countables are genuinely absolute — `[2,10,50,250]` means totals. Both are right, for different reasons |
| **D7** | **Retirement reads the tier server-side, always** | `alloc_v2`'s tier is caller-supplied and advisory. If retirement inherited it, a client could suppress its own downgrade |
| **D8** | **Detection is a sweep; hooks are the fast path** | A tier change happens outside every resource. A missed hook must not mean a member keeps what they stopped paying for — or stays restricted after upgrading |
| **D9** | **Every transition is logged** with plan-before, plan-after, and amount affected | Deletion must be explainable afterwards |
| **D10** | **The member sees the consequence before the change**, with a real number, and is told plainly when evacuation is impossible | Telling someone to "move your data" when their remaining bands are full is advice they cannot follow |
| **D11** | **`retention: none` for every period resource** | The period reset is the release. Wiring retirement here would delete records to "reclaim" an allowance that resets anyway |

## 6. What registry v4 resolved, and what is still open

### Resolved by a registry field

| Was | Now |
|---|---|
| ~~OQ1 — cumulative or absolute?~~ | **`tier_mode`**, per resource. `storage_files_size_kb` cumulative; all 28 others absolute |
| ~~OQ5 — one grace window or per feature?~~ | **`grace_days`**, per resource. 30 default, 60 for `voip_numbers_count`, 0 for binary |
| ~~OQ7 — multi-domain holders~~ | **`scope_graph`**. Per-domain means per *instance*; cascade is defined |
| ~~OQ4 — where does shared code live?~~ | Largely dissolved. The shared layer is registry-driven by necessity; per-feature code supplies only inventory access and the retire/purge action |

### Resolved 2026-09-23

- **~~OQ-A~~ — Is the inventory or `alloc_v2` authoritative for the forward edge?**
  D2 already settled it for retirement (the backward edge): the inventory is authoritative,
  the Parse counter is a projection. **Resolved: for now, `alloc_v2`/Parse stays
  authoritative for `canUse`/`consume`/`release` (the forward edge), per resource, until
  that resource's inventory is identified** — not a blanket switch to the inventory. Reasons:
  1. **It isn't actually choosable yet for 25 of 29 resources.** OQ-F: only 4 have an
     identified inventory (`storage_files_size_kb` → `members/storage_nodes`,
     `domain_direct_payments_count` → `www/laravel_direct_payments`,
     `domain_processors_count` → `www/laravel_processors`, `secondary_users` → checkpoint
     secondary users). Asking "the inventory" for the other 25 has no inventory to ask.
  2. **The two edges answer different questions and can tolerate different sources.** The
     backward edge (D2) must be inventory-authoritative because you cannot retire from a
     count — an item has to be identified and acted on. The forward edge only ever answers
     "is there room," a question a well-maintained counter answers correctly for as long as
     nothing else writes the inventory out from under it.
  3. **Switching reintroduces the exact race `increment()` was built to avoid.** The
     module's own concurrency notes (`alloc_v2.ts` class docblock) explain why `consume()`
     uses Parse's atomic `increment()` rather than a read-then-write: two callers racing a
     naive check-then-write can both pass and both write. A `canUse` that reads a live
     inventory row and a `consume` that increments a *different* system's counter right
     after is a two-system read-then-write with the same race, and worse — the two numbers
     being compared no longer even live in the same datastore.
  4. **It costs latency and cross-database reach for no present benefit.** A member's own
     data lives on that member's own website deployment (a duplicated `www` clone, its own
     database — §5 "Table prefixes"), not in a single shared inventory `canUse` could reach
     with one indexed query the way it reaches Parse. Moving the forward edge there turns
     one Parse round-trip into a per-tenant database connection.

  **What this does settle, so it isn't re-litigated per resource:** once a resource's
  inventory is identified (OQ-F) and its backward edge is built and proven (the way
  storage's was), *re-pointing that resource's forward edge at the inventory* is a decision
  that can be made **for that resource alone**, on its own merits, informed by whether drift
  between the Parse counter and the real inventory has actually been observed for it. Storage,
  the one resource with both edges built, has **not** re-pointed `canUse` at `storage_nodes`
  — its forward edge still reads the Parse ledger — and nothing in this session found that
  to be causing a problem in practice, which is itself evidence for the default above.

### Still open

- **OQ-B — What does "retire" do to a live external resource?** Storage retires inert
  bytes. Three resources retire things with irreversible external effects:
  `domain_email_accounts` (bouncing mail changes sender behaviour permanently),
  `voip_numbers_count` (a released number cannot be reclaimed), `secondary_users`
  (a real person loses access). Each needs its own answer — see the per-resource sections
  in [../TODO-member-resources.md](../TODO-member-resources.md).
  **`domain_email_accounts` resolved 2026-09-21**, as part of building that feature —
  [features/2-domain_email_accounts.md](features/2-domain_email_accounts.md) §F2.3: turned
  out to have no irreversible external effect at all once decided — inbound mail is never
  suppressed at any lifecycle stage, so there's nothing external to retire; login/send are
  ordinary in-application checks. `voip_numbers_count` and `secondary_users` are each their
  own question, answered the same way — per-resource, when that resource's own feature is
  actually built (`features/[N]-[name].md`), not in the abstract beforehand.
  `voip_numbers_count`'s open questions are recorded in
  [features/23-voip_numbers_count.md](features/23-voip_numbers_count.md) §F23.5 — the
  provisioning feature itself doesn't exist in the codebase yet, so there's nothing to
  attach a retention decision to. `secondary_users` has no `features/` doc yet.
- **OQ-C — Does `overage: notify` imply `retention: none`?** `dreamgraph_graph_nodes` is
  the only `notify` resource and is currently marked `banded`. If a resource is willing
  to let you exceed the limit, retiring what you exceeded it by is arguably incoherent.
  Same as OQ-B: this is `dreamgraph_graph_nodes`'s own retention policy, decided when it
  gets its own `features/18-dreamgraph_graph_nodes.md`, not before.
- **OQ-D — Notification ladder.** `RetentionService`'s own docblock records that storage's
  T+7/T+21/T+28 reminders were never built. Billing's `BillingMailer` now exists to hook
  into. Silent retirement is the failure mode most likely to produce *"you deleted my data
  and never told me."*
- **OQ-E — `account_status = 3` (marked for deletion).** D5 covers suspension. Status 3 is
  a different thing and nothing models it.
- **OQ-F — 25 unknown inventories.** Only 4 of 29 resources have their item store
  identified. Each is a small lookup, but none can be retired until it is done.

## 7. What "nothing changed" must cost

On a holder whose plan matches their bands: **one indexed read per resource, no writes,
no events, no log line.** On a deployment with no rows for a resource: the sweep touches
nothing and exits.

Anything that logs "nothing to do" on a schedule across every tenant is its own denial of
service.

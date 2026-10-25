# 05 — Rollout and Testing

> Renumbered 2026-09-22 (was `07-rollout-and-testing.md`) — section numbers below were
> renumbered from §7.x to §5.x to match, along with every cross-reference to them elsewhere
> in `plans/`. See `README.md`.

> **STUB, FOR AUDIT.** Phases are ordered by risk. Per-resource progress:
> [../TODO-member-resources.md](../TODO-member-resources.md).

## 5.1 Phase 0 — decide

Questions from [00](00-overview.md) §6. These aren't gates on other work — each is decided
per-resource, when that resource's own `features/[N]-[name].md` is actually being built, not
batched up front:

- ~~**OQ-A**~~ — **resolved 2026-09-23**: `alloc_v2`/Parse stays authoritative for the
  forward edge, per resource, until that resource's inventory is identified (not a
  blanket switch) — see [00](00-overview.md) §6 for the reasoning. This one is engine-wide
  rather than tied to a single resource's own feature doc, which is why it's resolved here
  rather than held for later.
- **OQ-B** — what does "retire" do to a live external resource (mail, phone numbers,
  people)? `domain_email_accounts` resolved 2026-09-21, as part of building that feature.
  `voip_numbers_count`'s open questions are recorded in
  [features/23-voip_numbers_count.md](features/23-voip_numbers_count.md) §F23.5 — the
  provisioning feature itself doesn't exist yet. `secondary_users` has no `features/` doc
  yet either.
- **OQ-C** — does `overage: notify` imply `retention: none`? Applies only to
  `dreamgraph_graph_nodes` — decided when it gets its own
  `features/18-dreamgraph_graph_nodes.md`.

Registry v4 already answered cumulative-vs-absolute, grace windows, multi-instance scoping,
and most of where-the-code-lives.

## 5.2 Phase 1 — fix what is already broken

No dependency on any Phase 0 answer. Worth shipping on its own merits.

- **B3 — `planFor()` treats a missing membership row as plan 0.** Highest value in the plan:
  a resolve-but-empty membership table would retire every band for every member in one
  sweep. Billing already gets this right; storage does the opposite.
- **B1 — wire `release`.** Without it a downgrade is permanently punitive.
- **B2 — `canUse` fails open** on `block` resources.
- **M8 — `destroy()` leaves live mail routes behind.** Mail is being delivered to accounts
  the UI reports as deleted, today.
- **M7 — implement `--delete-email-account` for real.**
- **B8 — the registry loader cannot distinguish "absent" from "zero."** A missing registry
  file yields `{ limit: 0, enabled: false }`, which is indistinguishable from a legitimate
  plan-0 member. Same shape as B3, second location. [02](02-architecture.md) §2.9 cannot be
  implemented until it is fixed — [06](06-registry-distribution.md) §6.6.

## 5.2a Phase 1b — one registry, resolved at runtime

**Prerequisite for Phase 2**, and independent of every Phase 0 answer. The registry is
currently twelve files, nine of them compiled into shipped JS bundles, so policy is a
snapshot frozen at each application's last build. An engine that deletes member data must
not read that.

Sequence, breakage analysis, and the deletion list are in
[06-registry-distribution.md](06-registry-distribution.md) §6.5. Deletion of the eleven
copies is the **last** step, not the first — nine of them are `import`ed and removing one
early is a build failure.

## 5.3 Phase 2 — the engine

Ledger, state machine, grace clock, cascade, preview, event log, sweep. Registry-driven,
**no resource adopts it yet.** Proven against a synthetic resource with no consequences.

The cascade is the part to test hardest — it is the only genuinely new machinery.

## 5.4 Phase 3 — storage refactor

[storage](features/25-storage_files_size_kb.md), under its no-observable-change rule. Ships only once the tests
prove the clock and transitions are identical.

## 5.5 Phase 4 — email accounts

Schema (status + band columns), backfill, disable/restore mechanism, admin UI, cascade
routing on create. Backfill runs **read-only first** and reports what it would assign.

## 5.6 Phase 5 — the remaining 23

One at a time, via [../TODO-member-resources.md](../TODO-member-resources.md). Each is a
registry entry that already exists plus an adapter. Start with the ones whose inventory is
already known.

**`member_domains_count` goes last** — it has the largest cascade in the registry and every
child resource should already work before its parent starts retiring.

## 5.7 Phase 6 — notifications

**OQ-D.** Storage specified a reminder ladder and never built it. Silent retirement is the
failure mode most likely to produce *"you deleted my data and never told me."*

Generate the content from an aggregated `preview()` ([03](03-api-contracts.md) §3.4) so it
cannot drift from what actually happens.

## 5.8 The test matrix

The tests that matter prove nothing deletes early.

| Case | Expected |
|---|---|
| Plan unchanged, sweep runs 100× | No state change, no events, no writes |
| Downgrade 3→2 | Band 3 retired, `purge_after` = now + `grace_days`, items readable |
| Upgrade back before grace expires | Restored, clock cleared, `band_restored` logged |
| Upgrade back after purge, before hard delete | Restored from the hold window |
| Upgrade after hard delete | Band returns active and **empty** — the honest outcome |
| Suspend 20 days into a 30-day grace | Clock **frozen**; on unsuspend, 10 days remain — not 30, not 0 |
| Suspend with no downgrade | Everything frozen, **no** `purge_after` set anywhere |
| Registry unreachable | New allocations denied; **nothing retired, nothing purged** |
| **Registry file absent entirely** | Treated as **unknown, not zero**. Holder skipped; nothing retired. Must not resolve to "every limit is 0" |
| Registry updated at the canonical path | Every consumer reflects it **without an application rebuild** (§5.2a) |
| **Membership row missing** | Holder **skipped entirely**. Not treated as plan 0 |
| Downgrade with kept bands full | `can_evacuate: false`; UI states it; no move offered |
| Two bands retired, upgrade covers one | Only that one restores; the other keeps its **original** `purge_after` |
| Member deletes an item while retired | Allowed — reduces what will be purged |
| **Domain retired with 40 mailboxes and 2 stores beneath it** | Cascade retires all of them, **all inheriting the domain's `purge_after`**, not their own `grace_days` |
| **Cascade child adapter unreachable** | Whole cascade for that instance aborts. No half-retired subtree |
| `retention: none` child under a retired parent | Not retired; unusable only because its parent is |
| Platform-critical item in a retiring band | Must not be retired |

### The one that must never pass silently

> **A member who has not changed plan, whose registry is unreachable or whose membership row
> fails to resolve, must end a sweep with exactly the data they started with.**

Fail-closed on allocation is recoverable. Fail-closed on deletion is not — and "missing
membership ⇒ plan 0" would retire every band for every member at once.
[02](02-architecture.md) §2.9 forbids it; this test is how we know.

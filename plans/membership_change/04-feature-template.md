# 04 — Resource Template

> Renumbered 2026-09-22 (was `06-feature-template.md`). See `README.md`.

> **STUB, FOR AUDIT.** Per-resource progress lives in
> [../TODO-member-resources.md](../TODO-member-resources.md) — 29 sections, one per
> registry key. This document is what each of those sections has to be able to answer.

## The registry answers most of it already

`checkpoint_registry_v4.json` declares six things per resource, so they are never re-argued:

| Field | Answers |
|---|---|
| `scope` | who the holder is — and, via `scope_graph`, what cascades |
| `tier_mode` | does the allowance sum across tiers, or replace |
| `unit_kind` | can a single item fail to fit in a band |
| `retention` | `none` / `binary` / `banded` |
| `grace_days` | how long before purge |
| `inventory` | where the items actually live |

**A new resource is a registry entry plus an adapter.** If it needs an engine change,
something is wrong with the model — raise it rather than special-casing.

## The adapter — the only per-resource code

```
list(scope, band?)     enumerate items, with size and current band
assign(item, band)     record a band assignment
retire(items)            make them unusable but readable
restore(items)           reverse retire
purge(items)             actually delete
```

Everything else — ownership, allowance, the clock, cascade, preview, move, events — is the
shared engine.

## The five questions that catch problems early

1. **Is retiring this irreversible from the outside?**
   Storage's is not — a read-only file is invisible to third parties. Three resources are:
   `domain_email_accounts` (a bounce changes sender behaviour permanently),
   `voip_numbers_count` (a released number cannot be reclaimed), `secondary_users` (a real
   person loses access, and they are not the account owner).
   **An irreversible external effect needs more caution than the storage precedent implies.**

2. **Can a single item fail to fit?** `unit_kind: continuous` — yes. `discrete` — never.

3. **Does anything the platform depends on live here?** `postmaster@`, the address billing
   sends from, the domain the Brain resolves. Those need pinning to band 0 so they can
   never be retired. **Disabling the address the platform sends from is a self-inflicted
   outage.**

4. **Can the member actually rescue anything?** If the kept bands are full,
   `can_evacuate: false` and the UI must say so instead of offering a move.

5. **What does the sweep cost on a tenant that never uses this resource?**
   One indexed read, per [00](00-overview.md) §7.

## What "done" means

Both edges, per [../TODO-member-resources.md](../TODO-member-resources.md):

- Forward: `canUse` (**fails closed**), `consume` on create only, `release` on delete.
- Backward: inventory identified, band assignment + backfill, retire/restore/purge
  implemented, cascade handled if it has children, preview + chooser UI, notification.

A resource wired forward-only is **not done**.

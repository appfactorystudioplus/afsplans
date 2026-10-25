# connections — completion records

One file per numbered plan document, named `<source basename>.complete.md` — the convention
every sibling folder under `plans/www/` follows.

**K0, K1, K2 and K4 have shipped**, 2026-09-13 (K4 shipped ahead of K3 — its own dependency
is K2, not K3). Start at
[`11-rollout-and-testing.complete.md`](11-rollout-and-testing.complete.md) — it's the hub the
other completion docs hang off, with the full K0–K12 phase checklist.

## What a completion document contains

| Section | Contents |
|---|---|
| **Header** | Plan file, rollout phase, repo, date, status |
| **What shipped** | Per plan section, what was actually built — not what was intended |
| **Not implemented** | Explicitly, with the phase that owns it |
| **Files** | Every file added or changed |
| **Acceptance** | The plan section's acceptance list, each row ✅ / ⚠️ / ❌ with the evidence |
| **Verification** | The exact commands a reader can run |
| **Residue** | Anything found on the way that belongs elsewhere, with where |

## Index

| Doc | Phase | Status |
|---|---|---|
| [11-rollout-and-testing](11-rollout-and-testing.complete.md) | K0, K1, K2, K4 (hub) | ✅ |
| [03-database-schema](03-database-schema.complete.md) | K1 | ✅ |
| [02-architecture](02-architecture.complete.md) | K1/K2 slices only | 🟡 partial by design — see doc |
| [04-connection-registry-and-enforcement](04-connection-registry-and-enforcement.complete.md) | K1 + K2 slices | 🟡 partial by design — see doc |
| [05-admin-connections](05-admin-connections.complete.md) | K4 | 🟡 backend + partial frontend — see doc |

Also relevant: [`../../site_users/complete/13-identity-and-email-change.complete.md`](../../site_users/complete/13-identity-and-email-change.complete.md)
(K1's identifier-migration steps 1–2), in the `site_users` plan folder because that is where
the identifier itself is specified.

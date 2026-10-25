# 08 — Allocations and limit overrides

## Completion document

Built 2026-10-05 (P6 and P6b; device and server verification pending): [complete/08-allocations-and-overrides.complete.md](complete/08-allocations-and-overrides.complete.md).

Phases: usage view and overrides **P6**; single-counter adjust **P6b**. No new server work: every route exists
([04](04-server-additions.md) §4.1), behind `portal.level99`. Background plan: [`members_brain`](../members_brain/README.md).

## 8.1 What this screen is for, and what it deliberately is not

"Allocations" is the member's usage ledger against the limits their plan grants: how many domains they have used of the
three their plan allows, how many email accounts on each domain, how much storage. The browser's Allocations tab is a
generated form over **35 resources × 7 scopes**, with a JSON editor and a diff-confirm step. That is a desk tool.

On a phone the valuable 90% is two things:

1. **Look** — "what is this member using against their ceiling?"
2. **Grant** — "give this customer five more" (an **override**), with a reason, optionally expiring.

So the app has a **Usage** list and an **Overrides** list, and — later (P6b) — a **one counter at a time** adjustment. It does
not have the JSON editor, the generated multi-row form, or "force save". The row model makes this natural: the server addresses
every counter by a stable `ledger_key` and checks concurrency **per row** (`BrainAllocationController`), so adjusting one row never
needs the rest of the document.

## 8.2 Data

| What | Source | Shape |
|------|--------|-------|
| The member's counters | `GET /portal/api/v1/brain/member/{ref}/allocation` | `data.rows[]`: `{ objectId, updated_at, ledger_key, resource_id, scope_type, scope_key, scope_parent, used, retired, state, limit_override, period_start, purge_after, retired_at }`; `data.tier` (0–4); `data.account_status` |
| Resource definitions and per-plan ceilings | `GET {REGISTRY_URL}` (public JSON) | `resources[id] = { label, description, group, category, scope, unit, period (lifetime|monthly), overage, warn_at_pct, tiers:[{value, enabled}×5], tier_mode, retention, … }`; `tiers[i].label` |
| Active overrides | `GET …/overrides` | `overrides[]`: `{ id, resource_id, scope_type, scope_value, limit_value, reason, expires_at, expired, created_at, created_by }` |

The app fetches the registry once per session (and on pull-to-refresh), sends **no token** to it, and treats a failed fetch as
"labels unavailable": rows are still listed by `resource_id`, ceilings show "—", and nothing is hidden or guessed. (Mirrors the
server's own fail-closed stance on a missing registry — [membership_change/06](../membership_change/complete/06-registry-distribution.complete.md).)

**Effective ceiling** follows the documented resolution order ([`members_brain/05`](../members_brain/05-limit-overrides.md) §5.4):
(1) an **unexpired override** matching the row's `(resource_id, scope)`, else (2) `registry.resources[id].tiers[tier].value`
(`-1` = unlimited). **`enabled` is never overridden** — if `tiers[tier].enabled` is false the resource is "Not included in this
plan" and an override does not make it appear. The app shows the ceiling's **source** ("plan" / "override"). The row's own
`limit_override` Parse field (an editable counter field whose role `members_brain` does not document) is shown as an
informational "row override: n" and is **not** folded into the computed ceiling, so the app never claims a precedence the
server does not define.

## 8.3 The Usage screen

Grouped by the registry's `group` (domains, storage, …), then by scope instance:

```
Member scope
  Websites / Domains         2 / 3       ██████░░  67%     plan: Pro (3)
  Cloud storage              1.2 GB / 10 GB  ██░░░░░░ 12%
Domain  example.com
  Email accounts per domain  4 / 10      ████░░░░  40%     override: 25 (until 2026-12-31)
  Outbound sends (monthly)   310 / 5000  ░░░░░░░░   6%     resets 2026-11-01
```

- **Bar and percentage** from `used` vs effective ceiling; amber at the resource's `warn_at_pct`, red at ≥ 100%.
- **Monthly** resources show `period_start` and when they reset; the registry's `period` says which are monthly (4 of 35).
- **`state` ≠ active** (`retired`, `purging`, `suspended`) is shown as a chip with `retired_at` / `purge_after` where present — these are
  the retention engine's states, read-only here.
- **Plan names:** the header shows the Brain's plan label (Free · Pro · Business · Organization · Enterprise); next to a limit the app
  says "plan: <registry label> (<value>)". The registry's own names (Free · Starter · Professional · Business · Enterprise) differ
  ([O8](01-current-state-audit.md)); both are positional, so the app always indexes by the 0–4 number and **never matches by name**.
- Units come from the registry `unit`; `-1` renders "Unlimited". Storage values use the registry's unit (KB) formatted with `intl`.
- A **502** from the allocation route ("Failed to load allocation rows from Parse") renders as "Allocation ledger unavailable — the
  Brain cannot reach Parse" with Retry; it is not an empty list.
- **The acting member** (`appfactorystudio`) shows usage but no edit controls (BF13; the server returns 404 on a self-edit).

## 8.4 Overrides

A list of active and expired overrides with reason, who created them and when, and expiry. **Add override** (a bottom sheet):

| Field | Control | Server rule (enforced in the app first) |
|-------|---------|------------------------------------------|
| Resource | searchable picker over `registry.resources` (label + group) | `resource_id` required, ≤ 64 |
| Scope | segmented: **Member** · **Domain** · **PM project** | `scope_type ∈ {member, domain, pm_project}` |
| Scope value | text, shown only for Domain / PM project (domain name, project name) | **required** for those two; the Usage list offers a "Use this domain" shortcut |
| New limit | integer (`-1` = unlimited) | `limit_value` required integer |
| Reason | multiline | **required, 10–500 characters** |
| Expires | "Never" (default) or a date picker | `expires_at` in exactly `Y-m-d\TH:i:s\Z` (UTC); omitted when "Never" — the server's own note: *absent*, not null |

`POST …/overrides` → on success the list refreshes; `422` shows field errors from the `errors` map. **Remove** an override with
`DELETE …/overrides/{id}` after a confirmation (reason is not asked — the server has none for delete). Overrides take effect
for the member immediately because `BrainController::getMember` and `BrainEntitlementController` deliver them with the tier.

Overrides are **audited server-side** (`created_by_user_id/username`); the app's device label is not on this record because the
route predates it — noted, not changed (BF5).

## 8.5 P6b — adjust one counter

From a Usage row's overflow: **Adjust…** opens a sheet for exactly one `ledger_key`, offering only the server's
`EDITABLE_FIELDS`: `used`, `limit_override`, `state` (`active | retired | purging | suspended`), `period_start`, `purge_after`,
`retired`. The app sends one change record:

```json
{ "mode": "form", "reason": "…", "force": false,
  "changes": [ { "ledger_key": "…", "field": "used", "row_updated_at": "<row.updated_at>", "before": 2, "after": 1 } ] }
```

Rules (all from `BrainAllocationController::store`):

- **Reason required (≥ 10 chars) for any decrease** and for any forced save; the app asks for it whenever `after < before` or the field is `state`.
- `before` must equal what the server still holds; a mismatch comes back as **409** with `failed_changes[]`
  (`reason: stale | value_changed | no_such_row | invalid_value`). The app renders the conflict per row — "Changed to 3 while you
  were editing" — and offers **Reload** only. **`force` is not exposed** (BF9): overwriting someone's concurrent change from a
  phone is the exact blind-overwrite the row model was built to end.
- Values are validated locally per field (`used` non-negative integer ≤ 4,294,967,295; dates ISO; `state` from the list).
- A **partial write** (`502` with `saved_rows`/`total_rows`) is reported literally ("2 of 3 rows saved"), then the screen reloads.
- An **audit failure after a successful write** returns `500` with a message that changes were saved; the app shows that
  message verbatim and reloads rather than retrying.
- The member's own browser may be `consume()`-ing the same counter; `row_updated_at` is what makes that safe.

P6b is a separate phase because correcting a counter is a rarer, riskier act than granting an override; the app is useful
without it.

## 8.6 Why not the rest of the web feature

| Web feature | In the app? | Reason |
|-------------|:-----------:|--------|
| Generated form over all rows | ❌ | 35 resources × scopes is a desk layout; the Usage list covers reading |
| JSON editor / mode switch | ❌ | A phone keyboard editing a ledger document is the wrong tool; the row model needs no document editing |
| Multi-row diff-confirm save | ⚠ single row | One reason, one row, one explicit diff — the same safety in a phone-sized step |
| `force` save | ❌ | See §8.5 |
| Hide-zero / overrides-only filters | ✅ | Two toggles on the Usage list |

## 8.7 Acceptance

- Opening `james1` → Allocations lists rows from the real Parse ledger (or the "ledger unavailable" state if lab2's Parse is down —
  `PARSE_SERVER_URL` is `dashboard-registry.<lab>/parse`); a registry outage leaves rows listed by id.
- Adding an override with a 9-character reason sends nothing; with 10+ it appears in the browser SPA's overrides panel and in
  `GET /brain/member?reference_num=` (`alloc_overrides`).
- Removing it removes it from both.
- (P6b) Two edits to the same counter, the second stale, produce the per-row conflict and never overwrite.
- `appfactorystudio` shows usage with no controls.

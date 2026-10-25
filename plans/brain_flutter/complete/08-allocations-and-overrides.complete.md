# 08 — Allocations and limit overrides — Completion

**Verified:** 2026-10-05 — `tool/gates.sh` fully green (G1–G9, `flutter analyze` clean, `flutter test` **539 passed**, of which 140 are new for this file: 86 logic / repository / error-shape / cubit, 50 widget, 4 shell).
**No server code was written or changed for this file** (the plan says none is needed; every route already existed behind `portal.level99`).
**Not run:** nothing in this file has been exercised on the phone or against lab2's Parse; the allocation routes have never been called by this app against a live server.

**Status:** **Complete in code (P6 and P6b); not verified on a device or against a live server.** Every acceptance line in §8.7 is covered by a test; the lab checks are listed at the bottom.

## What was built

| § | Item | State | Evidence |
|---|------|-------|----------|
| 8.2 | **Data**: ledger rows, overrides, registry | ✅ | `features/allocations/data/`. Rows keep the date strings **exactly as sent** because an edit echoes them back as `before` and `row_updated_at`. The registry is fetched through the one client **without the token**, once per session, and refetched on pull-to-refresh; a failed fetch is **not remembered** (test) |
| 8.2 | **Effective ceiling** in the documented order, with its source | ✅ | `logic/usage.dart` `resolveUsage`: unexpired override for the same (resource, scope), else the plan's allowance **by position**. `enabled` is never overridden (a plan that excludes a resource stays "Not included in this plan" whatever an override says). The row's own `limit_override` is shown as "row override: n" and never folded in. 14 tests |
| 8.3 | **Usage list** | ✅ | `ui/allocations_tab.dart`: grouped by the registry `group`, then by scope instance (member scope first); "used / ceiling" with the registry unit; percent and bar; **amber at `warn_at_pct`, red at ≥ 100%, each with a word pill ("Near limit" / "Over limit") so colour is never the only signal**; "plan: Pro (3)" or "override: 25 (until 2026-12-31)"; monthly counters say "Resets 2026-11-01"; a retired / purging / suspended row shows its state with `retired_at` and `purge_after`; -1 reads "Unlimited" with no bar; kilobyte units read KB / MB / GB / TB |
| 8.3 | **Failure states** | ✅ | A 502 from the ledger is **"Allocation ledger unavailable — the Brain cannot reach Parse"** with Retry, never an empty list; any other failure is the standard error view; a **registry outage** lists rows by id with limits "—" and a banner, hides nothing and guesses nothing; an overrides read that failed is flagged ("a limit shown may be missing one"); a failed refresh keeps the list and shows a banner |
| 8.3 | **Acting member** | ✅ | Usage is shown with a "View only" note, no row menu, no Add override, no Remove; the overrides themselves stay readable |
| 8.6 | Hide-zero / overrides-only toggles | ✅ | "Hide unused" and "Overrides only" filter chips, and a "Nothing matches these filters" state |
| 8.4 | **Overrides list** | ✅ | Reason, limit, scope, expiry ("Never expires" / "Expires …"), who granted it and when; expired ones are marked and cannot be removed; the segment counts only active ones |
| 8.4 | **Add override** | ✅ | `ui/override_sheets.dart`. Searchable resource picker (label, id and group); Member / Domain / PM project; scope value required for the last two; integer limit (-1 unlimited); reason 10–500; expiry Never or a date, sent as the **end of that day in UTC, `Y-m-d\TH:i:s\Z`, and omitted — not null — for Never**. Every rule is checked before anything is sent, by one function (`overrideProblem`) that the form and the cubit share, so they cannot disagree. A **9-character reason sends nothing** (cubit and widget tests). A 422 shows the field error under that field and keeps the sheet open |
| 8.4 | **"Use this domain" shortcut** | ✅ | The row menu's "Add override…" opens the sheet with the resource, scope and domain/project already filled in |
| 8.4 | **Remove override** | ✅ | Confirmation first; no reason asked (the server has none for a delete); the list reloads |
| 8.5 | **P6b — adjust one counter** | ✅ | `ui/adjust_sheet.dart`: one row, one field from the server's closed list (`used`, `limit_override`, `state`, `period_start`, `purge_after`, `retired`), current value shown, one change record with `before`, `after`, `row_updated_at`, `mode: form`, **`force: false` always**. A reason of 10+ characters is required for any decrease and any state change. Values are validated per field (used 0…4 294 967 295; limit −1 or more, or clear; state from the list; dates parseable, or clear). A no-op is "Nothing changed", not a request |
| 8.5 | **Conflicts** | ✅ | A 409 `failed_changes` is shown per row ("Changed to 3 while you were editing.", or the stale / no-such-row / invalid-value wording); the only action is **Reload**, which brings in the fresh row and clears the conflict. **There is no force anywhere in the app** (BF9) — a test asserts no "force" text on the sheet |
| 8.5 | **Partial and audit outcomes** | ✅ | A 502 with `saved_rows` / `total_rows` is reported literally ("0 of 1 rows saved") and the screen reloads; a 500 saying the changes were saved is shown verbatim, the sheet closes, the screen reloads and **nothing is sent again** |
| 8.5 / 05 | **Ambiguous outcomes** | ✅ | Add override: re-read the overrides and look for the same (resource, scope, limit, reason). Remove: re-read, saved if the id is gone. Adjust: re-read the ledger, saved if the field now holds the target value. In every case the request was sent **once**; a re-read that also fails reports the original error |
| 8.1 | Capability gating | ✅ | `allocations` adds the tab; `overrides` adds the Overrides view and the override actions and is what lets the cubit ask for overrides at all (without it nothing is requested and ceilings are the plan's own). The tab loads lazily: nothing is requested until it is opened |

## Divergences

- **Cumulative resources are summed.** The plan (§8.2) says the ceiling is `registry.resources[id].tiers[tier].value`. The registry's own `conventions.tier_modes` says that for `tier_mode: cumulative` the allowance at plan *n* is the **sum of plans 0..n**; one real resource uses it (`storage_files_size_kb`: 512 MB + 2 GB … ). The app follows the registry, so Pro storage reads 2.5 GB, not 2 GB. If the Brain's enforcement does not follow the same convention the screen would be wrong for that one resource — worth a check against `AllocationV2Controller` when the lab is available.
- **Ledger rows use the registry's scope names, overrides use different ones.** A row says `per_domain` / `per_project`; an override says `domain` / `pm_project`. The app maps them the way the browser does (`brain_membership.ts` `hydrateRowsWithOverrides`). A row in any other scope (`per_store`, `per_graph`, …) has **no override action**, because the server's override scope enum cannot target it. The plan did not mention this.
- **The tab is called "Allocations"** (the plan's acceptance wording), and the overrides segment reads "Overrides (n)" with the active count.
- **Hide-zero is labelled "Hide unused".**
- **A reason is not required for an increase**, and is sent when given. The server only enforces one for decreases and forced saves; the app's 10-character minimum is stricter than the server (which only checks `max:500`), as the plan asks.
- **The error parser grew three cases for these routes**: a singular `field` (the override route names `scope_value` that way), the 502 progress detail, and the 500 "changes were saved" marker. These routes predate the `BS_E_*` shape.
- **`readOverrides`** on the cubit is an addition: the overrides capability is separate from the allocations one, and asking a server that lacks the route would have shown a misleading banner.

## Not verified — what is left for you (08 §8.7)

1. **Deploy and open `james1`** (a member with real rows): the tab should list the Parse ledger, or show "ledger unavailable" if lab2's Parse is down. I did not read the lab's `PARSE_SERVER_URL` or call it.
2. **Add an override** with 10+ characters and confirm it appears in the browser SPA's overrides panel and in `GET /brain/member?reference_num=` (`alloc_overrides`); **remove it** and confirm it is gone from both. Do this on a lab member, not a real one.
3. **P6b:** make two edits to the same counter (one from the app, one from the browser, or the same twice) and confirm the second shows the per-row conflict and overwrites nothing.
4. **`appfactorystudio`** should show usage with no controls.
5. Compare the cumulative-storage ceiling against what the server actually enforces (see Divergences).
6. TalkBack labels on the new screens were not reviewed.

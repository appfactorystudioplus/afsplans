# 06 — Members: see all, add, edit, remove — Completion

**Verified:** 2026-10-05 — `tool/gates.sh` fully green (`flutter analyze` clean, `flutter test` 216 passed). On a moto g play against lab2 the handshake, the member list (`GET /v1/memberships` → 200) and a member detail (`GET /v1/brain/staff/members/m1xTHpRmdtH2Y` → 200) loaded with no errors in the log. **The write flows (edit, plan, status, add, remove) have not been run against the lab**; they are covered by widget and unit tests with fakes, and by the server tests that have not been executed (file 04).

**Status:** **Complete for everything this file can deliver today.** One tier is blocked by another plan (R2, real delete, needs `account_close`), and one wording item needs file 07. Everything else in §6.1–§6.6.1 is built.

## What was built

| § | Feature | State | Evidence |
|---|---------|-------|----------|
| 6.1 | **List**: two-line cards, all members, `per_page 50`, infinite scroll within 400 px, header count, debounced search (350 ms), filter sheet with removable chips (status, plan multi-select, billing), sort (Newest/Username/Email/Plan), pull-to-refresh, cached first page ("Cached · N min ago"), loading **skeleton**, empty, error + Retry, "You" tag on the acting member | ✅ | `features/members/ui/member_list_page.dart`, `cubit/member_list_cubit.dart`; skeleton added in this pass (static placeholder cards, no spinner) |
| 6.2 | **Detail**: avatar/name/status/plan header, **Details** and **Activity** tabs, long-press to copy, `mailto:` from the email row, pull-to-refresh, overflow menu hidden for the acting member and when the capability is absent | ✅ for P1 tabs | `member_detail_page.dart`. Subscription, Payments and Allocations tabs belong to files 07 and 08 |
| 6.3.1 | **Edit profile** (names and address), country picker over a bundled ISO list that sets code and name, email/username read-only, only changed fields sent with `expected_updated_at`, **409 → draft kept, Reload or Review changes** | ✅ | `edit_profile_page.dart`, `country_picker.dart`, `core/countries.dart`, `member_forms.dart`. **Review changes added in this pass**: reloads, then shows each affected field as Before / Yours / On the server now, flags a field both people changed, and "Save my changes" re-sends only the fields the user edited against the refreshed `updated_at`; a field someone else changed and the user did not touch is never overwritten |
| 6.3.2 | **Change plan**: radio list from `create-options`, reason 3–500 enforced before any request, current plan disabled, "does not change billing" line, downgrade help text | ✅ | `member_sheets.dart` |
| 6.3.3 | **Change status**: Active / Suspended / Marked for deletion, close date for the last two, confirmation for 2 and 3, typed username on production for 3 | ✅ | `member_sheets.dart` |
| 6.4 | **Add member**: required fields and rules enforced in the app (BA5), live username/email availability, password ≥ 8 with no whitespace, Generate / reveal / copy, processor default from the server, plan and status, uppercase discount code; the reply's session material is never read; a generated password is shown once more after creation with a warning; **a taken username or registered email now lands on that field and focuses it** (the server answers 422 with a message only, so the form maps it); Checkpoint failure (502) says "Checkpoint is unavailable (code). Nothing was created." and keeps the form; a lost response looks the member up by username and never re-sends | ✅ | `add_member_page.dart`, `member_form_cubit.dart`, `new_member.dart`, `member_forms.dart` |
| 6.5 | Stale protection everywhere a row is written; no optimistic UI (every write waits for the server and renders what it holds); every write appears in Activity as `source: admin_mobile`; forms scroll with the keyboard | ✅ | `MemberDetailCubit._write` |
| 6.6.1 | **Remove (tier R1)**: Suspend or Mark for deletion (+ optional close date), states plainly that nothing is deleted and lists what does not happen (no data removed, identity untouched, email/username reserved, subscription not cancelled), confirmation, typed username on production | ✅ | `RemoveSheet` in `member_sheets.dart` |
| 6.6.2 | **Remove (tier R2)**: soft/hard delete, danger zone, preview/acknowledge/execute | ❌ **blocked, by design** | Needs the unbuilt `account_close` plan's routes; the app shows it only when `whoami.capabilities.account_close` is true (always false today), and `account_close` is reported honestly as `false`. Nothing in R1 changes when it lands |
| 6.6.1 (last line) | Remove sheet links to **Subscription → Cancel** when a subscription exists | ❌ needs file 07 | The sheet's text still says the subscription is not cancelled and is a separate act |

## Divergences

- **Review changes** shows Before / Yours / Server rather than a side-by-side table (small screen); it only offers "Save my changes" or "Keep editing". There is no per-field "use theirs": the user keeps editing, or reloads.
- **Plan sheet second line**: the plan says "This member pays through Stripe for **Pro**…". The detail payload does not yet carry the provider (BS4 `subscription_summary` is still null, file 04), so the sheet says "pays through a payment provider" and shows it when the member has a payer and a billing state.
- **Duplicate-username focus** relies on the server's message text ("Username already taken."/"Email already registered."), because `adminStore` answers with a message and no field map. If that wording changes server-side the error falls back to the general message at the bottom of the form (still shown, just not on the field).
- Plan labels in the list/detail come from the server once per session (file 05); the add form and plan sheet use the options they fetch.

## Manual acceptance (§6.7) — to run on the phone, against lab2, on a throwaway member

Not done by me; none of the write flows has been exercised against a live server.

1. The list shows lab2's members; searching `james2` leaves one; filtering Plan = Enterprise leaves `appfactorystudio`.
2. Open `james1`: reference `m1xTHpRmdtH2Y`, plan Pro, payer `pay_a1b2c3d4e5f6`.
3. **Create a throwaway member first** (Add): a taken username shows the field error and focuses it, with no Checkpoint call; a valid member then appears in the list and in the browser SPA, and `GET /brain/member?reference_num=` returns it. Use only this member for steps 4–8.
4. Edit its city: the browser shows it after reload; Activity shows `profile_updated` with source `admin_mobile`.
5. Change its plan to Business with reason "lab test": Activity shows the plan change; with a 2-character reason no request is sent.
6. Change the browser first, then save the same member in the app: the stale state appears, Review changes shows the diff, saving does not overwrite the other field.
7. Remove… → Suspend, then Mark for deletion: both audited; the sheet says nothing was deleted.
8. `appfactorystudio` shows no actions menu.

## Still to do for this file

- Run step 1–8 above on a device, and the server tests (file 04) that back them.
- R2 and the "Subscription → Cancel" link once `account_close` and file 07 exist.

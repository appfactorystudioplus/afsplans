# 06 — Members: see all, add, edit, remove

## Completion document

Built 2026-10-05 (R2 blocked on `account_close`; on-device acceptance pending): [complete/06-members-feature.complete.md](complete/06-members-feature.complete.md).

Phases: list/detail **P1**; add/edit **P2**; remove **P3**. Server pieces named here are in [04](04-server-additions.md).

## 6.1 The member list

**Source:** `GET /portal/api/v1/memberships?summary=1&page=&per_page=&search=&status=&tier=&billing_state=&sort=&dir=`
(BS3). The browser's table shows Reference · Username · Email · Membership · Status · Created and a View button; the phone
shows the same facts as a two-line card.

```
┌────────────────────────────────────────────┐
│ james1                         ● Active    │   ← username, status chip
│ james1@appfactory.studio                   │   ← email
│ m1xTHpRmdtH2Y   ·   Pro   ·   2026-05-22   │   ← reference, plan, created
│ ⚠ past due                                  │   ← only when billing_state ≠ current
└────────────────────────────────────────────┘
```

- **All members, no cap.** `per_page = 50`; the list loads the next page when the user scrolls within 400 px of the end
  (`pagination.current_page < last_page`). The header always shows `pagination.total` ("3 members").
- **Search** (one field, debounced 350 ms) → `search=`; the server matches email, reference, username and Direct Payment
  id (`MembershipController::index`). Clearing it restores the unfiltered list.
- **Filters** (a bottom sheet, shown as removable chips): Status (Active / Suspended / Marked for deletion), Plan
  (Free…Enterprise, multi-select), Billing (Past due / Downgraded). Defaults: none — "all members".
- **Sort**: Newest (default) · Username · Email · Plan.
- **Pull-to-refresh**; the cached first page paints immediately on cold start ([05](05-app-architecture.md) §5.9).
- **States:** loading skeleton · empty ("No members match") · error view with the normalised message and a Retry.
- **The acting member** (`reference_num == BRAIN_MEMBER_REF`) is shown with a "You" tag and its destructive actions are
  hidden everywhere (BF13).
- Tapping a card opens the detail ([§6.2](#62-member-detail)).

## 6.2 Member detail

**Source:** `GET /portal/api/v1/brain/staff/members/{ref}` (BS4) — one call. The page is a header plus a tab bar, mirroring the
browser's tab strip (`Details · Subscription · Payments · Allocations`) and adding **Activity**:

| Tab | Content | Phase |
|-----|---------|-------|
| **Details** | Identity (reference, username, email, name), Plan, Account status (+ close date), Address, Payer/processor/Direct Payment refs (read-only, copyable), created / updated | P1 |
| **Subscription** | [07](07-subscriptions-direct-payments.md) | P4 / P5 |
| **Payments** | [07](07-subscriptions-direct-payments.md) §7.6 | P4 |
| **Allocations** | [08](08-allocations-and-overrides.md) | P6 |
| **Activity** | `recent_events`: plan changes, status changes, dunning events, profile edits — with actor, reason, source (`admin`/`admin_mobile`/`scheduler`) | P1 |

Header: avatar initials, name, status chip, plan chip, and an overflow menu: **Change plan**, **Change status**,
**Edit profile**, **Remove…**. Pull-to-refresh reloads BS4. The overflow menu is hidden for the acting member.

Every value the user is likely to read out or paste (reference, email, payer, subscription id) is long-press-to-copy.
Email and phone-style fields use `url_launcher` (`mailto:`) from a trailing icon.

## 6.3 Edit

### 6.3.1 Profile (names and address) — BS5

An **Edit profile** page with: First name, Last name, Street, City, State, ZIP, Country (searchable picker over a bundled ISO 3166
list; sets both `country_code` and `country_name`, as the browser's country select does). **Email and username are shown
read-only** with a note "Sign-in identity — not editable here" (BF8, [OQ2](00-overview.md)).

- Sends `PUT /portal/api/v1/memberships/{ref}` with **only the fields that changed** plus `expected_updated_at`.
- `409` → the page keeps the user's draft, shows "This member changed elsewhere", and offers **Reload** (discarding the
  draft) or **Review changes** (re-fetch and show a diff of server-vs-draft before re-saving).
- Validation before sending: names ≤ 128; country code ↔ name consistent; nothing else is required on edit.
- Success → detail state replaced from the response; list row updated in place; Activity gains a "profile updated" event.

### 6.3.2 Plan — existing `PUT …/tier`

**Change plan** opens a sheet: current plan, a radio list of the five plans (labels from `create-options`, BS7), and a
**Reason** text field (required, 3–500 — the server's rule, enforced in the app first). The confirmation line reads
"Pro → Business. Recorded in the audit trail. This sets the plan directly; it does not change billing." and, if the member
has a subscription, a second line: "This member pays through Stripe for **Pro**. To change what they are charged, use
**Subscription → Change plan**." — because `PUT …/tier` writes `tier_source = manual` and **does not touch the provider**
(`MembershipController::updateTier`'s own docblock). Two different operations; the UI keeps them visibly separate ([07](07-subscriptions-direct-payments.md) §7.5).

Sends `{ membership, reason, expected_updated_at }`. `422 "Member is already on that plan"` is prevented client-side by
disabling the current plan. The downgrade caveat the server states — "a downgrade through this endpoint changes the column and
nothing else" (no retention engine yet, [membership_change/08](../membership_change/08-member-management.md)) — is shown as
fixed help text on a downgrade.

### 6.3.3 Status — existing `PUT …/status`

**Change status** sheet: Active / Suspended / Marked for deletion; a **close date** picker appears for the last two (optional);
sends `{ account_status, account_close_date, expected_updated_at }`. Wording per option:

| Status | Help text in the app |
|--------|----------------------|
| Active | Normal account. |
| Suspended | Recorded and audited. Whether other apps act on a suspension is decided by them. |
| Marked for deletion | **Nothing is deleted.** The status is recorded and audited; a close date is stored. Permanent removal is a separate action ([§6.6](#66-remove)). |

There is no reason field (the server has none for status; it writes the event itself). The app adds a confirmation
dialog for 2 and 3.

## 6.4 Add a member

**Route:** `POST /portal/api/v1/memberships` (`MembershipController::adminStore`). A full-screen form, sections in the
browser's order. Required and rules are copied from the browser form because **the server enforces almost none of them**
([BA5](01-current-state-audit.md)):

| Field | Rule (app-enforced) | Source of rule |
|-------|--------------------|----------------|
| Username | required; live availability via `GET …/validate-username` (debounced; shows ✓ / "taken") | browser `requiredFields`; server 422 on duplicates |
| Email | required; valid format; live availability via `GET …/validate-email` | same |
| Password | required; **≥ 8 characters, no whitespace**; a *Generate* button makes 16 random characters (letters + digits, no ambiguous glyphs); shown with a reveal toggle and a Copy button | browser lines ~2556–2565 |
| First / last name | required | `requiredFields` |
| Street, City, State, ZIP | required | `requiredFields` |
| Country | required (picker sets `country_code` + `country_name`) | `requiredFields` |
| Processor | required; picker from `create-options.processors`, default from `membership_direct_payment`'s processor ([OQ4](00-overview.md)) | `requiredFields` |
| Plan | required; default Free (0); five plans | `requiredFields` |
| Account status | default Active (1) | optional in payload |
| Discount code | optional; upper-cased before sending | browser |

Sent exactly as the browser sends it (`AdminMembershipCreationPayload`): `username, email, password, firstname, lastname,
membership, street, city, state, zip_code, country_code, country_name, processor, discount_code, account_status`. **Not
sent:** `level` (the server ignores it by design), `reference_num` (server-generated), `direct_payment`.

Outcomes:

| Response | App behaviour |
|----------|---------------|
| `201 { membership, checkpoint_session }` | **Discard `checkpoint_session` unread** (it holds live session material, [O7](01-current-state-audit.md)); open the new member's detail; show the generated password once more with Copy if it was generated, with the warning that it will not be shown again |
| `422 Username already taken` / `Email already registered` | Field error on that field; focus it |
| `502` with `code` (Checkpoint registration failed) | "Could not complete signup — Checkpoint is unavailable (`<code>`). Nothing was created." The server has logged a row in `brain_membership_checkpoint_errors`; the **Checkpoint errors** screen ([09](09-other-brain-sections.md)) shows it. The form keeps its values for a retry |
| timeout / unreachable | `unknownOutcome` ([05](05-app-architecture.md) §5.6): the app looks the member up by `username` before offering a retry, so a lost response never creates a duplicate |

The password is **not** emailed or otherwise delivered by the server today; delivering it to the new member is the
operator's job (as in the browser) — [OQ3](00-overview.md).

## 6.5 Cross-cutting

- **Stale protection** everywhere a member row is written (§6.3), via `expected_updated_at` taken from the detail
  snapshot and refreshed from each response.
- **Optimistic UI is not used.** Every write waits for the server and then renders the server's response.
- **Audit visibility:** every write made from the app appears in Activity as `source: admin_mobile` ([04](04-server-additions.md) §4.2).
- **Large text and small phones:** all forms scroll; no field is hidden behind the keyboard (`resizeToAvoidBottomInset`).

## 6.6 Remove

The user asked to "remove" members. The platform has **no deletion** today ([BA7](01-current-state-audit.md)), so removal is two
tiers, and the app is explicit about which one is in front of you.

### 6.6.1 Tier R1 — available now (P3): suspend, or mark for deletion

The overflow item **Remove…** opens a sheet with two honest choices:

1. **Suspend** (`account_status 2`) — reversible, immediate, audited.
2. **Mark for deletion** (`3` + optional close date) — "Nothing is deleted yet."

Both go through the existing `PUT …/status`. The sheet states what is *not* happening: no data is removed, the Checkpoint
identity is untouched, email and username stay reserved, and the subscription is **not** cancelled (the sheet links to
**Subscription → Cancel** when one exists — they are separate, deliberate acts; the same separation `membership_change/08` D4
makes between status and billing). On `production`, step 3 requires typing the username.

### 6.6.2 Tier R2 — real soft and hard delete (P3b, capability-gated)

Built to the contract in [`account_close/04-admin-soft-and-hard-delete.md`](../account_close/04-admin-soft-and-hard-delete.md)
§4.3, **only after** that plan ships and its completion document confirms the routes. The app shows this UI only when
`whoami.capabilities.account_close === true` (BF10); until then **Remove…** offers R1 only.

What the app implements (all under `/portal/api/v1/memberships/{ref}/…`, behind `ApiAuth` + `portal.level99` per account_close §4.2):

| Action | Request | UI |
|--------|---------|----|
| Read state | `GET /closure` | **Danger zone** card on the detail page: open closure, kind, timers, and (state 5) the step list |
| Soft delete | `POST /soft-delete { reason, options{l3_sites,l4_mail,l5_billing,notify_member}, expected_updated_at }` | Modal with the four checkboxes and defaults from the server; reason required |
| Restore | `POST /restore { reason, expected_updated_at }` | In state 4 |
| Cancel closure | `POST /closure/cancel { reason }` | In state 3 |
| **Hard delete** | `POST /closure-preview` → show table + blockers → `POST /hard-delete { confirm_username, reason, retire_identifiers, preview_token, expected_updated_at }` → **202** → poll `GET /closure` every ~3 s | **Three stages** exactly as account_close §4.6: **Preview** (spinner, step table, blockers disable everything), **Acknowledge** (two checkboxes, reason, type the username), **Execute** (progress panel with per-step status, a *Resume* button on a failed step) |
| Legal hold | `POST` / `DELETE /legal-hold { reason }` | Toggle that disables both delete buttons |

Rules the app adds on top of the server's:

- The preview token expires in 15 minutes ([account_close §4.3.2](../account_close/04-admin-soft-and-hard-delete.md)); the app shows
  a countdown and re-previews rather than sending a stale token.
- The execute button is disabled until the typed text equals the username **exactly** (the server re-checks, `AC_E_CONFIRM_MISMATCH`).
- Hard delete is never offered for the acting member (the server refuses too, `AC_E_PROTECTED`).
- The app keeps the screen awake and keeps polling while a purge is in state 5, and survives being backgrounded (it re-polls on resume).
- Each `AC_E_*` code maps to a specific message; blockers render as a list with their `message` and `detail`.

This tier is **not built in the first release**. It is specified now so that the day `account_close` P-phases land, the app
work is a single known-size phase (P3b) rather than a redesign, and so that nothing in P1–P3 has to change.

## 6.7 Acceptance for this file

- With lab2's three members: the list shows all three; searching `james2` shows one; filtering plan = Enterprise shows
  `appfactorystudio`; opening `james1` shows reference `m1xTHpRmdtH2Y`, plan Pro, payer `pay_a1b2c3d4e5f6`.
- Editing `james1`'s city changes the row in the browser SPA after a reload; the Activity tab shows `profile_updated` with
  `source admin_mobile`.
- Changing `james1` to Business with reason "lab test" shows `admin_tier_change` in the browser's data and in Activity; with
  no reason, no request is sent.
- Creating a member with a taken username shows the field error and sends no Checkpoint call; creating a valid member makes
  a row that `GET /brain/member?reference_num=` returns, and the app never logs or stores the returned session.
- `appfactorystudio`'s overflow menu has no Remove / Change plan / Change status.
- A stale edit (change the same member in the browser first) shows the stale state and never overwrites.

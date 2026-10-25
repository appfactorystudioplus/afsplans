# 04 — Brain admin: soft and hard delete

## Completion document

Not yet carried out. When it is: `complete/04-admin-soft-and-hard-delete.complete.md`.

## 4.1 What the admin gets

On a member's detail page (`brain:memberships:detail`) a new **Danger zone** card, below the
existing Account Status card, with — depending on the member's state — up to four actions:

| Member state | Buttons shown |
|--------------|---------------|
| 1 Active / 2 Suspended | **Soft delete…** · **Permanently delete…** |
| 3 Closing (member asked to leave) | **Cancel closure** · **Soft delete now…** · **Permanently delete…** |
| 4 Closed | **Restore access** · **Permanently delete…** |
| 5 Purging | *(no buttons)* a live **progress panel** and, if a step failed, **Resume** |

Plus, at any state that is not 5, a **Legal hold** toggle ([08 §8.5](08-security-and-safety.md)) that
disables both delete buttons while set.

## 4.2 Authorization

Today the membership routes sit in a group with **only** `ApiAuthMiddleware` — it accepts a Bearer
token or the `portal_auth_token` cookie and proves only "some staff user", with **no level check**
(`routes/portal.php` ~1180–1230, [AC20](01-current-state-audit.md)). `portal.level99` — the
platform-superuser gate — is **commented out** on the general Brain group (`:461–464`) and in
force on the allocation/override group (`:540–555`).

**Every closure endpoint goes in a new group carrying both** `ApiAuthMiddleware::class` **and**
`portal.level99`. Deleting a member is not something any staff token may do. The existing
status/tier/edit routes are **not** moved — this plan does not change who may edit a member.

Server-side checks that hold regardless of the UI ([08 §8.1–8.4](08-security-and-safety.md)):
the acting staff user's own member account cannot be closed by them; a protected account
refuses; a legal hold refuses; a blocker refuses.

## 4.3 Endpoints

All under `/portal/api/v1/memberships/{ref}`, in the group above. `{ref}` is
`brain_memberships.reference_num`. Bodies are JSON. **Stale-record protection** is the existing
pattern: the client sends `expected_updated_at`; a mismatch returns **409 `STALE_RECORD`**
(`MembershipController::staleResponse`, line 747).

### 4.3.1 Where these run — `www` is the door, `members` is the room ([D2, D11, D17](00-overview.md))

The **contract below is what the admin UI sees**, and it is served by `www`. But `www` runs none of
it: `MembershipClosureController` is a **thin proxy** that

1. authenticates the staff user (`ApiAuthMiddleware` + `portal.level99`, [§4.2](#42-authorization)),
2. adds the **actor** — `actor_user_id` and `actor_username` from
   `$request->attributes->get('auth_user')` — and the acting staff user's own member reference (so
   the engine can refuse a self-close, [08 §8.1](08-security-and-safety.md)),
3. forwards a **signed** request to `members` through the channel `www` already uses
   (`MembersSignature`, the `MembersEmailClient` pattern) — to
   `POST /api/internal/www/v1/account-close/{action}` in `members`' `routes/www_internal.php`,
   under the `www.internal` middleware ("sender is always WWW"),
4. relays `members`' JSON and status code unchanged.

So the **engine, the closure tables, the preview and every erase are `members`'.** The actor
travels *inside the signed body*, so `members` trusts it exactly as far as it trusts the signature:
the only sender that can authenticate on that surface is `www`, and `www` only forwards after
level-99 authentication. `members` **never** accepts an admin action from a browser or a member
token.

`www` proxies with a **short timeout** (10 s): every endpoint returns quickly. Anything slow —
the purge — is asynchronous ([§4.3.3](#433-why-the-hard-delete-is-asynchronous)); the preview is
the one long read and is given a longer timeout (60 s) and a spinner in the UI.

| Method & path | Purpose | Body | Success | Refusals |
|---------------|---------|------|---------|----------|
| `GET /closure` | The member's open closure (or the latest), its steps, and its timers | — | 200 `{ closure, steps[] }` or `{ closure: null }` | 404 unknown member |
| `POST /closure-preview` | **Dry run** — every eraser reports what it *would* remove and what would *block* | — | 200 `{ preview_token, generated_at, steps[{key, action, counts, paths}], blockers[] }` | 404 |
| `POST /soft-delete` | 1/2/3 → **4**, no timer | `{ reason (req), options{l3_sites,l4_mail,l5_billing,notify_member}, expected_updated_at }` | 200 `{ closure }` | 409 `STALE_RECORD`; 422 `AC_E_NOT_CLOSABLE_STATE`, `AC_E_PROTECTED` |
| `POST /restore` | 4 → 1 | `{ reason (req), expected_updated_at }` | 200 | 409; 422 `AC_E_NOT_CLOSABLE_STATE`, `AC_E_HOLD` |
| `POST /closure/cancel` | 3 → 1 (admin cancels a member's grace) | `{ reason (req) }` | 200 | 422 `AC_E_NOT_CLOSABLE_STATE` |
| `POST /hard-delete` | → **5**, starts the purge | `{ confirm_username (req), reason (req), retire_identifiers (bool), preview_token (req), expected_updated_at }` | **202** `{ closure }` — the purge runs asynchronously | 409 `STALE_RECORD`; 422 `AC_E_PREVIEW_REQUIRED`, `AC_E_CONFIRM_MISMATCH`, `AC_E_PROTECTED`, `AC_E_HOLD`, `AC_E_BLOCKED` (with `blockers[]`) |
| `POST /hard-delete/resume` | Re-run a stalled or failed purge from its first unfinished step | — | 202 | 422 `AC_E_NOT_CLOSABLE_STATE` (not in 5) |
| `POST /legal-hold` / `DELETE /legal-hold` | Set / clear the hold | `{ reason (req) }` | 200 | |

**Error shape** matches the existing membership controller: `{ success:false, code, message }` and,
for a blocked delete, `blockers: [{ code, message, detail }]` so the UI can list *why*.

### 4.3.2 The preview token

`POST /closure-preview` returns a `preview_token`, **minted by `members`** — an HMAC over
`(member_reference_num, member.updated_at, generated_at)`. `POST /hard-delete` **rejects** a token that does not match the
member's current `updated_at` or is **older than 15 minutes**. So an admin cannot confirm against a
preview of a member who has changed since (a new website, a new subscription) and cannot click
through a stale screen. This is [D8](00-overview.md) made enforceable rather than a UI convention.

### 4.3.3 Why the hard delete is asynchronous

A purge deletes files and databases on remote servers; it can take minutes. `POST /hard-delete`
returns **202** as soon as the closure is recorded and status is 5. **There is no queue job**
([D17](00-overview.md)): `members` sends its 202 back through the `www` proxy, then
`PurgeRunner::advance()` ([05 §5.6](05-purge-engine.md)) runs the first time-budgeted slice of the
ordered steps **in `members`' own PHP process after that response is sent**
(`dispatchAfterResponse`) — so the admin's browser is never held open — and the `members`-scheduled `account-close:tick`
([07 §7.2](07-notifications-and-scheduling.md)) carries any remaining slices, one per five-minute
tick. The UI **polls `GET /closure`** every ~3 s for step progress; a purge that spans several
ticks shows "continuing — next run within 5 minutes" between them.

## 4.4 Soft delete — the modal

A Bootstrap modal in the `modal-status bg-danger` style already used for delete-server
(`html/views/html/modules/brain/brain_http_content.html:276–301`), opened with the existing
`showModal(id)` / `hideModal(id)` (`utils/helpers.ts:213, 253`).

```
  Soft delete  <username>  (<email>)

  This will:
    ✔ block login and revoke every session and API token          (always)
    ✔ keep all data exactly as it is
    ✔ keep the email and username unavailable to new signups

  Also:
    [x] Disable their mailboxes            (L4 — default on)
    [x] Cancel their subscription(s)       (L5 — default on)
    [ ] Show a maintenance page on their websites   (L3 — default OFF: sites keep serving)
    [x] Notify the member by email                  (default on — turn off for abuse/fraud cases)

  Reason (required) [____________________________________]

                                   [ Cancel ]  [ Soft delete ]
```

Defaults come from `members_account_closure_settings.default_soft_delete_options`
([03 §3.9](03-database-schema.md)). The three options are the L3–L5 layers of
[02 §2.3](02-state-model-and-enforcement.md); L1 (identity) is not a checkbox because removing it
*is* the action.

**Reversible:** the panel afterwards shows **Restore access**. Restore sets status 1 and Delight
status normal, re-enables mailboxes the soft delete disabled, and — **deliberately** — does not
re-activate a cancelled subscription (that is the member's decision) or clear the watermark
([02 §2.6](02-state-model-and-enforcement.md)); the member logs in again and gets fresh tokens.

## 4.5 The detail page, in each state

The existing Account Status card (`#detail_account_status`, `#detail_account_close_date`,
`#membership_status_save_btn`; template `brain_memberships_detail_content.html`, handler in
`initMembershipDetailView`, `brain_membership.ts:2895`) changes as [02 §2.8](02-state-model-and-enforcement.md)
says: the select offers **1 and 2 only**; at 3/4/5 the status is shown read-only with the close
dates and a pointer to the Danger zone below.

The **Danger zone** card shows, for an open closure: its kind, requested-by and reason, the two
timers if any (`login_block_at`, `purge_after`), and — for 5 — the **step list** with a status icon
per step, the attempt count, and the last error of a failed one.

The **members list** (`loadMembershipList`, `brain_membership.ts:2646`) already prints
`ACCOUNT_STATUS_LABELS[account_status]` in its Status column; the two new labels (4 *Closed*,
5 *Purging*) render there with a badge colour. A `?status=` filter on `GET /memberships` is a small
follow-up, not required.

## 4.6 Hard delete — the flow

Three deliberate stages, so it cannot be done in one click:

**Stage 1 — Preview.** Clicking **Permanently delete…** opens the modal and immediately calls
`POST /closure-preview`. The modal shows a spinner, then a table:

```
  Permanently delete  <username>  (<email>)          ⚠ THIS CANNOT BE UNDONE

  Step            Action        What will be removed
  ─────────────────────────────────────────────────────────────────────────
  billing         cancel+anon   1 subscription (Stripe), 14 invoices anonymized
  tenants         DELETE        2 websites, 2 databases (412 MB), 2 vhosts
  dns             DELETE        3 zones, 11 subdomains
  mail            DELETE        4 mailboxes (1.2 GB)
  storage         DELETE        /mnt/node1/m1xAbC…  (18.4 GB, 9,231 files)
  app:notes       DELETE        128 notes
  app:market      ANONYMIZE     6 orders, 6 ledger entries
  parse           DELETE        7 MemberResource rows
  checkpoint      DELETE        identity + 9 satellite rows
  brain_memberships               the member row (last)

  Blockers:   (none)   — or —   ✖ 1 pending market payout ($42.10)
```

**If there are blockers, everything below is disabled** and each blocker states what to resolve
([08 §8.4](08-security-and-safety.md)). A blocker is never overridable from this modal.

**Stage 2 — Acknowledge.**

```
  [ ] I understand this permanently deletes the member and all of the data above.
  [ ] Retire this email and username so no one can register them again.   (default off)

  Reason (required)                      [_______________________________________]
  Type the username to confirm  <username>  [___________]
```

**Stage 3 — Execute.** The button enables only when both boxes are ticked, the reason is
non-empty, and the typed text equals the username **exactly** (checked again server-side —
`AC_E_CONFIRM_MISMATCH`). It posts `POST /hard-delete` with the `preview_token`. The modal
switches to the **progress panel** and polls `GET /closure`. A failed step stays visible with its
error and a **Resume** button.

**When it finishes** the member no longer exists: the panel says so, the list refreshes, and the
detail page navigates back to the list. The purge log ([03 §3.8](03-database-schema.md)) is what
remains, and it is readable from the admin under the closures view ([§4.8](#48-the-closures-view)).

## 4.7 Where the code goes

`brain_membership.ts` is already **4,357 lines**. The closure UI does **not** grow it; it goes in
a new module beside it.

| Repo / file | Change |
|-------------|--------|
| `www/resources/js/portal/admin/controllers/brain/brain_membership_closure.ts` *(new)* | The Danger-zone card, three modals, the preview table, the progress poller, the legal-hold toggle. Exports `initClosurePanel(member, helpers)` |
| `www/resources/js/portal/admin/controllers/brain/brain_membership.ts` | Import it; call it from `initMembershipDetailView` (line 2895); update `ACCOUNT_STATUS_LABELS` (2382); restrict the select ([02 §2.8](02-state-model-and-enforcement.md)); add a `postJson` beside `putJson` that carries `expected_updated_at` and understands `409 STALE_RECORD` |
| `www/resources/js/portal/admin/html/views/html/modules/brain/brain_memberships_detail_content.html` | The Danger-zone card and the three modal bodies, in the existing `modal-status bg-danger` markup |
| `www/app/Http/Controllers/Portal/Admin/MembershipClosureController.php` *(new)* | The **proxy** for the endpoints in §4.3 ([§4.3.1](#431-where-these-run--www-is-the-door-members-is-the-room-d2-d11-d17)) — authorize, add the actor, forward, relay. **A new controller**, not more methods on the 1,099-line `MembershipController`. Contains **no closure logic** |
| `www/app/Services/Members/MembersClosureClient.php` *(new)* | The signed forwarder, beside `MembersEmailClient`, reusing `MembersSignature` |
| `www/routes/portal.php` | The new level-99 group ([§4.2](#42-authorization)) |
| `www/app/Models/BrainMembershipEvent.php` | Constants ([03 §3.7](03-database-schema.md)) |
| `www/app/Http/Controllers/Portal/Admin/MembershipController.php` | `updateStatus`/`adminStore` narrowed ([02 §2.8](02-state-model-and-enforcement.md)); `logMembershipEvent` extracted so the Brain-surface status endpoint can reuse it ([§4.9](#49-audit)) |
| `members/routes/www_internal.php` + `members/app/Http/Controllers/Www/AccountCloseAdminController.php` *(new)* | The receiving end: `soft-delete`, `restore`, `cancel`, `closure`, `closure-preview`, `hard-delete`, `hard-delete/resume`, `legal-hold` (which itself asks `www` to write the `brain_memberships` columns) |
| `members/app/Services/AccountClose/MembershipLifecycle.php`, `PurgeRunner.php` *(new)* | The engine — the only writer of closure states ([02 §2.9](02-state-model-and-enforcement.md)) |

Strings are hard-coded English, matching the surrounding membership code — the admin's `i18n/*.json`
files are not used by `brain_membership.ts` today (the frontend investigation found the member
views entirely hard-coded English), and this plan does not introduce that convention here.

**Existing confirm patterns not used:** the native `confirm()` (used at `brain_membership.ts:1051,
1061, 1561, 2261, 2272, 3516`) is too weak for an irreversible action; and the hand-built
`div.modal.d-block` (lines 1760, 1820, 1885, 2078) is bypassed in favour of the template-based
modal so the markup lives in the HTML file.

## 4.8 The closures view

A read-only admin view, `brain:memberships:closures` (registered in `views/brainView.ts` beside
lines 205–292): every closure, newest first, with kind, state, member, actor, reason and timers,
and — for completed hard deletes — the purge-log row with per-step counts. It is how an operator
answers "what happened to that account" *after* the member row is gone. Backed by
`GET /memberships/closures?page=&search=` in the same level-99 group.

## 4.9 Audit

Every action writes a `brain_membership_events` row — and `brain_membership_events` is a **Brain
table**, so it is written **by `www`**, as part of the Brain-surface status call the engine makes
([02 §2.6](02-state-model-and-enforcement.md)). The engine passes the event constant, the actor
(`actor_user_id`, `actor_username`, forwarded by the admin proxy — [§4.3.1](#431-where-these-run--www-is-the-door-members-is-the-room-d2-d11-d17)),
the reason and the closure reference; `www` writes the row and the status **in one transaction**
through the existing `logMembershipEvent(Request, Membership, $event, $tierBefore, $tierAfter, array $payload, ?string $reason)`
(`MembershipController.php:769`), extracted to a service the Brain-surface endpoint can call. The
events die with the member at step `www_tables`; the **purge log** and the closure row — both in
`members_*` — are what persist.

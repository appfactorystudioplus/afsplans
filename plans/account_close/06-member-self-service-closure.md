# 06 — Member self-service closure

## Completion document

Not yet carried out. When it is: `complete/06-member-self-service-closure.complete.md`.

## 6.1 What the member experiences

A **progressive soft delete** ([D10](00-overview.md)): the account does not vanish on a click. It
moves through three phases, and the member is told, in writing, at every boundary.

```
  Request ──► CLOSING (grace, default 14 days) ──► CLOSED (retention, default 30 days) ──► PURGED
              login works                          login blocked                            gone
              can cancel with one click            data kept                                irreversible
              subscription already cancelled       only an operator can reactivate
```

| Phase | Status | The member can | Data | Ends |
|-------|:------:|----------------|------|------|
| **Closing** | 3 | Log in and use the account normally; **cancel the closure**; download what they need | kept | at `login_block_at` = request + `grace_days` |
| **Closed** | 4 | **Not log in.** Ask an operator to reactivate (a support path, not a button) | kept | at `purge_after` = `login_block_at` + `retention_days` |
| **Purged** | — | Nothing. The account no longer exists | erased | — |

The two windows are **settings** ([03 §3.9](03-database-schema.md)), not constants, and the whole
feature stays off until `self_service_enabled` is set (P3).

*OQ-C is the one question about this ladder the code cannot answer:* whether the member should be
able to use the account fully during grace, whether their websites keep serving, and whether billing
stops at the request or at the block. The defaults above — **full login, sites serve, subscription
cancelled at the request** — are the least surprising and are what the rest of this document
assumes. Changing any of them touches [§6.4](#64-what-the-request-does) only.

## 6.2 Where it lives in the members dashboard

The members app is a hash-routed SPA (`members/resources/js/views.ts`; settings routes at lines
485, 510, 521). A **Close account** section goes in the general settings view —
`/settings/general` (`resources/js/settings/settings.ts` and `templates/views/settings.html`) — as
a clearly separated block at the bottom, or, if it grows, its own `/settings/account` view
registered beside the existing settings routes.

**Owner only.** The endpoint goes in the group `members/routes/web.php` already protects with
`checkpoint.verify:primary_only` (line 116), which **rejects secondary (team) tokens** — a team
member must never be able to close the owner's account. Identity comes from
`request()->attributes->get('member_reference_num')`, set by `VerifyCheckpointToken`; it is never
taken from the request body.

### The flow

1. **Consequence preview** ([D10 of `membership_change`](../membership_change/00-overview.md) —
   the member sees the consequence before the change). The section calls
   `GET /api/v1/account/closure-preview` and shows, in plain language: the date login stops, the
   date the data is erased, the subscription(s) that will be cancelled, the number of websites,
   the storage in use, the mailboxes, and any **blocker** (an open market order, a pending payout)
   that means closure cannot start yet. The numbers come from the *same eraser previews* the admin
   sees ([05 §5.3](05-purge-engine.md)), filtered to what a member should see.
2. **Re-authentication.** The member must enter their **current password** and, if 2FA is enabled
   on the account, a **fresh 2FA code** — proof, at the moment of the request, that this is the
   owner and not an unattended browser or a stolen session. A token alone is not enough for an
   irreversible-in-effect action. `members` checks it against `auth`'s new re-authentication route
   ([§6.3](#63-the-request-is-native-the-calls-out-are-to-www-auth-and-the-apps)) and holds the
   returned proof for a few minutes only.
3. **Confirmation.** A checkbox stating the consequences and typing a confirmation word
   (`CLOSE`). Optional free-text reason ("why are you leaving") stored on the closure.
4. **Submit** → `POST /api/v1/account/closure` → the member lands on a **Closing** banner.

### While closing

Every members-dashboard page shows a persistent banner: *"Your account is scheduled to close on
&lt;login_block_at&gt;. [Cancel closure]"*. Cancelling calls `DELETE /api/v1/account/closure`,
returns the member to status 1 immediately, and re-arms nothing — but see
[§6.5](#65-cancelling-and-what-does-not-come-back).

## 6.3 The request is native; the calls out are to `www`, `auth` and the apps

With the engine in `members` ([D2](00-overview.md)), a member's request needs **no hop for its own
handling**: `members` receives it, verifies the token, re-authenticates the member, and runs
`MembershipLifecycle` itself. The earlier draft had `members` asking `www` to run the closure; that is
gone. What `members` calls *out* to is exactly what it cannot do itself ([D18](00-overview.md)):

| Call | To | Why | Channel |
|------|----|-----|---------|
| Re-authenticate the member (password, and a fresh 2FA code if enabled) | `auth` | Only `auth` can check them | A **new** `POST /checkpoint/auth/reauthenticate` `{ password, otp? }` on `auth`, presented with the member's own bearer token; it mints no session and returns a short-lived proof. (Reusing `/checkpoint/auth/login` would work but mints tokens as a side effect.) |
| Set `account_status`, with its audit event | `www` | `brain_memberships` and `brain_membership_events` are Brain tables | The new `members`→`www` Brain-surface channel |
| Cancel the provider subscription(s) | `www` | Provider SDKs are `www`'s | same |
| Send the notices | `www` | The mail-handler system is `www`'s | same |
| Block / erase the identity | `auth` | `auth` owns Checkpoint | System-key routes ([05 §5.4](05-purge-engine.md)) |

**`members` still does not write `brain_memberships` itself.** It technically could — the model has
`account_status` `$fillable` and the database is shared — and it would save a hop. It is **rejected**
(OQ-O): the Brain owns `brain_*` (`AGENTS.md §5`), and the Brain writes an event row and derives
`account_close_date` with every status change. `members` already follows this convention for
everything else it needs from Brain tables — `DomainController` creates `brain_dns_zones` **through
the Brain DNS API**, never directly ([AC25](01-current-state-audit.md)).

### The `members` → `www` channel

It exists: `members`→`www` calls go through `BrainSurfaceClient` (built for billing enforcement,
[billing/08 §8.2](../billing/08-brain-enforcement.md)). `members/routes/portal.php` still only proxies
`/portal/api/*` to `www` with the caller's headers, and the older Brain-bound calls `members` makes are
**unsigned** ([AC20](01-current-state-audit.md)). Account closure adds to what exists:

| Piece | Where |
|-------|-------|
| New methods on the existing signed client (`BrainSurfaceClient`, key `WWW_BRAIN_SURFACE_KEY`) | `members/app/Services/Www/` |
| Verification: `brain.only` + `brain.surface` on the existing `internal/brain` group, two accepted keys for rotation, nonce replay protection | `www` — already built |
| The Brain-surface endpoints — `status`, `erase/{preview\|run\|verify}`, `notices/send`, `legal-hold` | `www` — specified in [05 §5.4](05-purge-engine.md) |

`{ref}` in a path is **cross-checked against the member reference inside the signed body** — `www`
refuses a request whose path member and signed member differ. The signature covers method, path,
timestamp and a body hash, with a short clock-skew window and replay protection (a nonce cache), in the
shape `MarketSignature` already sets.

## 6.4 What the request does

All in `MembershipLifecycle` ([02 §2.9](02-state-model-and-enforcement.md)), **in `members`**, ordered
per [02 §2.9.1](02-state-model-and-enforcement.md) — `members` opens its transaction, asks `www` to
flip the gate, then commits — rather than one atomic transaction, because the gate and the process
are two writers:

| # | Action | Note |
|--:|--------|------|
| 1 | Refuse if the member is **protected** ([08 §8.3](08-security-and-safety.md)), under a **legal hold**, or has a **blocker** ([08 §8.4](08-security-and-safety.md)) — blockers gathered by the eraser previews, `www`'s and the apps' included | Returns the specific code; nothing else happens |
| 2 | Open the `members` transaction; insert the closure (`kind = self_service`, `phase = grace`), `login_block_at = now + grace_days`, `purge_after = login_block_at + retention_days`, `open_key = member`; capture `user_id`, `email_snapshot`, `username_snapshot` | [03 §3.3](03-database-schema.md) — **not yet committed** |
| 3 | **Ask `www` to set `account_status` ← 3** with `EVENT_CLOSURE_REQUESTED`; `www` derives `account_close_date` from the `login_block_at` it is given | Brain surface. If this fails, roll back step 2 and stop |
| 4 | Commit the closure row | If this fails after step 3, compensate: ask `www` to restore status 1 ([02 §2.9.1](02-state-model-and-enforcement.md)) |
| 5 | **Ask `www` to cancel the subscription(s)** at the provider (`SubscriptionActionsProvider::cancel`) | [`billing` D16](../billing/00-overview.md): cancel stops billing at once at every provider; access-end is a Direct Payments policy. OQ-C. A failure here is a **blocker at step 1 next time**, and is retried by the tick — it does not undo the closure |
| 6 | **Ask `www` to send** the **closure-requested** notice ([07](07-notifications-and-scheduling.md)) | states the dates, what is kept, what is lost, and how to cancel |
**Websites.** During grace they keep serving. The notice states plainly that the member's own
site visitors' data (form submissions, orders, customer accounts) is deleted with the site at the
purge (OQ-M). *No automated notice goes to those visitors.*

## 6.5 Cancelling, and what does not come back

The member's `DELETE /account/closure` in `members` (or the admin's `POST /closure/cancel`, proxied
by `www`) is only valid **before `login_block_at`**. `members` sets the closure `phase = cancelled`
and clears `open_key`, and **asks `www` to set status 1** with `EVENT_CLOSURE_CANCELLED` (which also
zeroes `account_close_date`), ordered per [02 §2.9.1](02-state-model-and-enforcement.md); then it asks
`www` to send the confirmation notice.

**What does *not* come back automatically:**

- **The cancelled subscription.** Cancelling closure does not resurrect billing — restarting it is
  the member's decision, on the billing page. The cancel confirmation says so.
- **Nothing else was removed** in grace, so nothing else needs restoring.

## 6.6 After the grace period — Closed

When `account-close:tick` finds `now ≥ login_block_at` ([07 §7.2](07-notifications-and-scheduling.md)),
`members` runs, in order:

1. Asks `www` to set `account_status` ← **4**; closure `phase = closed`; `blocked_at = now`.
2. The [02 §2.6](02-state-model-and-enforcement.md) revocation runs — `members` calling `auth` directly:
   watermark, Delight ban, `force_logout`, secondary sessions, L2 rows disabled.
3. **L3 and L4 per the self-service defaults** — mailboxes disabled; websites show a maintenance
   page *(OQ-C — the default here is the one that stops a closed account from doing anything
   publicly, since the member has been told and had 14 days)*.
4. `EVENT_LOGIN_BLOCKED`; the **login-blocked** notice is sent.

**Reactivation during retention** is an admin action — **Restore access** in the Danger zone
([04 §4.4](04-admin-soft-and-hard-delete.md)) — because a member who can no longer log in cannot
press a button. The login-blocked notice names the support address that can ask for it. A
self-service **"reactivate my account by email link"** flow is deliberately *not* in this plan: it
would need a token sent to an address of an account that is closed, with its own abuse cases, and is
noted as a possible follow-up.

## 6.7 Team members (secondary users)

A primary's secondary users share the primary's membership lookup (`SecondaryUserService`, lines
549, 755, 962), so **they lose access with the primary the instant it reaches status 4**
([02 §2.4.1](02-state-model-and-enforcement.md)). Nothing in this plan transfers ownership or gives
them notice of their own (OQ-L). The **closure-requested** notice to the primary asks them to tell
their team; a follow-up could send the same notice to each secondary user's address.

## 6.8 The purge

At `now ≥ purge_after` the tick starts the purge, exactly as an admin's hard delete does
([05](05-purge-engine.md)) — `phase = purging`, status **5** — except that:

- the closure options are the fixed self-service ones (`retire_identifiers = false` — OQ-D);
- the **preview and confirmation stages are skipped** — the member confirmed at request time and
  was reminded before the boundary; the **blockers** are still re-checked by every eraser's `run`
  ([05 §5.3](05-purge-engine.md)) and **a blocker at this point stops the purge and alerts an
  operator** rather than deleting past it;
- a **legal hold** set during retention stops it outright ([08 §8.5](08-security-and-safety.md)).

## 6.9 Not in this plan: data export

A closing member will reasonably want their data. **A self-service export is out of scope**
(OQ-G) — it is a feature in its own right (what is exportable, in what format, how large, where it
is delivered). The plan leaves a hook: `members_account_closures.options` may carry
`export_requested_at`, and the **closure-requested** and **login-blocked** notices reserve a line
for "how to get a copy of your data". Until that exists, the notice tells the member to contact
support *before* the login-block date.

## 6.10 Files

### `members` — the engine and the self-service flow

| File | Change |
|------|--------|
| `resources/js/views.ts` | Register the close-account view (or extend `/settings/general`) |
| `resources/js/settings/settings.ts`, `templates/views/settings.html` | The section, preview, re-auth, confirmation, and the Closing banner |
| `routes/web.php` | `GET /account/closure-preview`, `POST /account/closure`, `DELETE /account/closure` — inside the `checkpoint.verify:primary_only` group |
| `app/Http/Controllers/AccountClosureController.php` *(new)* | Re-authenticates through `auth` ([§6.3](#63-the-request-is-native-the-calls-out-are-to-www-auth-and-the-apps)), then runs `MembershipLifecycle` |
| `app/Services/AccountClose/MembershipLifecycle.php`, `PurgeRunner.php`, the `MemberEraser` classes *(new)* | The engine ([05](05-purge-engine.md)) |
| `app/Models/AccountClosure.php`, `AccountPurgeStep.php`, `IdentityTombstone.php`, `AccountPurgeLog.php`, `AccountClosureSettings.php` *(new)* | Models over the `members_*` closure tables ([03](03-database-schema.md)) |
| `app/Services/Www/BrainSurfaceClient.php` *(exists; add the closure calls)* | The signed `members`→`www` client |
| `app/Services/Checkpoint/CheckpointSystemClient.php` *(new)* | `auth`'s system-key routes ([05 §5.4](05-purge-engine.md)) |
| `routes/console.php` **and** `app/Console/Commands/AccountCloseTick.php` *(new)* | `Schedule::command('account-close:tick')->everyFiveMinutes()…` beside the existing storage/email sweeps ([07 §7.2](07-notifications-and-scheduling.md), [D17](00-overview.md)). **Not** `www/routes/console.php`, which runs on every tenant |
| `routes/www_internal.php`, `app/Http/Controllers/Www/AccountCloseAdminController.php` *(new)* | The admin actions `www` forwards ([04 §4.3.1](04-admin-soft-and-hard-delete.md)) |
| `app/Http/Middleware/VerifyCheckpointToken.php` | The `AUTH_ACCOUNT_CLOSED` status read ([02 §2.5](02-state-model-and-enforcement.md)) |
| `core/scripts/commands.sh`; `app/Services/AccountClose/RemoteCommandRunner.php` *(new)* | The four new commands and the safe runner ([05 §5.5](05-purge-engine.md)) |

### `www` — the Brain surface ([D18](00-overview.md))

| File | Change |
|------|--------|
| a new internal route group + a `members`→`www` signature middleware | [§6.3](#63-the-request-is-native-the-calls-out-are-to-www-auth-and-the-apps) |
| Brain-surface controllers | `status`, `erase/{preview|run|verify}`, `notices/send`, `legal-hold` ([05 §5.4](05-purge-engine.md)) |
| `app/Mail/AccountCloseMailer.php`, `app/Mail/modules/AccountClose/` *(new)* | The notices and their built-in fallback ([07 §7.3](07-notifications-and-scheduling.md)) |
| `resources/js/external/portal.d.ts` (and its copies) | **No change** — none of this is part of the externals API (`AGENTS.md §4`) |

### `auth`

`POST /checkpoint/auth/reauthenticate`; `POST /checkpoint/member/access`; `erase` and `erase/preview`; the Parse `memberPurge` function ([05 §5.4](05-purge-engine.md)).

# 08 — Security and safety

## Completion document

Not yet carried out. When it is: `complete/08-security-and-safety.complete.md`, recording the
result of every negative test in [09 §9.3](09-rollout-and-testing.md).

The premise: **an account deletion feature is a loaded weapon.** It permanently destroys data
across several systems on the strength of one request. Everything here exists so the request that
gets through is the intended one, from the intended person, against the intended member.

## 8.1 Who can do what

| Surface | Reached by | Guard | Refuses |
|---------|-----------|-------|---------|
| Admin closure endpoints on **`www`** ([04 §4.3](04-admin-soft-and-hard-delete.md)) | Brain staff | `ApiAuthMiddleware` **and `portal.level99`** — a new group; the existing membership group has no level check ([AC20](01-current-state-audit.md)). `www` is only a **proxy**: it adds the actor and forwards | Non-level-99 staff |
| Admin actions on **`members`** (`api/internal/www/v1/account-close/*`) | The `www` server only | The existing `www.internal` middleware — HMAC-signed, "sender is always WWW", two accepted keys ([D11](00-overview.md)). `members` **never** accepts an admin action from a browser or a member token | Unsigned, replayed or skewed calls; an admin acting on **their own** member account (the acting staff user's member reference is in the signed body); protected accounts; holds; blockers |
| Self-service request/cancel ([06](06-member-self-service-closure.md)) | The member, in `members` | `checkpoint.verify:primary_only` (owner tokens, never a secondary user's) **plus fresh re-authentication** against `auth` (password, and 2FA if enabled) | A secondary/team token; a stale session; a wrong password; protected accounts; holds; blockers |
| The Brain surface on **`www`** (`/internal/brain/*`) | The `members` server | A **new, separate key pair** (never `WWW_INTERNAL_API_KEY`, which is the opposite direction): method + path + timestamp + body hash, nonce, short skew; `{ref}` in the path **cross-checked** against the signed member. `www` additionally **validates every status transition** ([02 §2.9](02-state-model-and-enforcement.md)) and **checks the ledger** before deleting the member row ([05 §5.4](05-purge-engine.md)) | Unsigned, replayed, skewed, or path/body mismatch; an illegal transition (e.g. 5 → 1); a member-row delete while any other step is not `done` |
| `members` → `auth` (`/checkpoint/member/access`, `/erase*`, `/auth/reauthenticate`) | The `members` server | The existing `checkpoint.system` key **plus `auth`'s own veto** ([05 §5.4](05-purge-engine.md)): `erase` refuses unless the member is status 5 or already gone | A leaked or misused system key on an active member |
| `members` → satellite apps (`erase`) | The `members` server | Signed internal route per app | Anything unsigned |

**Trust is layered, not concentrated.** `www` refuses a bad status transition and a premature member
delete; `auth` refuses to erase a member who is not at 5; `members` refuses a protected account, a
hold and a blocker. **No single service's bug can, on its own, erase an active member** — which
matters more now that the engine sits in `members`, a service that is *not* the sole writer of the
tables it is deleting.
**Why `auth` has its own veto.** The identity's owner should not need to trust the caller's
judgement about who is safe to delete. `erase` reads the status itself — the same
`brain_memberships` lookup it already does on every login ([AC6](01-current-state-audit.md)) — and
proceeds only for status 5. That converts "a bug in `www`" from *"deleted an active member's
identity"* into *"a 4xx"*.

**Pre-flight.** Confirm the `checkpoint.system` middleware is actually enforced on
`/checkpoint/member/init` in this checkout — `membership_change/07-signup-flow.md` (S18) recorded
it once as unauthenticated. The new routes inherit whatever that middleware really does.

## 8.2 Wrong-target defences

- **Everything is addressed by `reference_num`**, the random `m1x…` string — never by username or
  email, and never by a name a person might mistype.
- **The hard delete requires typing the exact username** ([04 §4.6](04-admin-soft-and-hard-delete.md))
  *and* a preview token bound to that member's `updated_at` ([04 §4.3](04-admin-soft-and-hard-delete.md)).
  Two independent proofs that the operator is looking at the member they mean.
- **Self-service derives the member from the verified token**, never from the body or the URL
  ([06 §6.2](06-member-self-service-closure.md)). A member cannot name another member to close.
- **The purge re-derives its targets from the closure**, not from anything a request carried: the
  member reference, `user_id`, and snapshots are captured when the closure is *created*, under the
  request's authorization, and used unchanged after.

## 8.3 Protected accounts

Some rows must never be deletable by this feature, whatever the requester's rights.

| Protected | Because |
|-----------|---------|
| Any member with **`level ≥ 99`** — the platform-superuser sentinel already used by `portal.level99` (the seeded `appfactorystudio` account is one) | Deleting it takes the operator out of their own system |
| The members listed in `members_account_closure_settings.protected_member_references` (a small JSON array an operator maintains) | Service and integration accounts that are not people |
| **A member whose `user_id` is referenced by Brain staff/config** (`brain_users_subapps`, `brain_subapp_coordinator` `super_user_id`) | These are operator identities, not customers |

A protected member returns **422 `AC_E_PROTECTED`** from *every* closure endpoint, admin or
self-service — including soft delete and the request to close. **Only editing the setting or the
member's level lifts it**, and both are staff actions recorded in the audit trail.

## 8.4 Blockers — refusing rather than proceeding

A **blocker** is a condition under which erasing the member would harm someone else or the
platform. Each eraser's `preview` reports them; `run` re-checks them ([05 §5.3](05-purge-engine.md));
none is overridable from the UI. The list is a starting point, refined by OQ-F:

| Blocker | From | Why |
|---------|------|-----|
| Open market **orders** or **service orders** (paid, not delivered/completed) | `market` | A buyer or seller is mid-transaction |
| **Contracts / engagements** not concluded | `market` | Same |
| **Pending payouts** or a non-zero Connect balance | `market`, `laravel_processor_connected_accounts` | Money owed to or by the member |
| An **open dispute** / chargeback on any invoice | `billing` | The provider may still claw back |
| A **processor migration in progress** (`laravel_processor_migrations`) | `www` | Half-moved billing state |
| A **provider subscription that will not cancel** (provider error) | `billing` | Deleting local rows would orphan a live charge |
| A **legal hold** ([§8.5](#85-legal-hold)) | `brain_memberships` | See below |

A **warning** (shown, not blocking): past-due **unpaid invoices owed to the platform** — surfaced
so an operator can decide whether deleting is what they want (OQ-F).

For a *self-service* request the blockers are shown to the member as *"Before you can close your
account you need to…"*; for the purge that runs on the timer, a blocker that appears **stops it and
alerts an operator** ([06 §6.8](06-member-self-service-closure.md)).

## 8.5 Legal hold

`brain_memberships.legal_hold_at` ([03 §3.2](03-database-schema.md)). While set:

- hard delete refuses (`AC_E_HOLD`), and the sweep will not start a purge — including one already
  scheduled by a self-service closure;
- an in-flight purge **halts at its next step boundary**, stays at 5, and alerts;
- soft delete and self-service closure **are still allowed** — a hold preserves *data*, it does not
  keep someone logged in;
- setting or clearing it needs level 99, a reason, and writes `EVENT_LEGAL_HOLD_SET/CLEARED`.

## 8.6 Shell safety — and two existing problems this plan found

The remote commands take input that originated, at some remove, from a request. Their rules are
in [05 §5.5](05-purge-engine.md): strict validation, `realpath` confinement, quoting, exit codes.
Independently of this plan, the investigation surfaced two existing hazards:

1. **`--remove-website <sysuser> <dir>` is an injection risk today.** It runs `rm -rf $directory`
   with the path **unquoted and unvalidated** (`www/core/scripts/commands.sh:1073–1086`, called from
   `FunctionsController.php:10079`). This plan **does not use it** and does not change it, but it
   should be fixed — validate, quote, confine — as its own change.
2. **`delete_member.expect` contains a plaintext root MySQL password** and a
   hard-coded host — and **it is in two repositories**, `www/core/scripts/` **and**
   `members/core/scripts/` (the engine's own repo) ([AC1](01-current-state-audit.md)). **Rotate that credential now**, whether or not
   this plan proceeds; it is in version control, so treat it as disclosed. **Both copies** should be
   deleted once the purge engine exists, not reused.

New commands take **no secret in `argv`** (visible to every local user in the process list): the
tenant-database drop reads its credentials from stdin or the environment
([05 §5.5](05-purge-engine.md)).

## 8.7 Races

| Race | Defence |
|------|---------|
| Two admins act at once; double-click; a member and an admin both change state | Every transition runs under `lockForUpdate` on the closure/member row and re-checks the *from* state ([02 §2.9](02-state-model-and-enforcement.md)); the stale-record `expected_updated_at` protocol ([04 §4.3](04-admin-soft-and-hard-delete.md)) |
| **Restore vs. purge start** | Both start from status 4 and are serialized by the lock; whichever commits first wins, the other returns `AC_E_NOT_CLOSABLE_STATE` |
| **Two purge runners** | One runner per closure by `Cache::lock` + row lock ([05 §5.6](05-purge-engine.md)) |
| **Signup with the identifiers of a member mid-purge** | The `brain_memberships` row is deleted **last** and Brain's availability check runs **first** ([AC12](01-current-state-audit.md)); the identity is deleted just before the row, so the address is never free in Checkpoint while still taken in Brain, and never the other way round |
| **A live token or background job writing after status 4/5** | The gate and the watermark ([02 §2.4–2.6](02-state-model-and-enforcement.md)); scheduled work is taught to skip 4/5 ([05 §5.7](05-purge-engine.md)) |
| **A member re-requesting closure repeatedly** | At most **3 closure requests per 30 days**; only one open closure at a time (`open_key` unique, [03 §3.3](03-database-schema.md)) |
| **Reusing a Checkpoint id** | `ext_member_users` ids are auto-increment. The retained watermark row is kept **35 days** (longer than the 30-day refresh-token and SSO-cookie life, [AC7](01-current-state-audit.md)) and then pruned, so it can never hit a stranger even if the engine were ever to reuse an id. **Confirm** whether MyISAM here can reuse a deleted maximum id; the 35-day rule makes the answer harmless either way |

## 8.8 Information exposure

- **`AUTH_ACCOUNT_CLOSED` is returned only after valid credentials** ([02 §2.4.1](02-state-model-and-enforcement.md)),
  so it never tells an anonymous prober that an address belongs to a closed account.
- **The availability checks stay indistinguishable.** A soft-deleted, a retired and an active
  identifier all answer "taken" — nothing says *closed*.
- **Errors carry codes and generic messages**, never table names, paths or SQL. The detailed
  failure is in the closure's `last_error`, readable only at level 99.
- **The purge log and tombstones hold no plain identifier** — keyed HMACs only
  ([03 §3.5, §3.8](03-database-schema.md)); the key is `ACCOUNT_CLOSE_HMAC_KEY`, kept in the
  `_env` profiles ([07 §7.5](07-notifications-and-scheduling.md)) and never in the repository.
- **Logs use the closure reference and the member reference**, never the email or username.

## 8.9 What a purge does **not** erase — stated plainly

A hard delete removes **live** data. It does **not**, and this plan does not pretend it does:

- **Database backups and server snapshots** — they age out on their own schedule. *Document the
  backup rotation period alongside this feature* so "how long until it is really gone" has an
  answer.
- **Application logs** — e.g. `www/storage/logs/laravel.log` (already over 60 MB) can contain
  addresses from earlier `Log::info` lines (`events.confirmation`, `ecommerce.order_receipt`, …).
  Log rotation governs those.
- **The payment provider's own copy** of a customer and its charges (OQ-E).
- **Mail already delivered** to third parties, including the notices this feature sent.
- **The purge log**, by design — random reference, dates, actor, counts, keyed hashes.

If a regulator-grade erasure guarantee is a requirement, these are the items it adds; they are
noted here so that decision is made deliberately.

## 8.10 Audit integrity

- `brain_membership_events` is **append-only** (`UPDATED_AT = null`); nothing in this plan updates
  a row in it.
- The **closure row and the purge log survive the member** and are the record of every
  irreversible act: who, when, why, and per-step outcomes ([03 §3.3, §3.8](03-database-schema.md)).
- Both are readable only at level 99 ([04 §4.8](04-admin-soft-and-hard-delete.md)) and have **no
  delete endpoint anywhere in this plan.**

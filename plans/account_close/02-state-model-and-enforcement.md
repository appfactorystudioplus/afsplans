# 02 — State model and enforcement

## Completion document

Not yet carried out. When it is: `complete/02-state-model-and-enforcement.complete.md`.

## 2.1 The lifecycle

```
                       admin soft delete                admin restore
      ┌────────────────────────────────────────┐   ┌──────────────────────┐
      │                                        ▼   │                      │
   ┌──┴──┐  member requests closure   ┌────────┐   │  grace expires   ┌────┴───┐   retention expires   ┌─────────┐
   │  1  │ ─────────────────────────► │   3    │ ──┴────────────────► │   4    │ ────────────────────► │    5    │──► row gone
   │ Act │ ◄───────────────────────── │Closing │                      │ Closed │                       │ Purging │
   └──┬──┘   cancel (member or admin) └────────┘                      └────┬───┘                       └────┬────┘
      │                                                                    ▲                                │
      │ admin hard delete (blocks first, then purges) ─────────────────────┴───────────────►  5 ────────────┘
      │
      │  ┌─────┐
      └─►│  2  │  Suspended — unchanged by this plan; may enter 3, 4 or 5 like an Active member
         └─────┘
```

- **3 Closing** and **4 Closed** are the only reversible destinations. **5 Purging is one-way**:
  a purge that stops part-way stays in 5 and resumes ([05 §5.6](05-purge-engine.md)).
- An **admin soft delete** goes 1/2/3 → **4** and *stays there* — no timer ([D4](00-overview.md)).
- A **self-service closure** goes 1 → 3 → 4 → 5 on two timers ([D10](00-overview.md)).
- An **admin hard delete** never waits: it performs the block (step 0) and proceeds to 5.

## 2.2 The status values

| Value | Name | Login | Existing tokens | Data | Timer | Reversible | Entered by |
|------:|------|-------|-----------------|------|-------|-----------|-----------|
| 1 | Active | yes | valid | kept | — | — | signup; restore; cancel |
| 2 | Suspended | yes *(unenforced today — OQ-J)* | valid | kept | — | yes | admin `PUT /status` (unchanged) |
| **3** | **Closing** | **yes** | valid | kept | `login_block_at` | yes — member or admin | self-service request |
| **4** | **Closed** | **no** | revoked | kept | `purge_after` (self-service only; **null** for an admin soft delete) | admin only | admin soft delete; grace expiry |
| **5** | **Purging** | **no** | revoked | being erased | — | **no** | admin hard delete; retention expiry |

Two facts make this safe to ship in stages:

1. **3 changes nothing for anyone who is already at 3.** Login still works, so any live row an admin
   set to "Marked for deletion" before this plan keeps working (OQ-I still asks the lab to confirm
   how many there are).
2. **Only 4 and 5 are login-blocking.** Enforcement ([§2.4](#24-where-access-is-denied)) tests
   `status ∈ {4, 5}`, not `status ≠ 1`, which is what keeps 2 and 3 untouched.

`account_close_date` (existing, a `date`) becomes **the date login is/was blocked** — `login_block_at`
cast to a date, kept in step with the new column for the existing admin screen and
`showAfsConnect`. It is derived, never written independently ([03 §3.2](03-database-schema.md)).

## 2.3 What each state takes away

"External access" is not one thing. This is the matrix the two soft-delete flavours act on; each
row is one *access layer* with the mechanism that removes it.

| Layer | What it is | Mechanism | Admin soft delete (4) | Closing (3) | Closed (4, self-service) | Purging (5) |
|-------|-----------|-----------|:---:|:---:|:---:|:---:|
| **L1 Identity** | Login, refresh, SSO, 2FA, `verifyToken`; secondary users | `attachMember` gate + watermark + Delight ban ([§2.4–2.6](#24-where-access-is-denied)) | **off** | on | **off** | off |
| **L2 API access** | `afsconnect_api_tokens`, Connect service keys, market/coordinator tokens | Status check in the shared verifiers; token rows disabled | **off** | on | **off** | off, then deleted |
| **L3 Tenant sites** | The member's published websites and their vhosts | Serve a maintenance page; do **not** remove files | **option** *(default: keep serving)* | on | **off** *(default)* | removed |
| **L4 Mail** | `users_email` mailboxes, `vmailbox` routing | `users_email.status = 'disabled'`; routing kept | **option** *(default: disable)* | on | **off** | removed |
| **L5 Billing** | Provider subscriptions, payer customers | `SubscriptionActionsProvider::cancel` | **option** *(default: cancel)* | **cancelled at request** *(OQ-C)* | cancelled | cancelled; payer anonymized |

- L1 is the definition of "removes external access" and is **not optional**.
- L2 follows L1 automatically — an API token issued to a closed member must stop working.
- **L3–L5 are the admin's choice on a soft delete** — three checkboxes in the modal, defaulted as
  shown ([04 §4.4](04-admin-soft-and-hard-delete.md)). *Whether a soft-deleted member's website
  should keep serving is a product decision, not an engineering one*, which is why it is a switch
  and its default is the least destructive one.
- Self-service defaults are fixed by [06](06-member-self-service-closure.md), not chosen per case.

## 2.4 Where access is denied

The decision ([D3](00-overview.md)): **one lookup that every path already runs**, plus the two
paths that skip it, plus belt and braces.

### 2.4.1 The gate — `auth`

`MemberLookupService::fetchByUserId` (`auth/app/Services/Checkpoint/MemberLookupService.php:23–33`)
is `SELECT * FROM brain_memberships WHERE user_id = ?`. `AuthenticationService::attachMember`
(lines 1045–1107) consumes it and today throws `AUTH_MEMBER_NOT_FOUND` /
`AUTH_MEMBER_LOOKUP_FAILED`. **Add a third outcome:** when the row's `account_status` is 4 or 5,
throw a new **`AUTH_ACCOUNT_CLOSED`** (HTTP 403).

Because `attachMember` is called from `login`, `loginWithUsername`, `ssoExchangeToken`,
`completeTwoFactorAuth` and `verifyToken`, and because `SecondaryUserService` shares the lookup
(lines 549, 755, 962), **one change covers all of them**, including a team member acting under a
closed primary.

**No enumeration.** `login()` checks the password *before* `attachMember` runs
(`AuthenticationService.php:147, 197`), so `AUTH_ACCOUNT_CLOSED` is only ever returned to someone
who presented **valid credentials** — it never reveals whether an address belongs to a closed
account to an anonymous prober.

### 2.4.2 The two paths that skip the gate — `auth`

| Path | Today | Change |
|------|-------|--------|
| `refreshToken()` (lines 451–474) | watermark only → a closed member can refresh for **30 days** ([AC7](01-current-state-audit.md)) | Call a shared `assertAccountOpen($userId)` (the same lookup, same code) before minting the new pair. |
| `getSsoTicketFromCookie` / `decodeValidSsoCookie` (lines 755–846) | watermark only | Same assertion, so silent SSO fails at decode rather than one hop later at exchange. |

`assertAccountOpen` is extracted from `attachMember` so all seven call sites share one implementation.

### 2.4.3 Belt and braces — `auth`

Neither of these can be the gate ([AC5](01-current-state-audit.md)); both make the gate harder to route around.

1. **Delight `status`.** On close, set `ext_member_users.status` to Delight's *banned* value.
   Confirm the exact constant against `auth/vendor/delight-im/auth/src/Status.php` before writing
   it — this plan does not restate the vendor's numbering from memory. To make Delight *honour* it,
   pass Checkpoint's own `onBeforeSuccess` callable to `login()` (`Auth.php:302, 328`) that vetoes
   when `status ≠ normal`. That puts a second, independent check on the login path.
2. **`force_logout` bump.** Cheap, harmless, and it does kill any Delight PHP session should one
   ever exist. It does *nothing* to a JWT and must not be described as revocation.

### 2.4.4 Suspended (2) — deliberately not enforced here

`assertAccountOpen` is the place status 2 *would* be enforced — one added value in one set. It is
left out because suspension is `membership_change` D5: **freeze, do not downgrade**, with read-only
bands and a preserved purge clock (`membership_change/00-overview.md:111`). Deciding that a
suspended member cannot log in at all is a separate product decision (OQ-J).

## 2.5 The lag, and closing it

A blocked lookup stops the *next uncached* verification. Already-issued access tokens
(**3600 s**, `auth/config/checkpoint.php:96`) are stateless HS256 JWTs with no per-token table, so
the honest worst case is set by the verifiers' caches ([AC9](01-current-state-audit.md)):

| Verifier | Caches a good token | Worst case after close | Closed by |
|----------|--------------------|------------------------|-----------|
| `auth` itself (`/token/verify`, `/validate`) | not cached — live lookup | **0** | the gate, §2.4.1 |
| shared `CheckpointTokenValidator` (notes, flow, pdf_editor, dream_graph, coordinator) | `checkpoint.cache.success_ttl`, default **60 s** | **≤ 60 s** | accepted; also the watermark |
| `members` `CheckpointTokenVerifier` | remaining token life, **≤ 3600 s**, key `sha256(token)` (cannot be purged per user) | **≤ 1 h** | **add a status read in `VerifyCheckpointToken`** |
| `market` verifier | same shape | **≤ 1 h** | **same** |
| Parse `allocConsume` / `allocRelease` | resolved from a validated token | follows the verifier | the gate |

**The fix for members and market** (the two that can lag an hour): after the token verifies,
read `brain_memberships.account_status` for the resolved member and refuse 4/5 with
`AUTH_ACCOUNT_CLOSED`. `members` already reads `brain_memberships` through
`App\Models\Brain\Membership`. Cache that read for **≤ 30 s** keyed by `member_reference_num` —
*not* by token — so one purge is 30 s from effective everywhere without a per-request query. The
30 s figure is a starting default, tuned in P0.

The watermark row ([§2.6](#26-revocation-at-the-moment-of-close)) closes the rest: any token
issued before it is refused by `auth` regardless of cache state once `auth` sees it.

## 2.6 Revocation at the moment of close

When a member enters 4 or 5, the **`members` engine** does the following, in this order:

1. `brain_memberships.account_status` ← 4 or 5 (**first** — it is the gate). `brain_memberships` is a
   Brain table, so the engine **asks `www` to write it** through the Brain surface
   ([D18](00-overview.md), OQ-O): one signed call that flips the column, derives
   `account_close_date`, and writes the `brain_membership_events` row with the actor and reason,
   atomically on the Brain side. The engine never writes `brain_memberships` itself.
2. `checkpoint_primary_logout_watermarks` ← `(user_id, invalidated_at = now)`. Today the writer,
   `setPrimaryLogoutWatermark`, is **private** (`AuthenticationService.php:590`,
   [AC10](01-current-state-audit.md)). Expose it as `revokeAllTokensForUser(int $userId)` and reach
   it through `auth`'s `access` endpoint, which **`members` calls directly** with the Checkpoint
   system key ([05 §5.4](05-purge-engine.md), OQ-P).
3. `ext_member_users.status` ← banned; `force_logout` += 1.
4. Secondary users: their tokens ride the same lookup, so nothing extra — but their **secondary
   sessions** (`checkpoint_secondary_users_sessions`) are marked revoked for tidiness.
5. L2 rows disabled (`afsconnect_api_tokens`).

**On restore** (4 → 1): status ← 1, Delight status ← normal. **The watermark is deliberately not
deleted** — tokens issued before the close stay dead, and the member simply logs in again and gets
new ones. Restore therefore never resurrects a stolen session.

## 2.7 Identifier reservation

### Soft delete (and Closing, and Closed) — reserved for free

The `brain_memberships` row stays, so `username`, `email` and `reference_num` remain unique-indexed
(`create_brain_tables.php:224–226`). The three signup consultations — `store()`,
`validate-username`/`validate-email`, `reserve-reference` ([AC12](01-current-state-audit.md)) —
all ask `Membership::exists()` and therefore keep answering "taken".

Three gaps to close in P0:

| Gap | Fix |
|-----|-----|
| **Username is not unique in Checkpoint** (`ext_member_users` unique on `email` only; `register()`, not `registerWithUniqueUsername()`, [AC11](01-current-state-audit.md)). Reservation depends entirely on Brain's check being first. | Verify whether `POST /checkpoint/auth/register` is reachable other than through `CheckpointServerClient`. If it is, add a reservation check there. **Pre-flight item, [09 §9.1](09-rollout-and-testing.md).** |
| **Comparison normalization.** | Confirm the columns' collation is case-insensitive and that `validateEmail`/`validateUsername` (lines 797–837) lower-case and trim before comparing; add a test that `Bob@X.com` blocks `bob@x.com`. |
| **`store()` registers with Checkpoint *before* it inserts the row** ([AC12](01-current-state-audit.md), S28). | Not changed here — the existence check already runs first — but a retired identifier ([below](#hard-delete--released-or-retired)) must be checked **at that same first point.** |

### Hard delete — released, or retired

By default ([D5](00-overview.md), OQ-D) a purge **releases** the identifiers: every row holding
them is gone, so anyone may register them again.

The admin may instead choose **"retire these identifiers"** in the hard-delete modal. That writes
one row per identifier to `members_identity_tombstones` ([03 §3.5](03-database-schema.md)) —
`kind` (`email`/`username`), an **HMAC-SHA256 of the lower-cased, trimmed value** keyed by a
server secret (so the table cannot be reversed into a list of addresses by a dictionary), the
closure id, and `released_at` (null = permanent). The three signup checks gain one more test —
"is the HMAC in `members_identity_tombstones` with `released_at IS NULL`" — placed **before**
Checkpoint registration, alongside the existing `exists()` check.

## 2.8 Changes to the existing status endpoint and UI

| Where | Today | After |
|-------|-------|-------|
| `PUT /memberships/{ref}/status` → `updateStatus` (`MembershipController.php:639–673`) | `account_status` `in:1,2,3`; `account_close_date` `nullable|date`; writes both | **`in:1,2` only.** `account_close_date` is no longer client-writable (it is derived, §2.2). A request for 3, 4 or 5 returns 422 `AC_E_USE_CLOSURE_ENDPOINT`. |
| `adminStore` (`:562`) | `isset($data['account_status']) ? (int) … : 1` — any integer | Same `in:1,2` validation; default 1. |
| `brain_membership.ts` `ACCOUNT_STATUS_LABELS` (lines 2382–2386) and the `#detail_account_status` select | 1 Active, 2 Suspended, 3 Marked for deletion | Labels gain 4 Closed, 5 Purging (**display only**); the select offers **only 1 and 2**; a member at 3/4/5 shows the state read-only with a link to the closure panel ([04 §4.5](04-admin-soft-and-hard-delete.md)). |
| `brain_membership_events` | `EVENT_ACCOUNT_STATUS_CHANGED` | Unchanged; new event constants for closure ([03 §3.7](03-database-schema.md)). |

## 2.9 Allowed transitions

Enforced in one class, **`MembershipLifecycle`, in `members`** — no controller writes `account_status`
for a closure state directly, and nothing in `members` writes it *at all*: the write is a request
to the Brain surface ([§2.6](#26-revocation-at-the-moment-of-close), [D18](00-overview.md)).

| From | To | Trigger | Actor | Conditions |
|------|----|---------|-------|-----------|
| 1, 2 | 3 | request closure | member | re-auth passed ([06 §6.2](06-member-self-service-closure.md)); not protected; no legal hold |
| 3 | 1 | cancel closure | member or admin | before `login_block_at` |
| 3 | 4 | grace expired | sweep | `now ≥ login_block_at` |
| 1, 2, 3 | 4 | soft delete | admin | not protected |
| 4 | 1 | restore | admin | not 5; not under a hold that forbids it |
| 4 | 5 | retention expired | sweep | `now ≥ purge_after`; no hold; no blocker |
| 1, 2, 3, 4 | 5 | hard delete | admin | preview loaded ([D8](00-overview.md)); typed confirmation; no protected/hold/blocker |
| 5 | 5 | resume | sweep or admin | ledger has an unfinished step |
| 5 | — | complete | purge | last step deletes the `brain_memberships` row |
| 5 | any other | — | — | **forbidden** |

Every transition writes a `brain_membership_events` row with actor and reason (by `www`, as part of
the status write) and updates the closure record ([03 §3.3](03-database-schema.md)) in `members`.

### 2.9.1 Two databases' worth of state — ordering and compensation

The gate lives in `brain_memberships` (written by `www`) and the process lives in
`members_account_closures` (written by `members`). They are the same shared MySQL database but
**two writers with no shared transaction**, so a transition is ordered rather than atomic:

1. `members` opens a DB transaction and inserts or updates the closure row — **not yet committed**.
2. `members` asks `www` to write the status (the gate flips; the event is written).
3. `members` commits the closure row.

**If step 2 fails** the closure transaction rolls back and nothing changed — the member is untouched.
**If step 3 fails after step 2 succeeded** (rare: the connection dropped) `members` makes a
compensating call asking `www` to restore the previous status. **If even that fails**, the
reconciliation below catches it.

**Reconciliation, every tick** ([07 §7.2](07-notifications-and-scheduling.md)): any
`brain_memberships` row at status 3, 4 or 5 with **no open closure** — an admin's leftover
"Marked for deletion" from before this feature, a failed compensation, a manual edit — raises an
**operator alert and is left exactly as it is.** The engine never auto-corrects a status it did not
set: an unexplained 4 might be someone's deliberate act, and an unexplained 5 must never be
"fixed" into 1.

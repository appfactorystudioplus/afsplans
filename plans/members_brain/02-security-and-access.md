# 02 — Security and Access (P0)

Ships first, alone, on its own branch. Nothing else in this plan is safe to build on top of an
API that never asks who is calling.

## 2.1 The shape of the problem

This screen edits **other people's** quotas. It reads a Parse object addressed by
`member.parse_alloc` for a member the operator selected from a list, and writes numbers back.

Three things protect it today:

1. The sidebar link is hidden unless `member.level == 99` — a CSS property (**BR3**).
2. The route group is wrapped in `portal.cors`, which is `return $next($request);` (**BR1**).
3. Nothing else.

There is no third item. The Brain admin API answers an unauthenticated `curl`, and the same
group carries `GET /members`, DNS zone management and HTTP tenant management (**BR2**).

## 2.2 The middleware already exists and is applied to nothing

`PortalAdminAuthMiddleware` is written, and registered in `bootstrap/app.php:26` as
`portal.admin.auth`. **`grep -rn "portal.admin.auth" routes/` returns zero results.**

It validates a `portal_auth_token` against `laravel_user_tokens` including expiry, which is the
hard part, and then does two things wrong for an API:

```php
if (!$valid) {
    return redirect('/portal/admin/login');     // an API caller gets a 302 and an HTML page
}
return $next($request);                          // and nothing knows WHO the caller is
```

So P0 is not "write authentication". It is:

1. **Apply it.**
2. **Make it answer 401 JSON** for `api/v1/*` paths rather than redirecting.
3. **Resolve the principal** onto the request, so authorisation has something to read.

```php
// AFS_BRAIN_ALLOC_MODIFIER_ADDITION — plans/members_brain/02-security-and-access.md §2.2
//
// The token already resolves to laravel_user_tokens.user_id. Carrying the
// resolved membership row on the request is what turns authentication into
// something authorisation can build on: today the middleware proves SOMEONE is
// signed in and discards which someone, so every handler downstream has to
// trust its parameters.
$token   = $request->bearerToken() ?? $request->cookie('portal_auth_token');
$userTok = UserToken::with('user')->where('token', $token)->…->first();
$member  = BrainMembership::where('user_id', $userTok->user_id)->first();

$request->attributes->set('auth_user',   $userTok->user);
$request->attributes->set('auth_member', $member);
```

## 2.3 Split the Brain group, and gate it on level 99 server-side

```php
// routes/portal.php — AFS_BRAIN_ALLOC_MODIFIER_ADDITION
Route::prefix('api/v1/brain')
    ->middleware(['portal.admin.auth', 'portal.level99'])
    ->group(function () { /* … the existing routes, unchanged … */ });
```

`portal.level99` is a new alias, and it is four lines:

```php
/**
 * AFS_BRAIN_ALLOC_MODIFIER_ADDITION — plans/members_brain/02-security-and-access.md §2.3
 *
 * Platform-staff gate. `brain_memberships.level` is a tinyInteger defaulting to
 * 0; 99 is the platform superuser sentinel (../allocation.md §6).
 *
 * EXACTLY 99, never >= 99 — `level` has no documented ladder above 0, so it is
 * a sentinel and a `>=` comparison would silently admit any future value chosen
 * for an unrelated purpose.
 *
 * Read from the database row, never from the request: `app.window.member.level`
 * is a client-side convenience and a caller can claim any value they like.
 */
$member = $request->attributes->get('auth_member');
if (!$member || (int) $member->level !== 99) {
    return response()->json(['success' => false, 'message' => 'Not found.'], 404);
}
```

**404, not 403.** A 403 confirms the Brain admin API exists at that path to a caller who is not
allowed to know. Every other handler in this plan returns 404 for the same reason
([07](07-save-semantics.md) §7.6).

The level comes from `brain_memberships.level` (`create_brain_tables.php:235`), resolved through
`laravel_user_tokens.user_id → brain_memberships.user_id`. It is the same column `main.ts:596`
reads client-side — the difference is that this copy cannot be edited by the caller.

**The client-side check at `main.ts:596` stays.** It is a menu, and a menu that shows a link
nobody can use is worse than one that hides it. What changes is that it stops being the only
check.

## 2.4 The Parse configuration (BR13–BR16)

Three copies of one application id, two of which are wrong in different ways
([01](01-current-state-audit.md) §1.5).

### The one-character fix, first

`www/.env:209` reads `CHECKPOINT_APP_ID="…DMT22"`. The auth server that creates every registry
uses `…DMT`. **Delete the `22`.**

Until that lands, every `alloc_v2` call in `www` authenticates against an application the Parse
server does not know. This is the *"`.env.prod` Parse app-id mismatch"* already noted in
[../afs-connect/13](../afs-connect/13-step-60-findings-and-execution-plan.md), now with the two
values side by side.

**Fix the `.env`; do not "fix" the code to match it.** The hardcoded value is the correct one,
which is exactly why the mismatch has survived: the path that works is the one that hardcodes.

### Then remove the hardcoding (BR14, BR15)

```ts
// registry.ts — AFS_BRAIN_ALLOC_MODIFIER_ADDITION · AFS_ALLOC_V2_TAG
// WAS: Parse.initialize("1PM4…DMT", "YOUR_JAVASCRIPT_KEY");
//      Parse.serverURL = 'https://dashboard15-registry.appfactory.studio/parse';
//
// The literal was correct and the .env was not, which is the worst arrangement
// of the two: it works, so nothing forces the .env to be fixed, and the value
// cannot differ per environment. "YOUR_JAVASCRIPT_KEY" was a placeholder —
// alloc_v2.ts:449 documents why the second argument should simply be omitted.
```

### And route both clients through one initialiser (BR16)

`alloc_v2.ts:424` `ensureParseInitialised()` exists precisely to catch two initialisations with
different configuration — and it cannot see `registry.ts`'s call, because that one writes the
global Parse state directly instead of going through it. The guard misses the exact conflict it
was written for.

`RegistryMemberManager`'s constructor calls `ensureParseInitialised()` with the same
`applicationId`/`serverURL` `createAllocV2()` uses. Identical values are a no-op, and a future
divergence throws with a named error instead of silently re-pointing every Parse call on the
page.

**Do this in P0, not later.** It is small, and it is the difference between the app-id class of
bug being loud and being silent.

## 2.5 Every write is attributed (BR4)

An operator can zero a member's usage. Today nothing records that they did.

```php
// database/migrations/…_create_brain_alloc_audit_table.php
// AFS_BRAIN_ALLOC_MODIFIER_ADDITION · AFS_ALLOC_V2_TAG
laravel_brain_alloc_audit
    id
    member_reference_num   string(64)   index      // whose registry
    parse_object_id        string(32)              // which MemberRegistry
    actor_user_id          unsignedBigInteger index // who did it
    actor_username         string(128)             // denormalised: the row must
                                                   // stay readable after a staff
                                                   // account is renamed or removed
    mode                   enum form|json          // which editor produced it — 06
    changes                json                    // the diff: [{path, before, after}]
    reason                 string(500)  nullable   // §2.6
    parse_updated_at       timestamp    nullable   // the object version written over
    created_at             timestamp
```

Four properties, each deliberate:

- **In MySQL on the Brain, not in Parse.** Parse's `MemberRegistry` class-level permissions are
  public ([../allocation.md](../allocation.md) §11.2, **G2**), so an audit log stored beside the
  thing it audits is editable by anyone who can edit the thing it audits.
- **The diff, not the document.** Storing the whole before-and-after per save makes the table
  enormous and the interesting question — *what changed* — a manual comparison.
  [07](07-save-semantics.md) §7.3 computes that diff anyway, for the confirmation dialog; this
  writes down what the operator confirmed.
- **`mode`** distinguishes a targeted form edit from a raw JSON paste. Those fail differently
  and it is worth knowing which one preceded a problem.
- **`parse_updated_at`** records which version was overwritten, so a lost update is
  reconstructable rather than merely suspected.

The write happens **server-side**, through a new endpoint, not from the browser — a client that
logs its own writes logs the ones it chooses to.

### Which means the save stops going browser → Parse

Today the browser writes to Parse directly. That cannot be audited, so
[07](07-save-semantics.md) routes admin saves through
`POST /portal/api/v1/brain/member/{ref}/allocation`, which validates, merges, writes with the
master key, and records the audit row.

**Reads stay direct.** They are the fast path, they are already working, and a read is not the
thing that needs a witness.

## 2.6 Reason is required for a decrease

An operator increasing a counter is usually correcting a missed `consume`. An operator
**decreasing** one is giving a member room back, which is the action that costs money and the
action a member might later dispute.

So `reason` is optional on any save that only increases usage, and **required** — free text,
min 10 characters — on any save that decreases a counter or writes an override
([05](05-limit-overrides.md)). It goes in the audit row and, for overrides, on the override
itself.

This is not process theatre. It is the field that answers *"we gave them 5 extra because
support ticket 4412"* six months later, and the only moment it can be captured for free is the
moment it happens.

## 2.7 What an operator may not do

| | |
|---|---|
| Edit `objectId`, `createdAt`, `updatedAt`, `ACL` | Stripped and **hidden**, not merely stripped (**BR22**). A field that looks editable and silently is not is worse than an absent one |
| Edit their own registry through this screen | Refused. Self-service quota is not a feature. The screen loads by member reference; the handler compares it to the caller's own and 404s on a match |
| Set a counter below zero | Refused by validation ([07](07-save-semantics.md) §7.2) |
| Delete a whole container (`member_resources`, `domains`) | Refused. Emptying a container is not a repair and is never what was meant; removing one **domain entry** is, and is allowed |

## 2.8 Acceptance

- An unauthenticated `curl` to `GET /portal/api/v1/brain/members` returns **401**, not a member
  list (**BR1**, **BR2**).
- An authenticated caller whose `brain_memberships.level` is **not 99** gets **404** on every
  `api/v1/brain/*` route — including the DNS and tenant routes (**BR3**).
- A caller who sets `level: 99` in a request body still gets 404; the level is read from the
  database row.
- `portal.admin.auth` returns **401 JSON** for `api/v1/*` and continues to redirect for HTML
  routes.
- `grep -rn "portal.admin.auth" routes/` returns **more than zero** results.
- `www/.env` `CHECKPOINT_APP_ID` matches `auth/.env` `PARSE_APP_ID` **exactly** (**BR13**).
- No Parse application id appears in any `.ts` file (**BR14**).
- Initialising Parse twice with different configuration **throws** `ALLOC_V2_PARSE_ALREADY_INITIALISED`
  rather than silently re-pointing (**BR16**).
- Every allocation write produces exactly one `laravel_brain_alloc_audit` row carrying the
  actor, the diff and the overwritten `parse_updated_at`.
- A save that decreases any counter **without** a reason is rejected (§2.6).
- An operator loading their own member record sees the Allocations tab **refuse**, not render.

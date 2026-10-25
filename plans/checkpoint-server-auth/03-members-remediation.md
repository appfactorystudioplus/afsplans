# 03 — members remediation

## Completion document

When this plan step is completed, write `complete/03-members-remediation.complete.md` with
status, summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly using PHPDoc. Every route moved into the
auth group, and every route left out of it, carries a comment saying which and why.

---

members is the only app with unauthenticated, member-scoped routes. This document is the
route-by-route plan for closing that.

## 1. Current state

`routes/web.php`: 99 routes. The `checkpoint.verify` group spans `:82–190`. **32 routes
sit outside it**, of which 28 are API routes that should not be public.

```
:25   GET  /login                     ← public page shell, correct
:40   GET  /                          ← public page shell, correct
:53   GET  /dashboard                 ← public shell; client-side bootstrap redirects
:58   GET  /forgot-password           ← public page shell, correct

:62   GET  /api/storage/files              ┐
:63   POST /api/storage/upload             │ P0 — hardcoded admin login
:64   GET  /api/storage/files/{id}/download│
:65   DEL  /api/storage/files/{id}         ┘

:68   POST /api/voip/validate-credentials  ┐
:69   POST /api/voip/available-numbers     │
:70   POST /api/voip/provision-number      │ P2 — unauthenticated Twilio relay
:71   GET  /api/voip/numbers               │      no member scope, no plan enforcement
:72   PUT  /api/voip/numbers/{sid}         │
:73   DEL  /api/voip/numbers/{sid}         │
:74   GET  /api/voip/account-info          ┘

:82–190  ── checkpoint.verify group ──     ← the only correct block

:193  GET  /api/v1/member                  ← P0, ?user_id= IDOR

:195  GET  /api/v1/afs-connect/settings    ┐
:196  PUT  /api/v1/afs-connect/settings    │ P0 — ?user_id= IDOR, read AND write
:197  GET  /api/v1/afs-connect/sources     │ (sources is member-agnostic — see §4.3)
:198  GET  /api/v1/afs-connect/tokens      │
:199  POST /api/v1/afs-connect/tokens      │
:200  DEL  /api/v1/afs-connect/tokens/{id} ┘

:207–217 /api/v1/domains/* (8)             ← P0, incl. unauthenticated deletion

:218  GET  /api/v1/progress/{key}          ┐ P1
:219  POST /api/v1/progress/record         ┘
```

## 2. Target route structure

Invert the default. Public routes are an explicit, short, commented list; everything else
is inside the group. A new route added carelessly lands in the *authenticated* half.

```php
// ─────────────────────────────────────────────────────────────────────
// PUBLIC — no token required. Every entry here is a deliberate exception
// with a stated reason. Adding to this list is a security decision.
// ─────────────────────────────────────────────────────────────────────
Route::get('/login',           …);  // page shell; client-side Checkpoint bootstrap
Route::get('/',                …);  // page shell
Route::get('/dashboard',       …);  // page shell; JS redirects if signed out
Route::get('/forgot-password', …);  // page shell

// ─────────────────────────────────────────────────────────────────────
// AUTHENTICATED — identity from the verified token, never from the request.
// ─────────────────────────────────────────────────────────────────────
Route::middleware('checkpoint.member')->group(function () {
    // existing :82–190 block, unchanged
    // + /api/v1/member
    // + /api/v1/afs-connect/*      (except sources — §4.3)
    // + /api/v1/domains/*
    // + /api/v1/progress/*
    // + /api/voip/*                (§5)
    // + /api/v1/storage/*          (when cloud-storage lands)
});

// ─────────────────────────────────────────────────────────────────────
// SERVICE-TO-SERVICE — HMAC-signed, not member-scoped.
// ─────────────────────────────────────────────────────────────────────
Route::middleware('afs_connect.service')->group(function () { … });
```

The middleware name becomes `checkpoint.member` as part of the convergence in
[02](02-two-patterns.md) §4; until then the existing `checkpoint.verify` alias does the
same job for these routes.

## 3. Domains — the destructive one

`Brain/DomainController.php`. Fix first, ship first.

### 3.1 Identity

```php
// :67 and :276 — before
$memberRef = $request->query('member_reference_num') ?? session('member_reference_num');

// after
$memberRef = $this->currentMemberReferenceNum();
```

The `session(...)` half is dead code — that key is never written anywhere in the app
([01](01-platform-audit.md) §3.1.3) — so this removes a fallback that has never once
fired, not a working path.

### 3.2 Ownership on every by-reference action

`destroy()` and `update()` take a `{reference_num}` from the URL and act on it. Neither
checks who owns it. `destroy()` does not read a member reference at all.

```php
public function destroy(Request $request, string $reference_num): JsonResponse
{
    $memberRef = $this->currentMemberReferenceNum();

    // Ownership is checked HERE, against our own records, before any call to
    // Brain. Brain's /dns/zones endpoint does not know which member owns a
    // zone — it deactivates whatever reference it is given — so this is the
    // only place the check can happen.
    $website = Website::where('member_reference_num', $memberRef)
        ->where('reference_num', $reference_num)
        ->first();

    if (! $website) {
        // 404, not 403: a 403 confirms the zone exists and turns reference
        // numbers into an enumeration oracle.
        return response()->json(['success' => false], 404);
    }

    …
}
```

The same check goes on `update()`, and on any future route taking a zone or website
reference from the path.

**Why the check cannot live in Brain:** Brain's `/dns/zones` endpoint takes a reference and
a flag. It has no notion of the calling member — members is the app that knows the
ownership relation. Pushing the check downstream would require a Brain API change and a
new trust relationship; doing it here requires one query.

### 3.3 The frontend change

`resources/js/controllers/domains/api.ts:58` builds
`?member_reference_num=${encodeURIComponent(memberRef)}`. Once identity comes from the
token, that parameter is ignored — **delete it** rather than leave a value the server no
longer reads.

Good news for the cutover: `domains/api.ts` already uses `fetchWithSessionRefresh`, which
attaches `Authorization: Bearer` via `buildDefaultHeaders()`
(`checkpointHelper/static/sessionFetch.ts:189–201`). **The bearer token is already being
sent on every domains call today**, so adding the middleware does not break the page.

That must be verified for every route group before it is gated — §7.

## 4. The IDOR endpoints

### 4.1 `/api/v1/member`

```php
// MemberController.php:19 — before
$userId = $request->query('user_id');

// after
$memberRef = $this->currentMemberReferenceNum();
```

Resolve the member by `reference_num`, **not** by `user_id`. Coordinator hit this exact
issue during S0.5 and recorded the reason: `user_id` does not resolve for secondary
callers, because a secondary token's acting user id is the secondary's own while the
membership belongs to the super user.

### 4.2 `/api/v1/afs-connect/settings` and `/tokens`

`AfsConnectSettingsController.php:172` reads `query('user_id') ?? input('user_id')` — a
**write** path taking its subject from the request body. Same replacement.

### 4.3 `/api/v1/afs-connect/sources` stays public

It proxies coordinator's `GET /api/afs-connect/sources`, a static member-agnostic catalog
that coordinator deliberately left ungated for exactly this integration
([01](01-platform-audit.md) §3.3). The response carries no member data.

Keep it out of the group, in the public list, with the reason written next to it. Gating
it would break the integration for no benefit — and the S0.5 pass already made that call
once on coordinator's side.

### 4.4 `/api/v1/progress/*`

Progress keys are tracker strings for website provisioning. Move inside the group and
scope lookups to the caller's member. If a key must remain readable without a session for
a polling flow that cannot hold a token, make it a signed URL — dream_graph's
`exports/{export}/download` is the in-platform precedent — rather than leaving the route
open.

## 5. VoIP

`/api/voip/*` is a different problem from the IDOR routes and needs a decision, not just a
middleware ([01](01-platform-audit.md) §3.1.2).

The endpoints act on Twilio credentials the caller supplies and that are then held in a
Laravel session. So they do not leak member data — but they are an unauthenticated relay
to Twilio running on members' infrastructure, and they are unscoped to any member, so
`voip_numbers_count` from `checkpoint_registry_v3.json` cannot be enforced.

**Minimum:** move the group inside `checkpoint.member`. That closes the open relay and
costs nothing, because the flow is already interactive.

**Then decide** (an open question, not a decision this plan makes): should provisioned
numbers be recorded against `member_reference_num` so the plan limit can be enforced? If
yes, that is a schema plus a consume-call against alloc_v2 and belongs in its own step. If
no, the limit in the registry is decorative and should be documented as such.

## 6. Cleanups in the same pass

| Item | File | Change |
|---|---|---|
| **F9** `env()` at request time | `Brain/DomainController::brainApiUrl()` | Add `services.brain.base_url` to `config/services.php` beside the existing `services.checkpoint.base_url`; read via `config()`. Today, `config:cache` makes `env()` return `null` and the hardcoded default silently wins |
| **F10** dead session fallback | `Brain/DomainController.php:68,:277` | Delete. Never written; implies a session-auth path that does not exist |
| **F10** dead PDO builder | `StorageController.php:24–32` | Deleted with the controller (cloud-storage plan) |
| Deprecated trait | `Concerns/ResolvesMarketMember.php` | Rename to `ResolvesCheckpointMember`, keep the old name as an alias for one release ([02](02-two-patterns.md) §4.1) |

## 7. Pre-gating checklist, per route group

Adding middleware to a group that is currently open **will** 401 any caller that does not
send a bearer. Before gating each group, confirm every caller sends one.

| Group | Frontend caller | Sends bearer? | Notes |
|---|---|---|---|
| `/api/v1/domains/*` | `controllers/domains/api.ts` | ✅ `fetchWithSessionRefresh` | Verified |
| `/api/v1/member` | ? | **verify** | |
| `/api/v1/afs-connect/*` | ? | **verify** | Also called server-to-server by coordinator — check both |
| `/api/v1/progress/*` | ? | **verify** | Polling; may use plain `fetch` |
| `/api/voip/*` | ? | **verify** | |
| `/api/storage/*` | rewritten in cloud-storage | ✅ by design | |

Any caller using plain `fetch()` must be switched to `fetchWithSessionRefresh` **before**
its route group is gated, not after. That ordering is the difference between a clean
cutover and a broken page in production.

### 7.1 Server-to-server callers

`/api/v1/afs-connect/*` is reached by coordinator as well as the browser. members has no
per-member bearer to present on a backend hop, which is exactly the problem S0.5 solved in
the other direction — by adding a **signed internal endpoint**
(`afs_connect.service` HMAC) rather than leaving the route open or breaking the
integration.

Apply the same answer here: if a service-to-service caller needs one of these routes, give
it a signed internal route, not an exemption from the member gate.

## 8. Sequencing

| Step | Content | Breaking? |
|---|---|---|
| **3a** | Domains: middleware + identity from token + ownership checks on `destroy`/`update`. Frontend drops the query param | No — bearer already sent |
| **3b** | `/api/v1/member`, afs-connect settings/tokens, progress: middleware + trait. `sources` stays public | Only for callers relying on `?user_id=` — §9 |
| **3c** | VoIP: middleware. Plan-enforcement decision deferred | Verify caller first |
| **3d** | Route-file restructure into the public/authenticated/service shape (§2), plus §6 cleanups | No |
| **3e** | Storage routes join the group when cloud-storage Phase 2 lands | — |

3a first because it is the only destructive endpoint in the set.

## 9. The breaking change, managed

Endpoints today accept `?user_id=` and `?member_reference_num=`. After 3b they are
ignored. Unknown callers may depend on them.

**Log-and-ignore for one release** (decision D6):

```php
// Transitional. Accept the parameter, ignore it, and log when it disagrees
// with the verified token — that log line is the list of callers that must
// change before the parameter is removed outright.
$supplied = $request->query('member_reference_num');
if ($supplied !== null && $supplied !== $memberRef) {
    Log::warning('[auth] client-supplied identity ignored', [
        'route'    => $request->path(),
        'supplied' => $supplied,
        'resolved' => $memberRef,
        'agent'    => $request->userAgent(),
    ]);
}
```

Note what this does **not** do: it never uses `$supplied`. The parameter is inert from the
first deploy; the logging exists only to enumerate callers. Removing the parameter
entirely is a later, non-breaking cleanup.

## 10. Acceptance

| # | Criterion |
|---|---|
| M1 | Every route in `members/routes/web.php` is inside the authenticated group, the service group, or the documented public list. A test fails on anything else |
| M2 | No members controller reads `user_id` or `member_reference_num` from `query()`, `input()`, or `session()` |
| M3 | `DELETE /api/v1/domains/{ref}` returns 401 without a token, and 404 for a zone belonging to another member |
| M4 | `PATCH /api/v1/domains/{ref}` has the same two properties |
| M5 | `/api/v1/member` with another member's `?user_id=` returns the **caller's** record |
| M6 | `/api/v1/afs-connect/settings` PUT cannot write another member's settings |
| M7 | `/api/v1/afs-connect/sources` still answers unauthenticated, and coordinator's integration still works |
| M8 | `/api/voip/*` returns 401 without a token |
| M9 | The domains page works end to end after gating, with the query parameter removed from the frontend |
| M10 | `grep -rn "session('member_reference_num')" app/` returns zero matches |
| M11 | `grep -rn "env(" app/Http/Controllers/` returns zero matches |
| M12 | `config:cache` then a domains request still reaches the correct Brain URL |

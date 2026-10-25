# 01 — Platform audit, verified

## Completion document

When this plan step is completed, write `complete/01-platform-audit.complete.md` with
status, summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly using block comments (PHPDoc/JSDoc).

---

Everything below was read against the working trees on 2026-08-22. File and line
references are accurate at that commit. Nothing is inferred from documentation; where a
completion document is cited it is because the code was checked against it and agreed.

## 1. How Checkpoint authentication is supposed to work

```
browser / desktop / mobile
   │  Checkpoint SSO exchange (client-side)
   ▼
access token (JWT, HS256, 1 h)  +  refresh token (30 d)
   │  Authorization: Bearer <jwt>
   ▼
app server middleware
   │  POST auth15.appfactory.studio/checkpoint/auth/token/validate
   ▼
apps/auth  AuthController::validateToken()
   → AuthenticationService::verifyToken()          (AuthenticationService.php:371)
   → decode JWT, resolve $memberUserId
   → attachMember()                                (AuthenticationService.php:826)
   → SELECT * FROM brain_memberships WHERE user_id = ?
   ▼
{ success, data: { valid, user_id, user, expires_at,
                   user_type?, super_user_id?, member } }
   ▼
app sets request attributes; controllers read only those
```

### 1.1 Four properties of `apps/auth` every caller must handle

Verified in `apps/auth/app/Http/Controllers/Checkpoint/AuthController.php` and
`app/Services/Checkpoint/AuthenticationService.php`.

| # | Property | Consequence if ignored |
|---|---|---|
| **P1** | `validateToken()` catches every exception and responds `{success:false, data:{valid:false}}` with **HTTP 200** | `$response->successful()` is not a validity check. An app testing only the status code authenticates everyone |
| **P2** | `attachMember()` throws `AUTH_MEMBER_NOT_FOUND` **only when `isProduction()`**. Elsewhere a valid token can return `member: null` | An app trusting "valid ⇒ usable" gets an empty tenant key — which, joined into a path or a `WHERE`, becomes a shared bucket |
| **P3** | Secondary tokens resolve to the **super user's** membership (`$memberUserId = $isSecondary ? $superUserId : $decoded->sub`) | A sub-account acts on the primary member's data. Correct by design, but it must be a stated default, not a discovery |
| **P4** | Primary tokens carry **no `app_name` claim**; only secondary tokens are minted per app | An app rejecting primary tokens for `wrong_app` locks out every ordinary user. An app checking nothing accepts another app's secondary token |

### 1.2 Token characteristics

From `apps/auth/config/checkpoint.php`:

| Setting | Value |
|---|---|
| Algorithm | `HS256` |
| Access expiry | `JWT_EXPIRY` = 3600 s |
| Refresh expiry | `JWT_REFRESH_EXPIRY` = 2 592 000 s (30 d) |
| Issuer | `JWT_ISSUER` = `https://auth15.appfactory.studio` |
| Rotation | `JWT_PREVIOUS_SECRETS` + `JWT_PREVIOUS_SECRETS_EXPIRES_AT` |

### 1.3 The app-name registry

`members/resources/js/checkpoint/app-names.ts`, kept in sync with
`auth/app/Services/Checkpoint/CheckpointAppName.php`:

```
MEMBERS · COORDINATOR · NOTES_EDITOR · PDF_EDITOR · FLOW_EDITOR
GRAPH_EDITOR · GAME_EDITOR · MARKET · APPFACTORY_STUDIO
```

`MEMBERS` and `MARKET` both exist — so the two apps that do not check app scoping
(§3.1) are not blocked from doing so by a missing identifier.

## 2. The two implementations

Two independent solutions to the same problem grew up in parallel.

| | `checkpoint.verify` | `checkpoint.member` |
|---|---|---|
| Apps | members, market | coordinator, notes, dream_graph, pdf_editor, flow |
| Middleware | `VerifyCheckpointToken` | `{App}CheckpointAuthMiddleware` ×5 |
| Service | `CheckpointTokenVerifier` | `CheckpointTokenValidator` + `CheckpointIdentity` |
| Endpoint | `/checkpoint/auth/token/verify` | `/checkpoint/auth/token/validate` **or** `/checkpoint/secondary/token/validate` |
| Handles P1 | ✅ checks `data.valid === true` | ✅ checks `$body['success']` |
| Handles P2 | ✅ rejects empty `member_reference_num` | ✅ rejects `null_member` |
| Handles P3 | ✅ implicitly (auth resolves it) | ✅ explicitly, exposes `userType` |
| Handles P4 | ❌ **no app check at all** | ✅ `wrong_app` for secondary tokens; correctly skipped for primary |
| Result type | array | typed `CheckpointIdentity` value object |
| Attributes set | `member_reference_num` | `checkpoint_member_reference_num`, `checkpoint_user_id`, `checkpoint_user_type`, `checkpoint_app_role` |
| Positive cache | token lifetime (≤ 1 h) | 60 s |
| Negative cache | **5 s, deliberately** | **60 s** |

Neither is wrong so much as incomplete in different directions. The convergence design is
[02-two-patterns.md](02-two-patterns.md).

## 3. Per-app findings

### 3.1 members — the laggard

`routes/web.php`: **99 routes, 32 ungated.**

The `checkpoint.verify` group spans `:82–190` and covers freelance, asset-store,
market-notifications, and connect. Everything outside it is unauthenticated.

| Lines | Routes | Identity source | Verdict |
|---|---|---|---|
| `:62–65` | `/api/storage/*` (4) | `logInAsUserByUsername("appfactorystudio")` — `StorageController.php:35` | **P0.** Every caller is the same admin; `UserFile::where('user_id',$userId)` returns every member's files to every member |
| `:68–74` | `/api/voip/*` (7) | `session('twilio_account_sid')` | **P2**, different shape — see §3.1.2 |
| `:193` | `/api/v1/member` | `$request->query('user_id')` — `MemberController.php:19` | **P0.** Unauthenticated IDOR |
| `:195–200` | `/api/v1/afs-connect/*` (6) | `query('user_id') ?? input('user_id')` — `AfsConnectSettingsController.php:172` | **P0.** Unauthenticated IDOR, read **and** write |
| `:207–217` | `/api/v1/domains/*` (8) | `query('member_reference_num') ?? session(...)` — `Brain/DomainController.php:67,:276` | **P0.** See §3.1.1 |
| `:218–219` | `/api/v1/progress/*` (2) | none | P1 |
| `:25,40,53,58` | `/login`, `/`, `/dashboard`, `/forgot-password` | — | Legitimately public page shells |

`ResolvesMarketMember` was already fixed to read the request attribute, so the four
controllers inside the group are correct. The problem is entirely one of *coverage*.

#### 3.1.1 The worst single endpoint

```php
// members/app/Http/Controllers/Brain/DomainController.php:180
public function destroy(string $reference_num): JsonResponse
{
    Http::withHeaders(['Accept' => 'application/json'])
        ->post($this->brainApiUrl() . '/dns/zones', [
            'reference_num' => $reference_num,
            'active'        => 0,
        ]);
    return response()->json(['success' => true]);
}
```

No authentication (the route group carries no middleware), and **no ownership check** —
it does not read `member_reference_num` at all. Any unauthenticated caller who knows or
guesses a zone reference takes that domain offline.

This is the one finding in this plan that is destructive rather than disclosing, and it is
the reason [06](06-rollout-and-testing.md) sequences it first.

#### 3.1.2 VoIP is a different shape of problem

`TwilioController` reads `session('twilio_account_sid')` / `session('twilio_auth_token')`,
populated by `POST /api/voip/validate-credentials` from the request body. So a caller can
only act on a Twilio account whose credentials they already hold — it is **not** a
cross-member data leak.

What it is:

- an **unauthenticated relay** to the Twilio API running on members' infrastructure and IP
  reputation;
- **unscoped to any member**, so `voip_numbers_count` from
  `checkpoint_registry_v3.json` cannot be enforced — a member can provision unlimited
  numbers regardless of plan;
- built on a Laravel session in an app whose login never creates one (§3.1.3), so the
  session exists only for the window this flow creates it in.

#### 3.1.3 A dead fallback worth deleting

`session('member_reference_num')` is read at `DomainController.php:68` and `:277` and is
**never written anywhere in the application** — a repo-wide grep for `session()->put`,
`Session::put`, and array-form session writes of that key returns zero hits.

It has therefore never once resolved identity. Its presence implies a session-auth path
that does not exist, which is exactly what made the `ResolvesMarketMember`/`Auth::id()`
bug survive as long as it did.

#### 3.1.4 `env()` at request time

`DomainController::brainApiUrl()` calls `env('BRAIN_API_URL', …)` per request. Once
`php artisan config:cache` runs, `env()` outside a config file returns `null` and the
hardcoded default silently takes over — so a cached deployment talks to a different Brain
than an uncached one. Same class as `StorageController.php:24–32`, which builds a raw PDO
connection from `env()` on every call.

### 3.2 market — correct, and deliberately so

| Route file | Routes | Gate | Applied where |
|---|---|---|---|
| `internal.php` | 56 | `market.internal` (HMAC) | `bootstrap/app.php` at registration |
| `brain.php` | 2 | `brain.internal` | ditto |
| `engine.php` | 6 | `market.engine` (bearer) | ditto |
| `afs_connect.php` | 6 | `afs_connect.service` (HMAC) | ditto |
| `portal.php` | 64 | `web` only at group level; `checkpoint.verify` on the `api` sub-group (`:89`) | in-file |
| `web.php` | 23 | none — public storefront | — |

The portal shell being public is a **documented, reasoned decision**, not an oversight.
`bootstrap/app.php` carries the rationale: *"the page shell is public; the data is not"* —
every portal page must render for a signed-out visitor so the client-side Checkpoint
bootstrap can run and redirect; a server-side gate would 403 before that JS executes. The
comment even records that step 11 §11.1's worked example showed `checkpoint.member` on the
group and explains why that was read as a copy-paste error rather than an instruction.

Four separate credential schemes on four separate prefixes, with an explicit note that a
stolen `MARKET_INTERNAL_API_KEY` must not also unlock ledger writes. This is the strongest
routing-level auth design in the platform.

**Its one gap is P4** — `CheckpointTokenVerifier` does not check `app_name` (§3.1 of
[02](02-two-patterns.md)).

Zero header-trust references in `market/app/`.

### 3.3 coordinator

Middleware: `checkpoint.member` (`MemberCheckpointAuthMiddleware`, required app
`COORDINATOR`), plus `afs_connect.service`, `afs_connect.engine`,
`afs_connect.external_token`.

`routes/api.php`: **48 routes, 2 ungated.**

| Route | Assessment |
|---|---|
| `GET /dummy/ping` (`:36`) | Harmless; should still be labelled public |
| `GET /afs-connect/sources` (`:79`) | **Deliberate.** A static, member-agnostic catalog (`SourceRegistry::publicProjection()`) consumed server-to-server, unauthenticated, by members' `AfsConnectSettingsController::sources()`. Gating it would break that integration for no security benefit — the response carries no member data. Recorded in the S0.5 completion notes |

`CheckpointTokenValidator` here is 201 lines — 17 more than the other four. **Diffed: the
difference is entirely comments.** Logic identical.

### 3.4 notes

`NotesCheckpointAuthMiddleware`, required app `NOTES_EDITOR`.
`routes/api.php`: **25 routes, 0 ungated.** `pm` and `notes-pro` groups both gated;
`internal/afs-connect/v1` under `afs_connect.service`.

### 3.5 dream_graph

`DreamGraphCheckpointAuthMiddleware` (`GRAPH_EDITOR`), plus `tilemap.sync` for the engine
bridge. `routes/api.php`: **81 routes, 4 ungated.**

| Route | Assessment |
|---|---|
| `GET /dummy/ping` (`:27`) | Harmless |
| `GET /exports/{export}/download` (`:170`) | **Correct.** Laravel `signed` middleware; the URL is minted by gated `ExportController::store()`, so only someone with project access can obtain one |
| `GET /assets/{asset}/thumbnail` (`:187`) | ⚠️ **Known, documented exposure** |
| `GET /assets/{asset}` (`:188`) | ⚠️ same |

The asset routes carry an unusually honest in-code explanation: eight frontend call sites
load them via plain `<img src>` and unauthenticated `fetch()` and cannot attach an
`Authorization` header, so gating them would 403 every image and texture in the app rather
than close a gap. `AssetController::thumbnail()`'s docblock states plainly that asset
content *"is therefore still reachable by anyone who can guess or observe a UUID"* and
that the real fix is signed URLs threaded through all eight call sites.

Assessed in [05](05-open-exposures.md) §2. Severity is bounded by UUID unguessability —
this is capability-URL security, not an open directory.

### 3.6 pdf_editor

Two middlewares, which is the right answer to a real problem: `checkpoint.member`
(required) and `checkpoint.member.optional` (validates a bearer when present, allows the
request through without one so `DocumentController::resolveAccess()` can fall back to a
`?token=` share link).

`routes/api.php`: 4 routes, 0 ungated. `routes/web.php`: 16 routes, 2 ungated — the `/`
editor view and `GET /api/shared/{token}`, the latter labelled *"Deliberately
unauthenticated — resolves a public share link's metadata by token alone"*.

### 3.7 flow

`FlowCheckpointAuthMiddleware` (`FLOW_EDITOR`). `routes/api.php`: **11 routes, 0 ungated.**

## 4. The duplication

`CheckpointTokenValidator` exists in five repositories; `CheckpointTokenVerifier` in two.
Seven copies of one responsibility.

| App | Class | Lines | Content |
|---|---|---|---|
| coordinator | `CheckpointTokenValidator` | 201 | comments differ only |
| notes | `CheckpointTokenValidator` | 184 | **byte-identical to dream_graph** |
| dream_graph | `CheckpointTokenValidator` | 184 | **byte-identical to notes** |
| pdf_editor | `CheckpointTokenValidator` | 184 | comments differ only |
| flow | `CheckpointTokenValidator` | 184 | comments differ only |
| members | `CheckpointTokenVerifier` | — | identical to market but for namespace + config key |
| market | `CheckpointTokenVerifier` | — | ditto |

**No logic has drifted yet.** Every difference found by diffing is prose. That is the
argument for acting now rather than later: there is nothing to reconcile, so consolidation
is a mechanical change instead of a merge ([04](04-shared-validator.md)).

The S0.5g completion notes acknowledge the duplication directly — *"same file, three
copies — no shared PHP package across these deploys"* — so this is a known, accepted
trade-off being revisited, not an oversight being discovered.

## 5. The shared validator's actual logic

`notes/app/Services/Checkpoint/CheckpointTokenValidator.php`, the common variant:

```php
CACHE_TTL_SECONDS = 60;

validate($token, $requiredAppName)
  → '' token                                    ⇒ invalid('missing_token')
  → Cache::remember('checkpoint.token_validate.' . sha256($app|$token), 60, …)

validateUncached
  → tokenLooksSecondary($token)                  unverified JWT peek — routing hint only
      ? validateSecondary()                      POST /checkpoint/secondary/token/validate
      : validateNormal()                         POST /checkpoint/auth/token/validate

validateNormal
  → !$data['valid']                              ⇒ invalid('invalid_token')
  → member.reference_num === ''                  ⇒ invalid('null_member')      [P2]
  → user_id === ''                               ⇒ invalid('invalid_token')
  → resolved(userId, referenceNum, 'normal', null)

validateSecondary
  → !$data['valid']                              ⇒ invalid('invalid_token')
  → $data['app_name'] !== $requiredAppName       ⇒ invalid('wrong_app')        [P4]
  → member.reference_num === ''                  ⇒ invalid('null_member')
  → resolved(secondaryOwnUserId, referenceNum, 'secondary', appsClaim[app].role)

post()
  → config('afs_connect.checkpoint_base_url') empty ⇒ null
  → Http::timeout(8) … catch Throwable              ⇒ null
  → !$response->ok()                                ⇒ null
  → !$body['success']                               ⇒ null                     [P1]
```

Three things this gets right that are worth preserving verbatim into the shared package:

- **The cache key includes `$requiredAppName`.** The S0.5 completion notes record that the
  original engine-only version keyed on the token alone, so once a second caller existed a
  result cached for one app would be served for another. That bug was found and fixed
  during generalisation.
- **The secondary/primary split is decided by an unverified JWT peek**, explicitly
  documented as a routing hint that never grants access — a malformed token routes to the
  normal endpoint and is rejected there. Fails closed either way.
- **`post()` returns `null` on every failure path**, so a network error, a non-200, and a
  `success:false` body are indistinguishable to the caller and all mean "invalid". Fail
  closed by construction.

### 5.1 The one defect

`Cache::remember` stores whatever the closure returns — **including failures**. A
transient blip (auth restarting, a network hiccup, or the propagation delay right after a
fresh login mints a token auth has not fully committed) is cached as `invalid_token` for
the full **60 seconds**.

members' `CheckpointTokenVerifier` deliberately does the opposite, and says so in a
comment: a failed verification is cached for **5 s**, not the TTL a real success gets,
*"so a single transient failure … must not read as a minute-long outage"*.

Two implementations, opposite conclusions, both reasoned. members is right on this one.
Carried into [05](05-open-exposures.md) §4.

## 6. Dead scaffolding

The S0.5g pass deleted `dream_graph`'s `GraphProjectPolicy`, which checked
`$user->id === $project->owner_id` via Laravel's session-based `Auth::user()` — a model
that app does not use at all, with `owner_id` hardcoded `null` at creation. It looked like
authorisation and enforced nothing.

The equivalent in members is `session('member_reference_num')` (§3.1.3) and, historically,
`ResolvesMarketMember`'s `Auth::id()`. **Deleting misleading auth scaffolding is in scope
for this plan**, because the cost of leaving it is that the next reader believes a check
exists.

## 7. Header-trust: fully remediated

Scanning all seven apps for `X-User-Id`, `X-App-Role`, `X-Coordinator-Role`, and
`X-Checkpoint-User-Id`:

| App | Hits | Live code? |
|---|---|---|
| coordinator | 8 | **No** — all historical comments |
| notes | 5 | **No** |
| flow | 5 | **No** |
| dream_graph | 4 | **No** |
| members | 1 | **No** |
| pdf_editor | 1 | **No** |
| market | 0 | — |

Every reference is a docblock explaining what the code *used to* do. The header-trust
class of bug is genuinely closed platform-wide.

## 8. Summary of what remains

| # | Item | App(s) | Doc |
|---|---|---|---|
| F1 | 32 ungated routes | members | [03](03-members-remediation.md) |
| F2 | Unauthenticated domain deletion, no ownership check | members | [03](03-members-remediation.md) §3 |
| F3 | No `app_name` enforcement | members, market | [02](02-two-patterns.md) §4 |
| F4 | Two patterns, two attribute vocabularies | all 7 | [02](02-two-patterns.md) |
| F5 | Seven copies of the validator | all 7 | [04](04-shared-validator.md) |
| F6 | Asset serving by UUID | dream_graph | [05](05-open-exposures.md) §2 |
| F7 | 60 s negative caching | 5 apps | [05](05-open-exposures.md) §4 |
| F8 | Unauthenticated Twilio relay, no plan enforcement | members | [05](05-open-exposures.md) §3 |
| F9 | `env()` at request time | members | [03](03-members-remediation.md) §5 |
| F10 | Dead auth scaffolding | members | [03](03-members-remediation.md) §5 |
| F11 | `JWT_SECRET` may be a dev value in production | auth | [05](05-open-exposures.md) §5 |
| F12 | Unguarded secondary-login paths; primary-only rule enforced nowhere | afsdesktop5, afsmobile_flutter | [07](07-desktop-client-auth.md) §2.2, [08](08-mobile-client-auth.md) §2.2 |
| F13 | Plaintext token storage on the secondary path | afsdesktop5, afsmobile_flutter | [07](07-desktop-client-auth.md) §2.4, [08](08-mobile-client-auth.md) §2.3 |
| F14 | 2FA-enabled members locked out entirely | afsdesktop5, afsmobile_flutter | [07](07-desktop-client-auth.md) §2.5, [08](08-mobile-client-auth.md) §2.6 |
| F15 | No registered Checkpoint app name | afsmobile_flutter | [08](08-mobile-client-auth.md) §5 |

**Scope note.** §1–§7 above audit the seven *server* apps. The two *client* apps
(`afsdesktop5`, `afsmobile_flutter`) are audited in their own steps, because their findings
are about how a token is obtained and stored rather than how a request is authorised.

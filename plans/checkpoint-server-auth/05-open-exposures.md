# 05 — Open exposures

## Completion document

When this plan step is completed, write `complete/05-open-exposures.complete.md` with
status, summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly using block comments. Where this document
concludes "accept the risk", that conclusion belongs in the code as a comment with a date,
not only in this plan.

---

The route-coverage work ([03](03-members-remediation.md)) and the consolidation
([04](04-shared-validator.md)) leave five things unresolved. Each gets a decision here, an
owner, and a date — including the ones where the decision is "accept it".

## 1. Summary

| # | Exposure | App | Severity | Proposed | Status |
|---|---|---|---|---|---|
| F3 | No `app_name` enforcement | members, market | P1 | Fix — [02](02-two-patterns.md) §5.1 | Open — members/market not yet migrated onto the shared package ([04](04-shared-validator.md) follow-up) |
| F6 | Asset serving by UUID alone | dream_graph | P2 | Decide: sign, or accept and document | **Fixed 2026-08-23** — option B (signed cookie) + option C's headers. §2 |
| F8 | Unauthenticated Twilio relay; plan limit unenforceable | members | P2 | Gate now; enforcement decision separate | **Gated** ([03](03-members-remediation.md) §5). Plan-limit enforcement remains open by design |
| F7 | 60 s negative caching | 5 apps | P2 | Fix in the package — [04](04-shared-validator.md) §4.2 | **Fixed** — shipped in the merged validator, live in coordinator/flow/notes/pdf_editor/dream_graph |
| F11 | `JWT_SECRET` may be a dev value in production | auth | **P0 if true** | **Answer before anything else here** | **Answered and fixed** — confirmed live, rotated. §5 |

## 2. dream_graph asset serving (F6)

### 2.1 What it is

`GET /api/assets/{asset}` and `GET /api/assets/{asset}/thumbnail` are outside
`checkpoint.member`. Anyone who knows an asset UUID can fetch its bytes.

This is documented in the code, honestly and in detail. `AssetController::thumbnail()`'s
docblock states that asset content *"is therefore still reachable by anyone who can guess
or observe a UUID"*, names the eight frontend call sites that make gating impractical, and
records that the real fix is signed URLs.

### 2.2 Why it is not simply a bug

Eight call sites load these URLs through plain `<img src>` and unauthenticated `fetch()`:
`image.tsx`, `AssetPanel.tsx`, `AssetPicker.tsx`, `tilemapRef.tsx`, `PresentationMode.tsx`,
`NewTilesetDialog.tsx`, `tileset.ts`, and the asset picker's preview path. **An `<img>` tag
cannot attach an `Authorization` header.** Gating the route would 403 every image and
texture in the application.

So this is a design constraint, not an oversight — which is exactly why it was left,
labelled, rather than half-fixed.

### 2.3 Actual severity

This is **capability-URL** security: the URL *is* the credential. A v4 UUID is not
guessable at any practical rate, so the exposure is not "anyone can enumerate assets". It
is that the URL leaks through the ordinary channels URLs leak through:

- `Referer` headers to third parties when an asset is embedded off-site
- browser history and profile sync
- shared screenshots, support tickets, pasted links
- proxy and CDN access logs
- anything that captures a DOM snapshot

For a design tool holding client artwork and game textures, "URL known ⇒ content readable,
forever, by anyone" is a real if bounded property.

### 2.4 Options

| Option | Cost | Result |
|---|---|---|
| **A. Signed URLs** — mint short-lived signed asset URLs server-side, thread through all 8 call sites. The app already does this for `exports/{export}/download` | Highest. Touches 8 frontend files, needs a mint endpoint, needs re-signing for long sessions and cached DOM | Closes it properly |
| **B. Signed cookie scoped to `/api/assets`** — issue on authentication; the browser attaches it to `<img>` requests automatically | Moderate. No frontend change at all. Needs cookie-domain care and does not help non-browser clients | Closes it for browsers |
| **C. Accept and document** — keep as capability URLs, add `X-Robots-Tag: noindex`, `Referrer-Policy: no-referrer`, and a short `Cache-Control` | Lowest | Reduces leak channels; does not close it |
| **D. Gate the route** | Low code, breaks the app | Not viable — §2.2 |

**Recommendation: B, with C's headers applied regardless.** Option B is the only one that
closes the browser case without editing eight call sites, and the mitigating headers in C
are worth setting whichever option is chosen. Option A remains correct for any future
non-browser consumer.

This is a **product decision about dream_graph**, not something the auth plan should
decide unilaterally — it trades engineering time against a bounded, documented risk.
Record the outcome, with a date, in `AssetController`'s existing docblock.

### 2.5 Decided and implemented — 2026-08-23

**Option B, plus C's headers, exactly as recommended.** Before implementing, checked
whether the Joystick Engine — a non-browser client — is a hidden consumer of this route
(`option B "does not help non-browser clients"` above would matter if so). It is not:
read `JoystickEngine_v2/plan/steps/061_afs_connect_engine.md` and the engine's own
`EngineConfig.json` shape, which show the engine only ever calls dream_graph's
`tilemap-sync-tokens` and `tilemaps/{id}/export|import` endpoints — never
`/api/assets/*`. Confirmed directly in `TilemapSyncController::export()`: it returns the
full tilemap ZIP bytes in the response body, with textures/tilesets embedded inside,
over the already-authenticated `tilemap.sync:read` bridge. The engine never fetches an
individual asset by UUID, so option B has no non-browser consumer to account for here.

Implementation:

- `IssueAssetAccessCookie` middleware, added to the main `checkpoint.member` route
  group — issues (refreshes on every request) a short-lived, HMAC-signed, httpOnly
  cookie scoped to `/api/assets`. Written directly onto the response (not
  `Cookie::queue()`, which depends on `AddQueuedCookiesToResponse` being in the stack —
  it isn't, for this app's API routes).
- `VerifyAssetAccessCookie` middleware guards `GET /api/assets/{asset}` and
  `.../thumbnail` — accepts either a valid Checkpoint bearer (any future non-browser
  caller) or the signed cookie (what the 8 real browser call sites rely on, attached
  automatically, zero frontend changes).
- Mitigating headers (`X-Robots-Tag: noindex`, `Referrer-Policy: no-referrer`) added in
  `AssetController::streamAsset()` regardless. `Cache-Control` deliberately **not**
  shortened — assets are content-addressed by checksum (safe to cache forever) and the
  route is authenticated now, so a long cache on an authorized response isn't the leak
  vector the generic "short Cache-Control" suggestion was aimed at.
- **What this does not close**: per-asset project-ownership. A valid session from *any*
  member can still read *any* asset UUID, same as before this fix — narrowed from
  "anyone on the internet" to "someone with a valid Checkpoint session," which is
  exactly option B's stated scope, not option A's. Closing the ownership gap for real
  still means signed URLs threaded through all 8 call sites — remains open, out of
  scope for this pass.
- Verified with a real, executed test suite (not reasoned): 9 new tests
  (`tests/Feature/AssetAccessCookieTest.php`, `tests/Unit/IssueAssetAccessCookieTest.php`)
  covering no-credential rejection, valid-cookie acceptance with the mitigating headers,
  expired/tampered/garbage cookie rejection, the thumbnail route, and that issuance and
  verification actually agree on the wire format. All pass; dream_graph's full suite run
  before and after shows the same pre-existing, unrelated failure count (33 errors / 31
  failures — see [04](04-shared-validator.md)'s completion notes) with zero new failures.

## 3. members VoIP (F8)

Covered in [03](03-members-remediation.md) §5. Restated here because it is an open
exposure and not only a route-coverage item.

**Now:** move `/api/voip/*` inside the authenticated group. This costs nothing — the flow
is interactive and the caller is a signed-in member — and closes the open relay.

**Open question, deliberately not answered here:** should provisioned numbers be recorded
against `member_reference_num` so `voip_numbers_count` from `checkpoint_registry_v3.json`
can be enforced? Today the registry declares a per-tier limit that no code reads, so the
limit is decorative. Either wire it up or document that it is not enforced — a declared
limit that silently does nothing is worse than no limit, because it is quoted in pricing.

## 4. Negative caching (F7)

Two implementations reached opposite conclusions and both wrote down their reasoning.

| | Positive TTL | Negative TTL |
|---|---|---|
| `CheckpointTokenValidator` (5 apps) | 60 s | **60 s** — `Cache::remember` stores failures too |
| `CheckpointTokenVerifier` (members, market) | token lifetime | **5 s**, deliberately |

members is right. The failure mode of a long negative TTL is a user who logs in, hits a
one-off blip, and is locked out for a minute with no way to retry into a good state. The
failure mode of a short negative TTL is slightly more load on auth during an actual
outage — and during an actual outage everything is failing closed anyway.

Fixed once, in the package ([04](04-shared-validator.md) §4.2), rather than five times.

Note the positive TTL differs too: 60 s versus the token's full remaining lifetime (up to
an hour). The package standardises on **60 s**, which also bounds how long a *revoked*
token keeps working — see §6.

## 5. `JWT_SECRET` (F11)

`apps/auth/.env` contains:

```
# JWT Configuration
# IMPORTANT: Generate a secure 256-bit secret key for production!
JWT_SECRET=members_master_key_exchange_secret
```

The value is a human-readable phrase, not a generated key, sitting directly beneath a
comment telling whoever set it to generate one.

**If production uses this value, anyone who knows the string can mint a valid Checkpoint
token for any member of any app.** Every control in this plan — every middleware, every
ownership check, every app-scoping rule — assumes token signatures are unforgeable. That
assumption is upstream of all of it.

This plan cannot determine what production uses; `.env` files are per-deployment. **It must
be answered before any other work here is scheduled**, because the answer changes the
priority order of everything else.

Two supporting notes:

- **Rotation is already supported.** `config/checkpoint.php` reads `JWT_PREVIOUS_SECRETS`
  and `JWT_PREVIOUS_SECRETS_EXPIRES_AT`, so a rotation can be done without invalidating
  live sessions: add the new secret, keep the old in `previous_secrets` until existing
  access tokens age out (1 h) or refresh tokens do (30 d).
- **A second, unused secret exists.** `CHECKPOINT_JWT_SECRET` is set in the same file, but
  `config/checkpoint.php` reads only `JWT_SECRET`. Determine whether it is live anywhere
  else and delete it if not — an unused secret in `.env` is something a future reader will
  eventually assume is live and "reuse".

## 6. Revocation (open)

Neither pattern honours revocation within a token's lifetime. A verification result is
cached (60 s in the package, up to an hour in members today), and the underlying access
token is valid for an hour regardless — so a signed-out or disabled user keeps working
until the token expires.

For most of the platform that is an acceptable, industry-normal trade. Worth stating
explicitly rather than discovering during an incident:

- **Bounded by:** the 60 s cache plus the 1 h token lifetime.
- **Not covered:** "revoke this session now" — e.g. a member removing a secondary user's
  access, or an admin disabling an account mid-session.
- **If that is needed:** a revocation list checked at validation time, or short-lived
  access tokens with more frequent refresh. Both are `apps/auth` changes, out of scope
  here.

Standardising the positive cache at 60 s (§4) is what keeps this window bounded and
uniform rather than app-dependent.

## 7. Deliberate public routes — the register

These are **not** exposures. They are recorded here so that a future audit does not
re-flag them, and so that removing one is a conscious act.

| App | Route | Why public |
|---|---|---|
| coordinator | `GET /api/afs-connect/sources` | Static, member-agnostic catalog; consumed server-to-server by members. Carries no member data |
| coordinator, dream_graph | `GET /dummy/ping` | Health check |
| dream_graph | `GET /api/exports/{export}/download` | Laravel `signed` middleware; URL minted only by gated routes |
| pdf_editor | `GET /api/shared/{token}` | Public share links by design |
| pdf_editor | `GET /` | Editor page shell |
| market | `routes/web.php` storefront | Public marketplace |
| market | `routes/portal.php` page shells | *"The page shell is public; the data is not"* — the shell must render for a signed-out visitor so the client-side bootstrap can redirect. Data routes carry `checkpoint.verify` |
| members | `/login`, `/`, `/dashboard`, `/forgot-password` | Page shells, same reasoning |
| members | `GET /api/v1/afs-connect/sources` | Proxies coordinator's public catalog |
| members | `GET`/`POST /api/v1/progress/*` | **Deliberately ungated, not fully documented here — see [03](03-members-remediation.md) §4.4.** `record` has a real non-browser caller (a remote provisioning shell script) with no bearer to present; `getProgress` is a bounded capability-URL read via a 16-char random tracker key. Both remain a named follow-up, not a closed decision |
| members | `GET /api/storage/*` | **Not a deliberate public route — deferred, not decided.** Still uses the hardcoded-admin-login pattern (finding F1/P0); rewritten by the separate cloud-storage plan, out of this plan's scope |

**No longer in this table:** `dream_graph GET /api/assets/{asset}`, `…/thumbnail` — as of
2026-08-23 these require a valid Checkpoint session (signed cookie or bearer), see §2.5.
Not removed from the platform's attention entirely: a valid session from any member can
still read any asset UUID (no per-project ownership check), which is a narrower,
documented, accepted gap — not the same thing as being fully public.

**Rule going forward:** a route may only be public if it appears in this table. Adding a
row is a security decision and needs the reason written next to it — which is the
mechanism that makes acceptance criterion A1 enforceable rather than aspirational.

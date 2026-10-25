# 03-members-remediation — Completion

**Status:** Substantially complete. §2 (route restructure), §3 (domains), §4 (IDOR
endpoints), §5 (VoIP), and §6 (cleanups) are all done, with one deliberate, documented
exception (`/api/v1/progress/*` — see below) and a handful of verification gaps named in
full at the end of this document. Nothing in this pass was applied to a live environment;
everything here is a code change reviewed and lint-checked, not an observed test run.

## Summary

### §3 — Domains (the destructive endpoint)

`DELETE /api/v1/domains/{reference_num}` had no authentication and no ownership check at
all — the one destructive, unauthenticated endpoint on the platform (F2). Fixed:
`checkpoint.verify` now gates the whole `/api/v1/domains/*` group; `index()`/
`listWebsites()` resolve identity from the verified token; `destroy()`/`update()` check
ownership via a new `ownsZone()` helper (against Brain directly, not a local table — see
below) and return 404, not 403, for a zone that isn't the caller's.

**Also fixed in this pass, beyond §3's literal text:** `store()` and `createWebsite()`
validated and trusted a client-supplied `member_reference_num` in the request body — the
same class of bug (write/create attributed to another member) as the ownership gap §3.2
closes for `destroy()`/`update()`. Both now derive `member_reference_num` from the token
instead. `createWebsite2()` has the same pattern but is dead code — not routed anywhere,
not called from anywhere in the app — so it was left alone rather than fixed for no
reachable benefit.

**One deliberate deviation from §3.2's literal example**, carried over from the prior
pass: `ownsZone()` queries Brain (`GET /dns/zones` filtered by both `reference_num` and
`member_reference_num`), not the local `Website` model the plan sketches — `members_
websites.reference_num` is a website record's own reference, unrelated to the zone's
`reference_num`, so the plan's literal example doesn't hold against this schema.

### §4 — The IDOR endpoints

| Endpoint | Was | Now |
|---|---|---|
| `GET /api/v1/member` | `$request->query('user_id')` | `MemberController` resolves by `reference_num` from the token |
| `GET`/`PUT /api/v1/afs-connect/settings` | `query('user_id') ?? input('user_id')`, read **and** write | `AfsConnectSettingsController::resolveMember()` resolves by token |
| `GET`/`POST /api/v1/afs-connect/tokens`, `DELETE .../{id}` | same pattern | `AfsConnectTokenController::resolveMember()` resolves by token |
| `GET /api/v1/afs-connect/sources` | — | **Unchanged, stays public** — proxies coordinator's already-public, member-agnostic catalog (§4.3) |

All four now use the `ResolvesCheckpointMember` trait (see §6) and sit inside the
`checkpoint.verify` group.

**Frontend caller diligence (plan §7's pre-gating checklist), done properly this time:**
`resources/js/settings/afsConnectSettings.ts` called these endpoints with plain `fetch()`
and **no bearer token at all** — gating the routes without fixing this would have 401'd
the settings/tokens panel on every load. Switched all five call sites (settings GET/PUT,
tokens GET/POST/DELETE) to `fetchWithSessionRefresh` and dropped the now-unread
`?user_id=` parameter, matching the `fetchWithSessionRefresh` pattern already used for
domains.

### §4.4 — Progress endpoints: DEFERRED, not gated

`/api/v1/progress/*` was investigated and **deliberately left ungated**, unlike
everything else in §4. Verified two things that change the calculus here:

1. **`POST /api/v1/progress/record` has a real, live, non-browser caller with no bearer
   token to present.** `members/core/scripts/remote/builder/app_install.sh:147`
   (`record_progress()`) runs on a freshly provisioned remote server over SSH during
   website provisioning and `curl -X POST`s straight to this endpoint with no
   `Authorization` header (`:219` there), reading the URL from the JSON config
   `DomainController::createWebsite()` writes. Gating this route would 401 every
   provisioning-progress update from every server currently mid-provision. This is
   exactly the "server-to-server caller" shape the plan itself names (§7.1) — the
   prescribed fix is a signed/HMAC internal endpoint, mirroring `afs_connect.service`.
   Not built here: it requires coordinating a change to a remote bash script across
   however many provisioning hosts exist, which this pass had no way to safely verify or
   test. Left as a named follow-up rather than shipped blind.
2. **`GET /api/v1/progress/{key}`** is read by the browser polling widget
   (`resources/js/afslibs/services/progress.ts`), also via plain `fetch()` with no
   bearer. `tracker_key` is a 16-char random string minted server-side and returned only
   to the request that created it — bounded like dream_graph's capability-URL asset
   routes (plan `05-open-exposures.md` §2), not an open IDOR. The plan's own suggested
   eventual fix, if it must stay session-free, is a signed URL (§4.4) — not built here
   for the same reason as above.

A detailed comment explaining both, with exact file/line references, is left at the
route definition in `routes/web.php` so a future pass doesn't gate this blind.

### §5 — VoIP

`/api/voip/*` (`TwilioController`) is now inside the `checkpoint.verify` group, closing
the open relay (F8). **Verified before gating**: `resources/js/controllers/VoipController.ts`
has no live backend caller at all today — it is a complete client-side mock
(`localStorage`-backed fake phone numbers), so gating this had zero risk of breaking
anything currently working. `TwilioController` itself still reads credentials from a
Laravel session rather than scoping to a member — the plan-limit enforcement question
(`voip_numbers_count`) remains open by design (plan `05-open-exposures.md` §3), not
something this pass decides.

### §6 — Cleanups

- **F9 (`env()` at request time).** `DomainController::brainApiUrl()` — the finding's
  named example — now reads `config('services.brain.base_url')`. Two more `env()` calls
  found in the same file while fixing this (`writeRuntimeConfig()`'s `SUBPATH`,
  `getTenantData()`'s `SUBDOMAIN_PROPERTIES_SUBDOMAIN`) were fixed the same way, since
  leaving half a file cache-unsafe while claiming the finding closed would be
  inconsistent. `config/services.php` gained a `brain` block with all three keys.
- **F10 (dead session fallback).** Already fully closed in the prior pass (zero
  `session('member_reference_num')` matches).
- **`ResolvesMarketMember` → `ResolvesCheckpointMember`.** New trait created with the
  same logic; the old name converted to a one-line deprecated alias
  (`trait ResolvesMarketMember { use ResolvesCheckpointMember; }`) rather than deleted,
  so nothing breaks mid-migration. All eight controllers that used the old name
  (`DomainController`, `MemberController`, `AfsConnectSettingsController`,
  `AfsConnectTokenController`, `AssetStoreController`, `FreelanceController`,
  `Market\ConnectController`, `Market\MarketNotificationController`) now reference the
  new name directly.

### §2 — Route-file restructure

The target shape from §2 (public / authenticated / documented-exception) is now
reflected in `routes/web.php`: page shells at the top, one `checkpoint.verify` group
covering freelance/assetstore/connect/member/afs-connect/VoIP, domains in its own
`checkpoint.verify`-gated group, `afs-connect/sources` explicitly public with its reason,
and the progress routes explicitly documented as a deliberate exception rather than
silently left alone. Storage routes are untouched and explicitly commented as deferred to
the separate cloud-storage plan (§8, step 3e) — not part of this document's scope.

## Files changed

**Backend:**
- `members/routes/web.php` — full restructure per §2; VoIP, member, afs-connect
  (minus sources) moved into the authenticated group; domains group (prior pass)
  unchanged; progress routes annotated as a deliberate exception.
- `members/app/Http/Controllers/Brain/DomainController.php` — `store()` and
  `createWebsite()` now derive identity from the token; `brainApiUrl()`,
  `writeRuntimeConfig()`, `getTenantData()` read config instead of `env()`.
- `members/app/Http/Controllers/MemberController.php` — resolves by `reference_num`
  from the token.
- `members/app/Http/Controllers/AfsConnectSettingsController.php` — `resolveMember()`
  resolves by token.
- `members/app/Http/Controllers/AfsConnectTokenController.php` — same.
- `members/app/Http/Controllers/Concerns/ResolvesCheckpointMember.php` — new.
- `members/app/Http/Controllers/Concerns/ResolvesMarketMember.php` — now a deprecated
  one-release alias.
- `members/app/Http/Controllers/AssetStoreController.php`,
  `FreelanceController.php`, `Market/ConnectController.php`,
  `Market/MarketNotificationController.php` — trait reference updated to the new name
  (no behavioural change).
- `members/config/services.php` — added `services.brain.*` config block.

**Frontend:**
- `members/resources/js/settings/afsConnectSettings.ts` — five call sites switched to
  `fetchWithSessionRefresh`; `?user_id=` dropped.
- `members/resources/js/controllers/domains/api.ts` — `member_reference_num` dropped
  from `saveDomain()` and `createWebsiteApi()` request bodies (prior pass already
  handled `loadDomains()`).

## Verification steps

- `php -l` passed on every changed PHP file (12 files, confirmed in one sweep).
- `grep -rn "query('user_id')\|input('user_id')\|query('member_reference_num')\|
  session('member_reference_num')" app/Http/Controllers/` returns only doc-comment
  references (M2) — confirmed clean.
- `grep -rn "env(" app/Http/Controllers/Brain/DomainController.php` returns only a
  doc-comment mention — confirmed zero live calls (F9's named file).
- `grep -rln "ResolvesMarketMember" app/` returns exactly the alias file, the new
  trait's own docblock (explaining the rename), and one unrelated middleware's
  historical narrative comment — confirmed the rename is complete everywhere it needed
  to be.
- Confirmed via direct file reads that `VoipController.ts` has no backend caller (safe
  to gate) and that `afsConnectSettings.ts`'s five call sites used plain `fetch()` with
  no bearer (unsafe to gate without the frontend fix, which was done).
- Confirmed via `remote/builder/app_install.sh` that `record_progress()` posts to
  `/api/v1/progress/record` with no `Authorization` header from a non-browser context —
  the basis for deferring §4.4.
- **Not done:** no automated route-enumeration test (M1's "a test fails on anything
  uncategorised" is not built); no live end-to-end run of any of the touched pages —
  code review only, no dev environment / DB / Brain backend available in this session.

## Follow-up work

- **`/api/v1/progress/*`** needs the signed/HMAC internal-endpoint treatment the plan
  prescribes for service-to-service callers (§7.1), coordinated with a change to
  `remote/builder/app_install.sh`'s `record_progress()` across however many
  provisioning hosts exist. This is the largest remaining gap from this document.
- **M11's literal scope** (`grep -rn "env(" app/Http/Controllers/` returns zero matches,
  app-wide) is not fully satisfied — `StorageController.php` (explicitly deferred to
  the cloud-storage plan), `Processor/ProcessorSubscriptionController.php`, and
  `FunctionsController.php` still call `env()` directly. The latter two are unrelated
  to this plan's scope (billing/processor and generic utilities, not Checkpoint auth)
  and were not touched.
- **D6's log-and-ignore pattern** (plan §9) — logging when a client-supplied
  `?user_id=`/`?member_reference_num=` disagrees with the token, to enumerate real
  callers before the parameter is fully removed — was not implemented for `/api/v1/
  member` or the afs-connect endpoints. The parameters are fully ignored (not merely
  unused) with no logging, which is safe but forgoes the enumeration signal the plan
  recommends. Worth adding if there's reason to believe an external caller still
  depends on the old parameters.
- **`createWebsite2()`** is dead code (unreachable — not routed, not called anywhere)
  discovered while fixing `createWebsite()`'s identity trust. Left alone since nothing
  can reach it; worth deleting in a future cleanup pass, out of scope here.
- **No automated tests** exist for any of this — M1 (route enumeration), M3–M8
  (auth/ownership behaviour), and the static identity-source grep (plan §06 §4.2) are
  all currently manual/one-off checks done during this session, not regression-proof.
- **No live verification** of M9 (domains page end-to-end) or M12 (`config:cache` then
  correct Brain URL) — both are reasoned-correct from the code, not observed.

# Step 18 — Joystick Engine: the Service Assets Market — completion report

**Date:** 2026-08-20 (audited and remediated; original implementation 2026-08-19)
**Projects touched:** `JoystickEngine_v2` (engine + editor), market (`apps/market`), auth (`apps/auth`).
**Build:** `[100%] Built target JoystickApp`, 0 errors — Apple Clang 17, macOS, Filament rail.

> **This report replaces an earlier one that overstated completion.** The previous version was
> headed *"Implementation & Compilation Complete / fully complete"* while its own "Known Limitations"
> section recorded that HTTP, SHA-256, ZIP extraction, the bearer token and collision detection were
> all placeholders. Both cannot be true. The audit below lists what was actually wrong and what was
> done about it; the superseded working notes are kept in `_step18_working_notes/`.

---

## Audit findings and remediation

| # | Finding | Status |
|---|---|---|
| **C1** | `VerifyEngineToken` accepted **any** non-empty bearer string — never contacted Checkpoint, never resolved a member. The entire engine API was effectively unauthenticated. | **Fixed** — rewritten onto `CheckpointTokenVerifier` (step 13), the same cached, fail-closed verifier the portal and cart already use. Sets `member_reference_num` as a request attribute; 401 on an invalid token, 403 on a valid token with no membership. |
| **C2** | `MarketEngineController` was written against a schema that does not exist: `auth('sanctum')` (market has no Sanctum and no local users), `assets.seller_id` (column is `seller_reference_num`), a `distributionTags` relation (it is a column), `reference_number` (it is `reference_num`). It also queried assets the member **sells**, never touching `entitlements`. Every endpoint would 500. | **Fixed** — rewritten against the real schema. Identity comes from the verified token; the unit of authorization is the **entitlement**, not the asset. |
| **C3** | The `distribution_tag` filter was taken from the **client's query string**, so `?distribution_tag=other` returned `other`-tagged assets — the exact bypass §18.5 forbids. `Entitlement::scopeForEngine()`, written in step 03 for this purpose with a docblock citing §18.9, was never called. | **Fixed** — every query now runs through `scopeForEngine()`. The query parameter is ignored entirely. |
| **C4** | `PackageCache::ComputeSha256()` returned **the first 32 bytes of the input as hex** — not a hash. Its own comment said "always passes verification". §18.6 requires verification before extraction, every time. | **Fixed** — real SHA-256 via mbedtls (already vendored, already used identically in `Build/BuildSteps.cpp`). A hashing failure returns empty, which can never match a digest, so it fails closed. |
| **C5** | `MarketService::Initialize()` was never called anywhere — the worker thread never ran, the catalog was permanently empty, and `Install` / `View on Market` had empty bodies. The panel was inert. | **Fixed** — wired into `EditorLayer::OnAttach`/`OnDetach`; worker now obtains a real Checkpoint token, parses responses, and performs installs. Buttons do their jobs. |
| **C6** | Localization was ~1% done: zero `market.*` keys, zero `JS_TR` calls (every string hardcoded English), and no `menu.Windows/Service Assets Market` key despite §18.2 calling it out specifically. The panel also included the **wrong** header — `Loc.h` is game-runtime localization (step 80); editor panels use `I18n/Localization.h` (step 54). | **Fixed** — 27 `market.*` keys plus the menu key, in both `en.json` and `es.json` (344 keys each, symmetric difference empty). |
| **M1** | Four scratch `.md` files committed into `Joystick/src/Joystick/Market/`. These were also the **only** hits making §18.11's "zero `MARKET_MODIFIER_ADDITION` in the engine repo" check fail — which the old report ticked ✅. | **Fixed** — moved to `_step18_working_notes/`. Marker count in the engine repo is now **0**. |

### Further defects found and fixed while remediating

- **Hardcoded URL.** `MarketService::Initialize()` used a literal `https://market.joystick.app`, violating the engine's no-hardcoded-URL rule. Now reads `EngineConfig::Get().Market().BaseUrl`.
- **Data races.** `s_ShouldShutdown` / `s_RefreshRequested` / `s_IsInitialized` were plain `bool`s written on one thread and read on another. A cached read of `s_ShouldShutdown` is a worker that never joins on exit. Now `std::atomic<bool>`.
- **Non-portable cache path.** `PackageCache::GetCacheRoot()` used `std::getenv("HOME")` — null on Windows, and constructing a `std::filesystem::path` from a null pointer is undefined behaviour, not an empty path. Now `FileSystem::GetUserDataDir()`.
- **Entitlements were gated on an open project.** They belong to the member, not the project — a user must be able to see what they own before choosing a project. Only *install* needs a project, and that is where the check now lives.
- **Failure blanked the list.** A transient network error reset the catalog to empty, which reads as "you own nothing". It now degrades to the cached list with an `Offline` banner (§18.4's own row).

### The Checkpoint chain — a gap that made secondary users unusable

Tracing "is this actually authenticated against the IdP?" surfaced a defect **outside** step 18, in
`apps/auth`.

`AuthenticationService::verifyToken()` — the `/checkpoint/auth/token/verify` endpoint market calls —
resolved the member with `$decoded->sub`. For a **secondary (team-member) token** that is the
secondary user's own id, and memberships belong to primary accounts, so `member` came back `null`.
Market then correctly refused them. The symptom: **a team member signs in successfully and sees an
empty asset list.**

`SecondaryUserService::verifyToken()` already resolved this correctly by `super_user_id`, but only
for callers that knew to use the secondary-specific endpoint — and a service holding a bearer token
does not know which kind it holds; that is what it is asking. `verifyToken()` now branches on
`$decoded->user->type === 'secondary_user'` and resolves by `super_user_id`, making the two
functions agree. It also normalizes the `user` claim, which the two token kinds nest differently
(`user.user` vs `user`), so a consumer reading `user.email` gets a value for both.

**This edits `apps/auth`, which the market plan's README lists as out of scope.** Done with explicit
approval, and it is the only place the fix can live — a market-side workaround would mean every
consumer guessing at token internals.

---

## Verification

**Live, against a scratch MySQL database** (`market_scratch_test`), seeded with one buyer owning
**one `joystick_engine` asset and one `other` asset** — exactly the case §18.11 specifies. `.env`
backed up, restored and diffed identical afterwards; scratch DB dropped; mysql stopped.

| Check | Result |
|---|---|
| No bearer token | **401** |
| **Garbage token** (`Bearer totally-made-up`) — the old middleware accepted this | **401** `MARKET_E_ENGINE_INVALID_TOKEN` |
| Valid token | **200**, returns **only** the `joystick_engine` asset |
| **`?distribution_tag=other`** (§18.11's own bypass test) | returns **only** the engine asset — bypass closed |
| `?distribution_tag[]=other&distribution_tag=other` (array-injection variant) | same — bypass closed |
| `GET /packages/com.studio.photos` (owned, but `other`-tagged) | **404** `MARKET_E_NOT_ENTITLED` — unreachable from the engine even though owned |
| `GET /packages/com.studio.scifiprops` | **200** |
| `GET .../versions` | **200**, includes the server's `archive_sha256` (what C4 verifies against) |
| `POST .../1.0.0/download` | **200**, real signed URL + sha256 + expiry |
| `POST` download of the non-entitled package | **404** |
| `GET .../license` | returns the **entitlement's snapshot**, not the live license row |
| `asset_downloads` row | written with `source = 'engine'` (§18.9) |
| Engine build | `[100%] Built target JoystickApp`, 0 errors |
| `en.json` / `es.json` parity | 344 keys each, symmetric difference empty |
| `grep -rn MARKET_MODIFIER_ADDITION` in engine repo | **0** |
| Secondary-token member resolution | logic verified against both real JWT claim shapes (primary → own id; secondary → `super_user_id`); see caveat below |

---

## Completed in the follow-up pass

- **ZIP extraction is real** (`PackageInstaller::ExtractPackage`), on the already-vendored miniz — this is its first consumer in the engine. Extracts to a staging directory and renames into place, so a failure at any point leaves nothing behind (§18.6's own rule), with a cross-device copy fallback.
  - **Zip-slip defence.** Package archives arrive from other members, so entry names are untrusted: an entry containing `..`, a drive letter, or a leading separator rejects the **whole archive**, backed by a `lexically_normal` containment check. Verified against a purpose-built malicious archive whose entry was `../../../../../../tmp/ziptest/PWNED.txt` — refused, and the file was never created. A well-formed archive extracted correctly in the same harness.
- **`PackageManifest::ReadPackageJson` is real** — parses `package.json` out of the archive and rejects one missing `name`/`version`. Previously it returned `{}` unconditionally, so no package could ever install.
- **Asset-collision detection** (§18.6 step 4) is implemented **and now actually called** — it was written but had no call site, so the check existed only on paper. `Install()` refuses a same-package-different-version install and directs the user to Update. `Update()` bypasses it deliberately (replacing that version *is* the operation), via a shared `InstallInternal(..., isUpdate)`; without that split the check would have rejected every update.
- **Uninstall scene-reference warnings** (`FindReferencingScenes`) implemented — scans `.jscene` files for the `Packages/<name>/` reference prefix and reports paths relative to `Assets/`. A text scan rather than a full deserialize, deliberately: it runs on a user-facing confirm and must not fail on a scene from a newer engine version.

## What is still not done
- **"In Development" source** shows an explicit "not built" message rather than scanning `<Project>/Packages/`.
- **No interactive editor verification.** The panel compiles and is wired, but has not been driven with a signed-in session against a live market. Every market-side check above is HTTP-level.
- **Secondary-user flow not exercised end to end** — the auth fix is verified as *logic* against both real claim shapes, not against a signed JWT and a live database.
- **§18.4's "launch the launcher" button is not built.** `PlatformUtils` has only `OpenURL`, no process launch. Recorded as a deviation in the panel source rather than silently dropped; editor-side sign-in (under discussion) would remove the need for it.
- **`MarketCatalog.cpp` was never created** (§18.8 lists `.{h,cpp}`). The type is header-only, which is fine, but it is a deviation from the file list.
- **Per-file conflict detection against the server's `asset_version_files`** — §18.6 also mentions this, and it needs the file manifest from the versions endpoint, which the installer does not fetch. The collision check covers the version case only; the gap is recorded in `HasAssetCollision`'s own comment rather than treated as passing.
- **`GeneratePackageMetadata`** still returns `true` without writing `.jmeta` sidecars (§18.6 step 6). Deferred to the step 49 extension it references.

### Resolved since the first pass

- **Linux link gap — fixed.** `Joystick::Net::HttpClient` is deleted; everything now uses `libs/checkpoint_cpp`'s transport, which has a working Linux backend. See `_http_consolidation_complete.md`.

## Related pre-existing defects observed (not fixed here)

- **`ConnectService::Initialize()` is never called either** — the same inert-service defect step 18 had, in step 61's code. `PostSnapshotToCoordinator()` and `CheckEntitlement()` are `TODO(step 61-F)` stubs.
- **`EngineTaskController` (coordinator) is a stub** — both methods return placeholder JSON with `TODO(step 61-J)`.
- **Two HTTP stacks in one process** — the engine's client and Checkpoint's `DefaultTransport`. The `ITransport` seam exists to collapse them; the adapter `ITransport.h` says belongs in `Joystick/Connect/` does not exist.

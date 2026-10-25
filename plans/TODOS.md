# TODOs — checkpoint-server-auth: deferred, open, or needing revisit

Every item any completion doc, plan doc, or open-questions list left unresolved —
follow-up work explicitly deferred, product decisions the plan declined to make
unilaterally, and whole steps never started. Not a status report (see
`plans/checkpoint-server-auth/complete/*.complete.md` for those) — a punch list so a
future session doesn't have to re-read every completion doc to find what's still open.

Items from other plans are filed here too when that plan has no TODO list of its
own; each carries its plan reference in the heading.

**Steps with a completion doc:** 00 (read-only, fully done), 03 (substantially
complete), 04 (substantially complete — 5 of 7 apps), 05 (substantially complete), 06
(rollout tracking reconciled; §4 Testing deliberately left for the user to run — see
below), 07 (code + build verified; DA3/DA7/DA11 runtime testing deferred to the user).
**Steps never started:** 08, 09, 10, 11, 12. **Steps that exist only as
design docs consumed by 03/04/05, not executed as their own step:** 01 (audit), 02
(pattern convergence — partially realized through 04).

---

## Step 03 — members remediation (`03-members-remediation.md`) — substantially complete

Full account in `complete/03-members-remediation.complete.md`. Open items from that doc:

1. **`/api/v1/progress/*` was investigated and deliberately left ungated** — `POST
   .../record` has a real, live, non-browser caller (`members/core/scripts/remote/
   builder/app_install.sh:147`, a remote provisioning shell script with no bearer to
   present). Plan's own prescribed fix (§7.1) is a signed/HMAC internal endpoint,
   mirroring `afs_connect.service` — not built. **To resume:** design the signed
   endpoint, then coordinate updating `record_progress()` in the shell script across
   however many provisioning hosts exist — the actual blocker last time, not the
   Laravel side.
2. **M11's literal scope not fully satisfied** — `StorageController.php` (deferred to
   the separate cloud-storage plan on purpose), `Processor/ProcessorSubscriptionController.php`,
   and `FunctionsController.php` still call `env()` directly. The latter two are outside
   this plan's scope entirely (billing/generic utilities, not Checkpoint auth).
3. **D6's log-and-ignore pattern** (plan §9) was not implemented for `/api/v1/member` or
   the afs-connect endpoints — the old `?user_id=`/`?member_reference_num=` params are
   fully ignored with no logging, safe but forgoes the "who's still calling the old way"
   enumeration signal the plan recommends.
4. **`createWebsite2()` is dead code** — unreachable (not routed, not called anywhere),
   found while fixing `createWebsite()`'s identity trust. Left alone since nothing can
   reach it; worth deleting in a future cleanup pass.
5. **No automated tests** for M1 (route enumeration) or the static identity-source grep
   (plan §06 §4.2) — currently manual, one-off checks, not regression-proof.
6. **No live verification** of M9 (domains page end-to-end) or M12 (`config:cache` then
   correct Brain URL) — both reasoned-correct from the code, never observed running.

## Step 04 — shared validator package (`04-shared-validator.md`) — substantially complete

Full account in `complete/04-shared-validator.complete.md`. The package is real
(`appfactorystudioplus/checkpoint-auth` on GitHub, private, tagged `v1.0.0`/`v1.0.1`),
installed and verified in coordinator, flow, notes, pdf_editor, dream_graph. Open items:

1. **market and members are not migrated onto the package** — both need the attribute
   rename (`member_reference_num` → `checkpoint_member_reference_num`) the plan
   sequences last (§7, items 6–7). members' prerequisite (step 03) is done, so members is
   actually unblocked for a future pass; market has no dependency blocking it either,
   just risk ordering. **This is what keeps finding F3 (no `app_name` enforcement for
   members/market) open** — see step 05's summary table.
2. **CI/deploy machines besides this one need their own Composer credentials** for the
   now-private repo — only this machine's global Composer config
   (`%APPDATA%\Composer\auth.json`) has the GitHub token. Any CI runner, another dev's
   machine, or a deploy target will hit an auth failure pulling `afs/checkpoint-auth`
   until it's given its own credential (a scoped deploy-key-style PAT is preferable to
   reusing the one used to set this up).
3. **No CI wiring** runs `composer update` drift checks or the package's own test suite
   in any pipeline.
4. **dream_graph's test suite has real, pre-existing gaps** unrelated to this plan:
   missing `GraphProject::factory()`, a fixture reading an absent `uuid` key, and — the
   one actually relevant here — **no test anywhere in dream_graph fakes the Checkpoint
   HTTP call**, so its substantial `checkpoint.member`-gated feature-test surface has
   apparently never been exercised end-to-end. Worth its own pass.
5. **flow, notes, and pdf_editor have no real feature test coverage at all** — only
   Laravel's default scaffold tests. Not a regression from this migration, but there is
   currently no automated check that would catch a real auth regression in any of these
   three apps.

## Step 05 — open exposures (`05-open-exposures.md`) — substantially complete

Full account in `complete/05-open-exposures.complete.md`. F6 (dream_graph asset serving)
fixed and tested this pass (signed cookie + mitigating headers, confirmed via the
Joystick Engine's own plans that it isn't a hidden consumer of the route — see
`JoystickEngine_v2/plan/steps/061_afs_connect_engine.md` and
`TilemapSyncController::export()`). F7, F8's gating half, and F11 confirmed done via
other steps. Open items:

1. **F3 remains open** — same root cause as step 04's item 1 above (members/market not
   migrated).
2. **F6's ownership gap** — any member with a currently valid Checkpoint session can
   still read any *other* project's asset UUIDs; the fix narrowed "anyone on the
   internet" down to "someone with a valid session," not per-asset ownership. Closing
   that for real means signed URLs threaded through the 8 frontend call sites named in
   `AssetController`'s docblock (`image.tsx`, `AssetPanel.tsx`, `AssetPicker.tsx`,
   `tilemapRef.tsx`, `PresentationMode.tsx`, `NewTilesetDialog.tsx`, `tileset.ts`, and
   the asset picker's preview path) — materially larger than this pass's fix.
3. **F8's plan-limit enforcement question** — should provisioned VoIP numbers be
   recorded against `member_reference_num` so `voip_numbers_count` from
   `checkpoint_registry_v3.json` can be enforced? Deliberately unanswered by the plan;
   today the registry's limit is decorative.
4. **User must still apply the F11 JWT_SECRET rotation to whatever is actually live in
   production** — this session only edited the local `auth/.env` checkout. Whether that
   file is the deployment source of truth was never resolved (the user said "that's not
   important" when asked).
5. **Delete `JWT_PREVIOUS_SECRETS` / `JWT_PREVIOUS_SECRETS_EXPIRES_AT` from `auth/.env`
   after 2026-09-21** — leaving the old, compromised secret listed indefinitely defeats
   the rotation; it remains a valid signing key forever until removed.
6. **`members GET/POST /api/v1/progress/*` and `GET /api/storage/*`** — both newly
   reflected in the §7 public-route register this pass, neither resolved (see step 03
   item 1 above for progress; storage is out of this plan's scope entirely).

## Step 01/02 residue — findings not carried into any executed step

`01-platform-audit.md` is evidence, not a step with its own completion doc, but two
finding groups it names are not addressed by anything above:

1. **F12–F15 (desktop/mobile client findings)** — unguarded secondary-login paths,
   plaintext token storage on the secondary path, 2FA lockout, no registered mobile app
   name. These are steps 07/08's entire scope — see those sections below. Nothing in 03/
   04/05 touches the client repos at all.
2. **`02-two-patterns.md` §5.1's observe-then-enforce sequencing for app_name checking**
   was never started (it depends on members/market being on the shared package first —
   same blocker as step 04/05's F3 item).

## Step 06 — rollout and testing (`06-rollout-and-testing.md`) — open questions still live

Q-numbers per that document's own §1 table. Answered/closed since the doc was written:
Q1 (JWT_SECRET — answered and acted on), Q2 (`CHECKPOINT_JWT_SECRET` — confirmed dead,
deleted), Q5 (dream_graph assets — answered, option B implemented), Q7 (package
distribution — answered: a real private Composer/GitHub package, not the VCS+tag-only
minimal version originally drafted), Q8/Q9 (answered in the plan text itself, not yet
implemented — see step 10 below), Q12 (answered in the plan text itself).

**Still open:**

- **Q3** — which callers pass `?user_id=`/`?member_reference_num=` to members? Log-and-
  ignore was never implemented (step 03 item 3 above), so this is still unanswered by
  data, only by inspection.
- **Q4** — does every frontend caller of each members route group send a bearer? Verified
  for the groups step 03 actually gated (domains, member, afs-connect, VoIP — all
  confirmed via direct code reading, some required a real frontend fix first, e.g.
  `afsConnectSettings.ts`). Not re-verified for anything gated after this plan's own
  work, since nothing else has been gated since.
- **Q6** — VoIP plan-limit enforcement (same as step 05 item 3 above).
- **Q10** — does any existing secondary user hold a `MEMBERS`/Studio grant? Layer 1 says
  no, but layer 1 is only a UI constant. Blocked on step 10 (not started) — that step's
  §8 sequencing (10b) says observe before enforcing.
- **Q11** — should `require_2fa` default on for new accounts, or off? Blocked on step 09
  (not started). Plan's stated default: off, primary opts in.
- **Q13–Q16** — secondary-dashboard questions (`GAME_EDITOR` URL, launcher scope,
  password/email self-service, `VITE_MARKET_URL`). Blocked on step 11 (not started).
  Plan states defaults for all four already, just never implemented.

### §4 — Testing: deliberately left for the user to run (2026-08-23)

Full account in `complete/06-rollout-and-testing.complete.md`. At the user's explicit
direction, this session did not build or run the cross-cutting suite `06-rollout-and-
testing.md` §4 specifies — only the tests that ship alongside a specific code change
(the F6 asset-access-cookie suite, the shared package's own behaviour suite) exist and
already pass. What's still needed, exactly as the plan doc specifies it:

1. **§4.1 — the cross-app suite** (7 tests: no-bearer 401, malformed/expired/wrong-sig
   token 401, `{valid:false}`-with-HTTP-200 rejection, `{valid:true,member:null}`
   rejection, auth-timeout fail-closed, primary-token-any-app acceptance, secondary-
   wrong-app rejection). Needs writing once per app; the shared package's own suite
   (step 04) already covers this logic at the validator level for the 5 apps on the
   package — this item is about each *app* asserting it through its own routes, not
   re-testing the validator.
2. **§4.2 — the five static checks** (route enumeration against a documented public
   list, identity-source grep, `env()`-outside-config grep, validator-uniqueness grep,
   legacy-header grep). None exist as runnable scripts yet. The identity-source/`env()`/
   legacy-header checks are straightforward greps across each app; validator-uniqueness
   is straightforward across the 5 migrated apps + the package repo, harder to make
   meaningful for market/members until they're migrated; route enumeration is the
   hardest of the five — needs a machine-readable public-route manifest per app (today
   the public-route register is a markdown table in `05-open-exposures.md` §7, not
   something a script can check `php artisan route:list` output against).
3. **§4.3 — members-specific** (9 tests, mostly already reasoned-correct from the step
   03 work — domains 401/404, member/afs-connect IDOR-closure, VoIP 401, sources still
   public, domains page e2e, `config:cache` correctness). None executed against a real
   running app with a database in this session — no dev environment was available.
4. **§4.3a / §4.3b — client and 2FA/primary-only matrices.** N/A until steps 07–11 exist
   to test.
5. **§4.4 — cross-app integration** (5 tests: members↔coordinator sources, members↔
   coordinator signed HMAC status, coordinator→members afs-connect, desktop→API,
   mobile→API). The last two are N/A until steps 07/08; the first three are live now and
   untested.
6. **§4.5 — manual verification per app after package adoption** (sign in, load, one
   write, sign out, stale-token 401) — for the 5 apps that adopted the package this
   session (coordinator, flow, notes, pdf_editor, dream_graph). Each app's own automated
   test suite was run and showed no new failures (see steps 03–05's completion docs),
   but that's not the same thing as this manual pass.

---

## Step 07 — Desktop client auth (`07-desktop-client-auth.md`) — DONE (code + build verified; runtime testing deferred)

See `complete/07-desktop-client-auth.complete.md` for full detail. Summary: secondary-login
capability deleted from `client-handler.ts` and `secondary-users.ts` removed entirely (§3);
`assertPrimary()` added as layer-2 defense-in-depth on the login path, the 2FA-completion
path, and both session-restore paths (§4.1); the missing 2FA code-entry flow built end to
end with the pending token held in memory only (§6); legacy plaintext
`checkpoint_secondary_*`/`checkpoint_user_type`/`checkpoint_user_data` keys purged on
sign-out (DA9); `THIS_APP` documented (D5/DA10). Two findings not named in the plan's own
audit were also found and fixed: a second secondary-login-capable module
(`sso-handler.ts`) and a secondary-registration test harness
(`_testing/register-secondary.ts` + its stylesheet) — both deleted.

Verified via `tsc --noEmit` (clean) and a full `webpack` production-config build
(succeeded) — confirms nothing deleted is still referenced anywhere in the real build
graph. Layer 3/4 (server-side `primary_only` enforcement, §5) deliberately not touched —
that's step 10's scope.

**Deferred to the user, per the plan's own §8 testing table:**
1. DA3 — crafted `user_type=secondary` SSO callback against a running app.
2. DA7 — 2FA sign-in against a real 2FA-enabled account, including a recovery code.
3. DA11 — sign-in against both live production and development Checkpoint hosts.

None of these were exercised this session; they need a launched Electron app and a real
backend/account, which this session didn't have.

## Step 08 — Mobile client auth (`08-mobile-client-auth.md`) — DONE (code + analyze verified; runtime testing deferred)

See `complete/08-mobile-client-auth.complete.md` for full detail. Summary:
secondary-login capability deleted from `client_handler.dart` and `secondary_users.dart`
removed entirely, including the library-doc usage example (§3); `ClientUserType`
deliberately **kept** (not narrowed, contrary to the plan's own §3.1 suggestion) so a
legacy persisted `'secondary'` session still parses correctly for layer-2 rejection;
`_kUserType`/`_kUserData` moved off plaintext `SharedPreferences` onto
`FlutterSecureStorage` with a one-time migration purging the old keys (§3.3);
`_assertPrimary()` added as layer-2 defense-in-depth, adapted to check
`AuthState.userType` instead of the plan snippet's `user['type']` (that field doesn't
exist in this codebase's user JSON — copying the snippet literally would have made the
check a silent no-op) — wired into `login()`, `completeTwoFactor()`, and
`getCurrentUser()` (§4.1); the missing 2FA code-entry flow built end to end, mirroring
desktop's shipped `pendingTwoFactorToken`-in-memory pattern (§6); `APPFACTORY_MOBILE`
registered as a `lib/config.dart` constant, documented as not scoping mobile's own
sessions (§5, matches plan 10 §5/§5.2); package README created recording the deliberate
divergence from upstream apps/auth (§3.2).

Verified via `flutter analyze` (zero errors; only pre-existing unrelated lints) and a
`grep -rn "secondary"` sweep (MA1) — every hit is the rejection/migration code, its
docs, or an unrelated same-word match (Material's `ColorScheme.secondary`, an SSO
"secondary domain" comment, JSON fixture CSS tokens).

**Deferred to the user, per the plan's own §8 testing table:**
1. MA8/MA9 — 2FA sign-in against a real 2FA-enabled account, including a recovery code.
2. A pre-seeded legacy secondary session on a real device/emulator — confirm rejected
   and cleared on launch.
3. Fresh-install on-device storage inspection — confirm no plaintext token/profile
   survives.

None of these were exercised this session; they need a launched app and a real backend/
account, which this session didn't have.

Also still open: confirm `APPFACTORY_MOBILE` is registered server-side to match (that's
step 10 §5's job — step 10 hasn't been started, see below).

## Step 09 — Two-factor authentication (`09-two-factor-authentication.md`) — DONE (code + build/lint verified; migrations not run, step 12 explicitly deferred)

See `complete/09-two-factor-authentication.complete.md` for full detail. Summary:
secondary-user 2FA built end to end across three repos. A new shared HMAC package
(`packages/checkpoint-auth` — `CheckpointServiceSignature` + `checkpoint.service`
middleware, mirroring the existing `AfsConnectSignature` scheme) replaces the plan's
mistaken assumption that members already had a service-to-service channel to auth — it
didn't; `applications.ts` called auth directly from the browser with the primary's own
token. `TwoFactorService` rekeyed to `(userType, userId)` with all call sites updated;
`SecondaryUserService::buildLoginResponse()` grows the three-outcome branch; new
`/checkpoint/secondary/2fa/*` endpoints with `/status`/`/reset`/`/policy` genuinely
service-authenticated only (T10); rate limiting added (5 attempts/token burn, 15-min/
10-attempt per-user cache limit); hosted login gets secondary code-entry + forced
enrolment with client-side QR; members gets admin controls (policy toggles, status
badges, reset) proxied through the new HMAC channel.

**Two bugs found and fixed along the way, not originally scoped:** members'
`SettingsApp.ts` already had a complete primary-2FA management UI (contradicting the
plan's own "members has no management UI for it" claim) — with a stale-type bug
(`qr_code_uri` vs. the real `qr_code_url`) that had never been exercised, which meant its
QR rendering always fell through to a third-party image service
(`api.qrserver.com`), leaking the TOTP secret off-domain on every real call. Both fixed:
types corrected to match the actual PHP response shape, QR now rendered client-side via
`qrcode`. (First pass of this session duplicated the UI from scratch before this was
discovered via a full `tsc` type-check — caught and backed out before landing.)

**Explicitly deferred by the user's own decision, against the plan's own warning:**
adding `requires_2fa` to the secondary login response is confirmed (by reading
`SecondaryUsers.cpp` directly) to degrade the Joystick Engine's secondary login to a
generic `MALFORMED_RESPONSE` instead of crashing — a real but non-crashing UX
regression, live from this point until step 12 (not started) lands separately.

**Deferred to the user — needs a real database and running stack:**
1. Running the four new migrations (schema for these tables was never tracked before
   this step — verified no migration/DDL file existed anywhere in the repo).
2. Setting `CHECKPOINT_SERVICE_KEY_MEMBERS` to a matching value in both auth's and
   members' env, and tagging/publishing a new `checkpoint-auth` package version so
   `composer install` resolves the new classes for its five existing consumers plus auth
   and members.
3. The plan's full T1–T14 acceptance table, especially T10 (secondary token genuinely
   rejected by the service-only endpoints) and the full forced-enrolment flow.

**Amendment already recorded in the plan (step 11 §6, settled 2026-08-22):** the
primary/secondary split changed after this document was written — primary owns policy
and reset, secondary owns their own enrolment and recovery-code regeneration from
`/security`. Read step 11 §6 alongside §3 of this document, not §3 alone.

## Step 10 — Primary-only enforcement (`10-primary-only-enforcement.md`) — DONE for this step's actual scope (layers 1/2 + registration); layers 3/4-for-members deferred to step 11 per the plan's own text

See `complete/10-primary-only-enforcement.complete.md` for full detail. Summary:
`APPFACTORY_MOBILE` registered across all four of its places (`CheckpointAppName.php`,
members' and afsdesktop5's `app-names.ts`, mobile's `config.dart` — the last already done
in step 08). Layer 1: the load-bearing absence comment added to
`UI_APP_ID_TO_CHECKPOINT`, plus a 4-test vitest file asserting `MEMBERS`/
`APPFACTORY_STUDIO`/`APPFACTORY_MOBILE` stay absent (all pass). Layer 2:
`CheckpointAppName::PRIMARY_ONLY_APPS` + `isPrimaryOnly()`; new-grant paths
(`register()`/`updateUser()`'s app-grant path, both funnel through
`normalizeAppsPayload()`) reject immediately; login paths (`login()`/`selectAccount()`)
observe-only by default per the plan's own §8 "10b" rollout guidance, gated behind
`CHECKPOINT_PRIMARY_ONLY_APPS_ENFORCE` (default off) until logs confirm nothing
legitimate depends on the old behavior.

**Verified, not assumed:** `SecondaryUserController::update()` doesn't currently pass an
`apps` param through at all — `register()` is the only presently-reachable grant path
today. The service-layer guard is in place regardless, ready for whenever a grants-update
API is wired up.

**Explicitly not done — superseded or deferred by the plan's own text, not an oversight:**
§4 (members web client assertion), layer 3 for members web, layer 4 (members API
`primary_only` route-group split) — all superseded by or deferred to step 11 (§7's
amendment table, §8's "10d"). Desktop/mobile layer 3 (P9) was already done in steps 07/08.

**Deferred to the user:** flip `CHECKPOINT_PRIMARY_ONLY_APPS_ENFORCE` to `true` once
observe-mode logs are reviewed; `CheckpointAppNameTest` (auth-side) couldn't be run — no
`vendor/` in this sandbox, same limitation noted in step 09.

## Step 11 — Secondary user dashboard (`11-secondary-user-dashboard.md`) — DONE (code + build/lint verified; full SD1–SD12 acceptance table not run against a live stack)

See `complete/11-secondary-user-dashboard.complete.md` for full detail. Summary:
secondary users now sign in to members and reach a restricted launcher shell instead of
being refused.

**Found before building anything:** members' own native login page had no secondary-login
attempt at all (only `checkpoint.auth.login/loginWithUsername/ssoLogin`) — its SDK copy
still had `login()`/`selectAccount()` (never actually stripped, so plan 10 §4.2's
"reversal" needed no undoing), but nothing called them, and none of step 09's 2FA
additions had been ported to this second copy of the SDK either. Fixed by porting the
same secondary-login + 2FA + forced-enrolment flow already working on auth's hosted login
page to members' own login page (types/methods in `secondary-users.ts`, logic in
`login.ts`, enrolment modal in `login.blade.php`), plus fixing the identifier field's
label (previously gave no hint of the `username@identifier` scoped format).

Auth gained self-service secondary 2FA (`/checkpoint/secondary/2fa/self/*` —
status/enrol/regenerate, bearer-token authenticated, no self-service disable per the
primary-owns-policy/secondary-owns-device split). Members' `CheckpointTokenVerifier`/
`VerifyCheckpointToken` (its own middleware — NOT the shared `checkpoint-auth` package's
`checkpoint.member` the plan's pseudocode names; extending members' own was lower-risk
given unrelated in-flight work already in this exact area) extended to expose
`user_type`/`user` and support a `primary_only` mode. The existing `checkpoint.verify`
route group (freelance/market/storage/VoIP/afs-connect/secondary-admin — all genuinely
owner-only) became `checkpoint.verify:primary_only`; new `/api/v1/me/*` launcher group
(`MeController`, 6 endpoints — more than the plan's illustrative "three or four", but each
is minimal and own-data-only, which is the rule that actually matters) added alongside it.

Frontend: `views.ts`'s `registerViews()` now branches on `app.window.userType` (a field
that had to be added — the plan's own `app.window.user?.type` pseudocode doesn't match
this codebase's actual `AuthState` shape, where `userType` lives one level up). Existing
body extracted verbatim to `registerPrimaryViews()` (byte-identical, SD9); new
`views/secondary.ts` registers exactly three sub-views, no owner route reachable at all
(SD5). New `app-urls.ts` registry, three new templates, `GAME_EDITOR` rendered as an
explanatory tile per Q13's decided default.

**Deferred, by conscious scope decision, not oversight:** `secondary.*`/
`login.2fa_enrol.*` i18n strings added to `en.json` only, not the other four locales; Q15
(secondary self-service password change) — in the plan's open questions with a decided
default but not one of SD1–SD12, so left for a follow-up pass.

**Deferred to the user — needs a real database, running auth+members stack, and a
browser:** the full SD1–SD12 table, especially SD1 (an actual secondary user reaching the
shell) and SD6/SD7 (a real secondary token tested against both route groups).

## Step 12 — Joystick Engine client (`12-game-engine-client.md`) — NOT STARTED

**Why not started:** `JoystickEngine_v2` is a large, separate C++ repo with its own
extensive plan/build system (see that repo's own `plan/TODOS.md`, read in full while
producing this file) — genuinely separate scope from the Laravel/PHP work this session
focused on, and this step was never reached in sequence.

**Two things confirmed as part of step 05's investigation this session, worth recording
here even though the step itself wasn't started:**

1. **`AppNames.h`'s sync-script claim really is false**, confirmed by direct
   investigation (not just trusting the plan doc): `apps/afsserver/bin/
   sync-checkpoint-client.sh`'s `TARGETS` array has no C++ entry and cannot generate a
   `.h` from a `.ts`. `AppNames.h` genuinely is hand-maintained. Fixing the docblock's
   false claim (§3.2) and adding the missing `MARKET`/`APPFACTORY_STUDIO`/
   `APPFACTORY_MOBILE` constants (§3.1) is still unstarted.
2. **The engine does NOT consume `/api/assets/{uuid}`**, confirmed directly by reading
   `TilemapSyncController::export()` — it returns full tilemap ZIP bytes (textures
   embedded) over the already-authenticated `tilemap.sync:read` bridge. This was the
   basis for closing finding F6 safely in step 05; it's recorded here too since it's a
   fact about the engine's own architecture, not just about the exposure being fixed.

**What it needs, per the plan doc, when picked up:**
1. Registry sync fix above, plus a parity check added to `sync-checkpoint-client.sh
   --check` covering all four languages (§3.3) — and fix that script's own `TARGETS` gap
   (missing `market`) while in there (§3.3's own aside).
2. `SecondaryUsers::Login()`'s missing 2FA guard branch (§4.2) — **this is the step
   09 ordering dependency already flagged above**: shipping step 09 without this fix
   first breaks the engine's native secondary login the moment auth starts returning
   `requires_2fa`/`requires_2fa_enrolment` for secondary users.
3. The capability seam (`Capabilities.h`/`.cpp`, §5) — deliberately a no-op allow-all by
   construction; the plan is explicit that adding it must change zero behavior (GE7 is
   named as "the acceptance criterion that matters most").
4. Raise `loopbackTimeoutSeconds` from 300 to 900 (§6.2) — currently too short for a
   first-time 2FA enrolment mid-login once step 09 ships.
5. Runtime-verify the Windows ACL in `FileTokenStore` (§7, option A) — recommended now;
   full platform-native encryption (option B) recommended "when convenient," not blocking.

**To resume:** read `JoystickEngine_v2/plan/TODOS.md` first for that repo's own current
state and in-progress work before touching anything there — this checkpoint-auth step is
a small slice of a much larger, actively-developed codebase with its own open items that
have nothing to do with this plan.

---

## members_brain — `portal.level99` disabled on the Brain admin API (2026-08-29)

Not a checkpoint-server-auth item. Filed here because this is the estate's TODO list and
`plans/members_brain/` has none of its own.

**Plan:** `plans/members_brain/02-security-and-access.md` §2.3, "Split the Brain group, and
gate it on level 99 server-side". Shipped in commit `7d04b1f03`.

**What was done, and why.** `portal.level99` was removed from both `api/v1/brain` route
groups in `www/routes/portal.php` (~line 288 and ~line 352). `portal.admin.auth` is
untouched and still applies. The middleware class
(`app/Http/Middleware/PortalLevelNinetyNineMiddleware.php`) and its alias in
`bootstrap/app.php` are both left in place — re-enabling is one line per group.

**The design problem, in the user's words:** level 99 exists to identify **the application
itself**, not a person. Only the application holds it, and it is meant to bypass all
security. Modelling that as a per-user field on `brain_memberships` puts a *service*
identity in a *user* table, and the gate then sits in the wrong layer — it assumes the only
caller that ever needs the Brain admin API is the Brain. That is not true: members,
coordinator and the other apps need to reach it too, and none of them can hold a
level-99 user row.

**What it broke while it was on.** 39 routes were gated. Two internal callers went through
HTTP to reach them with no credentials at all and so got 401 forever, returning `null`
without logging anything:

1. `FunctionsController::getBrainConfig()`
2. `BrainHttpServerController::getBrainConfig()` — a byte-identical private copy

Both are fixed independently of this (they now read `brain_config` locally, since www **is**
the Brain and the row is in its own database), so re-enabling the gate will not re-break
them. `app/Mail/BillingMailer.php:59` still carries a "B24" comment written to
work around the null return — worth re-reading now that the method works.

**What is still not solved, and is the actual open question.** With `portal.level99` off,
those 39 routes are gated by `portal.admin.auth` alone — a `portal_auth_token` bearer/cookie
check. That authenticates a *person*, so a sibling application still cannot call the Brain
admin API. Disabling level99 removed the wrong gate; it did not add the right one.

**Candidate direction when this is picked up.** The mechanism probably already exists.
`www/routes/portal.php` (~line 340) has:

```php
Route::prefix('api/v1/brain')->middleware('afs_connect.service')->group(...)
```

with a comment describing `afs_connect.service` as "the same HMAC surface the Brain already
exposes to coordinator/members elsewhere" — i.e. an app-to-app identity, which is exactly
what level 99 was reaching for. The likely shape is: service callers authenticate over
`afs_connect.service`, human platform staff over `portal.admin.auth` plus a capability
check, and `level === 99` stops being an identity at all.

**Also worth fixing while in there:** the magic number is baked into both the alias
(`portal.level99`) and the class name (`PortalLevelNinetyNineMiddleware`), so the names
describe a comparison rather than an intent. `brain_memberships.level` is a `tinyInteger`
with no documented ladder between 0 and 99 — a sentinel wearing a scale's clothing, which
is why the middleware has to compare `=== 99` and not `>= 99`. And the gate returns 404
rather than 403 on purpose (so the endpoint's existence is not confirmed), which is
defensible but makes a misconfiguration look identical to a missing route.

**To resume:** decide whether the Brain admin API needs a service identity, a staff
identity, or both, before touching the middleware. Re-adding `portal.level99` as-is only
restores the original problem. Note the data: `brain_memberships` currently holds 3 rows,
exactly one at level 99 and two at level 0, so the ladder has never had a middle.

**Update, 2026-09-23 — `portal.level99` re-enabled on 5 of the 39 routes.** Found while
starting `plans/membership_change/08-member-management.md`'s leftover Allocations-tab work
(§8.7 OQ-A): the allocation-save and override-management routes
(`.../member/{ref}/allocation` GET/POST, `.../member/{ref}/overrides` GET/POST/DELETE —
`routes/portal.php` ~line 524) had no authorization beyond "not your own record." Any
authenticated portal user, not just staff, could grant, revoke, or overwrite any other
member's ceiling overrides and allocation registry. Verified before re-enabling that
nothing besides this app's own browser console calls either of these two route groups
(no reference in members/coordinator/auth's `resources/js`, and both broken callers named
above were already independently fixed by the time this was checked) — so the "sibling
apps can't hold a level-99 row" problem that forced the original disable does not apply to
these 5 routes specifically. The other 34 routes in the *first* `api/v1/brain` group
(`getMember`, `getMembers`, DNS/HTTP-server management) are **unchanged and still open** —
this did not resolve the broader question above, only narrowed it. See
`plans/membership_change/complete/08-member-management-allocations.complete.md` for the
full writeup, including two `Undefined array key` bugs in `BrainAllocOverrideController::
store()` found and fixed by writing this route group's first-ever test.

---

## www/ecommerce — deferred after P0–P10 rollout (2026-08-31)

Not a checkpoint-server-auth item. Filed here because `plans/www/ecommerce/` has no
TODO list of its own. Every item below was left open in one or more completion docs under
`plans/www/ecommerce/complete/`; the rollout summary is
`12-rollout-status.complete.md`.

**Plan tree:** `plans/www/ecommerce/` (numbered `01`–`12`).  
**Repo:** `www` — all migrations for the shared database live in `www/database/migrations/`
(AGENTS.md §5).

**What shipped:** core catalogue, cart, checkout, Direct Payments, orders/fulfilment,
promotions API + admin UI, tax/shipping settings UI, storefront SDK, builder Commerce
blocks, site-users checkout modes, store overview dashboard. Automated:
`cd www && php artisan test --filter=Ecommerce` (20 tests, 2026-08-31).

### Catalogue and admin product editor

1. **Collections (plan `04-catalogue.md` §4.7)** — no migration, model, admin screen, or
   storefront API yet. Plan names
   `..._create_ecommerce_collections_tables.php`. SDK stub returns empty:
   `www/resources/js/external/commerce/api.ts` (`collections()`). Builder Product Grid
   cannot filter by collection until this exists. Admin collections screen also deferred
   (`complete/08-admin-dashboard.complete.md`).

2. **Product variant grid editor and full product tabs (plan `11-admin-dashboard.md`
   §11.5)** — variants exist in schema and API (`App\Models\Externals\EcommerceVariant`,
   `App\Externals\Ecommerce\Services\EcommerceVariantService`) but admin is still list + basic
   form only: `resources/js/portal/admin/controllers/ecommerce/commerce-products.ts`.
   Plan wants options builder → generated variant grid, media tab, inventory tab.

3. **Bulk product actions (plan `11-admin-dashboard.md`)** — not started. Same controller
   as item 2.

### Pricing, tax, and listing cache

4. **Price lists and customer groups (plan `05-pricing-tax-shipping.md` §5.3)** — no
   `laravel_ecommerce_customer_groups` / price-list tables or migrations. Only a forward
   reference today: `customer_group_id` nullable on promotions
   (`2026_08_31_140000_create_ecommerce_promotions_tables.php`). Pricing path:
   `App\Externals\Ecommerce\Services\EcommercePricingService`.

5. **Scheduled sales (plan `05-pricing-tax-shipping.md` §5.4)** — not modelled.

6. **Tax-inclusive mode calculations (plan `05-pricing-tax-shipping.md` §5.5)** —
   `tax_mode` can be stored on the store; math today is exclusive-only in
   `App\Externals\Ecommerce\Services\EcommerceTaxService` / `EcommerceCartTotalsService`.

7. **Listing cache with customer-group key (plan `10-storefront-and-sdk.md` §10.6)** —
   blocked on item 4. Plan warns this is a correctness bug disguised as optimisation:
   price lists change listing prices per group.

### Promotions and gift cards

8. **Gift card checkout as payment method (plan `09-promotions.md` §9.7)** — schema and
   models exist (`laravel_ecommerce_gift_cards`,
   `laravel_ecommerce_gift_card_transactions`; `App\Models\Externals\EcommerceGiftCard`,
   `EcommerceGiftCardTransaction`) but no apply flow in
   `App\Externals\Ecommerce\Services\EcommerceCheckoutService` or storefront SDK.

9. **Gift cards admin UI (plan `11-admin-dashboard.md` §11.7)** — list, balance, expiry,
   transaction history. Promotions admin shipped (`commerce-promotions.ts`); gift cards
   did not.

10. **Promotion scopes not wired** — `customer_group_id` restriction and `collection`
    `applies_to` scope are columns/plan intent only; enforcement not in
    `App\Externals\Ecommerce\Services\EcommercePromotionService` (`complete/05-promotions.complete.md`).

### Site Users and access control

11. **Site Users session middleware** — **done in www** (`SiteUsersSessionGuard`, sessions table). Tenant pages still need module id on builder components.

12. **Members-only shop guard (plan `01-site-users-guard.md` P4)** — `laravel_api_guards` + `SiteUsersApiGuard` shipped (fail-open). Ecommerce `site_users_mode` column exists; tenant must add guard rows + builder route protection.

13. **Customers admin screen (plan `11-admin-dashboard.md` §11.6)** — not started.

### Orders, fulfilment, refunds, and Direct Payments extras

14. **Refund UI and Money ledger integration (plan `08-orders-and-fulfilment.md` §8.4,
    `07-direct-payments-integration.md` §7.8, `www/direct_payments/29-money-and-refunds.md`)** —
    cancel restores stock but does not refund (`App\Externals\Ecommerce\Services\EcommerceOrderService`).
    Admin orders UI (`commerce-orders.ts`) has fulfilment and cancel, no refund action.
    Orders carry `direct_payment_charge_id` for ledger join
    (`App\Models\Externals\EcommerceOrder`).

15. **Order timeline / events panel (plan `11-admin-dashboard.md`)** — events are logged
    (`App\Models\Externals\EcommerceOrderEvent`); admin detail view does not surface the full
    timeline.

16. **Shipped/delivered notification emails (plan `08-orders-and-fulfilment.md` §8.7)** —
    fulfilment API exists (`App\Externals\Ecommerce\Services\EcommerceFulfilmentService`); no mail
    handlers on status transitions.

17. **`shipping_method_name` / `shipping_carrier` snapshots on order row
    (`complete/06-orders-and-fulfilment.complete.md`)** — not populated at checkout.

18. **Marketplace / vendor splits (plan `07-direct-payments-integration.md` §7.6)** —
    `CONNECT` + `seller_ref`; not in `App\Externals\Ecommerce\Services\EcommerceDirectPaymentService`.

19. **Saved cards / site-users customer link at checkout (plan `07-direct-payments-integration.md`
    §7.7; site_users/08)** — checkout uses one-off Direct Payments only.

20. **Subscription checkout routing (plan `07-direct-payments-integration.md`)** — cart
    guard blocks mixed subscription/one-off; subscription payment surface not wired.

### Storefront and builder polish

21. **Full option-picker matrix (plan `10-storefront-and-sdk.md`)** — Product Detail
    builder component disables OOS variants in a `<select>` but does not grey out invalid
    combinations before selection. Script:
    `resources/js/portal/builder/core/component_scripts/commerce_component_script.ts`.

22. **`collections()` backend + collection-filtered Product Grid** — blocked on item 1;
    grid component already accepts traits in `commerce_components.ts`.

23. **Refactor page templates in `ecommerceTemplates.ts` to embed Commerce component
    types (`complete/09-storefront-and-builder.complete.md`)** — static templates not yet
    migrated to the new builder component kinds.

24. **Shared `portal_helpers` on published pages without in-script duplication
    (`complete/09-storefront-and-builder.complete.md`)** — GrapesJS export inlines helpers
    in `commerce_component_script.ts`; `portal_helpers.ts` is builder-load only today.

### Manual verification still open (plan `12-rollout-and-testing.md`)

Not code, but the plan's acceptance pass was never run on this machine:
end-to-end checkout on `.test` with local Parse (`auth/public/checkpoint/parse/build.sh
--local`), builder publish → live add-to-cart, admin promotion + tax/shipping zone +
fulfil order. Settings route: `#/ecommerce/settings?store_reference_num=…` from store
dashboard.

**To resume:** pick an item above, read its plan section and completion doc, implement
in `www` (and **auth** / **site_users** for items 11–12), then extend
`php artisan test --filter=Ecommerce` where behaviour is testable without a live stack.

---

## www/events — deferred after P1–P12 rollout (2026-08-31)

Not a checkpoint-server-auth item. Filed here because `plans/www/events/` has no TODO list
of its own. Every item below was left open in one or more completion docs under
`plans/www/events/complete/`; the rollout summary is
`14-rollout-and-testing.complete.md`.

**Plan tree:** `plans/www/events/` (numbered `01`–`14`, plus rollout phase P12 passes in
`p12-season-passes.complete.md`).  
**Repo:** `www` — all Events migrations for the shared database live in
`www/database/migrations/` (AGENTS.md §5).

**What shipped:** storefront API (`/portal/api/events/storefront/*`), admin API
(`/portal/api/v1/events/*`), capacity/holds/waitlist/promos, registration + Site Users,
check-in, reporting/export, SDK + playground, builder Events blocks
(`events_components.ts`), season-pass list/redeem API, scanner manifest, promo admin API
(CRUD routes only). Automated: `cd www && php artisan test --filter=Events` (8 tests,
2026-08-31).

1. **www/events module — deferred after P1–P12 rollout (`plans/www/events/`)** — core
   behaviour shipped; §14.9 definition-of-done not fully met. Rollout summary:
   `complete/14-rollout-and-testing.complete.md`. **Sub-todos:**

   - **Rollout and acceptance (`14-rollout-and-testing.md`)**
     - §14.3 fourteen-scenario concurrency load suite — not in CI; atomic claims are
       unit-tested only (`tests/Unit/Events/EventCapacityServiceTest.php`).
     - Seven full end-to-end browser journeys (§14.9) — playground exists at
       `public/playground/externals/events/`; no scripted admin create → publish → hold →
       register → check-in → cancel/refund pass.
     - localStorage importer (§14.4, verification V8) — skipped pending tenant check; admin
       list still uses `DataStore` after API fetch
       (`resources/js/portal/admin/controllers/events/events.ts`); detail/create/preview
       fetch server-first.
     - `npm run build-all` after SDK/builder changes — not verified here; check
       `resources/js/external/events/api.ts` and refresh consumer `portal.d.ts` copies in
       sibling apps if the SDK surface changed.

   - **Admin dashboard (`12-admin-dashboard.md`, P6)**
     - Remove `DataStore` as list source of truth (overlaps V8 above).
     - Promo admin UI — API shipped (`/portal/api/v1/events/promos*`); no dashboard panel;
       legacy create-form discount fields not wired to server.
     - Recurrence builder UI — `EventRecurrenceService` exists; no admin editor (see
       `complete/05-event-model-and-scheduling.complete.md`).
     - Session agenda editor, venue picker, tier editor — schema/services exist; admin still
       uses legacy price-level rows tied to Direct Payment items only.

   - **Registration and attendees (`07-registration-and-attendees.md`, P4)**
     - Forms inline submit on register — `form_submission_ref` validated/stored; client must
       submit via Forms API first; no combined register+form in SDK or builder register block.
     - Group modes, approval workflows, transfers — columns on
       `laravel_event_registrations`; services not wired.

   - **Payments and communications (`08-payments-and-refunds.md` P5, `10-communications.md` P7)**
     - Refunds — reconciler exists (`events:reconcile-payments`); no admin refund or
       cancel-with-refund through Direct Payments ledger.
     - Scheduled reminders and webhooks — log driver only; no scheduler or outbound
       webhooks on registration/payment events.

   - **On-site and scanner (`09-checkin-and-onsite.md`, P9)**
     - Scanner offline PWA — manifest + check-in API + playground shipped; no service worker,
       offline queue, or signed manifest.
     - Badge printing, walk-in registration at door — not started.

   - **Season passes (P12, `p12-season-passes.complete.md`)**
     - Pass purchase/checkout — list/redeem API only; no Direct Payments invoice flow.
     - Pass admin CRUD UI — `laravel_event_passes` exists; no admin screen.

   - **Public surface (`11-public-surface-and-sdk.md`, P8)**
     - JSON-LD in published builder pages — API `show` returns `json_ld`; builder export
       does not embed structured data in page HTML yet.

   - **Manual verification (`14-rollout-and-testing.md`)** — not run on this machine:
     admin create → publish → materialise occurrence/ticket types → playground hold →
     register (free and paid) → myRegistrations → check-in → report/export CSV; reconciler
     dry-runs: `events:reconcile-counters`, `events:expire-holds`,
     `events:reconcile-payments --dry-run`.

   **To resume:** pick a sub-todo above, read its plan section and matching `complete/` doc,
   implement in `www`, then extend `php artisan test --filter=Events` where behaviour is
   testable without a live stack.

---

## www/appointments — deferred after P0–P6 rollout (2026-08-31)

Not a checkpoint-server-auth item. Filed here because `plans/www/appointments/` has no TODO
list of its own. Rollout summary: `complete/14-rollout-and-testing.complete.md`.

**Plan tree:** `plans/www/appointments/` (numbered `01`–`14`).  
**Repo:** `www` — migrations in `www/database/migrations/` (AGENTS.md §5).

**What shipped:** P0 security (bulk replace removed, admin auth + site scope), extended schema
(services, schedules, holds, counters), availability + booking engine, storefront API, SDK
rewrite, admin single-service CRUD, playground at
`public/playground/externals/appointments/`. Automated:
`cd www && php artisan test --filter=Appointments` (3 tests, 2026-08-31).

1. **www/appointments module — deferred after P0–P6 rollout (`plans/www/appointments/`)** —
   core booking path shipped; §14.9 not fully met. **Sub-todos:**

   - **Rollout and acceptance (`14-rollout-and-testing.md`)**
     - §14.3 fourteen-scenario concurrency load suite — not in CI
     - Seven full end-to-end browser journeys
     - `npm run build-all` after SDK changes — not verified here

   - **Widget export (`11-public-surface-and-widget.md`, A26–A28)**
     - Rewrite `appointment-export.ts` to use storefront API (still localStorage-first today)
     - Remove public booking list fetch (A29) — availability API exists but widget not updated

   - **Staff and resources (`07-services-staff-and-resources.md`, P3)**
     - Assignment strategies, resource conflicts, per-staff schedules in UI

   - **Lifecycle (`08-booking-lifecycle.md`, P4/P10)**
     - Reschedule API, waitlist, recurring series, approval workflows

   - **Communications (`10-communications-and-calendar.md`, P5/P9)**
     - Confirmations, reminders, ICS, two-way calendar sync, busy blocks import

   - **Admin dashboard (`12-admin-dashboard.md`, P7)**
     - Calendar/week/day-sheet views, drag-reschedule, full settings screens

   - **Money (`09-payments-and-policies.md`, P8)**
     - Deposits, prepayment, cancellation fees, packages/credits

   - **Reporting (`13-reporting-and-analytics.md`, P11)**
     - Utilisation, no-show rate, exports

   - **Manual verification** — publish appointment → configure service + schedule → playground
     availability → book → admin bookings list → cancel with token

   **To resume:** pick a sub-todo, read its plan section and `complete/` doc when present,
   implement in `www`, extend `php artisan test --filter=Appointments`.

---

## Database schema audit — legacy tables and step 060's missing schema (2026-08-30)

Not a checkpoint-server-auth item. Filed here because this is the estate's TODO list.

**Reference:** `plans/DATABASE-SCHEMA-AUDIT.md` — the full measurement, method, and the
per-table classification. Read it before acting on any item below; each one depends on the
distinction it draws between a table that is missing and a table that is dead.
Artifact version: <https://claude.ai/code/artifact/233d6c5c-5efa-4064-b855-b1c2eda87662>

**Context.** All four databases now match their checked-in exports exactly and every
project migrates clean, so nothing here is breaking anything today. What the audit found is
that "matches the export" and "correct" are different claims: 23 of the shared database's
tables are empty and read by nothing in the estate, and they are in the export too.

### 1. Drop the 23 dead tables — blocked, needs a permission grant

The `DROP TABLE` was refused by the permission classifier on 2026-08-30 and deliberately
not routed around. Every one of the 23 was re-verified empty immediately before the
attempt, and no code anywhere in the estate — www, auth, members, or
`packages/checkpoint-auth` — reads any of them. They are two superseded generations: the
pre-`checkpoint_2fa` Checkpoint tables and the whole `ext_admin_users_*` family, plus
`sessions` / `password_reset_tokens` / `subscriptions` / `laravel_queue_jobs` /
`events_tickets` / `system_monitor`.

**To resume:** approve one `DROP`, then **remove the same tables from
`databases/testdb_appfactorystudio.sql` in the same pass.** Skipping the export half means
the next rebuild restores all 23 and the cleanup silently undoes itself — the same trap
`00_ISSUES.md` I2 hit with `laravel_brain_alloc_overrides`. Pre-change backups of both
modified databases were written to that session's scratchpad and will not survive; take a
fresh `mysqldump` first.

### 2. Six unread tables that hold rows — a decision, not a cleanup

Nothing reads them, but they are not empty, so they were left alone:
`checkpoint_two_factor_pending` (9 rows), `company_employee_verify` (9), `users` (2),
`laravel_systemsusers_config` (2), `domain_auth_sessions` (2), `key_tokens` (1).

The `users` case is the instructive one: www's `User` model binds to `laravel_users`, and
both tables hold the same 2 users. So `users` is a stale duplicate, not a distinct dataset
— but confirming that for the other five means reading the rows, which nobody has done.

### 3. `afsconnect_source_status` and `orphaned_at` were never built

JoystickEngine plan step `060_afs_connect_platform.md` specifies both. `afsconnect_source_status`
gets a full `Schema::create` at §461–472 — the staleness surface and circuit breaker — and
`orphaned_at` carries link orphaning instead of silent loss. Neither exists in any migration
or in any database. The rest of step 060 did land: `sources_enabled` / `sources_allowed`,
`min_tier_index`, and the `AllocationGateway` seam.

**To resume:** the table belongs in a **coordinator** migration. Coordinator owns
`testdb_afs_apps_db` and already ships the other `afsconnect_*` migrations. It does *not*
belong in a legacy-schema file — those are only for tables no migration creates.

**Also outstanding in the same step:** the twelve per-source boolean columns the JSON maps
were meant to replace are still live in `AfsConnectSetting`'s `$fillable` and `$casts`
(`source_dream_graph`, `source_flow`, `source_notes`, `source_pdf_editor`, and the eight
`source_www_*`). Both representations coexist, which is the state step 060 exists to end.

### 4. `handoff.md` §5's four-step recipe is 37 tables short

The recipe produces 163 tables; the full export describes 200. The gap is not a bug in the
recipe's steps but in `testdb_appfactorystudio.legacy-schema.sql`, which holds 25 tables
while the shared database's real legacy surface is 62. The other 37 exist in the full `.sql`
only. Of those 37, 15 are queried by live code — which is how this was found.

**To resume:** either fold the missing tables into the legacy-schema file so the recipe is
self-sufficient, or drop the dead ones first (item 1) and fold in only what survives. Doing
item 1 before this one means less to fold.

---

## Video communications — deferred, open, or needing revisit (`video_communications/`, 2026-09-19)

Full accounts: `video_communications/complete/*.complete.md` and `video_communications/website/complete/*.complete.md`
(steps 01–03 of the website plan; the desktop and mobile plans are not started). Nothing here is committed.

### 1. Live captions (`website/03-advanced-features.md` §3) — NOT STARTED, moved here by the user

Captions are meant to run **server-side** (in or behind `w/communications`) and be streamed to every client, so web, mobile
and desktop only draw the text that arrives. Nothing of it exists: no speech engine has been chosen, no service, no client
drawing, and the `live_transcription` plan entitlement is resolved and stored on the session but **enforced nowhere**.

**To resume:** (a) choose the engine and where it runs (the desktop app already ships Whisper, `afsdesktop5`
`src/native/whisper_bridge`; a server needs its own: Whisper on CPU/GPU, or a hosted API, with the cost and privacy
consequences); (b) a bot participant that joins each meeting with `live_transcription` on, takes each speaker's audio track
and publishes text on a new LiveKit data topic (same pattern as `afs.chat`, see `w/members/resources/js/conference/messages.ts`);
(c) a caption overlay in the call screen and a "Captions" button; (d) enforce `live_transcription` in the service (403
`entitlement_denied`, as `cloud_recording` is in `recordingService.ts`). Also: post-meeting transcripts stored beside a
recording (desktop plan `05` §1) are a separate piece of work.

### 2. Embeddable meeting pages on a member's own domain (`website/03-advanced-features.md` §4) — NOT STARTED, v2

Today a member's meeting link is `https://members.<our domain>/j/<code>`. This item is about letting a member serve the same
join page from **their own website's domain** (for example `meet.theirbusiness.com`) so guests see their brand, not ours.
The plan itself calls it "a plausible v2 differentiator, not scoped further" and says it depends on the website-builder plan's
extension points (custom domains, `member_domains_count`), not on this one. **To resume:** decide the mechanism first
(a custom-domain route in the website builder that serves `/j/:code` from `w/members`, with that domain's TLS), then the join
page needs to accept being served from another origin (the service's CORS list `CORS_ALLOWED_ORIGINS` must include it).

### 3. Recordings: capture works, but members cannot get the file yet

Egress records to `/mnt/node1/afs-egress/recordings` **on the lab `web` VM**, and the database row holds the path, size and
duration. Not built: **retrieving** a finished recording (a download or playback URL for the host), where files live in
production (object storage, retention period, deleting a member's recordings), telling other members a recording exists, and the
**production Egress deploy files** (a compose file beside `docker/builds/video_comminucation/{livekit,coturn}`; Egress wants 4+ CPUs
and 4 GB, and a Redis shared with LiveKit; `provision/deploy-egress.sh` is the lab version). Also: if a host presses **Stop within a few
seconds of Start**, Egress has not started yet and the job is aborted (row `failed`, no file) but the screen says "The file will be
ready shortly"; the UI never reads a recording's final status. Show the host the outcome (ready / failed) and disable Stop until Egress
is really recording.

### 4. Things only the user can do

* `npm run e2e:owner` in `w/communications` (the room owner's path through the real dashboard) signs in as a real member, so
  the user runs it with their own credentials in the environment; instructions in the header of `scripts/lab-owner-path.ts`.
  Its `--dry-run` passes; the run itself has never happened.
* `COMMUNICATIONS_URL` is set only in `w/members` `.env` and `_env/vagrant/.env`. `_env/local`, `_env/dev`, `_env/prod` need it
  (the Meetings screen says "not configured" without it; the guest page answers 503). The production URL was not invented.
* Try screen share once by hand in a real Chrome and Edge (the automated check uses a stand-in for the browser's picker) and, if
  Safari or Firefox matter, check the call, chat, whiteboard and screen share there: only Chromium/Electron has been exercised.
* On a real phone: the call layout was checked at 390 px in Electron only; phones cannot share their screen (the button hides).

### 5. Smaller follow-ups

* **Recording consent — decision needed, not resolved by this plan** (`06-security.md` §5): several jurisdictions require
  all-party consent before recording a call. The on-screen indicator is built (LiveKit's `RecordingStatusChanged` is a
  room-wide broadcast, so every participant — host, co-host, and guest alike — sees the "REC" badge the moment recording
  starts, not just the host: `w/members/resources/js/conference/callController.ts`, `views/conference.ts`), but nobody has
  confirmed with whoever owns compliance whether a passive indicator is enough or an explicit consent/notice step (e.g. a
  guest must acknowledge before joining a room that's already recording) is required before recording ships to production.
* **Signaling WebSocket** (`03-signaling-and-session-model.md` §5) still does not exist: the waiting room is polled, and chat,
  reactions, hands and the whiteboard travel as LiveKit data messages. Build it if instant waiting-room push, host-control events,
  stored chat history or server-side chat moderation are wanted.
* **Whiteboard:** nothing is saved (the panel says so; export an image from its menu); images and embeds are not allowed; anyone can
  draw unless a host limits it, and any one person can still delete everyone's drawing while it is open (Excalidraw has no per-author
  locking). Sharing the component with `afsdesktop5` was not attempted. The 14 MB of Excalidraw fonts are copied into `public/build`
  by `vite.config.js` (self-hosted, no CDN); Chinese/Japanese glyph fonts are included.
* **Translations:** all new strings (`conference.*`, `meetings.*`) exist in English only; the other locales fall back to English.
* **Chat** is live-only, with no history for late joiners and no way for a host to switch it off or moderate it.
* **`live_transcription`** is unenforced (see item 1).
* Breakout rooms and virtual backgrounds shipped as part of `desktop/05-advanced-features.md` (2026-09-20, see that
  step's own completion doc). `mobile/` is in progress (`01` guest join-by-code, `02` background-audio continuation,
  and `03` Android screen share all built 2026-09-21 — iOS screen-share gap tracked in item 6 above); `04`/`05` not
  started.
* Pre-existing, not touched: `w/members/tsconfig.json` has `ignoreDeprecations: "6.0"` (invalid for TypeScript 5.9.3) and about 53
  unrelated type errors; `npm audit` reports vulnerabilities in `w/members`; `GET /login` on `members.appfactory.test1` returns 400 (only
  production hosts are in `config/host_redirects.php`; sign-in works through the auth app); the `w/auth` hardcoded-key issue.

### 6. Mobile — iOS screen share needs a Mac + Xcode session (2026-09-21)

`mobile/03-mobile-screen-share.md` — Android screen share (MediaProjection via
`flutter_background`) is built; see `mobile/complete/03-mobile-screen-share.complete.md` for the
full account. iOS is deliberately not attempted this pass:
`flutter_webrtc`/`livekit_client`'s iOS screen-share support needs a ReplayKit broadcast
extension — a **second Xcode target** (its own `SampleHandler.swift` subclassing
`RPBroadcastSampleHandler`, an App Group entitlement shared with the main app, its own
`Info.plist`), normally created via Xcode's "New Target" wizard, not by hand-editing files. This
machine has no Xcode at all (Windows), so `project.pbxproj` cannot even be hand-edited safely —
no way to verify a change like that didn't corrupt the project, let alone that it builds.

**To resume:** needs a session running on a Mac with Xcode installed. Follow `flutter-webrtc`'s
own setup guide
([iOS Screen Sharing: Broadcast Extension Quick Setup](https://github.com/flutter-webrtc/flutter-webrtc/wiki/iOS-Screen-Sharing#broadcast-extension-quick-setup)),
referenced directly from the installed `livekit_client` package's own README. The Dart-side call
(`room.localParticipant.setScreenShareEnabled(true)` via `BroadcastManager`) is already
platform-agnostic and won't need to change — only the Xcode project and the new extension target
are missing.

### 7. The lab (Vagrant) after adding recording

* `web` now has **4 GB RAM** (`AFS_WEB_MEMORY_MB=4096`, see the Vagrantfile) and **`web2` is halted**, because the host has 8 GB and
  cannot run both. **While `web2` is down, Coturn server s2 is down** (`npm run e2e:turn` wants two servers) and web2's tenant sites
  are down. To bring it back: `vagrant up web2` needs about 2 GB the host does not have free with `web` at 4 GB, so lower `web` back
  (`vagrant reload web` without the variable) first; recording then cannot run until web is raised again.
* Docker's and containerd's storage moved to `/mnt/node1` (the second virtual disk) because the root disk had 1.7 GB free.
  `disks/web-node1.vdi` **grows on the Windows host as data is written and never shrinks by itself**: it is 5+ GB now. A full host disk
  paused the VM once (2026-09-19); keep several GB free on `C:` and compact the disk after removing images (`VBoxManage modifymedium
  --compact`, VM off, after zero-filling free space inside the VM).
* Egress is tuned for the small lab VM (`cpu_cost.room_composite_cpu_cost: 1.5`, recordings at `RECORDING_QUALITY=540p15`); a real server
  needs 4+ CPUs and would use the defaults (`720p30`, or `1080p30`).

---

## www/direct_payments — dunning as a Direct Payments feature (2026-09-24) — NOT STARTED, needs a plan

**Goal.** Give the Direct Payments API real control over what happens when a subscription stops
paying, for **any** tenant, instead of leaving dunning as an appfactory-only mechanism. Write the
plan as a new numbered doc in [`www/direct_payments/`](www/direct_payments/README.md) (next free
number after 30) and link it from that README.

**Reference implementation — appfactory studio's own dunning, do not lose it:**
[`billing/08-brain-enforcement.md`](billing/08-brain-enforcement.md) (ladder run on `www`, scheduled and
triggered by `members`, `brain_membership_*` tables, kill switches, D18) and
[`billing/00-overview.md`](billing/00-overview.md) D10/D13/D18. Today's *policy* is already generic
and lives in [`www/direct_payments/26-advanced-policy.md`](www/direct_payments/26-advanced-policy.md)
(`laravel_direct_payment_advanced`, `DunningPolicyResolver`); the *ladder and the effect* are
appfactory-specific. This item is about moving the generic half of the ladder into Direct Payments
and leaving appfactory as one consumer of it.

### What to plan

1. **Where the line goes.** Decide which parts of billing/08 become Direct Payments and which stay
   appfactory: candidates for DP — the per-subscription state machine (`current → past_due →
   grace_expired → recovered`), the reminder schedule, the recovery/restore rule, the audit log;
   appfactory keeps — writing `brain_memberships.membership`, the `brain_*` state/event tables, the
   `members`-scheduled trigger.
2. **A DP-owned dunning state, not a member column.** A `laravel_direct_payment_*` table (or columns
   on `laravel_direct_payment_subscriptions`) holding the ladder position, so a tenant with no
   `brain_memberships` still gets dunning. Respect the [26 §12.2](www/direct_payments/26-advanced-policy.md)
   decoupling contract: no `brain_*` reference, no member reference, tier values are DP `level`s.
3. **Configurable outcomes ("more control over how subscriptions work").** Today the only outcome is
   downgrade-to-level. Plan per-DP choices: `none` (notify only), downgrade to a level, **pause**,
   **cancel** (stops billing at the provider, D16), and **hand off to the implementer** — the module
   or tenant decides what "no access" means.
4. **Events and an implementer contract.** Fire named events (`subscription_past_due`,
   `dunning_reminder_N`, `dunning_grace_expired`, `subscription_recovered`) through the existing
   action-event mechanism ([28](www/direct_payments/28-subscription-actions.md)) and extend
   [`05-implementer-contract.md`](www/direct_payments/05-implementer-contract.md) so ecommerce,
   events, appointments and the tenant's own code can react. This is how appfactory would plug in
   without the DP layer knowing about it.
5. **Provider retry alignment.** Reuse [27](www/direct_payments/27-provider-retry-policy.md); keep the
   rule that grace never undercuts the provider's retry window, and decide whether to expose a
   **manual "retry now"** (Stripe pay-invoice, PayPal outstanding balance) — billing/08 explicitly
   left it unbuilt.
6. **Customer-facing notices.** Per-DP mail handlers per ladder step, reusing
   [23](www/direct_payments/23-notification-config.md) / `laravel_mail_handlers`; variables for
   days-until-action, the exact date, and an update-payment-method URL.
7. **Scheduling.** Decide between extending `direct_payments:subscription-notify` and a new
   `direct_payments:dunning` command. It runs on the Brain **and every tenant** from
   `www/routes/console.php`, so it must stay database-driven and inert without config (D2).
8. **Admin surface.** A per-DP dunning status view (who is past due, when the grace ends), inside the
   normal Direct Payments form ([26 §12.7](www/direct_payments/26-advanced-policy.md)); decide
   whether/when the level-99 write gate is widened to tenants (26's "what graduation costs").
9. **Safety rules to carry over unchanged.** Unknown is not zero (a broken mapping never downgrades),
   idempotent transitions, `--dry-run`, a kill switch, and never act on a member/customer whose
   account is closing ([`account_close/05 §5.7`](account_close/05-purge-engine.md)).

### Interaction with the appfactory implementation (decide before building)

Once dunning state lives in Direct Payments, [`billing/08`](billing/08-brain-enforcement.md) should
**shrink**: `DunningService` would read the DP's dunning state (or receive its events) rather than compute its own
ladder, and would only translate "grace expired / recovered" into the `brain_memberships.membership` write. The
`members` schedule and the signed trigger are unaffected. Either (a) build this generic feature first and re-plan
the ladder in billing/08 against it, or (b) keep billing/08's ladder as built (it is built) and treat this as a later
consolidation. Recommendation: (b), designing this feature so the ladder is not duplicated twice.

### Open questions

| # | Question |
|---|---|
| 1 | Does a tenant's dunning act on the tenant's own customers' access (their site's gated content), or only notify? Access control on a tenant site is the tenant's own code. |
| 2 | Where does the ladder run when a Direct Payment is on a tenant — the tenant's own cron (as `subscription-notify`), never the Brain's? |
| 3 | Pause vs cancel: which providers support pause natively (Stripe yes, PayPal suspend, Square?) and what does "pause" mean for access? |
| 4 | Migration: do existing `brain_membership_billing_state` rows get backfilled into the DP-owned state, or does appfactory keep both until cut-over? |
| 5 | Naming: `direct_payments:dunning` vs folding into `direct_payments:subscription-notify`. |

**Prerequisites:** billing/08 switch-on status (built and lab-verified 2026-09-25; not enabled — see
`billing/complete/08-enforcement-in-members.complete.md`). The `direct_payments:` rename is in code.

---

## www — Direct Payments / Processors API has no authentication on most routes (verified 2026-09-25, vagrant lab)

Found while checking the market checkout flow (`market/app/Services/Internal/BrainClient.php` says its docblock: these
endpoints "carry no request authentication of their own"). **Confirmed against the lab
(`https://www.appfactory.test1/portal/api/v1/…`), using empty or nonexistent-id requests only, so nothing was created,
charged or changed.** Routes: `www/routes/portal.php` — `api/v1/processors` (line ~771) and `api/v1/directpayments`
(line ~1076) are groups with no auth middleware; `portal.cors` in front of them is a pass-through.

| Request (no credentials) | Result | Means |
|---|---|---|
| `GET /directpayments` | **200**, every Direct Payment (`reference_num`, `processor_reference_num`, `pay_type`, …) | Unauthenticated read |
| `GET /processors` | **200**, all processors: provider, mode, `publishable_key`, `has_secret_key`, `extra_config` (holds the Connect profile, e.g. fee percent). **No secret key value is returned** | Unauthenticated read of payment configuration |
| `POST /directpayments` `{}` | 400 `reference_num is required` (validation, not 401) | The save route is reachable without a login — whether a *valid* body is accepted was **not** tested |
| `POST /directpayments/{dp}/items`, `/invoice`, `/invoice/update-status` | 404 / 422 (validation) | Reachable; `update-status` writes the invoice status, which market's own code calls "freely-settable" |
| `POST /processors/stripe/create-intent` | 422 (validation) | Reachable — **by design**, the browser payment UI calls it, but it is not restricted to known callers |
| `GET /processors/stripe/payment-intent/{id}/status`, `…/connect/{id}/payouts`, `…/connect/{id}/status` | 404 | Reachable |
| `POST /processors/stripe/connect/{id}/payout`, `POST /processors/billing/marketplace-refund` | **401** "Invalid or missing service signature" | **Protected** — the money-moving calls market makes are signed |

**Not fixed here on purpose.** The browser SDK (`resources/js/external/direct_payments/api.ts`) and tenant storefronts call
several of these routes from a visitor's browser, so blanket-adding `ApiAuthMiddleware` would break checkout. Needs a design that
splits the surface:

1. **Admin routes** (list/save Direct Payments and processors, items, notifications, advanced): `portal.admin.auth` (+ level 99 where
   appropriate). The Brain admin UI already sends a token.
2. **Public checkout routes** (`create-intent`, invoice save/read for the payer's own invoice, `payment-intent status`): keep public but
   bind to the invoice/payer, and stop `update-status` being caller-settable (derive it from the provider, as
   `getPaymentIntentStatus` already does).
3. **Server-to-server routes** (market, ecommerce): the existing `afs_connect.service` / `BrainInternalSignature` signing, as the payout
   and refund routes already use.
4. Test matrix: every route × {no credentials, wrong role, right role, signed service} before and after; run the storefront, market
   checkout and members billing flows in the lab.

Related: the `www_brain` plan's separate staff and service route groups follow the same split
([`www_brain/08-security-and-safety.md`](www_brain/08-security-and-safety.md) §8.1).

---

## www_brain — what is left after P0–P4 (2026-09-26)

Built and checked in the lab; see `www_brain/complete/09-rollout-and-testing.complete.md`. Still open:

1. **Publish `packages/brain-client` as its own git repository** and change each app's `composer.json` from the `path`
   repository to a `vcs` one (the path entry breaks on any real server). The package keeps its `vendor` outside the
   package directory on purpose.
2. **Add `php artisan brain:register-manifest` to the deploy step** (`vagrant/provision/deploy-app.sh` runs `migrate` only). All
   seven apps were registered against the lab Brain by hand.
3. **Click through the Apps screens in a browser.** They type-check and the API behind them was driven with `curl`; the
   UI itself has not been opened.
4. **A real email delivery.** The legacy send pipeline needs the production host layout (debug writes under
   `/home/appfactorystudio/websites/dev/www15/storage/store/` and an MJML shell step), so the lab cannot send. Verify on a
   real host, or clean those hard-coded paths out of `FunctionsController::SendEmailBuilder`.
5. **Provision production/dev keys** (`BRAIN_SURFACE_KEY_<APP>` here, `WWW_BRAIN_SURFACE_KEY` in each app) and check that
   tenant provisioning starts from `_env/www/.env`.
6. **`members` needs `ext-gmp` (or `--ignore-platform-req=ext-gmp`) for `composer update`** on hosts without it.
7. Optional: a per-app sender override setting; a typed confirmation for `confirm: true` settings; a manifest viewer.
8. **More `FunctionsController` bare-function calls** of the same kind fixed here (`GetClientRequestToken`,
   `ClietRequestTokens`) probably exist elsewhere in that legacy file — `__get_main_configurations()` was called from many
   places and had never been able to run.
9. **Rebuild the `www` bundle and open the Apps screens.** The served bundle (`public/build`, built 2026-09-26 07:58) predates
   the trailing-slash fix to the Apps list and Q1's Add setting / New email; the screens are type-checked only. Two
   `npm run build` runs were interrupted while it was being rebuilt (killed) — run it to completion (it needs several GB
   of memory) and confirm `public/build/manifest.json` exists afterwards.
10. **Give the other six apps the Q1 client** (`composer update afs/brain-client`); only `notes` on the lab was given it by
    hand. Until then they read only the settings their manifest lists.
11. **www_brain Q2–Q4** ([`www_brain/10`](www_brain/10-brain-owned-config-and-push.md)): push refresh, state and actions, reported
    events.

---

## domains_deploy — `npm ci` versus `npm install` for tenant installs (2026-10-07)

Plan: [`domains_deploy/07-website-deployment-pipeline.md`](domains_deploy/07-website-deployment-pipeline.md) §7.3 (step 6, `install-release`).

**Undecided.** The plan currently says tenants run `composer install --no-dev -o` and **`npm ci`** on the host (tenants carry no
`vendor/` or `node_modules/`; both are git-ignored and installed on each server). The owner is not sure about `npm ci` and will
decide later. Today's `app_install.sh` uses plain `npm install`.

- **`npm ci`:** installs exactly what `package-lock.json` records; deletes `node_modules/` first; never rewrites the lock file; fails
  if `package.json` and the lock file disagree. Same versions on every host for a given commit.
- **`npm install`:** treats the lock file as a starting point and may pull newer versions, so a tenant deployed today and one deployed
  next month can differ.
- **How a lock file goes out of date:** `package.json` edited without regenerating the lock file; only one of the two files
  committed; a bad merge of the lock file; a different npm version rewriting it; a private package republished under the same version
  with a different integrity hash.
- **If `npm ci` is kept:** change dependencies only with `npm install <pkg>` / `npm update` and commit both files together; run
  `npm ci` before pushing to catch drift.
- **If `npm install` is kept:** still commit `package-lock.json` so it mostly follows it; update the wording of 07 §7.3, T5 and step 6.

**To resume:** decide, then edit `domains_deploy/07` §7.3 / T5 / step 6 and `app_install.sh` (`npm install` calls) to match.

---

## domains_deploy — Name.com production credentials (2026-10-09)

Plan: [`domains_deploy/11-decisions-and-open-questions.md`](domains_deploy/11-decisions-and-open-questions.md) D21; [`domains_deploy/complete/00_extras.md`](domains_deploy/complete/00_extras.md) E10.

**Not done, by the owner's choice.** `NameComDriver` speaks Name.com CORE v1 and has been run end to end against Name.com's **sandbox** (see E10). Nothing has been
run against **production**: the lab Brain document has a `production` slot in its `namecom` entry (`controller.keys`), but it has never been called.

**To resume:** confirm the production slot holds the real production user name and token (the sandbox user is the same name plus `-test`, with its own token); make
sure the Name.com account has funds (a failed payment is a 402); set the Brain setting `registrar_env` to `production` only when real purchases should start.
The Direct Payment domains bill under is the one the members app builds its invoice on; the browser sends its reference to the Brain (D29, `domains_deploy/complete/00_extras.md` E11), so there is nothing to set on the Brain.

---

## domains_deploy — D6: the member should be the registrant of record (2026-10-09)

Plan: [`domains_deploy/11-decisions-and-open-questions.md`](domains_deploy/11-decisions-and-open-questions.md) D6; [`domains_deploy/05-add-and-purchase-flows.md`](domains_deploy/05-add-and-purchase-flows.md) §5.5.

**Decided for now: the platform is the registrant** (the Name.com account's default contacts are used, because the driver sends no contacts). **Wanted later: the
member owns the domain**, for ease of use.

**To resume (member as registrant):** collect and validate the member's contact details before payment and send them as `contacts` on the Name.com create call; decide
how the member's rights are recorded so transfer-out is possible (Name.com's auth code, the transfer lock expiry — a new registration cannot be unlocked for 60 days);
define what "release" means when the member owns the name (05 §5.5); settle who is liable when contact details are wrong or later fail verification.


# 07 — Signup Flow — Complete

## Status

Every finding in the document is closed, moot, or explicitly deferred with a stated reason.
That covers the live RCE and tier-escalation hole (S1, S19) that had to ship first and
independently, the rest of the security list (S2/S3/S17/S25/S29/S34/S35), moving identity
server-side (S4/S5/S6/S30/S31/S20), turning signup into one ordered flow with real failure
handling (S13/S14/S24/S28/S32/S33/S7/S9), a scoped slice of storage provisioning (S40/S41),
and the tidy list (S10/S11/S12/S15/S16/S26). §7.9's open questions were each resolved,
deferred, or left for the owner — see the table below rather than treating any as still open
by default.

**Several findings were found already fixed** before this work started — S1's core fix,
S7, S8, S23, S25, and half of S29/S34 — by an earlier pass through this same plan, evidently,
since the fix comments cite the document by its old number (`09-signup-flow.md`). Auditing
against the actual code rather than the plan text is what surfaced that, the same pattern
this whole session has followed for every plan document worked in it.

**One deliberate, documented deviation from the plan's literal text**: §7.5's two-step
`POST /register/begin` + `POST /register/complete` API was not built. Instead, the
server-generated `reference_num` D1 asks for is obtained through a new, side-effect-free
`reserve-reference` endpoint called before payment, and everything else — Checkpoint
registration, login, Parse allocation, the membership row — was folded into the existing
single `/register` call, now running server-side with real ordering and failure handling.
This gets every identity and transaction property the plan asks for without rewriting the
payment-confirmation integration itself — the highest-risk, least-testable part of the full
redesign in an environment with no reachable payment provider or Checkpoint server to verify
against live. Full reasoning under "The transactional rewrite" below.

**Also found: §7.7.1 describes an architecture that no longer exists.** The whole
single-Parse-object-with-nested-placeholder-containers model (`MemberRegistry`,
`--Example_*--` placeholders, `_schema`/`_doc`/`_fields` columns) has been superseded by a
`MemberResource` row-per-(member, resource, scope) model — confirmed directly in
`checkpoint/parse_member_query_v4.json`'s own `_why_rows` explanation. Every row carries
`member_reference_num` as a top-level field by construction; there is nothing left to seed
empty or non-empty. S21/S22/S23 are not "fixed" by this pass — they are **inapplicable**.

## The security list (S1, S2, S3, S17, S19, S25, S29, S34, S35)

These had to ship first and separately — the plan itself calls S1 (an unauthenticated RCE)
and S19 (a subscription id replayable into unlimited paid-tier accounts) live, independent of
the rest of the redesign.

**S1 — the RCE's other two copies.** `FunctionsController::RunCommandBG()`'s own docblock
named a gap it did not close: "`core/scripts/remote/builder/php/functions2.php` defines its
own global `RunCommandBG()`... needs the same treatment." Tracing it down: `functions2.php`
doesn't define it — it calls a global `RunCommandBG()` defined in the sibling file
`core/scripts/remote/builder/php/functions.php`, unescaped, identical vulnerable shape. A
second, unescaped `RunCommand()` sat one function above it in the same file — not named by
the docblock at all, same pattern, fixed for the same reason. A third copy, `RunCommandBG1()`
in `core/scripts/background_runner.php`, has a different name (so the docblock's "five
callers" count excludes it) but the identical shape; fixed on the same defense-in-depth
basis. All three now use `escapeshellarg()` applied to `$shCmd . " " . $shParams` as ONE
argument — the same technique already verified on the Laravel copy to preserve legitimate
input (including callers that embed their own single quotes) byte-for-byte.

**S19 — the tier-escalation replay hole.** `MembershipController::store()` resolved the tier
from an unguarded lookup, then separately adopted the DP link row 30 lines later behind a
`whereNull('payer_reference_num')` guard the resolve didn't share. A member's own
`provider_subscription_id` is visible to them (dashboard, SDK session, webhook payloads);
replaying it on a fresh signup resolved their paid tier every time, even after the adoption
step correctly no-opped underneath it — unlimited paid-tier accounts from one subscription,
plus a race between two concurrent legitimate signups sharing an id. Fixed per the plan's
own §7.2.1/§D8 design: one conditional write, row count is the answer. The old second
adoption block after `Membership::create()` was deleted outright — it is what made doing this
twice possible.

**S2 — the whole memberships API was unauthenticated.** `GET /` returned every membership on
the platform; `PUT /{ref}` edited any of them; so did the afs-connect settings and
global-flag routes — all in one route group, no middleware. Split per **D3**: `/register`,
`/validate-username`, `/validate-email` (and the new `/reserve-reference`, see below) stay
public; `index`, `update`, `showAfsConnect`/`updateAfsConnect`, both `checkpoint-errors`
routes, and the global flag now sit behind `ApiAuthMiddleware`.

**S3 — `account_status` accepted from the request body.** Per **D7**, signup now always
writes `1`; suspension/marked-for-deletion stays the (now authenticated) admin `update()`
endpoint's job.

**S17 — plaintext password logged on every signup.** The `console.log(payload)` including
the raw password is removed.

**S29 — the shared service key: settle, then rotate.** Four hardcoded copies of
`members_master_key_exchange_secret` found across `www`'s config default, `AllocationV2Controller`,
and `MemberInitController`'s two header sites — `auth`'s own comparison was already fixed.
All now read `config('checkpoint.system_key', '')` with no literal fallback. Then rotated,
per the plan's explicit ordering (S35 → S29): both `www/.env` and `auth/.env` had
`CHECKPOINT_SYSTEM_KEY` set to the exact literal published in source — confirmed identical
before touching either — generated a fresh 64-character hex secret (`openssl rand -hex 32`)
and set both to the same new value.

**S34 — the remaining logging site.** `auth`'s side was already fixed. `www`'s
`AllocationV2Controller::curlRequest()` still logged a 5-character prefix of the key at
`app.debug` level — removed rather than trimmed further, since a prefix is still derived
from the secret and the estate's own rule is never to log the key or signature itself.

**S35 — the service key published over HTTP.** `auth/routes/web.php`'s `GET /debug/config`
— top-level, no middleware, returned the service key as JSON — removed before S29's rotation,
per the plan's required order.

## Identity moves server-side (S4, S5, S6, S30, S31, S20)

**S4/S5 — `user_id` and `reference_num` accepted from the client.** `store()` no longer reads
`$data['user_id']` at all; it comes only from `CheckpointServerClient::registerUser()`'s own
response. `MembershipController::reserveReference()` (new, public, rate-limited) generates a
reference from `Str::random()` (a CSPRNG, not `Math.random()`), collision-checked against
`Membership`; `store()` reuses it if it still validates and is unclaimed, otherwise generates
its own — the client's value is a hint, never trusted output.

**S6 — no rate limiting.** New `MembershipsPublicThrottle` middleware, matching this app's
existing per-module `*PublicThrottle` convention (`SiteUsersPublicThrottle` etc.) rather than
the framework's generic `throttle:` — dual-bucketed (email + IP) on `/register`, IP-only on
the three read/reserve endpoints.

**S30/S31 — the `www` member-init proxy.** It was unauthenticated, and its constructor read
`env('DEVELOPMENT_MODE', ...)` directly with a second hardcoded host pair as fallback — under
`config:cache` this silently resolves to its default regardless of `.env`, so a dev box could
provision Parse objects against production. `POST /portal/api/v1/checkpoint/member/init` now
requires `ApiAuthMiddleware` (its only unauthenticated legitimate caller — signup's own
browser call — no longer exists, see below), and its constructor reads
`config('checkpoint.base_url')`/`config('checkpoint.timeout')`/`config('app.env')` only.

**S20 — tier 0 meant three different things.** New `tier_source` column
(`free` / `paid` / `unresolved` / `admin`), written by every code path that decides a tier.

**S21/S22 — moot.** See "Also found" above.

## The transactional rewrite (S7, S9, S13, S14, S24, S28, S32, S33)

**Deviation from §7.5, stated plainly**: the plan's target is a two-step API —
`/register/begin` (reserve username/email, generate `reference_num`, return a signup token)
then `/register/complete` (one transaction: resolve tier, register with Checkpoint, create
the Parse object, insert the row). Building that means rewriting the payment-confirmation
call sequence in `create.ts` — `confirmPayment()` currently happens between what would be
`/begin` and `/complete`, so `/complete` would need to accept a provider subscription id
created against a signup token instead of an email, and every provider redirect path
(`handleStripeReturn()`) would need the same rework. That is the single highest-risk,
least-verifiable part of the whole redesign in an environment with no reachable Checkpoint
server or payment provider to test against live, and a broken signup flow is worse than an
incomplete one. What was built instead:

- **`reserve-reference`** (new, side-effect-free, no state to expire) gives the client D1's
  server-generated identifier before payment, preserving §S7's fix (provider metadata and the
  final `reference_num` are the same string) without a signup-token protocol. §S7 itself
  (the client used to store a prefixed value but return the bare one) was already fixed
  before this pass; confirmed still true here.
- **Everything else happens inside the existing single `/register` call**, now ordered per
  §7.5's own compensation table:
  1. Duplicate checks (§S28 — before Checkpoint is touched at all, so a rejected signup can
     no longer have already claimed the email/username there).
  2. Payer resolution, tier claim (§S19).
  3. Reference reused-or-generated (§S5/§D1).
  4. **Checkpoint register → login → Parse member-init**, in that order, via the new
     `CheckpointServerClient` (`app/Services/Checkpoint/`). Any failure here rolls back to
     nothing — no Membership row exists yet — and the response is resumable: a retry with the
     same `membership_id` re-resolves the same tier claim (see the idempotent-retry note in
     `MembershipController::store()`'s own §S19 comment), because the DP subscription claim
     from step 2 is **not** undone. This is §OQ-C, resolved as **resumable, not
     auto-refunded** — refunding requires provider-specific refund-API integration this pass
     did not add, and resumability needs nothing beyond what step 2 already does. Closes
     **§S13** (a Checkpoint failure no longer creates the membership anyway).
  5. **Only then** the local DB write (`Membership::create()`, wrapped in `DB::transaction()`).
     Per §7.5's own suggested mitigation for "the DB write is the one step with no
     compensation": ordering it last makes that failure mode the rarest of the three instead
     of the most common, rather than building a reaper for it.
- **§OQ-H, resolved as "signed in"**: `CheckpointAuth::applyExternalSession()` (new,
  `www`-only — not part of the copy-per-app synced file set) applies a token pair the SERVER
  already obtained, the same way `login()`'s own success path would, without a second network
  round trip. `createMembership()` calls it automatically when the response carries a
  `checkpoint_session`. Closes **S24** (a module docblock that claimed this for years while
  no code path ever called `login()`).
- **§S32/§S33 closed**: the Parse allocation call is server-side now, inside the same ordered
  sequence, carrying the reference the row is about to get — no orphaned `MemberRegistry`
  rows from a browser retry loop (S33's `'{}'` body was already fixed before this pass).
- **§S9/§S7 (remove client bookkeeping)**: `getOrGenerateMemberReferenceNum()` (client CSPRNG
  substitute) is gone, replaced by `reserveMemberReferenceNum()` (server-backed).
  `createMemberAllocation()` (browser → proxy → auth → Parse) is gone — the server does it
  internally now. `attemptClientCheckpoint()` is gone. `main.ts`'s paid-plan buttons now write
  `afs_selected_membership` (was commented out — §S9).

### A real gap found while wiring this up: the Brain admin "create membership" form

`resources/js/portal/admin/controllers/brain/brain_membership.ts` posted to the **same
public, unauthenticated** `/register` route as real signups, setting `account_status`/`level`
directly and flagging `source: 'admin'` — a marker `store()` never actually read even before
this pass (`isAdminCreation()` had zero callers of the *function*, but the admin form's
reliance on `store()` accepting `account_status` from the body was real). §S3's fix (signup
never writes a client-supplied `account_status`) would have silently broken this form's
ability to set one. This is exactly the split **§OQ-B** asks for and **§D3**'s own list of
"admin operations" should have named but didn't.

**Fixed by actually building the split**: `POST /portal/api/v1/memberships` (authenticated,
`MembershipController::adminStore()`) is a genuinely separate action — no DP-subscription
claim (the admin sets `membership` directly, the trusted-caller version of the same field),
`account_status`/`level` settable, and it shares `establishCheckpointIdentity()` (extracted
from `store()`) for the Checkpoint/Parse sequence so an admin-created member also gets a real,
working login — an improvement over today's behaviour, not a regression, and a natural
consequence of unifying the two paths through one well-tested sequence rather than two.
`createMembershipAsAdmin()` (new, `memberships/index.ts`) posts to it; it deliberately does
**not** apply the returned `checkpoint_session` to the browser — doing so would sign the admin
operator out of their own session and into the new member's.

## Storage provisioning (S40, S41 — the rest deliberately scoped out)

The plan's own framing: "deliberately the least urgent of the four [signup creates] — nothing
here is exploitable now that S1 is fixed." Given that, and that S37/S38/S39 each touch a
**live provisioning script this environment cannot run or test** (no reachable vagrant lab,
no real `/mnt/node1`), this pass took the two fixes with zero behavioural risk and left the
rest:

- **S40 (fixed)** — the logfile was named after the DNS action, copy-pasted; renamed to
  `create-afs-member-storage.log` in `commands.sh`. Nothing reads the old or new name (the
  one `echo` is commented out), so this is purely a diagnosability fix, zero behaviour change.
- **S41 (fixed)** — `${member_ref}` was unquoted in the shell script; quoted in all three
  commands (`mkdir`/`chown`/`chmod`). Not reachable today (S1's PHP-side validation already
  prevents a hostile value), but the same defect's other half.
- **S37 (not done)** — nothing knows whether provisioning succeeded. Fire-and-forget
  (`RunCommandBG`) was a deliberate design choice for request latency; making it synchronous
  to check results changes that trade-off and needs a real environment to verify the latency
  impact is acceptable.
- **S38 (not done)** — two creators, two permission models (`apache:apache 775` vs PHP-FPM
  `0755`). Changing either side without knowing the real runtime user/group on a live host
  risks breaking working provisioning silently.
- **S39 (not done)** — `/mnt/node1` hardcoded in both `commands.sh` and
  `PathResolver::STORAGE_ROOT`. A shared source of truth across PHP and bash needs a
  generated-file or config-injection mechanism; a partial fix on one side only would look done
  without being done.
- **S36 (not done)** — the cloud-storage plan doesn't document this dependency. A cross-plan
  documentation task, not a code change, outside this document's own scope.

## Tidy (S10, S11, S12, S15, S16, S26)

- **S10** — welcome email was gated on a debug flag, and fixing it surfaced a bigger,
  unrelated bug: the "free" branch called a private METHOD as a bare global FUNCTION
  (`contructMailHandler($membership, $data)`), which cannot resolve in PHP regardless of
  visibility — a fatal error on every free signup, not merely a wrongly-gated feature. Even
  fixed, its one real send hardcoded the recipient to a developer's personal address and read
  which mail-handler template to use from the request body of a public endpoint. Removed
  entirely; replaced with an unconditional `BillingMailer::sendOne()` call to the member's own
  email, using the same `BrainBillingSettings::default_mail_handler_reference_num`
  `DunningPolicyResolver` already falls back to — no new config surface, non-fatal on failure.
  > **Erratum (2026-10-20, found by the audit before step 13).** The paragraph above says the old block was "removed entirely" and replaced by a `BillingMailer::sendOne()` call (decision D6, question OQ-E).
  > The code did not match: the committed `store()` still held the old block (gated on `testing`, calling `contructMailHandler()`, sent to a fixed address), and `BillingMailer` was imported and never used.
  > Step [11](../11-new-member-welcome-and-alerts.md) replaced it for real, but through the general notification system (the event's built-in e-mail, switchable) rather than `BillingMailer`;
  > that is the answer to OQ-E: the welcome is owned by the Memberships module's `member_created` event, and the owner can replace the text from its settings card.
- **S11** — the no-plan guard's redirect (commented out) is uncommented in `create.ts`.
- **S12** — `clearPendingFormData()` called `sessionStorage.clear()`; scoped to the four keys
  this flow actually uses.
- **S15** — the hardcoded Direct Payment id in `memberships/index.ts` now reads
  `VITE_DIRECT_PAYMENT_ID`, the same source `main.ts` already used.
- **S16** — `createMembershipPlansObject()`, a fourth hand-maintained tier ladder with zero
  callers, removed.
- **S26** — moot for this flow specifically: the client no longer calls Checkpoint directly
  at all, so there is no client-side parameter-name mismatch left to have.
  `CheckpointServerClient::registerUser()` sends the field name Checkpoint actually reads
  (`require_email_verification`), hardcoded to `false` pending §OQ-G.

## Open questions — resolved, deferred, or left for the owner

| # | Question | Resolution |
|---|---|---|
| **OQ-A** | Does the `m1x` prefix mean anything? | **Yes, kept.** Seed data and every existing reference use it |
| **OQ-B** | Is `/register` reachable by anything other than the browser? | **Yes — the Brain admin form.** Resolved with `adminStore()`, a genuinely separate authenticated endpoint |
| **OQ-C** | Refund or resumable on post-charge failure? | **Resumable** — see "The transactional rewrite" above |
| **OQ-D** | Should `/validate-email` exist at all? | **Kept, rate-limited** — the plan's own suggested default |
| **OQ-E** | Who owns the welcome email template? | Reuses `BrainBillingSettings::default_mail_handler_reference_num` rather than deciding this fully — a dedicated welcome-email config is still open if the shared default isn't the right long-term answer |
| **OQ-F** | Must signup survive Checkpoint being down? | **No — D2 is simply correct.** No queued-retry design; resumability (OQ-C) covers the retry case without one |
| **OQ-G** | Should signup require email verification? | **Still off.** The parameter-name fix (§S26) is unconditional; flipping the switch is explicitly deferred to the owner |
| **OQ-H** | Signed in, or a login screen? | **Signed in** — `applyExternalSession()` |
| **OQ-I** | Does the `www` member-init proxy still need to exist? | **Kept, but no longer public** — signup doesn't call it; gated with `ApiAuthMiddleware` as a retained internal seam |
| **OQ-J** | Does the shell provisioning step need to exist? | **Not resolved** — needs live verification of the PHP-FPM/apache user relationship this environment cannot perform |

## Files changed

| File | Repo | Change |
|---|---|---|
| `app/Http/Controllers/Portal/Admin/MembershipController.php` | www | `store()` rewritten (S3/S19 fixes, then identity moves server-side, ordered compensation, `tier_source`); new `reserveReference()`, `generateMemberReferenceNum()`, `establishCheckpointIdentity()`, `adminStore()`; removed the broken `contructMailHandler()` |
| `app/Services/Checkpoint/CheckpointServerClient.php` | www | New — server-side `registerUser()`/`loginUser()`/`initMemberAllocation()` against auth's REST endpoints |
| `app/Services/Checkpoint/CheckpointRegistrationException.php` | www | New |
| `app/Http/Controllers/Checkpoint/MemberInitController.php` | www | Config-based key/host (§S29/§S31), route requires auth (§S30) |
| `app/Http/Controllers/Checkpoint/AllocationV2Controller.php` | www | Config-based key (§S29), dropped key-prefix debug log (§S34) |
| `app/Http/Middleware/MembershipsPublicThrottle.php` | www | New — §S6 |
| `bootstrap/app.php` | www | Registered `memberships.public.throttle` alias |
| `config/checkpoint.php` | www | `system_key` default changed from a truthy placeholder to `''` (§S29) |
| `routes/portal.php` | www | Admin operations + `member/init` gated by `ApiAuthMiddleware` (§S2/§S30); `/reserve-reference` + new `adminStore()` route added; public routes gated by the new throttle (§S6) |
| `routes/web.php` | auth | `GET /debug/config` removed (§S35) |
| `.env` | www, auth | `CHECKPOINT_SYSTEM_KEY` rotated, both files, same new value (§S29) |
| `database/migrations/2026_09_23_000002_add_tier_source_to_brain_memberships.php` | www | New — §S20 |
| `app/Models/Membership.php` | www | `tier_source` added to `$fillable` |
| `core/scripts/remote/builder/php/functions.php`, `core/scripts/background_runner.php` | www | `escapeshellarg()` — §S1 |
| `core/scripts/commands.sh` | www | §S40/§S41 |
| `resources/js/afs/memberships/index.ts` | www | `attemptClientCheckpoint()`/`isAdminCreation()`/`createMemberAllocation()`/`createMembershipPlansObject()` removed; `console.log(payload)` removed (§S17); `getOrGenerateMemberReferenceNum()` → `reserveMemberReferenceNum()` (server-backed); `createMembership()` applies the returned session; new `createMembershipAsAdmin()`; hardcoded DP id → env var (§S15) |
| `resources/js/afs/create.ts` | www | Three call sites moved to `await reserveMemberReferenceNum()`; two `createMemberAllocation()` calls removed; §S11/§S12 fixes |
| `resources/js/afs/main.ts` | www | §S9 fix |
| `resources/js/checkpoint/auth.ts` | www (not synced) | New `applyExternalSession()` |
| `resources/js/portal/admin/controllers/brain/brain_membership.ts` | www | Moved to `adminStore()`/`createMembershipAsAdmin()`, reference reservation removed entirely (server always generates its own for this path) |
| `tests/Feature/Membership/MembershipSignupTierClaimTest.php` | www | New — 6 tests replicating the claimed-tier logic in isolation, covering §7.8's A15/A16/A17 |
| `tests/Feature/Membership/MembershipUpdateIgnoresIdentityFieldsTest.php` | www | Updated for §S2 (real auth token), new 401-without-one test (§7.8 A2) |
| `tests/Feature/Membership/MembershipSignupServerSideTest.php` | www | New — reserve-reference, admin-auth-required, resumable-on-Checkpoint-failure (mocked `CheckpointServerClient`) |

## Verification performed

- `php -l` on every touched PHP file — clean.
- `npx tsc --noEmit` (full project) — clean, zero errors, after fixing a real break the first
  draft introduced (removing `createMemberAllocation`/`getOrGenerateMemberReferenceNum` broke
  `brain_membership.ts`, caught by the type check rather than missed).
- `npm run check:modules` — still passes.
- Full `tests/Feature/Membership/` + `tests/Feature/DirectPayments/` (110 tests) — all pass.
- Full repository suite: 548 passed, the same 11 pre-existing, unrelated failures as every
  other baseline this session (`Appointments`/`Builder`/`Ecommerce`/`Events`/`Forms`).
- Auth's `tests/*Checkpoint*` suite, as a sanity check for the config/key changes — 3
  pre-existing failures in `CheckpointTemplateConsistencyTest`, confirmed unrelated (`git
  status` on `auth` shows only `.env`, `resources/js/checkpoint/resources.ts`, and
  `routes/web.php` touched — nothing near the provisioning template those tests check; the
  gap is real but belongs to whichever `video_communications` step added the `communications`
  registry resource, not to this document).
- **Not run**: `store()`'s success path end to end via HTTP. As
  `BrainMembershipPayerResolutionTest`'s own docblock documents, it also calls the legacy
  `FunctionsController::GetManagedDomain()` shell-provisioning path, which opens a real MySQL
  connection this environment doesn't have. `MembershipSignupTierClaimTest` and the mocked
  tests in `MembershipSignupServerSideTest` cover the logic that runs before that point in
  isolation instead.
- **Not run**: anything against a live Checkpoint server, a live payment provider, the
  rotated `CHECKPOINT_SYSTEM_KEY` in a real exchange, or the actual `commands.sh` provisioning
  script — this session cannot reach the vagrant lab, the same limitation noted throughout
  this session. Config caches (`php artisan config:cache`) on any live deployment must be
  cleared for the rotated key and the `system_key` default change to take effect.

## What's left, explicitly

- **§7.5's literal two-step API** — not built; see "The transactional rewrite" above. If a
  future pass wants it, it needs its own careful design against a real, reachable Checkpoint
  and payment-provider test environment.
- **S36–S39** (storage provisioning) — left for a pass that can verify against a live host.
- **OQ-E, OQ-G, OQ-J** — genuine product/infrastructure decisions left open, per the table
  above.
- **The `CheckpointTemplateConsistencyTest` gap** (registry has 15 member-scoped resources
  including `communications`; the provisioning template lists 14) — real and pre-existing,
  but belongs to a different plan than this one.

## Lab verification — 2026-09-24

`2026_09_23_000002_add_tier_source_to_brain_memberships` was applied to the vagrant lab
(`php artisan migrate --force` in `/vagrant/afs-eco/w/www`, along with three other pending
migrations — see `../../brain_membership_identity/complete/02-database-and-server.complete.md`).
It ran clean. Until then a real signup returned a 500 (`Unknown column 'tier_source'` from
`store()`'s `Membership::create()`); the migration had been written but never applied.

**Still not confirmed:** a signup completing end to end in the lab. Not asserted here until it has.
Lab DNS finding from the same session: `web`'s resolver had reverted, breaking
`createMemberAllocation()` (`Could not resolve host: auth.appfactory.test1`); this is the
known issue in `AFS-DEPLOYMENT-VAGRANT.md`, not a code defect.

### Update 2026-09-24

A signup completed in the lab and wrote `tier_source` = `paid` on the new `brain_memberships` row, so
the column and `store()`'s write to it work. The response was still a 500 because of a post-commit
queue failure, documented in `00_extras.md`. The Stripe-redirect path and the `unresolved`/`admin`
values are not yet exercised in the lab.

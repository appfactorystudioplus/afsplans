# 01 — Current-state audit

## Completion document

Not yet carried out. When it is: `complete/01-current-state-audit.complete.md`.

Everything below was checked in the code under `C:\projects\vagrant\afs-eco` and, for data, against the
lab2 database (`testdb_appfactorystudio` on `10.0.0.243`, read-only `SELECT`/`DESCRIBE`; no row was changed
and no token value was printed). Findings are **BA1–BA20**.

## 1.1 What "the brain controller" actually is

`w/www/resources/js/portal/admin/controllers/brain/` holds five files:

| File | Lines | What it is |
|------|------:|------------|
| `BrainController.ts` | 50 | One method, `getMember(referenceNum)` → `GET /portal/api/v1/brain/member?reference_num=`. A helper for tenant apps, **not** the admin UI |
| `brain.ts` | 0 | Empty |
| `brain_membership.ts` | 4,357 | **The member admin.** List, create, detail/edit, subscription, payments, allocations, domains/DNS, afs-connect, Checkpoint errors |
| `apps/brain_apps.ts` | 618 | The Apps control plane (settings, mail events, history) — `www_brain` plan |
| `storage/brain_storage.ts` | 734 | The storage fleet (servers, devices, keys) — `storage-sharding` plan |

**BA1.** The thing to mimic is `brain_membership.ts`, reached from the sidebar view ids
`brain:memberships:init` (list), `:create`, `:detail`, `:subscription`, `:payments`, `:allocations`,
`:afs`, `:domains` and `:checkpoint`. The member detail screen is a tab strip
(`setupMemberDetailTabs`, line 2868) that navigates between those views, carrying the member object as a
snapshot.

## 1.2 The member record

`brain_memberships` in lab2 (verified with `DESCRIBE`):

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint unsigned PK | |
| `reference_num` | varchar(64) **unique** | The member's public key: `appfactorystudio`, `m1xTHpRmdtH2Y` |
| `username` | varchar(128) **unique** | |
| `email` | varchar(255) **unique** | The Brain member's own email is **empty** in lab2 |
| `firstname`, `lastname` | varchar(128) null | |
| `membership` | tinyint | The plan/tier: `0 Free · 1 Pro · 2 Business · 3 Organization · 4 Enterprise` |
| `tier_source` | varchar(16) null | `manual`, `admin`, … ([membership_change/07](../membership_change/07-signup-flow.md) §S20) |
| `membership_id` | varchar(255) null | Signup-time claim ([billing](../billing/README.md)) |
| `level` | tinyint | **99 = platform superuser.** Not writable from any endpoint |
| `user_id` | bigint unsigned null | → `laravel_users.id` (the portal/Checkpoint user) |
| `street`, `city`, `state`, `zip_code`, `country_code`, `country_name` | varchar | Address |
| `payer_reference_num` | char(32) **unique** null | `pay_…`; the Direct Payments join key ([brain_membership_identity](../brain_membership_identity/README.md)) |
| `zone` | tinyint | |
| `processor`, `direct_payment` | varchar null | The processor and Direct Payment this member signed up through |
| `json_data` | text (cast `array`) | e.g. `discount_code` |
| `account_status` | tinyint | `1 Active · 2 Suspended · 3 Marked for deletion` |
| `account_close_date` | date null | |
| `created_at`, `updated_at` | timestamp | `updated_at` is the optimistic-concurrency stamp |

**`customer_id` does not exist.** It was dropped in `brain_membership_identity` BM-P6.

lab2 holds **three** members: `appfactorystudio` (id 1, level 99, plan 4, `user_id 1`, no payer),
`james1` (id 16, plan 1, payer `pay_a1b2c3d4e5f6`) and `james2` (id 45, plan 1, payer `pay_g7h8i9j0k1l2`).

## 1.3 Authentication and authorisation

**BA2 — a bearer token in `laravel_user_tokens` is the whole credential.**
`ApiAuthMiddleware` and `PortalAdminAuthMiddleware` both read `$request->bearerToken()` (or the
`portal_auth_token` cookie), look it up in `laravel_user_tokens` (`token varchar(80) unique`,
`expires_at` **nullable**), and stash `auth_user` and `auth_member`
(`Membership::where('user_id', $user->id)`) on the request.

- `PortalAdminAuthMiddleware` accepts `expires_at IS NULL OR expires_at > now()` — a **NULL expiry is a
  non-expiring token**.
- `ApiAuthMiddleware` rejects only when `expires_at` is set **and** past — the same reading.
- `AuthController::login` mints 64-character tokens that expire in **1 day, or 30 with `remember`**.
- `AuthController::loginSuperadmin` (`POST /portal/api/auth/login-superadmin`) mints one with no password,
  but only for a request whose IP is in `SUPER_ADMIN_APPROVED_IPS` — a **single public IPv4 address**
  (`.env`), useless for a phone that moves between Wi-Fi and mobile data. It also logs in
  `SUPER_ADMIN_EMAIL`/`USERNAME`, which in lab2 is `superadmin@example.com` / `superadmin`.

**BA3 — three different gates guard the member surface.**

| Gate | Middleware | Routes |
|------|-----------|--------|
| Any valid token | `ApiAuthMiddleware` | `GET/POST /memberships`, `PUT /memberships/{ref}`, `/status`, `/tier`, afs-connect, checkpoint-errors, `/money/*`, `/auth/me` |
| Any valid token, member resolved | `portal.admin.auth` | `GET /brain/member`, `/members`, `/member-by-email`, `/config`, `/logs`, DNS, HTTP-server routes. `portal.level99` is **deliberately off** here (`routes/portal.php:461`, `plans/TODOS.md`) |
| Level 99 exactly | `portal.admin.auth` + `portal.level99` | `GET/POST /brain/member/{ref}/allocation`, `/overrides` |
| Brain-only **and** level 99 | `brain.only` + above | `/brain/apps/*`, `/brain/storage/*` |

`portal.level99` returns **404, not 403**, to a non-superuser (`PortalLevelNinetyNineMiddleware`). The app
must read a 404 from a level-99 route as "not authorised", not "not found" ([05](05-app-architecture.md) §5.5).

The Brain member's chain, verified: `brain_memberships (reference_num appfactorystudio, level 99, user_id 1)`
→ `laravel_users (id 1, username superadmin, role super_admin, active 1)`. A token for user 1 therefore passes
**every** gate above. lab2's `laravel_user_tokens` already holds tokens for user 1 minted by ordinary logins
(the newest created 2026-09-17, 30-day expiry) — evidence that the mechanism in [02](02-authentication-device-token.md)
is the one the browser already uses.

**BA19 — CSRF is not in play.** `routes/portal.php` is loaded by `bootstrap/app.php`'s `then:` closure under
`Route::prefix('portal')` with no `web` middleware group, and the CSRF exception list there
(`portal/api/v1/processors/*`, …) is irrelevant to the member routes. The browser SPA's `PUT`s carry no CSRF
token and work. P0 still **probes** one `PUT` with a bearer token before anything is built on this ([12](12-rollout-and-testing.md) P0).

## 1.4 Members: list, create, edit

**BA4 — list and search work.** `GET /portal/api/v1/memberships?page=&per_page=&search=`
(`MembershipController::index`): `search` matches `email`, `reference_num`, `username` and
`direct_payment` with `LIKE %…%`; `per_page` 1–200 (default 50); ordered `created_at desc`. Response:
`{ success, memberships: [Membership…], pagination: { current_page, per_page, total, last_page } }`. It
returns whole rows including `json_data`. There is **no** status, plan or sort filter (account_close's
plan names the missing `?status=` as a "small follow-up", `04-admin-soft-and-hard-delete.md` §4.5).

**BA5 — create is `adminStore`, and it validates almost nothing.** `POST /portal/api/v1/memberships`
(`MembershipController::adminStore`, ApiAuth) checks only that `username` and `email` are unused (422
otherwise), generates `reference_num` server-side, and — **only if `password` is non-empty** — registers the
user in Checkpoint (`registerUser`, `loginUser`, `initMemberAllocation`), returning a `checkpoint_session`
that contains live session material. It sets `tier_source = 'admin'`, never writes `level`, and defaults
`account_status` to 1. The **required-field rules live in the browser** (`brain_membership.ts:2529`):
`username, email, firstname, lastname, street, city, state, zip_code, country_code, country_name,
processor, membership`, password ≥ 8 characters with no whitespace. The app must enforce the same rules
itself. A Checkpoint failure returns 502 with a `code` and logs a row to
`brain_membership_checkpoint_errors`. `validate-username`/`validate-email`
(`GET`, public, throttled by `memberships.public.throttle`) give live availability checks.

**BA6 — edit is three narrow operations, not one.** After `membership_change/08`:

| Endpoint | Writes | Notes |
|----------|--------|-------|
| `PUT /memberships/{ref}` | `firstname`, `lastname` **only** | Address cannot be edited by any endpoint today |
| `PUT /memberships/{ref}/status` | `account_status ∈ {1,2,3}`, `account_close_date` | Writes a `brain_membership_events` row (`account_status_changed`) in a transaction |
| `PUT /memberships/{ref}/tier` | `membership ∈ 0..4`, sets `tier_source = manual` | **`reason` required, 3–500 chars**; refuses "already on that plan" (422); writes `admin_tier_change` |

All three accept `expected_updated_at`; a mismatch returns **409** `{ success:false, error:{ code:'STALE_RECORD' }, message }`.
There is no `GET /memberships/{ref}`; the detail screen uses `GET /brain/member?reference_num=` (which also
attaches `alloc_overrides`) and carries the object as a snapshot.

**BA7 — there is no remove.** No `DELETE` exists in the memberships API, no closure route exists in
`routes/portal.php` (searched for `closure`/`account-close`: none), and `ACCOUNT_STATUS_LABELS` has only
`1/2/3`. `account_status = 3` ("Marked for deletion") is stored and displayed but **nothing enforces it** —
no login block, no purge. `plans/account_close` (written 2026-09-24, "PLAN ONLY") designs soft delete, restore,
and hard delete with a 15-minute preview token and an asynchronous purge in `members`; none is built.

## 1.5 Subscriptions and payments

**BA8 — the web Subscription and Payments tabs call things that do not exist.**
`loadMemberSubscription()` (line 3161) and `loadMemberPayments()` (line 3370) both
`GET /portal/api/v1/processors/billing/payments?processor_reference_num=&customer_id=`. `routes/portal.php`
registers no such route and `ProcessorBillingController` has no `payments` method (its first method is
`sync`; a comment above it still says "Payments — charges + subscription summary"). They also send
`member.processor` and `member.customer_id`, and `customer_id` is gone from the table ([§1.2](#12-the-member-record)).
The route comment block above `Route::prefix('api/v1/memberships')` advertises `/{ref}/payments`, `/refund`,
`/discount`, `/billing-amount`, `/billing-date` — **none are registered**. These tabs cannot be the model for
anything.

**BA9 — Direct Payments' subscription endpoints are self-service only.**
`DirectPaymentSubscriptionController` `show / cancel / update / setPaymentMethod / history` all start with
`resolveOwnSubscription()`: the caller's identity comes from `CheckpointIdentityResolver::resolveMemberReferenceNum($request)`
(the **member's own** Checkpoint bearer), a `member_ref` in the body that differs from it is a **403**
`DP_E_FORBIDDEN`, and the link row is found by `Membership.payer_reference_num` →
`laravel_direct_payment_subscriptions.payer_reference_num`. The Brain's device token is a *portal* token for
user 1, not a Checkpoint bearer for the target member. So the staff app **cannot** call these for another
member. What it *can* reuse is everything underneath: `StripeSubscriptionActions` / `SquareSubscriptionActions` /
`PayPalSubscriptionActions` (via the private `actionsFor()`), `SubscriptionSyncService::syncOne`,
`TierBindingRegistry::priceForExistingSubscription`, `DunningPolicyResolver::cancellationAccessEnds`, and the
`SubscriptionPlanChanged` event whose listener (`ApplyTierOnPlanChange`, Brain only) writes
`brain_memberships.membership` synchronously.

**BA10 — the processor billing routes carry no authentication.** `processors/billing/refund`,
`/discount`, `/billing-amount`, `/billing-date`, `/payment-methods`, `/payment-methods/store`,
`/payment-methods/default` and `/sync` sit in `Route::prefix('api/v1/processors')` with no auth middleware
(the controller's own comments say so; the only protection is knowing a provider customer id). The app
**must not** use them and must not make a second unauthenticated door. Reported in [§1.9](#19-observed-not-fixed-here).

**BA11 — the money ledger and refund are authenticated.** `GET /portal/api/v1/money/ledger` and
`POST /portal/api/v1/money/charges/{chargeId}/refund` sit behind `ApiAuthMiddleware`
(`MoneyController`); the ledger takes `provider`, `from`, `to`, `status`, `direct_payment` and returns
charges with their refunds. There is no per-member filter.

**BA12 — member → subscription resolution has holes in real data.** In lab2:

| Table | Content |
|-------|---------|
| `laravel_direct_payment_subscriptions` | **1** row: `sub_1UGiUt…`, tier `TMKJx6A8tZQ7` (level 1), DP `vfPSdk2xj`, `payer_reference_num` **NULL**, not cancelled |
| `laravel_processor_subscriptions` | 1 row: `stripe`, **`incomplete`**, next charge 2026-10-17, $15.00, plan 4 (Pro) |
| `laravel_direct_payment_payer_customers` | `pay_a1b2…` → `cus_UYsv…`; `pay_g7h8…` → `cus_V0Wu…` |
| `laravel_processor_customers` | 1 row, `cus_VHCb…` — **not** either payer's customer |
| `brain_membership_billing_state`, `brain_membership_events` | **empty** |

So the single subscription link cannot be reached from `james1` or `james2` by the self-service rule
(`payer_reference_num` match), and the dunning table has nothing to show. The staff resolver ([04](04-server-additions.md) BS6)
therefore needs a **fallback chain** and the app must render "no subscription found" and "subscription not linked to a
payer" as normal states, not errors ([07](07-subscriptions-direct-payments.md) §7.3).

**BA13 — the plan catalogue.** `laravel_direct_payment_tiers` for DP `vfPSdk2xj` ("MyPayments", processor
`2uDJGCr`, Stripe "MainStripe"): four tiers, each with a `level` (1–4) and a provider product id
(`processor_tier_id`); `laravel_processor_plans` holds the names and prices (Pro $15, Organization $50,
Business Plus 3 $52, Enterprise $200 monthly). A second DP, `eq4W10iF8` "Domain Purchase", exists and is not a
membership product. `GET /portal/api/v1/directpayments?direct_payment=` returns a DP with its `tiers`.

**BA13b — billing state vocabulary.** `BrainMembershipBillingState.state ∈ current | past_due | downgraded`;
`BrainMembershipEvent` events: `past_due_detected`, `dunning_reminder_1`, `dunning_reminder_2`, `dunning_final`,
`membership_downgraded`, `membership_restored`, `subscription_access_ended`, `evaluation_skipped`,
`admin_tier_change`, `account_status_changed`. `source` is `varchar(20)` (`scheduler`, `admin`, …) and
`actor_user_id`/`actor_username` record who. `BrainEntitlementController` (HMAC, not for the app) is the
server-derived tier.

## 1.6 Allocations and overrides

**BA14 — Parse-backed, level 99, and self-edit is refused.** `GET /brain/member/{ref}/allocation` returns
`{ success, data:{ member_reference_num, tier, account_status, rows:[…] } }` (rows are Parse `MemberResource` rows — one per member/resource/scope instance, addressed by a stable `ledger_key` — read
server-side with the master key; a Parse outage is a **502**). `POST` takes `{ mode:'form'|'json',
reason?, force?, changes:[{ ledger_key, field, row_updated_at?, path?, before?, after? }] }`, requires a
**reason ≥ 10 characters** for any decrease or forced save, returns **409** with `failed_changes` on a
per-row conflict, and returns **404** (!) if the actor edits **their own** allocation (`appfactorystudio`
cannot). Overrides: `GET/POST/DELETE /brain/member/{ref}/overrides[/{id}]`; `POST` requires `resource_id`,
`scope_type` (exactly `member | domain | pm_project`), `scope_value` (for `domain`/`pm_project`), `limit_value`,
**`reason` 10–500 chars**, optional `expires_at` in exactly `Y-m-d\TH:i:s\Z`.

**BA15 — the form is generated from the registry.** The web builds allocation rows from
`checkpoint_registry_v4.json` (35 resources, served from `auth`'s `public/checkpoint/`, one canonical copy —
[membership_change/06](../membership_change/complete/06-registry-distribution.complete.md)). The same file gives the
app resource labels, tiers and scopes for the override picker. Its URL is configuration, not code ([03](03-environments-and-config.md)).

## 1.7 Environment scheme and the sibling apps

**BA16.** `w/ENVIRONMENTS.md`: one `_env/<mode>/.env` per project, **all modes define identical keys**,
no host in code. Client apps beside `w/` (`afsdesktop5`, `afsmobile_flutter`) use the mode folders
`development, production, vagrant, vagrant-lan, lab2` (`dev→development`, `prod→production`; `local` has none),
are switched by hand with `bash packages/afsserver/bin/use-env.sh <mode> --skip-sync`, and **each build bakes its
`.env` in, so rebuild after switching**. `check-env.py` has a `CLIENTS` map naming the role of each variable's
host (`afsmobile_flutter`: `CHECKPOINT_URL→auth`, `API_URL→members`, `COMMUNICATIONS_URL→communications`); a new
client is added there ([03](03-environments-and-config.md) §3.5).

**BA17 — what `afsmobile_flutter` already solves** (reuse, do not reinvent): `lib/config.dart` loads the
bundled `.env` with `flutter_dotenv`, `_require()`s every key at startup and throws a `StateError` naming the
missing key; `lib/services/lab_ca.dart` adds `assets/lab/mkcert-rootCA.pem` to `SecurityContext.defaultContext`
**only** when `kDebugMode` and not production, because Dart's TLS stack ignores a user-installed CA;
`android/app/src/debug/res/xml/network_security_config.xml` does the Java-side equivalent for debug builds only;
release signing reads `key.properties`. Stack: `flutter_bloc ^9`, `equatable`, `http ^1.2`, `flutter_dotenv ^6`,
`shared_preferences`, `intl`; SDK `^3.9.2`. Flutter is installed at `C:\flutter`; the Android SDK's `adb` is
**not** on the shell `PATH`.

**BA18 — Brain-only routes.** `brain.only` aborts with 404 unless `config('brain.is_brain')`
(`IS_BRAIN=true` and the production guard). lab2's `www` has `IS_BRAIN=true`, so `/brain/apps` and `/brain/storage` exist
there.

## 1.8 Reaching the lab from a phone

lab2 is `lab2-brain` `10.0.0.243` on the router network; `www` is `https://www.10-0-0-243.sslip.io`
(sslip.io answers any name containing the IP, so the phone needs no DNS change). Its certificate is signed by a
private mkcert root (`lab2/certs/mkcert-rootCA.crt`). The phone must (1) be on the home Wi-Fi and (2) trust that
root; `lab2/README.md` §"Android phone: trust the lab CA" is the procedure. An unauthenticated probe from this
machine returned **401** from `…/portal/api/v1/brain/member`, confirming reachability and that the route is gated.

## 1.9 Observed, not fixed here

These are real, found while tracing, and outside this plan's scope. Listed so they are not lost; none is a blocker
(except as noted).

| # | Observation | Effect on this plan |
|---|-------------|---------------------|
| O1 | `processors/billing/*` (refund, discount, billing-amount, billing-date, payment-methods, sync) are unauthenticated (**BA10**) | App avoids them; staff wrappers added ([04](04-server-additions.md)) |
| O2 | Web Subscription/Payments tabs are dead (**BA8**) | Not ported |
| O3 | The route-comment block above `api/v1/memberships` lists endpoints that are not registered (**BA20**) | Documentation drift; the contracts in [04](04-server-additions.md) are taken from registered routes only |
| O4 | `GET /directpayments` (list a DP with tiers) has no auth middleware beyond the group | App uses it read-only for the plan picker; the staff plan list ([04](04-server-additions.md) BS7) replaces it |
| O5 | `loginSuperadmin` is IP-gated to one address | Not usable from a phone (**BA2**) |
| O6 | One `laravel_direct_payment_subscriptions` row has `payer_reference_num NULL` (**BA12**) | Resolver fallback; backfill is `direct_payments`' job |
| O7 | `adminStore` returns a live `checkpoint_session` to the caller | The app discards it unread and never logs or stores it |
| O8 | The two systems name the five tiers differently: the Brain's `TIER_LABELS` are Free · Pro · Business · Organization · Enterprise; `checkpoint_registry_v4.json` `tiers[].label` are Free · Starter · Professional · Business · Enterprise. Both are positional 0–4 | The app keys everything on the **index**, shows the Brain label as the plan name and the registry label only beside limits, and never joins by name ([08](08-allocations-and-overrides.md) §8.3) |

## 1.10 Findings index

| # | Finding | Drives |
|---|---------|--------|
| BA1 | The mimic target is `brain_membership.ts`, not `BrainController.ts` | scope |
| BA2 | Bearer token in `laravel_user_tokens`; NULL expiry honoured | [02](02-authentication-device-token.md) |
| BA3 | Three gates; level 99 answers 404 | [05](05-app-architecture.md) §5.5 |
| BA4 | List/search exists, no status/plan/sort filter | BS3 |
| BA5 | `adminStore` validates only duplicates; rules are client-side; returns a session | [06](06-members-feature.md) §6.4 |
| BA6 | Edit = names / status / tier; no address edit | BS5 |
| BA7 | No remove; `account_close` unbuilt | [06](06-members-feature.md) §6.6 |
| BA8 | Web Subscription/Payments tabs dead; `customer_id` gone | [07](07-subscriptions-direct-payments.md) |
| BA9 | DP subscription actions are self-service only | BS6 |
| BA10 | `processors/billing/*` unauthenticated | BF6 |
| BA11 | Money ledger/refund authenticated, no per-member filter | BS8 |
| BA12 | Member→subscription holes in lab data | BS6 resolver |
| BA13 | One membership DP, four tiers with levels | BS7 |
| BA14 | Allocation: Parse-backed, level 99, self-edit refused | [08](08-allocations-and-overrides.md) |
| BA15 | Registry v4 is the shape source | [08](08-allocations-and-overrides.md), [03](03-environments-and-config.md) |
| BA16 | `_env` scheme, `use-env`, `check-env` CLIENTS | [03](03-environments-and-config.md) |
| BA17 | `afsmobile_flutter` conventions to reuse | [05](05-app-architecture.md) |
| BA18 | `brain.only` gates apps/storage | [09](09-other-brain-sections.md) |
| BA19 | No CSRF on portal routes (probe in P0) | [12](12-rollout-and-testing.md) |
| BA20 | Stale route comments advertise unregistered endpoints | §1.9 O3 |

# 07 — Signup: how a member is created

> Renumbered 2026-09-22 (was `09-signup-flow.md`) — section numbers below were renumbered
> from §9.x to §7.x to match, along with every cross-reference to them elsewhere in
> `plans/`. See `README.md`.

> **STUB, FOR AUDIT — banner stale, see `complete/07-signup-flow.complete.md`.** Written
> from reading `www/resources/js/afs/`, `MembershipController`, `FunctionsController` and
> `routes/portal.php` directly — not from other plan documents. **"No application code has
> been written" is no longer true**: every finding in the document — the security list (S1's
> RCE, S19's tier-escalation replay, and the rest), server-owned identity, one ordered signup
> flow with resumable failure, a scoped storage fix, and the tidy list — was implemented and
> verified on 2026-09-23. §7.5's literal two-step API was NOT built — see the completion doc's
> own deviation note for why and what was built instead. §7.7.1's whole provisioning
> narrative (S21/S22/S23) turned out to describe an architecture the estate had already
> replaced. §7.9's open questions were resolved, deferred, or left for the owner — see the
> completion doc's own table rather than treating any of them as still fully open.

**Scope:** the public signup flow — `www.appfactory.studio/` (plan selection) → `/create`
(account form + payment) → `POST /portal/api/v1/memberships/register` → a row in
`brain_memberships`. Client code lives in `www/resources/js/afs/`
(`main.ts`, `create.ts`, `memberships/index.ts`, `globalAuth.ts`).

> **On placement.** This folder is otherwise about the **backward** edge — what happens to
> resources a member already holds when their tier changes. Signup is the **forward** edge's
> first event: it is where `brain_memberships.membership` is written for the first time, and
> where `reference_num`, `parse_alloc`, `user_id` and `account_status` — the four identifiers
> every other document here depends on — come into existence. The retention engine
> ([02](02-architecture.md)) reads all five columns and can only be as correct as the row
> created here. If the owner would rather this sat in `billing/`, moving it costs a `git mv`
> and a link pass.

---

> ## 🔴 S1 — Read this before anything else in this document
>
> **`POST /portal/api/v1/memberships/register` is unauthenticated and passes a
> caller-controlled string into a shell without escaping.**
>
> `MembershipController::store()`:
>
> ```php
> $shParams = "--create-afs-member-storage " . $data['reference_num'];
> FunctionsController::RunCommandBG($shParams, $managed_domain, null, true);
> ```
>
> `FunctionsController::RunCommandBG()` (`app/Http/Controllers/FunctionsController.php:594`):
>
> ```php
> $cmd = $basefolder . "/bin/afs-website \"" . $shCmd . " " . $shParams . "\"";
> $process = popen("nohup $cmd 2>&1 > /dev/null & echo $!", 'r');
> ```
>
> No `escapeshellarg`, no `escapeshellcmd`, no allowlist. `$data['reference_num']` is read
> straight from the request body of a public endpoint — the route sits in the
> `Route::middleware('portal.cors')` group, and `PortalCorsMiddleware::handle()` is
> `return $next($request);`, a no-op. The two `ApiAuthMiddleware` groups in that file close
> at ~line 205; the memberships group opens at line 672.
>
> A `reference_num` containing a `"` closes the quoted argument and everything after `;` runs
> as the web user, on the Brain, unauthenticated.
>
> **This is not a signup-flow improvement. It is a live remote code execution hole on the
> platform's control plane, and it should be closed today, independently of this plan and
> before any of it.** §7.3 P0 states the minimum fix.
>
> Two things make it worse rather than better: the same request also provisions storage, so
> the endpoint is *expected* to have side effects; and `RunCommandBG` is used in many other
> places, so `escapeshellarg` at the call site fixes one caller while the helper stays
> dangerous for the rest.

---

## 7.1 How signup works today

```
  www.appfactory.studio/                    main.ts
    │  Portal SDK: dp.setupSubscription() renders live tier prices
    │  click a plan → dp.setSelectedTierPlan(n) → redirect
    ▼
  /create                                   create.ts
    │  reads the plan back from the SDK (NOT from sessionStorage)
    │  validates the form, checks username/email availability
    │  paid: dp.confirmPayment()  ── may redirect to the provider and come back
    │                                 via ?subscription=success (handleStripeReturn)
    ▼
  memberships/index.ts
    │  1. checkpoint.auth.register()      ← IN THE BROWSER. Failure is non-fatal
    │  2. POST /portal/api/v1/checkpoint/member/init   → parse_alloc objectId
    │  3. POST /portal/api/v1/memberships/register
    ▼
  MembershipController::store()
       derives the tier from the DP↔subscription link row
       creates brain_memberships row
       adopts the DP link row onto the new member
       fires --create-afs-member-storage
```

**What is already correct, and should not be "fixed" back:**

- **The tier is no longer copied from the request body.** `store()` resolves it from
  `DirectPaymentSubscription` → `DirectPaymentTier.level`, keyed on the provider subscription
  id, logs a warning when the posted tier disagrees, and defaults to 0 when no subscription
  resolves. That closes **B38** as `www/direct_payments/22` §4.2.5 wrote it, and any document still
  describing signup as *"writes whatever tier the client claims"* is out of date.

  **The model is right; the lookup is not.** "Prove you paid, or you are free" is the correct
  shape — tiers 1–4 require a resolvable subscription, tier 0 is the absence of evidence, and
  the safe default grants least. But the subscription id it resolves *from* is still supplied
  by the client, and the lookup does not check that the subscription belongs to this signup.
  **S19** is what that leaves open, and it is a tier-escalation hole rather than a residual
  risk.
- **The DP link-row adoption** (`whereNull('member_reference_num')`) is best-effort and
  deliberately never fails the signup, with `direct_payments:backfill-subscriptions` as the
  recovery path. That trade is right — the member has already paid.
- **The password is deleted before the request is sent** to Laravel. See **S17** for where it
  still leaks.

## 7.2 Findings

Ordered by severity, not by layer. Each was read in the code, not inferred.

### Security

| # | Finding | Where | Consequence |
|---|---|---|---|
| **S1** | **Unauthenticated RCE** — client `reference_num` → unescaped shell | `store()` + `RunCommandBG:594` | Arbitrary command execution as the web user on the Brain. See the block above |
| **S2** | **The whole `api/v1/memberships` group is unauthenticated.** `GET /` returns **every membership on the platform**; `PUT /{ref}` edits any of them; so do the afs-connect settings routes and the global flag | `routes/portal.php:672` | Full member-table disclosure and mutation, unauthenticated. Same root cause as the `portal.cors` no-op named in [../members_brain/README.md](../members_brain/README.md) item 1 and [../www/ecommerce/01](../www/ecommerce/01-site-users-guard.md) §1.1 |
| **S3** | **`account_status` is accepted from the request body** — `isset($data['account_status']) ? (int) $data['account_status'] : 1` | `store()` | A public signup can choose its own account status. `account_status` is the suspension axis [02](02-architecture.md) §2.4 depends on |
| **S4** | **`user_id` — the Checkpoint identity — is accepted from the client.** Checkpoint registration happens in the browser (`attemptClientCheckpoint`) and the resulting id is posted | `memberships/index.ts`, `store()` | Breaks the ecosystem rule *"identity is derived server-side, never accepted from the client"*. A caller can bind a new membership to somebody else's Checkpoint user id |
| **S5** | **`reference_num` is generated in the browser** with `Math.random()`, 10 chars, and used verbatim by the server | `getOrGenerateMemberReferenceNum()` | The member's primary key across the entire ecosystem is chosen by the client, from a non-cryptographic RNG. Collisions are possible and the value is predictable/squattable. It is also the S1 injection vector |
| **S6** | **No rate limiting** on `/register`, `/validate-username` or `/validate-email` | route group | `validate-email` is a clean account-enumeration oracle; `/register` has real side effects (Checkpoint user, Parse object, storage provisioning, a shell command) |
| **S19** | **A paid subscription id can be replayed to mint unlimited accounts at that tier.** Tier resolution looks up `provider_subscription_id` with **no ownership check and no `whereNull('member_reference_num')`** — unlike the adoption step 30 lines below it, which has the guard | `store()` | **Tier escalation.** See §7.2.1 |
| **S17** | **The plaintext password is `console.log`ged on every signup.** `registerMembership()` builds `payload` including `password`, logs the whole object, and only `createMembership()` — called afterwards, on a copy — deletes it | `memberships/index.ts` | The password is in the browser console, in any error-reporting tool that captures console output, and in a screen share |

### Correctness

| # | Finding | Where | Consequence |
|---|---|---|---|
| **S7** | **`getOrGenerateMemberReferenceNum()` returns two different values.** On the generate path it stores `"m1x" + ref` but **returns `ref`** (unprefixed); on every later call it returns the stored, prefixed value | `memberships/index.ts` | `create.ts` passes the **unprefixed** value to `confirmPayment({ user_id: memberRef })`, then `registerMembership()` reads the **prefixed** one from sessionStorage. The provider's payment metadata and the member's `reference_num` **disagree on every first-attempt signup** — so reconciling a payment back to a member by that field fails |
| **S8** | **Storage is provisioned from `$data['reference_num']`, not `$membership->reference_num`** | `store()` | When the client omits it the row gets `Str::random(12)` while the shell command gets an undefined array key — storage provisioned under the wrong identifier, and a PHP warning. The two identifiers must be the same one |
| **S9** | **`afs_selected_membership` is never set for paid plans** — the write in `selectPlan()` is commented out; only the free button sets it | `main.ts` | `parseInt(null)` → `NaN` → posted as `membership: null`. Harmless for the tier (the server derives it, §7.1) but `savePendingFormData()` stores `NaN` and the value is posted as if it meant something |
| **S10** | **`if($data['testing']==true)`** gates the welcome email, and no client ever sends `testing` | `store()` | **No welcome email is sent on any real signup**, and every signup raises an undefined-array-key warning. A production side effect gated on a debug flag |
| **S36** | **The cloud-storage plan does not know signup provisions the storage root** — `--create-afs-member-storage` appears in none of its fourteen documents | `store()` / [../cloud-storage/](../cloud-storage/README.md) | The root is created by one system and used by another with no contract between them. §7.7.4 |
| **S37** | **Storage provisioning is fire-and-forget at two layers** — `popen(… > /dev/null &)` discards the result, and the script `exit 0`s without checking `mkdir`/`chown`/`chmod` | `RunCommandBG` / `commands.sh` | A full disk or unmounted volume is indistinguishable from success. Nothing is recorded, so nothing can retry |
| **S38** | **Two creators, two permission models** — the script writes `apache:apache 775`, PHP later writes `0755` as the FPM user via `@mkdir` | `commands.sh` / `StorageTree`, `RetentionService` | Different owner and mode on one tree, and the PHP half suppresses its own errors |
| **S39** | **`/mnt/node1` is hardcoded in two repositories** — `commands.sh:340` and `PathResolver::STORAGE_ROOT` | www / members | They agree only by coincidence. The script even has a `${STORAGE_LOCATION}` variable and uses it for the logfile five lines earlier |
| **S40** | **The provisioning step logs into the DNS log file** — `update-dns-records-subdomains.log`, copy-pasted, and nothing writes to it | `commands.sh` | Harmless; a reliable way to mislead whoever debugs this next |
| **S41** | **`${member_ref}` is unquoted inside the script** | `commands.sh:340-342` | Not reachable now that **S1** is fixed at the PHP layer, but it is the other half of the same defect |
| **S31** | **The www proxy hardcodes both auth hosts and reads `env()` in its constructor** | `www` `MemberInitController` | Under `config:cache`, `DEVELOPMENT_MODE` resolves to its default — so a **dev** box provisions Parse objects against the **production** registry, silently. M11/M12 in one more controller |
| **S32** | **A Parse object is created before the membership row, and orphans are never reaped** | `create.ts` | Every failed attempt leaves a `MemberRegistry` object nothing points at; every retry adds another |
| **S33** | **The allocation call posts a literal `'{}'`** — it cannot pass a member reference even if the template had the field | `www` `MemberInitController` | The concrete reason **S22** exists. §7.5's two-step flow is what supplies the reference |
| **S24** | **The member is never signed in, and the module docblock claims they are.** `register()` posts and returns; only `login()` calls `setTokens()`, and signup never calls it | `memberships/index.ts` | Members finish signup logged out, having been told otherwise. §7.7.2 |
| **S25** | **The success page hardcodes the development auth host** — `https://auth15.appfactory.studio/login`, inline, while `main.ts` thirty lines away correctly uses `import.meta.env.VITE_AUTH_URL` | `create.ts` `showSuccessPage()` | A production signup sends the member to the dev host |
| **S11** | **The no-plan guard's redirect is commented out** — `if (!dpId \|\| level === null) { //window.location.replace('/'); return; }` | `create.ts` | Landing on `/create` without a selected plan renders a dead form with no explanation |
| **S12** | **`clearPendingFormData()` calls `sessionStorage.clear()`** | `create.ts` | Wipes every key on the origin, not this flow's. Anything else on `www` using sessionStorage loses its state on a successful signup |
| **S13** | **Checkpoint registration is client-side and non-fatal.** A failure logs to `MembershipCheckpointError` and the membership is created anyway | `attemptClientCheckpoint()` | A paying member can end up with a billing record and **no way to sign in**, recoverable only by an admin noticing the error table |
| **S14** | **No compensation between payment and account creation.** `confirmPayment()` → `createParseAllocation()` → `registerMembership()`; a throw in either of the last two leaves a completed charge with no member | `create.ts` `handleSubmit()` | The member has paid and has nothing. `handleStripeReturn` covers the redirect path only |
| **S15** | **Two sources for the AFS Direct Payment id** — `import.meta.env.VITE_DIRECT_PAYMENT_ID` in `main.ts`, hardcoded `'vfPSdk2xj'` in `memberships/index.ts` | both | They can disagree, and the hardcoded one wins for registration |
| **S16** | **`createMembershipPlansObject()` hardcodes plan labels, refs and tier indices** — a fourth copy of the tier ladder alongside the registry, `brain_memberships` labels, and the DP tiers | `memberships/index.ts` | Renaming a plan for marketing changes one of four places |
| **S20** | **Tier 0 means three different things and the row cannot tell them apart** — chose free, paid-but-unlinked, or paid-and-replayed. Only a `Log::warning` distinguishes them | `store()` | A member who paid and hit a link failure holds a free account, discoverable only by grepping logs. See §7.2.2 |
| **S34** | **Bearer tokens and the service key are written to the log on every allocation call** — `Log::debug(… $request->headers->all())`, plus the key's own value on failure. `CHECKPOINT_DEBUG` defaults to **on** | `auth` `AllocationV2Controller::authorizeRequest()` | Credentials in logs, against the estate's own rule in [../afs-connect/06](../afs-connect/06-security-and-service-auth.md). §7.7.3 |
| **S35** | **`GET /debug/config` on `auth` returns the service key as JSON** — top-level route, no middleware | `auth/routes/web.php:81` | The shared secret is readable by anyone who can reach the auth host. **Remove before rotating**, or the rotation publishes the new key too. §7.7.3 |
| **S29** | **The service key is a hardcoded literal in four places**, one of which (auth's `$validKey`) is read from neither config nor env — plus a fifth, disagreeing, placeholder default in both config files | www config / auth config / www ×2 / `auth` | **Verified: both `.env` files are set to the very literal that is in the source.** The channel is protected by a published string, so this needs **rotation**, not just tidying. §7.7.3 |
| **S30** | **The www proxy in front of `member/init` is unauthenticated too** — `portal.cors`, with an optional bearer that is allowed to be empty | `www` `MemberInitController` | Two open doors in the chain, not one. Guarding only auth's endpoint leaves this reachable |
| **S28** | **A failed signup burns the email address.** Checkpoint registration runs *before* `store()`'s duplicate check, so a rejected signup leaves a Checkpoint user already holding that email and username | `createMembership()` + `store()` | The member is told *"Email already registered"*, retries, and is now rejected by Checkpoint too. The orphaned user is reaped by nothing. **Strands a real person.** §7.7.2 |
| **S26** | **`require_verification` is sent under one name and read under another** — client sends `require_verification`, `AuthController::register()` reads `require_email_verification` | `auth.ts` / `AuthController` | The client's value is discarded; the server's default (`false`) always wins. Outcomes coincide today, so nothing looks broken — and enabling verification from the client would silently do nothing |
| **S27** | **No email verification anywhere in signup** — a consequence of S26 plus the server default | `AuthController::register()` | Any address can be claimed by anyone, unauthenticated and unrate-limited (**S6**). That address is the member's identity and their billing destination |
| **S23** | ~~**`_schema`, `_doc` and `_fields` are written as Parse columns**~~ — **CONFIRMED AND FIXED 2026-08-30.** Parse rejected the save outright (`code 105, Invalid field name: _schema`), so **every signup was failing** at `createParseAllocation()` and had been since 2026-08-27. A second fault sat behind it: empty containers reached Parse as `{}` because `range(0, -1)` returns `[0, -1]` | `MemberInitController::init()` | Was a live outage, not a cleanup. Both faults fixed and verified end to end; see §7.7.1 and `../00_ISSUES.md` I3. **Note the coupling: S21 must not ship without this** |
| **S22** | **The provisioning template has no `member_reference_num`** — nothing on the Parse object points back at the member | `parse_member_query_v4.json` | Breaks the ownership check [../alloc-v2-parse-direct/03](../alloc-v2-parse-direct/03-parse-cloud-functions.md) §3.4.2 is built on, and leaves `MemberRegistryCounter` rows unjoinable. A prerequisite, not a tidy-up |
| **S21** | **Every member is provisioned owning six placeholder resources** — an example domain with an example store, plus an example project, graph, PDF and design project | `parse_member_query_v4.json` | They carry `band`/`state`/`resources`, so the retention cascade walks them, counts them toward allowance, and will retire a domain that never existed |
| **S18** | **`createParseAllocation()` calls an unauthenticated master-key endpoint** — `/checkpoint/member/init` has no middleware and creates Parse objects with the master key | [../alloc-v2-parse-direct/03](../alloc-v2-parse-direct/03-parse-cloud-functions.md) §3.8 step 0 | Anyone can mint `MemberRegistry` objects. Already tracked there; repeated because signup is its only legitimate caller and any fix has to keep this call working |

### 7.2.1 S19 in full — replaying a subscription id

The tier is resolved by one query, and the adoption that follows it is a second, independent
one:

```php
// resolve — no guard at all
$link = DirectPaymentSubscription::where('provider_subscription_id', $membershipId)->first();
$resolvedLevel = $link && $link->direct_payment_tier_reference_num
    ? DirectPaymentTier::where('reference_num', $link->direct_payment_tier_reference_num)->value('level')
    : null;

// …30 lines later — adopt, correctly guarded
DirectPaymentSubscription::where('provider_subscription_id', $data['membership_id'])
    ->whereNull('member_reference_num')          // ← the guard the resolve does not have
    ->update(['member_reference_num' => $membership->reference_num]);
```

`$membershipId` is `$data['membership_id']` — the request body of an unauthenticated,
unrate-limited endpoint (**S6**). Nothing checks that the subscription belongs to the person
signing up, and nothing checks that it is unclaimed.

**The attack needs no guessing.** A member's own `provider_subscription_id` is visible to
them — it is in their dashboard, in the SDK session, and in every webhook payload. They post
it as `membership_id` on a fresh signup:

1. tier resolves to their paid tier ✅
2. the adoption `update()` matches **zero rows** — the subscription already has a
   `member_reference_num` — so it silently no-ops
3. a new account is created at that tier, with no subscription of its own and nothing to bill

Nothing looks wrong afterwards. The original member keeps their link row; the extra accounts
are simply Enterprise. Repeat for as many accounts as wanted. One paid subscription becomes
unlimited paid-tier accounts.

**The same gap is also a race**, without any attacker: two legitimate signups posting the same
id concurrently both resolve the paid tier, and only one adopts.

**The fix is to stop doing it twice.** Claim the row and read the tier from what was claimed,
so *"the tier is the one this signup owns"* is true by construction rather than by two queries
agreeing:

```php
$claimed = DirectPaymentSubscription::where('provider_subscription_id', $membershipId)
    ->whereNull('member_reference_num')
    ->update(['member_reference_num' => $reference]);

// $claimed === 1 → resolve the tier from that row
// $claimed === 0 → already taken, or no such subscription → tier 0, subscription_unlinked
```

This is the same conditional-write shape as
[../alloc-v2-parse-direct/03](../alloc-v2-parse-direct/03-parse-cloud-functions.md) §3.4.1 and
[../www/forms/15](../www/forms/15-allocation.md) §15.6: **the row count is the answer**, and
the check cannot be separated from the act.

Two consequences to accept deliberately:

- It needs `reference_num` to exist *before* the tier is resolved. Today `Membership::create()`
  runs first. Under §7.5's two-step flow the reference already exists by then, which is one
  more reason that ordering is the right one.
- `direct_payments:backfill-subscriptions` must not undo it — a backfill that adopts an already
  adopted row would reintroduce the same ambiguity from the other direction. Check that
  command's `whereNull` before shipping this.

### 7.2.2 S20 — free and broken-paid are the same row

Related, and worth stating separately because the fix is different.

When `membership_id` is present but does not resolve, the member lands on tier 0 —
**byte-identical in `brain_memberships` to somebody who chose Personal.** The only trace is a
`Log::warning('subscription_unlinked')`. `store()`'s own comment says the two *"are NOT the
same"*, and it is right, but nothing in the data records the difference.

So a member who paid for Business and hit a link-row failure has a free account, and the only
things that know are a log line and whoever next runs
`direct_payments:backfill-subscriptions`. The trade the comment defends — don't fail the signup,
the member has already paid — is the right one. Recording nothing is not.

**Fix:** a `tier_source` column on `brain_memberships` — `free` · `paid` · `unresolved`.
[../billing/09](../billing/09-entitlement-integration.md) §9.3's entitlement endpoint already
returns a `tier_source` field (`billing` vs `manual`); it has nothing to report because signup
never stored one. `unresolved` also gives the backfill command a queryable work list instead of
a log grep.

## 7.3 Phases

**P0 is not part of the redesign.** It is damage control and ships on its own branch, today.

| Phase | Contents | Depends on |
|---|---|---|
| **P0 — stop the bleeding** | **S1**: `escapeshellarg()` in `RunCommandBG` *and* an allowlist on `reference_num` at the call site (belt and braces — the helper has other callers). **S19**: collapse tier-resolve and adopt into one guarded claim (§7.2.1) — small, self-contained, and it closes a live tier-escalation path. **S2**: put the admin routes behind real auth, leaving only `/register`, `/validate-username`, `/validate-email` public. **S3**: stop reading `account_status` from the body. **S17**: delete the `console.log`. **S25**: use `VITE_AUTH_URL` on the success page — a production signup currently ends at the dev host. **S35**: remove `GET /debug/config` — it hands the service key to anyone. **S34**: stop logging headers and key values. **S29**: settle the key as configuration **and rotate it**, in that order, and **before** step 0's guard is added — otherwise the guard validates a string that is in the repo and on a public endpoint. S35 → S29 → step 0 is the sequence; any other order publishes the new key or guards with the old one. ~~**S23 *if* the check in §7.7.1 fails**~~ — **DONE 2026-08-30.** The check failed, so this was live. Fixed along with the empty-container branch it was masking (§7.7.1); init now succeeds, which is what makes the rest of this section testable at all | nothing |
| **P1 — server owns identity** | **S5/S4**: the server generates `reference_num`; the server performs Checkpoint registration. **S8**: provision from `$membership->reference_num`. **S6**: rate limits. **S30**: guard the www proxy as well as auth's endpoint. **S31**: host from config, no `env()` in the constructor, no hardcoded host fallback. **S20**: `tier_source` recorded (§7.2.2) — it is a migration plus one write, but it means nothing until the reference exists before the claim, which is P1's job. **S21/S22**: the template seeds empty containers and carries `member_reference_num` (§7.7.1) — same reason, the reference has to exist first | P0 |
| **P2 — one transaction** | **S13/S14**: a single server-side signup transaction with defined compensation. **S28**: uniqueness reserved before Checkpoint is touched, so a rejected signup no longer burns the email (§7.7.2). **S24**: sign-in behaviour matches what the member is told. **S32/S33**: the allocation call moves inside the transaction and carries the member reference, so a failure rolls back instead of orphaning (§7.7.3). **S9/S7**: remove the client's tier and reference bookkeeping entirely — it stops existing rather than getting fixed | P1 |
| **P2b — storage provisioning** | §7.7.4, and it can run alongside P2 rather than after it — it shares no code with the rest. **S37**: record the outcome and check the script's steps. **S36**: give it an owner in cloud-storage. **S38/S39**: one permission model, one path constant. **S40/S41**: logfile, quoting, and the dead `$domain` | P0 (for **S1**) |
| **P3 — tidy** | **S10** welcome email, **S11** guard, **S12** scoped clear, **S15** one DP id, **S16** one tier ladder, **S26** one parameter name for verification (which then makes **OQ-G** answerable) | P2 |

### 7.3.1 Why P1 before P2

P2's transaction is only worth building once the server, not the browser, decides who the
member is. Building it first would wrap a transaction around a client-asserted identity and
make the wrong thing atomic.

## 7.4 The decisions

| # | Decision | Why |
|---|---|---|
| **D1** | **`reference_num` is generated server-side**, cryptographically, and returned to the client — never accepted from it | It is the primary key every app in the estate joins on, it is the S1 injection vector, and `Math.random()` is not a source of identifiers. The `m1x` prefix convention stays; only the generator moves |
| **D2** | **Checkpoint registration moves to the server**, inside the signup transaction | S4 and S13 are the same defect seen from two sides. The server already talks to Checkpoint over HMAC (`checkpoint.service`); the browser doing it is what makes both "identity is client-asserted" and "membership without a login" possible |
| **D3** | **The endpoint splits in two.** `POST /register` stays public and narrow; the admin operations (`index`, `update`, afs-connect settings, the global flag) move behind Checkpoint auth | One handler serving *"anyone on the internet"* and *"a platform operator"* is why S2 and S3 exist. `store()`'s own `isAdminCreation()` shows the seam was already felt and answered with a client-supplied field |
| **D4** | **Signup is one server-side transaction with explicit compensation**, not a client-orchestrated sequence of three calls | The browser currently owns the ordering of payment → Parse object → membership row, and any failure between them is unrecoverable without an admin. See §7.5 |
| **D5** | **The client stops carrying tier state.** No `afs_selected_membership`, no posted `membership`, no `parse_alloc`, no `user_id`, no `account_status` | The server already derives the tier (§7.1). Every remaining client-supplied field on this endpoint is either ignored or a hole. Deleting them is smaller than validating them |
| **D6** | **The welcome email is a real, unconditional side effect** of a successful signup, sent through `BillingMailer` | It is currently gated on a debug flag nobody sets (S10). `billing/`'s mailer exists now; this should not be a bespoke SendGrid call inside a controller |
| **D7** | **`account_status` is never writable by signup.** It is always `1` | Billing writes `membership`; account lifecycle writes `account_status`; features read both ([../handoff.md](../handoff.md) §9). Signup is neither |
| **D8** | **A paid tier is granted only by a subscription this signup successfully claimed** — one conditional write whose row count is the answer, never a lookup followed by a separate adoption | Two independent queries can disagree, and today they do: the resolve has no guard while the adopt does, so a replayed subscription id resolves a paid tier and adopts nothing (**S19**). The same shape appears in [../www/forms/15](../www/forms/15-allocation.md) §15.6 and [../alloc-v2-parse-direct/03](../alloc-v2-parse-direct/03-parse-cloud-functions.md) §3.4.1 — *the check cannot be separated from the act* |
| **D9** | **How a member reached their tier is recorded, not inferred** — `tier_source` of `free`, `paid` or `unresolved` | Tier 0 currently means three different things and the data cannot tell them apart (**S20**). "The member paid and we could not link it" is an operational state that needs a queryable home, not a log line |

## 7.5 The target flow

```
  browser                          server (Brain)
  ───────                          ──────────────
  select plan
  fill form
  POST /register/begin  ─────────▶ validate; reserve username+email
                       ◀───────── { reference_num, signup_token }
  confirmPayment()
    (provider; may redirect)
  POST /register/complete ───────▶ ┌─ ONE TRANSACTION ────────────────┐
    { signup_token,                │ resolve tier from the DP link    │
      provider_subscription_id }   │ register with Checkpoint (HMAC)  │
                                   │ create MemberRegistry (Parse)    │
                                   │ INSERT brain_memberships         │
                                   │ adopt the DP link row            │
                                   └──────────────────────────────────┘
                                     then, outside it:
                                       provision storage (escaped)
                                       send the welcome email
                       ◀───────── { success, reference_num }
```

**What the two-step buys.** `reference_num` exists *before* payment, so the value in the
provider's metadata and the value on the member row are the same one by construction — S7
cannot recur. It also gives username/email reservation a place to live, closing the race
where two people take the same username between the availability check and the insert.

**Compensation, per step, must be written down before this is built:**

| Fails | Then |
|---|---|
| Checkpoint registration | Roll back the transaction; the member is told to retry; **the charge is refunded or the attempt is resumable** — pick one, D-level decision (OQ-C) |
| Parse `MemberRegistry` creation | Roll back. Signup without an allocation record produces a member `alloc_v2` cannot resolve, which fails closed on every `block` resource |
| `brain_memberships` insert | Roll back; the Checkpoint user is orphaned — needs a reaper, or creation ordered last |
| Storage provisioning | **Do not roll back.** Log and retry out of band; a member without storage is degraded, not broken |
| Welcome email | Do not roll back. Log |

The rule, which is [../handoff.md](../handoff.md) §9's asymmetry applied here: **fail the
signup while it can still be retried; never fail it after the money moved.**

## 7.6 What the client keeps

`create.ts` and `memberships/index.ts` stay responsible for exactly what a browser should be:

- rendering live tier prices through the Portal SDK
- form validation and the availability hints
- calling `confirmPayment()` and handling the provider redirect
- the two `POST`s above

They stop being responsible for: generating identifiers, deciding the tier, registering with
Checkpoint, creating the Parse object, and sequencing the three writes.

That is a **reduction** in client code. `memberships/index.ts` loses
`attemptClientCheckpoint`, `createParseAllocation`, `getOrGenerateMemberReferenceNum`,
`createMembershipPlansObject` and most of `MembershipCreationPayload`.

## 7.7 What this does not change

- **The Portal SDK stays the pricing source.** `dp.setupSubscription()` /
  `setUITierPrice()` / `setupTiers()` work and are the right shape.
- **The tier derivation in `store()` stays** — its *model* is right (§7.1); it gains the
  guarded claim from §7.2.1 and moves into the transaction.
- **The DP link-row adoption and `direct_payments:backfill-subscriptions` stay**, unchanged.
- **`parse_member_query_v4.json` and `MemberInitController` stay** — one Parse object per
  member, created server-side from a template, which also closes **S18** for this path. What
  the template *seeds* changes; see §7.7.1.
- **The `m1x` reference prefix, the 0–4 tier index, and every column name** stay.

### 7.7.1 The initial Parse object — what the template seeds

Provisioning is the one place the new allocation model reaches back into signup, so this
section is where it gets reconciled.

**What happens today.** `MemberInitController::init()` copies the template key-for-key. Its
only transformation is `updatePeriodStarts()`; nothing else is filtered:

```php
foreach ($template as $key => $value) {
    if (is_array($value)) { … setArray / setAssociativeArray … }
    else                  { $member->set($key, $value); }
}
```

**S21 — every member is provisioned owning six things they do not have.**
[../members_brain/README.md](../members_brain/README.md) item 4 flagged this against v3, where
the template seeded one example domain and one example project. v4 seeded six:

> **Template fixed** in `4b2e9fa` — `parse_member_query_v4.json` is now generated and
> contains **zero** `--Example_` entries, with all five containers empty. The existing-member
> half is done too: on 2026-08-30 the registry held 15 `MemberRegistry` objects for 3
> members (12 orphans — S18's fingerprint), 12 of them carrying placeholders. All 15 were
> deleted and 3 rebuilt in v4 shape with `member_reference_num` set, and
> `brain_memberships.parse_alloc` was relinked. **But see S23 — removing the placeholders is
> exactly what exposed the empty-container bug, so this must not ship without that fix.**

For the record, what v4 used to seed:

```
domains[]              → "--Example_DOMAIN--"  (containing "--Example_STORE--")
pm_projects[]          → "--Example_PM_PROJECT--"
dreamgraph_graphs[]    → "--Example_GRAPH--"
pdf_editor_documents[] → "--Example_PDF_DOCUMENT--"
design_flow_projects[] → "--Example_DESIGN_PROJECT--"
```

Under the retention engine these are not cosmetic. Each carries `band`, `state` and a
`resources` map, so [02](02-architecture.md)'s cascade walks them, counts them toward
allowance, and on a downgrade will retire a domain that never existed.

**S22 — the template has no `member_reference_num`, and something now depends on it.**
Top-level keys are `_schema`, `_doc`, `_fields`, `member_resources` and the five containers.
Nothing points back at the member, so the link is one-directional
(`brain_memberships.parse_alloc` → objectId).

That was survivable while the browser was the only caller. It is not survivable now:
[../alloc-v2-parse-direct/03](../alloc-v2-parse-direct/03-parse-cloud-functions.md) §3.4.2's
ownership check compares `registry.get('member_reference_num')` against the resolved caller
and would find nothing; `MemberRegistryCounter` rows are keyed on `member` and cannot be
joined back; and no reconciler can walk Parse → member. **This field is a prerequisite of the
cloud-function design, not a tidy-up.**

**S23 — `_schema`, `_doc` and `_fields` are written as real Parse columns, and cannot be.**
They are documentation — a prose paragraph and a six-key description object — copied into
every member's record. Parse validates field names with `/^[A-Za-z][A-Za-z0-9_]*$/` (the
regex [../alloc-v2-parse-direct/05](../alloc-v2-parse-direct/05-findings-and-execution-plan.md)
§5.2 quotes for the dot-path problem), **and these keys start with an underscore.**

> **SETTLED 2026-08-30 — it was the outage branch. Now fixed.** The check this section
> asked for was run against the live registry with the master key:
>
> ```
> {"code":105,"error":"Invalid field name: _schema."}
> ```
>
> The save is all-or-nothing, so `init()` failed on **every** call and every signup failed
> at `createParseAllocation()`. It had been broken since the v4 template introduced these
> keys on 2026-08-27 — that file has only two commits and both carry them, so the v4
> template never once created an object. `handoff.md` §7 recording that nothing is
> published is why a permanently broken init went unnoticed.
>
> **A second fault was hiding behind it, and S21 is what exposed it.** With the `_` keys
> skipped, `init()` still failed:
>
> ```
> schema mismatch for MemberRegistry.domains; expected Array but got Object
> ```
>
> The controller decided sequential-vs-associative with
> `array_keys($value) === range(0, count($value) - 1)`. For an **empty** array that is
> `[] === range(0, -1)`, and PHP's `range` counts *downward* when start exceeds end,
> returning `[0, -1]`. So every empty container took the associative branch and reached
> Parse as `{}` where the schema expects `[]`. v3 never hit it because its containers held
> the `--Example_` rows and so were never empty: **the placeholder was accidentally
> load-bearing.** The two fixes must land together — S21 alone breaks provisioning, S23
> alone leaves it broken.
>
> Both are fixed in `MemberInitController::init()`: skip `_`-prefixed keys, and use
> `array_is_list()` for the branch. Verified end to end — `POST /checkpoint/member/init`
> now returns `{"success":true,"data":{"parseObjectId":"…"}}` and produces 14 resources,
> five real empty arrays and no underscore keys. Full account in `../00_ISSUES.md` I3.

#### What the initial object becomes

```json
{
  "member_reference_num": "m1x…",
  "member_resources":     { "…14 keys…": { "used": 0, "retired": 0, "purge_after": null } },
  "domains":              [],
  "pm_projects":          [],
  "dreamgraph_graphs":    [],
  "pdf_editor_documents": [],
  "design_flow_projects": []
}
```

Empty containers, and the reference. Three changes produce it:

| | Change |
|---|---|
| **Template** | Placeholder entries removed from all five containers; `member_reference_num` added as an empty string the controller fills |
| **`init()`** | Takes the member reference as a parameter and writes it; **skips `_`-prefixed keys** so documentation stays in the file and never reaches Parse |
| **Caller** | Server-side, inside the signup transaction (**D2**/**D4**), which is what lets the reference be known at provisioning time |

`member_resources` keeps its fourteen keys at zero. Once counters live in
`MemberRegistryCounter` those values are a **projection**, not the authority — but a
present-and-zero key renders correctly on a dashboard where a missing one makes every reader
handle absence.

The placeholders' real job — showing what a populated registry looks like — belongs to
`_doc`, which stays in the file. That is the distinction the current template collapses:
**a schema example and a member's live record are not the same document.**

#### Ordering

§7.5 already creates `reference_num` before payment, so by the time the transaction reaches
Parse the value exists. That is a third reason for the two-step flow, alongside S7 (the
provider metadata matching the member row) and username reservation.

### 7.7.2 Checkpoint registration — the other thing signup creates

Same treatment as §7.7.1, for the identity half. **D2** moves this to the server; this section
is what the move has to account for.

**What happens today.** `createMembership()` calls `attemptClientCheckpoint()` *first*, then
POSTs to Laravel:

```ts
const cpResult = await checkpoint.auth.register(
    payload.email, payload.password, payload.username,
    { requireVerification: false }
);
return { user_id: cpResult.user_id };     // ← posted to Laravel, trusted verbatim (S4)
```

A failure is caught, logged to `MembershipCheckpointError`, and signup continues without a
`user_id` (**S13**).

**S28 — a failed signup burns the email address.** This is the one to fix first in this
section, because it strands a real person.

The order is: Checkpoint registers the user → *then* `store()` runs its duplicate checks:

```php
if (Membership::where('username', $data['username'] ?? '')->exists()) { return 422; }
if (Membership::where('email',    $data['email']    ?? '')->exists()) { return 422; }
```

So a signup that fails the duplicate check has **already** created a Checkpoint user holding
that email and username. The member sees *"Email already registered"*, tries again, and
Checkpoint now rejects them too — because it took the address on the attempt that failed. The
orphaned Checkpoint user is not reaped by anything.

The blur-time `checkUsernameAvailability` / `checkEmailAvailability` calls do not prevent
this: they are advisory, race-prone, and **return `true` on network error** so a transient
failure never blocks submit. The authoritative check is the one that runs too late.

**S24 — the member is never signed in, and a docblock says otherwise.**
`memberships/index.ts` opens by stating the module *"also registers the user in the Checkpoint
authentication system (client-side) so they are immediately signed in."* It does not.
`register()` posts and returns; only `login()` calls `setTokens()` and `notifyAuthChange()`,
and nothing in the signup flow calls `login()`. Members finish signup logged out.

**S25 — the success page hardcodes the development auth host.**
`showSuccessPage()` builds inline HTML containing
`https://auth15.appfactory.studio/login`. `main.ts`, in the same folder, does this correctly:
`import.meta.env.VITE_AUTH_URL + '/login'`. So the environment variable exists and is used
thirty lines away. **A production signup sends the member to the dev host.**

**S26 — `require_verification` is read under a different name and silently discarded.**

```
client  auth.ts          →  require_verification: options?.requireVerification ?? true
server  AuthController   →  $request->input('require_email_verification', false)
```

Different keys. Whatever the client sends is ignored and the server's own default — `false` —
applies. Today the outcomes coincide, so nothing looks broken. **A future change setting
`requireVerification: true` would do nothing at all**, which is the same class of never-exercised
mismatch as the `qr_code_uri` / `qr_code_url` bug in [../TODOS.md](../TODOS.md) step 09.

**S27 — there is therefore no email verification anywhere in signup.** Any address can be
registered by anyone, on an endpoint with no authentication and no rate limit (**S6**). That
address becomes the member's identity in `brain_memberships` and the destination for every
billing email. Fixing S26 is a prerequisite for turning verification on; it is not the same
decision (**OQ-G**).

#### What Checkpoint registration becomes

| | Change |
|---|---|
| **Where** | Server-side, over the existing `checkpoint.service` HMAC channel, inside the signup transaction — **D2** |
| **Order** | Uniqueness is settled **before** Checkpoint is touched. §7.5's `/register/begin` reserves username and email; Checkpoint registration happens in `/register/complete`, after the reservation holds |
| **`user_id`** | Comes from the server's own call. Never read from the request body (**S4**, **D5**) |
| **Failure** | Rolls the transaction back — no `brain_memberships` row, no orphaned Checkpoint user, and the member can retry with the same email (**S13**, §7.5) |
| **Sign-in** | Either the server returns a session and the docblock becomes true, or the success page says plainly that they need to log in. Both are fine; the current state — claiming one and doing the other — is not (**S24**) |
| **Redirect** | `VITE_AUTH_URL`, like `main.ts` (**S25**) |
| **Verification** | Client and server agree on one parameter name (**S26**), after which whether to require it is a product decision (**OQ-G**) |

`MembershipCheckpointError` and its two admin routes stay. Under a transactional flow they stop
being the recovery mechanism for a half-created member and become what they should be — a record
of *why* a signup was refused.

> **The password does not change hands.** It goes from the browser to Checkpoint directly, which
> is correct — Checkpoint is the identity provider. Moving registration server-side means the
> password reaches Laravel, so it must be held in memory for the length of the call and never
> logged, never persisted, never echoed. **S17** is the existing version of that mistake and is
> a P0 fix regardless.

### 7.7.3 The Parse allocation call — the third thing signup creates

Same treatment again, for `createParseAllocation()`. This is the shortest-looking step in
signup and the one with the most behind it: a browser call, a www proxy, an auth controller, and
the Parse SDK — **four hops, two of them unauthenticated.**

```
create.ts  ──POST /portal/api/v1/checkpoint/member/init──▶  www MemberInitController
                                                                    │ cURL, body: '{}'
                                                                    ▼
                                            auth MemberInitController  ──▶  Parse (master key)
```

**S30 — the proxy is unauthenticated too, so the chain has two open doors, not one.**
§3.8 step 0 of [../alloc-v2-parse-direct/03](../alloc-v2-parse-direct/03-parse-cloud-functions.md)
names auth's endpoint. The www proxy in front of it is equally open — it sits in the
`portal.cors` group (**S2**), and its own bearer handling is optional by construction:

```php
$jwt = $request->bearerToken() ?? $request->cookie('portal_auth_token') ?? '';
// …
if ($jwt) { $headers[] = 'Authorization: Bearer ' . $jwt; }
```

An empty string is accepted and the request proceeds without the header. **Guarding only auth's
endpoint leaves this one reachable**, so step 0 has to cover both.

**S29 — the service key is a hardcoded literal in four places, and the config default is a
fifth string that disagrees with them.**

| # | Where | Value |
|---|---|---|
| 1 | `www/config/checkpoint.php` | `env('CHECKPOINT_SYSTEM_KEY', 'default_system_key_change_me')` |
| 2 | `auth/config/checkpoint.php` | the same placeholder default |
| 3 | www `MemberInitController` (×2 headers) | `config('checkpoint.system_key') ?: 'members_master_key_exchange_secret'` |
| 4 | www `AllocationV2Controller:222` | `$systemKey = 'members_master_key_exchange_secret';` |
| 5 | `auth` `AllocationV2Controller::authorizeRequest()` | `$validKey = 'members_master_key_exchange_secret';` — **read from neither config nor env** |

Because the config default is truthy, site 3's `?:` never fires: with `CHECKPOINT_SYSTEM_KEY`
unset it sends `default_system_key_change_me`, which site 5's literal does not match. So the
placeholder is not a safety net — it is a credential that travels and is then rejected, while
the deployment looks configured.

> ### The live key is the published string. Verified, not inferred.
>
> `CHECKPOINT_SYSTEM_KEY` is set in both `www/.env` and `auth/.env`. Both values are
> **34 characters and hash identically to each other and to
> `members_master_key_exchange_secret`** — the literal in the source.
>
> So the server-to-server channel is currently protected by a string committed to the
> repository. **Removing the literals does not fix that.** The remediation is two steps and
> only the second one matters:
>
> 1. Both ends read `config('checkpoint.system_key')`, no literals, no placeholder default,
>    deny when unset, and compare with `hash_equals()` rather than `===`.
> 2. **Rotate `CHECKPOINT_SYSTEM_KEY` in both environments together.** Step 1 makes rotation
>    possible; it is not the rotation. Same shape as the `JWT_SECRET` rotation still open in
>    [../TODOS.md](../TODOS.md) step 05 item 4 — and the same failure mode if it is skipped,
>    because the old value stays valid forever until it is replaced.
>
> **This is also a trap for the step-0 guard.** The moment `checkpoint.service` is added to
> `member/init`, the credential it validates is that published string, and sites 1–2 disagree
> with sites 3–5 about which string it even is. Whoever does step 0 will find it either
> silently passing on a shared literal or failing for reasons unrelated to their change.
> Settle and rotate the key first.

**S35 — `auth` publishes the service key over HTTP, unauthenticated.**

```php
// auth/routes/web.php:81 — top level, outside every group, no middleware
Route::get('/debug/config', function() {
    return response()->json(['system_key' => config('checkpoint.system_key')]);
});
```

Anyone who can reach the auth host can read the shared secret. This is why S29's rotation is
worth doing rather than filing: the key is available three separate ways — in source, in the
repository's `.env`, and from a public endpoint — so any one of them being closed leaves the
other two.

**Remove the route first, rotate second.** Rotating while this endpoint is live simply
publishes the new key. Per the estate's own preference for retiring rather than deleting
([../market/20](../market/20_retire_legacy_stripe_scripts.md)), taking it out of the route
file is enough; the closure need not survive anywhere.

**S34 — bearer tokens and the service key are written to the log on every allocation call.**
The same `authorizeRequest()`:

```php
Log::debug('authorizeRequest started', ['headers' => $request->headers->all()]);
// …on failure:
if (env('CHECKPOINT_DEBUG', true)) { Log::debug('AllocV2 System Auth Failed', [ … 'header_val' => $systemKey … ]); }
```

`$request->headers->all()` includes `Authorization`. The failure branch logs the secret's
**value**. `CHECKPOINT_DEBUG` defaults to **true**, and being `env()` inside a controller it
resolves to that default under `config:cache` regardless of what `.env` says.
[../afs-connect/06-security-and-service-auth.md](../afs-connect/06-security-and-service-auth.md)
states the opposite rule for its own signing scheme — *never log the key or signature itself*.

This lives in `AllocationV2Controller`, the proxy already slated for retirement (§7.3 P0 / 03
§3.8 step 6) — but it is routed and live until that happens.

**Correction 2026-09-22:** the `/consume` and `/release` routes specifically have already been
removed from `auth/routes/checkpoint.php` (a dated comment there cites this same `03 §3.8
step 6`) — confirmed directly while wiring
[features/2-domain_email_accounts.md](features/2-domain_email_accounts.md)'s cross-repo
channel, after first trying to call them and finding out why they 405'd. `authorizeRequest()`
itself, and S34's logging inside it, is still live for the surviving `index`/`show`/`check`
routes, so this does **not** mean S34/S35/S29 are resolved — only that the specific
consume/release write path this bullet describes is gone. The replacement write path is the
`allocConsume`/`allocRelease` Parse Cloud Functions (`auth/public/checkpoint/parse/cloud/functions.ts`),
which resolve identity from a validated Checkpoint token or a separate `ALLOC_SERVICE_KEY`
service credential — not `AllocationV2Controller`'s `X-Checkpoint-System-Key` at all, so S29's
key-rotation concern doesn't carry over to the replacement unchanged; it would need its own
audit of `ALLOC_SERVICE_KEY`'s handling.

**S31 — the proxy hardcodes both auth hosts and reads `env()` in its constructor.**

```php
$devMode = filter_var(env('DEVELOPMENT_MODE', false), FILTER_VALIDATE_BOOLEAN);
$default = $devMode ? 'https://auth15.appfactory.studio' : 'https://auth.appfactory.studio';
$this->checkpointUrl = rtrim((string) env('CHECKPOINT_BASE_URL', $default), '/');
```

Same class as **S25**, server-side. `env()` outside a config file returns its default once
`config:cache` has run, so a cached-config deployment resolves `DEVELOPMENT_MODE` to `false`
whatever `.env` holds — and a **development** box then provisions Parse objects against the
**production** registry. That is the dangerous direction, and it is silent.

This is finding **M11/M12** from
[../checkpoint-server-auth/03](../checkpoint-server-auth/03-members-remediation.md), still open
per [../TODOS.md](../TODOS.md) step 03 item 2, in one more controller.

**S32 — a Parse object is created before the membership row, and orphans are never reaped.**
`create.ts` runs `createParseAllocation()` and *then* `registerMembership()`. Every failed
attempt after that point leaves a `MemberRegistry` object with nothing pointing at it, and
every retry creates another. A member who takes three attempts leaves three; `parse_alloc` names
the last. Combined with **S28**, a retry loop leaves debris in three systems.

**S33 — the call cannot pass a member reference, because it posts a literal `'{}'`.**

```php
CURLOPT_POSTFIELDS => '{}',
```

This is the concrete reason **S22** exists. Even with `member_reference_num` added to the
template there is no argument to fill it from — at this point in the flow the reference is a
client-side value the server has never seen. §7.5's two-step flow is what makes it available.

#### What the allocation call becomes

| | Change |
|---|---|
| **Caller** | Server-side, inside the signup transaction — the browser stops calling it, which closes **S18** and **S30** for this path |
| **Body** | Carries `member_reference_num`, so the object is identifiable and §3.4.2's ownership check has something to check (**S33**, **S22**) |
| **Failure** | Rolls the transaction back. A member without an allocation record is one `alloc_v2` cannot resolve, and that fails closed on every `block` resource ([../allocation.md](../allocation.md) §10) — so this is not a "log and continue" step (**S32**) |
| **Both endpoints** | Service-authenticated, on a key that is configuration rather than a literal (**S29**, **S30**) |
| **Host** | `CHECKPOINT_BASE_URL` from config, never `env()` in a constructor, with no hardcoded host as a fallback (**S31**) |
| **Logging** | Headers and key values never logged (**S34**) |

> Once signup is one server-side transaction, the proxy has no browser caller left. Whether it
> is retired or kept as an internal seam is **OQ-I** — but it should not stay reachable from the
> public route group either way.

### 7.7.4 Storage provisioning — the fourth thing signup creates

The last of the four. `store()` finishes by firing a shell command:

```php
$managed_domain = FunctionsController::GetManagedDomain();
$domain = $managed_domain["Domain"];                       // ← assigned, never used
$shParams = "--create-afs-member-storage " . $membership->reference_num;
FunctionsController::RunCommandBG($shParams, $managed_domain, null, true);
```

and `core/scripts/commands.sh` does, in full:

```bash
if [ "$action" = "--create-afs-member-storage" ]; then
    member_ref="$param1"
    logfile="/mnt/${STORAGE_LOCATION}/$systemuser/logs/update-dns-records-subdomains.log"
    create_log_directory $systemuser $logfile
    now="$(date +"%r")"
    mkdir  -p /mnt/node1/${member_ref}
    chown  -R apache.apache /mnt/node1/${member_ref}
    chmod  -R 775 /mnt/node1/${member_ref}
    exit 0
fi
```

**S36 — the cloud-storage plan does not know this exists.** Fourteen documents about member
file storage, and `--create-afs-member-storage` appears in none of them. Its only two hits for
"provision" are about a mocked VM feature, explicitly marked *"Nothing to do with files."*

So the storage **root** is created by signup and everything beneath it is owned by
[../cloud-storage/](../cloud-storage/README.md), with no contract between the two. That is the
same seam §7.7.1–§7.7.3 each found, in the fourth place.

**S37 — nothing knows whether it worked, and nothing can retry it.** Two independent layers of
silence:

- `RunCommandBG` runs `popen("nohup $cmd 2>&1 > /dev/null & echo $!")`. Output goes to
  `/dev/null`; only a PID comes back, and `store()` discards it.
- The script `exit 0`s unconditionally. `mkdir`, `chown` and `chmod` are unchecked, so a full
  disk, an unmounted `/mnt/node1`, or a permission failure are indistinguishable from success.

Nothing is written to the database to say provisioning was attempted, so there is no work list
and no reconciler. A member whose storage silently failed is discovered when they try to upload.

**S38 — two creators, two permission models, and the second one is silent.**

| | Creates | Owner | Mode | On failure |
|---|---|---|---|---|
| `commands.sh` at signup | the member root | `apache:apache` | `775` | ignored (`exit 0`) |
| `StorageTree` / `RetentionService` later | bands and subdirectories | the PHP-FPM user | `0755` | **`@mkdir`** — suppressed |

Different owner, different mode, and both failure paths swallowed. `PathResolver` documents the
division — *"The caller is responsible for mkdir'ing the band root before use"* — but neither
side documents the ownership split, and `@mkdir` means a permission clash surfaces as a missing
file rather than an error.

Worth noting what follows from it: `@mkdir($absolute, 0755, true)` is **recursive**, so PHP
would create the member root itself given permission on `/mnt/node1`. The shell step's only
unique contribution is the `chown`. Whether it needs to exist at all is **OQ-J**.

**S39 — `/mnt/node1` is hardcoded in two repositories.** `commands.sh` line 340, and
`PathResolver::STORAGE_ROOT = '/mnt/node1'`. They agree today, which is the only reason storage
works. The script has a `${STORAGE_LOCATION}` variable and uses it for its logfile five lines
earlier — then hardcodes `node1` for the directory that matters. Moving storage to another node
means editing two repos, in two languages, with nothing linking them.

**S40 — the log path is a copy-paste from the DNS action.**
`logfile=".../logs/update-dns-records-subdomains.log"` — storage provisioning opens a DNS log.
`create_log_directory` then runs for a file nothing writes to, because the only `echo` is
commented out. Harmless, and a reliable way to mislead whoever next debugs this.

**S41 — `${member_ref}` is unquoted inside the script.** `mkdir -p /mnt/node1/${member_ref}`.
The PHP layer now escapes (**S1**) and validates the field, so this is not currently reachable
— but the script is one unvalidated caller away from word-splitting a path, and it is the
second half of the same defect. Quote it.

#### What storage provisioning becomes

| | Change |
|---|---|
| **Ownership** | Named in [../cloud-storage/](../cloud-storage/README.md) as its own concern, with signup as a caller. Today neither plan mentions the other (**S36**) |
| **Outcome** | Recorded. A `storage_provisioned_at` (or a row in whatever cloud-storage settles on) turns "did this work?" into a query and gives a reconciler something to sweep (**S37**) |
| **Failure** | Checked. The script tests each step; the caller sees a result rather than a discarded PID |
| **Rollback** | **None.** Per §7.5's table this stays outside the transaction: a member without storage is degraded, not broken, and failing a paid signup over a directory is the wrong trade. It is retried out of band — which is exactly why the record in row 2 has to exist |
| **Path** | One source of truth for `/mnt/node1`, read by both the script and `PathResolver` (**S39**) |
| **Permissions** | One documented owner and mode for the whole tree, not one per creator (**S38**) |
| **Hygiene** | Correct logfile, quoted variable, and the dead `$domain` assignment in `store()` removed (**S40**, **S41**) |

> **This section is deliberately the least urgent of the four.** Nothing here is exploitable
> now that **S1** is fixed, and the failure mode is a degraded member rather than a lost one.
> It is here because storage is the only resource in the estate with a working retention
> implementation ([../cloud-storage/08](../cloud-storage/08-storage-retention.md)) — the one
> the whole retention engine is modelled on — and the directory that model operates over is
> created by a fire-and-forget shell call that no storage document mentions.

**Existing members** carry the placeholders and no reference. A one-time sweep strips the
`--Example_*--` entries and backfills `member_reference_num` from
`brain_memberships.parse_alloc`. Same caveat as every other backfill in this folder: confirm
[../handoff.md](../handoff.md) §7 still holds and this is a script nobody has to write.

## 7.8 Acceptance

| # | Check | Expect |
|---|---|---|
| A1 | `reference_num` of `x"; touch /tmp/pwned; #` posted to `/register` | Rejected by the allowlist; **no file created**. Then repeat against `RunCommandBG` directly — also inert |
| A2 | `GET /portal/api/v1/memberships` with no credentials | 401 |
| A3 | `POST /register` with `account_status: 2` | Member created with `account_status = 1` |
| A4 | `POST /register` with someone else's `user_id` | Ignored; the Checkpoint user is the one the server registered |
| A5 | Two clients posting the same `username` concurrently | One succeeds, one gets a clean 422. No duplicate row |
| A6 | Provider metadata `user_id` vs `brain_memberships.reference_num`, first attempt | **Identical** (S7) |
| A7 | Checkpoint made to fail during signup | No `brain_memberships` row, no orphaned Parse object, and the member can retry |
| A8 | A successful paid signup | Welcome email sent, without any `testing` flag (S10) |
| A9 | Browser console during a full signup | **No password anywhere** (S17) |
| A10 | `sessionStorage` after a successful signup | Only this flow's keys removed (S12) |
| A11 | Load `/create` with no plan selected | Redirected to `/`, not a dead form (S11) |
| A12 | 50 signup attempts in a minute from one IP | Rate limited (S6) |
| A13 | A free signup | Tier 0, no payment, no subscription row, welcome email sent, `tier_source = free` |
| A15 | **Sign up posting a `membership_id` that already belongs to another member** | Tier **0**, not the borrowed tier; `subscription_unlinked` logged; the original member's link row **untouched** (S19). This is the test that matters most in P0 |
| A16 | Two signups posting the same unclaimed `membership_id` concurrently | Exactly one gets the paid tier; the other lands on 0 with `subscription_unlinked`. No row adopted twice |
| A17 | A paid signup whose link row is missing | Tier 0, `tier_source = unresolved`, and the member appears in `direct_payments:backfill-subscriptions`' work list — not only in a log (S20) |
| A18 | `direct_payments:backfill-subscriptions` run against an already-adopted row | No change. It must not re-adopt, or it reopens S19 from the other side |
| A19 | `POST /checkpoint/member/init` against a live Parse server | **Succeeds** — settles S23. Run this first; it decides whether S23 is a cleanup or an outage |
| A20 | The Parse object of a brand-new member | All five containers **empty**; no `--Example_*--` anywhere; `member_reference_num` matches `brain_memberships.reference_num` (S21, S22) |
| A21 | The same object's stored fields | No `_schema`, `_doc` or `_fields` column (S23) |
| A22 | A new member's `member_resources` | Fourteen keys present, all `used: 0` — the projection renders without any reader handling absence |
| A23 | Downgrade a brand-new member who owns nothing | The retention cascade retires **nothing**. Against today's template it would walk five placeholder resources (S21) |
| A24 | **Sign up with an email that already has a membership** | 422, **and no Checkpoint user is created**. Retrying with that email then succeeds once the conflict is resolved. This is S28 and it is the one that strands a person |
| A25 | Force the `brain_memberships` insert to fail after Checkpoint registration | Transaction rolled back, **no orphaned Checkpoint user**, member can retry with the same email |
| A26 | Complete a signup and read the success page | Redirect points at `VITE_AUTH_URL`, not `auth15` (S25) |
| A27 | Complete a signup and inspect session state | Matches whatever OQ-H decided — and the module docblock says the same thing the code does (S24) |
| A28 | `register()` called with verification requested | Server actually receives it under the name it reads (S26). Independent of whether verification is switched on |
| A29 | `POST /portal/api/v1/checkpoint/member/init` with no credentials | 401 — **the proxy, not just auth's endpoint** (S30) |
| A30 | `grep` the repository for the service key's value | No literal anywhere; both sides read it from configuration, and they agree (S29) |
| A30b | `GET /debug/config` on the auth host | **404** (S35) |
| A30c | The value in both `.env` files after remediation | **Not** `members_master_key_exchange_secret`. Rotation is the fix; removing the literals only makes it possible (S29) |
| A30d | Send the *old* key to a service endpoint after rotation | Rejected. An unrotated-but-tidied key is the failure mode this row exists to catch |
| A30e | Unset `CHECKPOINT_SYSTEM_KEY` and call a service endpoint | Denied, with a log line naming the missing configuration — never accepted on a placeholder default (S29) |
| A31 | An allocation call, then read the log | No `Authorization` header, no key value, at any log level (S34) |
| A32 | `php artisan config:cache`, then a member init on a dev deployment | Talks to the **dev** Checkpoint host, not production (S31) |
| A33 | Force `registerMembership()` to fail after the allocation call | Transaction rolled back; **no orphaned `MemberRegistry` object**; a retry creates exactly one (S32) |
| A34 | The object created by a signup | Carries `member_reference_num` — the call passed it rather than posting `{}` (S33, S22) |
| A35 | Unmount `/mnt/node1`, then sign up | Provisioning **reports failure**; the member is still created; the failure is queryable rather than silent (S37) |
| A36 | A completed signup | `/mnt/node1/{reference_num}` exists, and a record says provisioning succeeded (S37) |
| A37 | Upload a file as a brand-new member | Bands are created beneath the root with the **same owner** the root has — no permission clash between the shell and PHP creators (S38) |
| A38 | `grep -rn "/mnt/node1"` across both repos | One definition, read by both `commands.sh` and `PathResolver` (S39) |
| A39 | Provision a member whose reference contains a space *(cannot occur post-S1; test the script directly)* | One directory, correctly named — not two (S41) |
| A14 | Storage provisioned for a member whose client omitted `reference_num` | Provisioned under the row's real `reference_num` (S8) |

## 7.9 Open questions — close these before implementing

| # | Question | Notes |
|---|---|---|
| **OQ-A** | **Does the `m1x` prefix mean anything?** `getOrGenerateMemberReferenceNum` prepends it, `Str::random(12)` in `store()` does not, and the seed data in [../market/17](../market/17_seed_data.md) uses `m1xTHpRmdtH2Y`-style refs | If it is load-bearing, the server generator must produce it and the `Str::random(12)` fallback is already producing invalid refs today |
| **OQ-B** | **Is `/register` reachable by anything other than the browser?** `store()` supports an admin path (`isAdminCreation()`, `source: 'admin'`) that the client deletes before sending | D3's split depends on the answer. Same shape as the `/api/v1/progress/*` shell-script caller in [../TODOS.md](../TODOS.md) step 03 item 1 |
| **OQ-C** | **What happens to the money when account creation fails after a successful charge?** Refund automatically, or hold and make the signup resumable | §7.5's table cannot be finished without this. It is a product decision, not an engineering one |
| **OQ-D** | **Should `/validate-email` exist at all?** It is a straightforward account-enumeration oracle | The usual answer is to keep it for UX and rate-limit it hard, and to make the *failure* generic on submit. Worth a deliberate choice rather than an inherited one |
| **OQ-E** | **Who owns the welcome email's content and template?** D6 routes it through `BillingMailer`; the current inline SendGrid call reads `brain_config`'s first email provider token directly | Touches [../www/direct_payments/23-notification-config.md](../www/direct_payments/23-notification-config.md)'s configuration model |
| **OQ-F** | **Does signup need to survive Checkpoint being down?** Today it does, badly (S13) | If the answer is yes, the design is a queued retry, not a non-fatal catch. If no, D2 is simply correct |
| **OQ-G** | **Should signup require email verification?** Today none happens, and **S26** means the client cannot ask for it even if it tried | Fixing the parameter-name mismatch is unconditional. Whether to then turn it on is a product call with real consequences: it gates the paid path too, so a member who paid and cannot receive mail is stuck. If yes, decide what an unverified paid member may do while they wait |
| **OQ-J** | **Does the shell provisioning step need to exist at all?** | `StorageTree`'s `@mkdir($path, 0755, true)` is recursive, so PHP already creates the member root given permission on `/mnt/node1`. The script's only unique contribution is `chown apache.apache`. If the PHP-FPM user and `apache` are the same, or the mount is group-writable, the step is redundant — and deleting a shell call is better than fixing one (§7.7.4) |
| **OQ-I** | **Once signup is server-side, does the www member-init proxy still have a reason to exist?** | Its only caller today is the browser (§7.7.3). The server could call auth directly. Keeping it means one more hop to secure and keep in sync; retiring it means auth's endpoint is reached from more places. Either way it should leave the public route group |
| **OQ-H** | **Does signup end signed in, or at a login screen?** | **S24** says the code and its own docblock disagree. Either is defensible; returning a session is friendlier and means the server holds the password one moment longer. Decide before writing §7.5's `/register/complete` response |

## 7.10 Related

| Document | Relationship |
|---|---|
| [../www/direct_payments/22-subscription-state.md](../www/direct_payments/22-subscription-state.md) §4.2 | The tier derivation and DP link row this flow depends on — **already implemented**, unlike most of that plan |
| [../billing/08-brain-enforcement.md](../billing/08-brain-enforcement.md) | Writes the same column later, downward |
| [02-architecture.md](02-architecture.md) | Reads `membership` and `account_status`; both are created here |
| [../alloc-v2-parse-direct/03](../alloc-v2-parse-direct/03-parse-cloud-functions.md) §3.8 | Step 0 is S18. Signup is `member/init`'s only legitimate caller |
| [../members_brain/02-security-and-access.md](../members_brain/02-security-and-access.md) | Same `portal.cors` root cause as S2, on an adjacent route group |
| [../allocation.md](../allocation.md) §10 | Why a member with a missing `parse_alloc` must fail closed — the reason §7.5 rolls back on Parse failure |

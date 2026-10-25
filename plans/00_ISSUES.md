# Issues — environment, tooling, and infrastructure faults and their resolutions

Faults that blocked work on this estate, what actually caused them, and what was done
about them. Companion to `TODOS.md`: that file tracks *planned work not yet done*, this
one tracks *things that were broken*. An issue lands here when the diagnosis cost real
time, when the failure's symptom pointed somewhere other than its cause, or when the
resolution left residue someone needs to know about.

Not a changelog. If a fix is self-evident from the diff, it does not belong here. The
entries that earn their place are the ones where the error message lied.

**Status vocabulary:** `RESOLVED` — fixed and verified. `RESOLVED (residue)` — the
immediate blocker is gone, but named follow-ups remain. `OPEN` — diagnosed, not fixed.

---

## I1 — `afs/checkpoint-auth` unresolvable; `composer install` dead in `auth` and `market` — RESOLVED (residue)

**Date:** 2026-08-29
**Surfaces:** `w/auth`, `w/market`, `packages/checkpoint-auth`, Git Credential Manager,
global Composer config
**Related:** `TODOS.md` step 04 item 2 (CI/deploy machines need their own Composer
credentials) — this issue is the same root problem hitting the *primary* machine.

### Symptom

Two failures that looked like one:

1. `composer install` in `w/auth` refused outright:
   ```
   Warning: The lock file is not up to date with the latest changes in composer.json.
   - Required package "afs/checkpoint-auth" is not present in the lock file.
   ```
2. Underneath it, every git operation against the package's remote failed:
   ```
   remote: Write access to repository not granted.
   fatal: unable to access 'https://github.com/appfactorystudioplus/checkpoint-auth.git/':
   The requested URL returned error: 403
   ```

`auth` and `market` both had no `vendor/` directory at all, so nine projects' migrations
could not be run past five of them.

### Root cause — three independent faults, stacked

**(a) The credential was stored in the wrong field.** Git Credential Manager held the
PAT in the **username** slot, with the literal string `x-oauth-basic` as the
**password**. Git therefore sent `Authorization: Basic base64(<PAT>:x-oauth-basic)`.

This is the old `https://<token>:x-oauth-basic@github.com` convention. It was valid for
classic OAuth tokens; GitHub does **not** honour it for fine-grained `github_pat_*`
tokens, which must be presented as the *password*.

The 403 is the trap. GitHub answers a fine-grained PAT in the username position with
`403 — Write access to repository not granted`, **on a read operation**, rather than a
clean `401 Bad credentials`. That message reads like a repository-permissions problem
and sends you to the wrong place entirely. It is not a permissions problem.

**(b) Two GitHub credentials existed on this machine; only one had access.**

| Credential Manager entry | token | `GET /repos/appfactorystudioplus/checkpoint-auth` |
| --- | --- | --- |
| `git:https://x-access-token@github.com` | A | **200** — full access, private repo visible |
| `git:https://github.com` (`equippedcoding`) | B | **404** — no access |

Both authenticate successfully as `equippedcoding` against `/user`. Only token A can see
the repo. GCM's account-picker dialog was therefore asking a genuine question, and
choosing `x-access-token` was the correct answer — which is why it had been working
before, intermittently, whenever that option was picked.

**(c) `auth/composer.lock` was stale, independently of the credentials.** The lock dated
from 2026-07-21 and predated `afs/checkpoint-auth` ever being added to `composer.json`.
Even with perfect credentials, `composer install` would still have refused, because
`install` never resolves — it only replays a lock. This needed `composer update
afs/checkpoint-auth`, not `install`.

### Aggravating error made during diagnosis

Recorded because it cost the most time and is easy to repeat.

`git credential approve` was used to rewrite the credential the right way round, on the
assumption that it would **overwrite** the existing `equippedcoding` entry. It did not —
GCM left token B in place and wrote a separate entry. The `x-access-token` entry was
then deleted as a presumed duplicate, which destroyed **the only copy of token A**.

The observable effect was the repo endpoint returning `200` early in the session and
`404` twenty minutes later with the token apparently unchanged, which looks exactly like
a server-side revocation and was briefly diagnosed as one. It was not. Two different
tokens were being read at the two times. Comparing MD5 of the two values is what settled
it — `47811e…` (A, works) versus `0bfcb2…` (B, no access).

**Lesson, stated plainly:** `git credential approve` is not an upsert against whatever
entry you happen to be looking at. Never delete a credential entry before confirming the
replacement resolves — `git credential fill` with an explicit `username=` line reads one
specific entry without triggering the picker.

### Resolution

1. **`composer` made available as `composer`** — `composer.bat` already existed in
   `~/.config/herd-lite/bin/` (on PATH) and worked in cmd/PowerShell, which resolve
   `.bat` via `PATHEXT`. Git Bash does not apply `PATHEXT` to bare names, so `composer`
   was "command not found" there only. Added a POSIX shim at
   `C:\Users\mrmit\.config\herd-lite\bin\composer` forwarding to `composer.phar` via the
   bundled `php.exe`. Both shells now report Composer 2.8.12 / PHP 8.4.0.
2. **Token A restored** into `git:https://x-access-token@github.com`.
3. **Global git config returned to its original state.** A global pin
   (`credential.https://github.com.username` + `.provider generic`) had been added during
   diagnosis and was removed: it forced *every* repo onto token B and bypassed GCM's
   OAuth refresh for the normal `equippedcoding` account. Global config is back to only
   the two pre-existing Gitea `provider generic` entries.
4. **Account pinned locally in `packages/checkpoint-auth` instead**:
   ```
   credential.https://github.com.username = x-access-token
   credential.https://github.com.provider = generic
   ```
   That repo never shows the picker; every other repo behaves exactly as before.
5. **Composer given its own credential**, which is the correct mechanism for private
   GitHub packages and is independent of git credentials entirely:
   ```
   composer config --global --auth github-oauth.github.com <token A>
   ```
   Lands in `%APPDATA%\Composer\auth.json`. Composer resolves the package over the
   GitHub API zipball endpoint and never consults Credential Manager.
6. **`composer update afs/checkpoint-auth` run in `auth`** — locked and installed
   `v1.0.1`, plus the 121 other packages the stale lock had never installed.

### Verification

- `git ls-remote --tags origin` in `packages/checkpoint-auth`, no overrides and with
  `credential.interactive=false`: returns `v1.0.0` and `v1.0.1`, no prompt.
- `git fetch --tags origin`: exit 0, no prompt.
- All three original Credential Manager entries present and unchanged in count.
- `composer show afs/checkpoint-auth` → `v1.0.1`, dist resolved from
  `api.github.com/repos/appfactorystudioplus/checkpoint-auth/zipball/5453495…`.
- `composer install --dry-run` in `auth` → `Nothing to install, update or remove`.

### Residue — open follow-ups

1. **Token A was printed to the session transcript** during diagnosis and is on disk in
   this machine's session log. It never left the machine. Rotate it when convenient, and
   note that rotating means updating **two** places: the Credential Manager entry and
   `%APPDATA%\Composer\auth.json`.
2. **Token B (`equippedcoding`) has no access to the repo, and it is not established
   whether that is intentional.** If the primary GitHub account is supposed to be able to
   reach `appfactorystudioplus/checkpoint-auth`, granting it removes the need for a
   second credential and makes the picker dialog disappear permanently. That is the
   cleaner end state and it is not done.
3. **The fine-grained PAT's expiry date is unknown.** When it lapses this recurs, with
   the same misleading `403 — Write access to repository not granted`. Anyone hitting
   that message should read this entry before investigating repository permissions.
4. **`market` still has no `vendor/`.** Its `composer.json`/`composer.lock` are healthy —
   a dry run resolved 111 installs with no conflicts — it simply has never had
   `composer install` run. It does not depend on `afs/checkpoint-auth` and was never
   affected by the credential fault.
5. **The installed package is the tagged release, not the local working tree.**
   `packages/checkpoint-auth` currently has 2 modified files and 3 untracked ones —
   including `src/CheckpointServiceSignature.php` and
   `src/Http/Middleware/VerifyCheckpointServiceAuth.php`, which look like the S29
   service-auth work. **None of that is in `v1.0.1`, so none of it is in
   `auth/vendor/`.** If `auth` is expected to consume that code, it needs committing and
   tagging (`v1.0.2`), or a `path` repository during development. Worth settling
   deliberately rather than discovering at runtime.
6. **Other machines remain unprovisioned** — this is `TODOS.md` step 04 item 2, unchanged.
   Only this machine has a Composer credential for the private repo; any CI runner or
   second dev machine hits the same wall. A scoped deploy-key-style PAT is preferable to
   copying token A around.

### Diagnostic recipe, for next time

The three checks that separate these failure modes, in order:

1. `curl -H "Authorization: Bearer <token>" https://api.github.com/user` — **401** means
   the token is genuinely bad. **200** means the token is fine and the problem is how git
   is *presenting* it, or which token it is presenting.
2. `curl -H "Authorization: Bearer <token>" https://api.github.com/repos/<org>/<repo>` —
   **404** on a private repo means this token has no access (GitHub hides existence
   rather than returning 403). **200** means access is real.
3. `printf 'protocol=https\nhost=github.com\n\n' | git credential fill` — read which
   field the secret is actually in. A `github_pat_*` value appearing on the `username=`
   line is fault (a) above.

Do **not** test the git endpoint with plain `curl` against
`/info/refs?service=git-upload-pack` — GitHub returns 403 to any non-git User-Agent, so
that check produces a false negative that mimics the real fault. Use `git ls-remote` with
`GIT_ASKPASS` instead.

---

## I2 — every Brain admin API endpoint returned 500 — RESOLVED

**Date:** 2026-08-30
**Surfaces:** `w/www` (`PortalAdminAuthMiddleware`, three Brain controllers, `app/Models`),
`w/auth` (`app/Models`, `database/migrations`, the Checkpoint guardrail test)
**Related:** `handoff.md` §3 (www is the sole schema owner for the shared Brain database),
`members_brain/05-limit-overrides.md` §5.3

### Symptom

The admin portal's Brain view was dead. Seven parallel `GET`s — `/portal/api/v1/brain/`
`config`, `dns/servers`, `dns/zones`, `dns/subdomains`, `http/servers`, `http/clusters`,
`http/regions` — every one a 500, and `imp_brain.ts`'s `Promise.all` rejected on the first
of them.

Seven endpoints across three unrelated controllers failing identically is the tell: the
fault is upstream of all of them, in shared middleware, not in any handler.

### Cause — two independent missing classes

1. **`App\Models\BrainMembership` never existed.** `PortalAdminAuthMiddleware:49` resolved
   the caller's Brain membership through it on *every* `portal.admin.auth` request, so it
   fatalled before any handler ran. The real model is `App\Models\Membership`
   (`brain_memberships`) — used correctly by ~20 other call sites. `BrainAllocationController`
   and `BrainAllocOverrideController` carried the same phantom name.

2. **`App\Models\BrainAllocOverride` was in the wrong project.** The model and its migration
   were shipped into `w/auth`, which has no reader for either. auth's migration therefore
   created `laravel_brain_alloc_overrides` in auth's *own* database (`afs_idpdb_test`) while
   every consumer — `BrainController::getMember()` / `getMemberByEmail()`,
   `BrainEntitlementController`, `BrainAllocOverrideController` — is a www controller reading
   www's default connection, the shared Brain database. So `/brain/member` and
   `/brain/member/{ref}/overrides` 500'd on "Class not found" even after fault 1 was fixed.

The second fault was invisible while the first one existed: `portal.admin.auth` killed the
request before the missing model could be reached.

### Resolution

`BrainMembership` → `Membership` in the three www files. The model and migration moved from
`auth` to `www`, which owns the shared Brain schema; `php artisan migrate` created the table
where its readers actually look. Both guardrail tests still pass
(`MigrationOwnershipTest`, `CheckpointSchemaConnectionTest`), and the latter's docblock —
which asserted in prose that auth's own database holds `laravel_brain_alloc_overrides` —
was corrected.

Verified: all ten Brain endpoints return 200 against live data, and no new errors reach
`laravel.log`.

### Residue — cleared 2026-08-30

Four places had to agree, not two. The live databases were the obvious pair; the **exports
were the other**, and leaving them alone would have re-created the stray on the next
rebuild — the fix would have looked done and quietly undone itself at step 4 of §5.

1. `afs_idpdb_test` — the empty `laravel_brain_alloc_overrides` table dropped (verified 0
   rows first), and the `migrations` row for the removed migration deleted. auth is now 4
   files / 4 rows.
2. `testdb_appfactorystudio` — holds the table, created by www's migration. 200 tables.
3. `afs_idpdb_test.sql` — the trailing "tables added to match the live schema" block and
   the `(5, …)` migrations row removed, following the same edit-in-place rule as §4. Note
   the export's `AUTO_INCREMENT=4` on `migrations` had always contradicted its own id-5
   row; removing that row is what makes it coherent.
4. `testdb_appfactorystudio.sql` — the table added to its live-schema section, the same
   move `admin_users` / `admin_sessions` made in §4.

Both edited exports were import-tested into throwaway databases and dropped again: auth
restores to 9 tables with no overrides table and 4 migrations rows; www restores to 200
with the table present and its unique constraint intact. The restored structure matches
the migration-created one exactly — a `SHOW CREATE TABLE` diff shows only explicit
`CHARACTER SET` qualifiers, an artifact of the scratch database's default collation that
appears identically on tables nobody touched.

### What would have caught this

Nothing in the test suite touches middleware. A single smoke test that hits one
`portal.admin.auth` route with a valid token would have failed on fault 1 the day it
shipped; the same test over `/brain/member` would have caught fault 2.

---

## I3 — member provisioning had never worked; every signup failed — RESOLVED

**Date:** 2026-08-30
**Surfaces:** `w/auth` (`MemberInitController`, `parse_member_query_v4.json`), the live Parse
registry at `dashboard15-registry.appfactory.studio`, `brain_memberships.parse_alloc`
**Related:** `membership_change/07-signup-flow.md` §7.7.1 — S21, S22, S23, S18

### Symptom

None. That is the point of this entry.

`POST /checkpoint/member/init` returned `MEMBER_INIT_FAILED`, and nothing anywhere reported
it. `handoff.md` §7 records that nothing is published, so no signup had run against the
current code and the failure had no audience. The plan had already reasoned its way to the
suspicion — S23 — and written the one-request check that would settle it, but nobody had run
the request.

### Cause — two faults, the second hidden behind the first

**1. Parse rejects `_`-prefixed field names.** `init()` copies the template key-for-key, and
the v4 template's top level begins `_schema`, `_doc`, `_fields` — documentation carried
inside the data file. Parse validates field names against `/^[A-Za-z][A-Za-z0-9_]*$/`:

```
{"code":105,"error":"Invalid field name: _schema."}
```

The save is all-or-nothing, so **every** call failed, and therefore every signup failed at
`createParseAllocation()`. The template file has only two commits and both carry these keys,
so the v4 template never once created an object — broken from the day it landed, 2026-08-27.

**2. Every empty array was sent as an object.** With the `_` keys skipped, `init()` still
failed:

```
schema mismatch for MemberRegistry.domains; expected Array but got Object
```

The sequential-vs-associative branch was
`array_keys($value) === range(0, count($value) - 1)`. For an **empty** array that evaluates
`[] === range(0, -1)` — and PHP's `range` counts *downward* when start exceeds end, returning
`[0, -1]`, not `[]`. So the test failed for exactly the empty case, every empty container took
`setAssociativeArray`, and Parse received `{}` where its schema expects `[]`.

**The two are coupled, and that is the lesson.** v3 never hit fault 2 because its containers
held the `--Example_` placeholder rows and so were never empty. **The placeholder was
accidentally load-bearing.** Removing it — S21, filed as a correctness fix in its own right —
is precisely what would have exposed fault 2 in production. S21 shipped alone would have
broken provisioning; S23 fixed alone would have left it broken. Neither is safe without the
other.

### Resolution

Two changes in `MemberInitController::init()`, four functional lines: `continue` on
`str_starts_with($key, '_')`, and `array_is_list($value)` for the branch. Both carry comments
recording the evidence and the date, because the plan had this marked "Untested".

The registry was also rebuilt. It held **15 `MemberRegistry` objects for 3 members** — 12
orphans, clustered five on 05-20 and five on 05-21, which is S18's fingerprint: the init
endpoint is unauthenticated and holds the master key, so anything can mint registry objects.
Twelve carried placeholders. All 15 were deleted (backed up first), 3 were recreated in v4
shape with `member_reference_num` set, and `brain_memberships.parse_alloc` was relinked.

Verified end to end: `POST /checkpoint/member/init` now returns
`{"success":true,"data":{"parseObjectId":"…"}}` and produces 14 resources, five real empty
arrays, no underscore keys, `periodStart` at the first of the month. The test object was
deleted; the class holds exactly 3.

### Residue

1. ~~**S22 is still open and is not a two-line fix.**~~ **CLOSED 2026-08-30.** `init()` did
   ignore its `Request` entirely, and the fix was indeed the caller contract, not the
   controller: `createParseAllocation()` in the browser now sends `member_reference_num`,
   the `www` proxy forwards it (it used to POST a literal `'{}'`) and rejects a request
   without one, and `init()` requires it — 422 `MEMBER_REF_REQUIRED` rather than an anchor
   no later read can find. `init()` is also find-or-create on that field now, so a retried
   signup cannot produce a second anchor.

   While wiring it, `getOrGenerateMemberReferenceNum()` turned out to store `"m1x" + ref`
   and **return the bare `ref`**. The first call in a session handed out a 10-character id
   while every later call — and everything read back from sessionStorage — used the
   13-character one. The two identify the same member and are not equal, which is the worst
   possible property for a value that keys the Parse ledger rows,
   `brain_memberships.reference_num` and the processor's `user_id`. Fixed to return what it
   stores.
2. **S18 is untouched.** `/checkpoint/member/init` remains unauthenticated with the master
   key. The 12 orphans are what that looks like in practice, and deleting them does not stop
   more appearing.

### What would have caught this

The plan did — it identified the fault, wrote the exact curl, and said "run this before
planning around anything else in this section." The check simply went unrun for three days.
A single test that POSTs to `member/init` against a live registry would have failed from the
first commit.

---

## I4 — PHP had no CA bundle; every outbound HTTPS call from PHP failed — RESOLVED

**Date:** 2026-08-30
**Surfaces:** `C:\Users\mrmit\.config\herd-lite\bin\php.ini` (the loaded ini for the CLI),
every server-side Parse call in `auth` and `www`
**Related:** I3 — the same machine, and the reason "verify it against the live registry"
kept coming back inconclusive.

### Symptom

Any PHP code path that reached Parse returned:

```
{"success":false,"error":{"code":"MEMBER_INIT_FAILED",
 "message":"SSL peer certificate or SSH remote key was not OK"}}
```

The same request issued with `curl` from Git Bash succeeded, and the browser reached the
host fine. So the host, the certificate and the network were all provably good, which is
exactly what made this look like a code fault in the Parse SDK.

### Cause

`curl.cainfo` and `openssl.cafile` were both empty. PHP on Windows ships no trust store of
its own and does not fall back to the Windows certificate store, so *every* `https://`
request from PHP failed — Parse, and anything else. `curl` and the browser were fine
because each carries its own bundle.

Nothing about the message says "this process has no CA list at all", and because the
symptom appears per-call it reads as a problem with the thing being called.

### Resolution

Pointed both ini settings at a bundle built from the one Laragon ships, with Laragon's own
`laragon.crt` appended so the local `.test` vhosts verify too (see I5):

```ini
curl.cainfo    = "C:\laragon\etc\ssl\cacert-with-laragon.pem"
openssl.cafile = "C:\laragon\etc\ssl\cacert-with-laragon.pem"
```

Rebuild it with `cat cacert.pem laragon.crt > cacert-with-laragon.pem` if either input
changes.

The previous php.ini is at `php.ini.bak-alloc-v2`. Verified with a direct
`curl_exec` against the registry health endpoint (`{"status":"ok"}`) and then end to end
through `MemberInitController`.

### Residue

The bundle is dated **December 2024**. It works today; it will start rejecting hosts as
roots rotate. Any machine that needs to run PHP against Parse needs the same two lines —
this is not committed anywhere, because it is machine configuration, so it will bite the
next environment too.

---

## I5 — no app could resolve the registry; two different causes, one symptom — RESOLVED

**Date:** 2026-08-30
**Surfaces:** every `.env` in `w/*`, `coordinator/config/afs_connect.php`, the loaded
`php.ini`
**Related:** `membership_change/06-registry-distribution.md` — 08 removed the bundled
copies, so this URL is now the single point of resolution for every consumer, PHP and JS.
That is what turned a config error into an estate-wide outage.

### Symptom

Coordinator's `ParseAllocationGateway` denied every allocation, logging
*"registry unreachable and no cached copy — denying"*. Nine of thirteen
`ParseAllocationGatewayTest` cases failed, against a live network call rather than against
anything they assert. Two of them asserted `Http::assertNothingSent()`, which that call
broke outright.

### Cause — two, and the first one hid the second

**1. Seven apps pointed at the wrong host.** `auth`, `coordinator`, `dream_graph`, `flow`,
`market`, `members`, `notes` and `pdf_editor` all had
`CHECKPOINT_BASE_URL="https://auth15.appfactory.studio"` in their local `.env`. v4 is not
deployed there — `checkpoint_registry_v3.json` returns 200, `..._v4.json` returns 404. Only
`www` pointed at the local `https://auth.test`, which serves the committed v4 file
correctly (verified byte-identical, 34 resources).

Alongside it, `coordinator/config/afs_connect.php` read `env('CHECKPOINT_URL')` for the
host. **No `.env` in the estate defines `CHECKPOINT_URL`** — they all define
`CHECKPOINT_BASE_URL` — so the value always fell through to the hardcoded default.
Silently, because that default happened to be the same wrong host.

**2. PHP could not verify the local certificate.** Fixing (1) alone would not have helped:
`https://auth.test` is served with Laragon's self-signed leaf (`CN=laragon`, `CA:FALSE`,
SAN covering every `.test` host), which the browser trusts because Laragon installs it in
the Windows store. PHP does not read that store — see I4 — so every server-side call to
`auth.test` would have failed on certificate verification instead of on a 404. Same
symptom, different cause, one layer down.

### Resolution

`CHECKPOINT_BASE_URL`, `CHECKPOINT_SSO_DOMAIN`, `CHECKPOINT_URL` and market's `AUTH_URL`
now name `https://auth.test` in each app's root `.env` **and** its `_env/local/.env`.
`_env/dev/` and `_env/prod/` are untouched — they point at `auth.appfactory.studio` and are
not this machine's business. Timestamped `.bak-` copies sit beside each file. The
`VITE_CHECKPOINT_*` mirrors needed no edit: they are already `"${CHECKPOINT_BASE_URL}"`.

`config/afs_connect.php` now reads `CHECKPOINT_URL`, then `CHECKPOINT_BASE_URL`, then the
default.

For (2), `curl.cainfo`/`openssl.cafile` point at
`C:\laragon\etc\ssl\cacert-with-laragon.pem` — the Mozilla bundle with `laragon.crt`
appended. A self-signed leaf works as its own trust anchor, so this trusts the local vhosts
without weakening verification for anything else.

Verified: coordinator resolves
`https://auth.test/checkpoint/checkpoint_registry_v4.json` and answers
`check('secondary_users')` with `limit 3, used 0` read from the live `MemberResource` row —
registry over HTTPS and rows over REST, in one call path. Public TLS still verifies
(`dashboard15-registry` health returns `{"status":"ok"}`).

### Residue

1. **`curl` on this machine cannot reach `https://auth.test` at all**, and its failure is
   not informative: `SEC_E_ILLEGAL_MESSAGE`, which reads like a broken TLS listener. This
   build is `curl 8.14.1 ... Schannel`, and the Schannel backend **ignores `--cacert`** —
   there is no way to hand it the Laragon cert on the command line. `-k` does not help
   either. This cost real time: `http://auth.test:443/` returning **400** was read as
   "port 443 is speaking plain HTTP, so SSL is off", when 400 is precisely what `mod_ssl`
   replies to a plain-HTTP request on an HTTPS port. SSL was on the whole time.
   **Verify local HTTPS with PHP or a browser, not with curl.**
2. ~~duplicate keys in `members/.env` and `www/.env`~~ **FIXED.** `members` carried the
   entire Checkpoint block **twice** — eight keys — and the two copies did not agree:
   the earlier one left `CHECKPOINT_APP_ID` and `CHECKPOINT_SERVER_URL` **empty**, and its
   `VITE_*` mirrors expanded `"${CHECKPOINT_APP_ID}"` against that empty value. Only
   dotenv's last-one-wins ordering made the app work. The dead block is removed.

   `www` declared `VITE_CHECKPOINT_BASE_URL` twice with different values:
   `https://www.test/portal` and `"${CHECKPOINT_BASE_URL}"`. The comment above the first
   says "the domain where the Checkpoint server is hosted", and both consumers agree that
   means the auth host — `globalAuth.ts:27` passes it to `CheckpointClient` as `baseUrl`,
   and `brain_membership.ts` appends `/checkpoint/checkpoint_registry_v4.json` to it. www's
   own portal is neither. The stray is removed; the correct one already won.

   Both apps' `_env/local/.env` got the same treatment. `.bak-dupes-` copies sit beside each.
   A scan of all eighteen root/local `.env` files now reports **no duplicate keys** — except
   the one in residue 5 below.
3. v4 remains undeployed on `auth15.appfactory.studio` and `auth.appfactory.studio`, which
   is what `_env/dev` and `_env/prod` point at. Not a local problem, but it is the same
   404 waiting on the next deploy.
4. **`auth/.env` declares `SENDGRID_API_KEY` twice** — a real key at line 144 and the
   placeholder `your-sendgrid-api-key` at line 241. dotenv takes the last, so **auth is
   currently configured with the placeholder** and SendGrid cannot be authenticating. Found
   by the same duplicate-key scan; left alone because which key is current — and whether
   the one sitting in the file needs rotating now that it has been read — is not a
   registry decision.

## I6 — the Brain allocation editor wrote to a field nothing reads — RESOLVED

**Date:** 2026-08-30
**Surfaces:** `www/app/Http/Controllers/Brain/BrainAllocationController.php`,
`www/resources/js/portal/admin/controllers/brain/brain_membership.ts`,
`www/config/checkpoint.php`, `www/app/Models/BrainAllocAudit.php`
**Related:** `allocation.md` G13 — this is what G13 looks like from the operator's chair.

### Symptom

An operator corrects a domain's email-account usage in the Brain allocations form. The save
returns success, the JSON view shows the new number, the audit row records it. The member is
still blocked.

### Cause — four independent faults, each sufficient on its own

1. **The controller could not load.** It imported `Parse\ParseClient`; `parse/php-sdk` is
   not in `www`'s `composer.json`. It also read `config('checkpoint.parse')`, a key
   `www/config/checkpoint.php` has never declared — so the constructor threw
   "Parse Server credentials are not configured" before any request reached an action.
   The credentials were present all along, under `config('services.parse.*')`.

2. **The paired write never matched.** §7.5 required each `used` write to be mirrored into
   the flat `ac_*` counter, because that mirror was what `readUsage()` actually preferred.
   `deriveAcFieldName()` built the name by replacing dots and dashes with underscores.
   The real `scopeToken()` in `alloc_v2.ts` is a DJB2 hash with a base-36 suffix. The two
   NEVER produced the same string, so every paired write created a brand-new field nothing
   has ever read, and left the real counter untouched.

3. **Paths were array indices.** Changes were addressed as
   `/domains/0/resources/<id>/used`. An index is not an identity: another admin adding or
   removing a domain renumbers every path after it, so a save prepared against one ordering
   could land the right number on the wrong domain.

4. **`forced` was not fillable.** `BrainAllocAudit` omitted it from `$fillable` while the
   controller passed it, so mass assignment dropped it and the column kept its default.
   Every *forced* save — the ones that bypass the conflict check, and therefore the ones an
   audit trail exists for — was recorded as an ordinary save.

### Resolution

The row model removes 2 and 3 rather than fixing them: `used` is a top-level field on one
`MemberResource` row, addressed by `ledger_key`, so there is nothing to mirror and nothing
to renumber. The controller now talks REST via `config('services.parse.*')` (1), and
`forced` is fillable and cast (4). Concurrency moved from one document-wide
`base_updated_at` to the target row's own `updated_at`, so a member consuming storage in
their own browser no longer conflicts a pending admin edit to their email quota.

Verified against the live registry: read 34 rows; a valid save wrote 0 → 7 and returned 200;
a stale timestamp returned 409 `stale`; an attempt to write `ledger_key` returned 422; a
negative value returned 409 `invalid_value`; a forced save recorded `forced = 1`. Test data
removed afterwards.

### What would have caught this

A single test that POSTs one change and then reads the value back through `alloc_v2`'s own
`get()`. Every existing check verified that the save was *accepted*, which it always was.

---

## I7 — `MemberRegistry` removed from the system — RESOLVED

**Date:** 2026-08-31
**Surfaces:** Parse (class + objects), `brain_memberships.parse_alloc`,
`laravel_brain_alloc_audit.parse_object_id`, nine copies of `alloc_v2.ts`, eight
`registry.ts` factories, the signup flow in `www/resources/js/afs`, five PHP consumers,
two migrations, three tests
**Related:** I5/I6 — this closes the class of failure both of those were instances of.

### What it was

One Parse object per member, holding two fields: its own `objectId` (stored as
`brain_memberships.parse_alloc`) and `member_reference_num`. Every `MemberResource` ledger
row already carries `member_reference_num`, and `registry_id` on a row was a plain string
rather than a Parse pointer — so Parse enforced no relationship and nothing joined through
it.

### Why it went

It was not needed, and the evidence was already on the floor. `m1xTHpRmdtH2Y` spent an
entire session with a `parse_alloc` naming a deleted anchor; its rows were correct and
readable throughout, and the only thing that noticed was the client insisting on fetching
the anchor first. That insistence is what made I5's and I6's symptoms fatal rather than
cosmetic.

What it cost while it existed: a round trip on every cold read, a MySQL column to keep in
step with Parse, an orphaned object per failed signup (`membership_change/09` **S32**), and
one genuine failure mode — a stale `parse_alloc` throwing `Object not found` for every
quota read.

### What changed

- **Parse** — `registry_id` deleted from all 62 rows; 3 anchor objects deleted; the
  `MemberRegistry` class dropped from the schema.
- **MySQL** — `brain_memberships.parse_alloc` dropped, and
  `laravel_brain_alloc_audit.parse_object_id` with it (it recorded which anchor an edit
  touched). Both removed from `2026_05_15_000001_create_brain_tables.php`.
- **`alloc_v2.ts` ×9** — `parseObjectId` replaced by a REQUIRED `memberReferenceNum`;
  `fetchAnchor()`, `MEMBER_REGISTRY_CLASS` and the `registry_id` write removed;
  `memberRef()` is no longer async, because it no longer performs I/O.
- **`/checkpoint/alloc/v2`** — identity input is now `member_reference_num`, not
  `parseObjectId`.
- **Signup** — `createParseAllocation()` became `createMemberAllocation()`, returning
  nothing. `registerMembership()` lost its `allocId` parameter.
- **A whole pre-v2 layer went with it.** All eight `registry.ts` files carried a
  `MemberRegistryManager` with `GetMemberRegistry` / `RegistryUpdate` / `RegistryCheck` /
  `SaveRegistry` and a `createParseAllocation` writing `name: "Test Allocation"`,
  `domain_processors: {default: 0}` and `sync_storage` — an allocation scheme older than
  the nested v3 document. **None of it had a caller.** Only `displayDialog()` did, and it
  draws a modal and touches no data; it survives as a plain function.

### Found on the way, and worth knowing

**The Brain admin create form provisioned against the wrong member.** It called
`createParseAllocation()` with no argument, and the default falls back to
`getOrGenerateMemberReferenceNum()` — which reads *this browser's* sessionStorage. On the
signup page that is the right member; in the admin form it is the **admin's** session, so
the 14 provisioned rows were keyed to whoever was logged in while `referenceNum` went to
MySQL. Silent, and introduced by the fix that added the parameter. Now passed explicitly.

**The `/checkpoint/alloc/v2` HTTP API has no callers.** The routes, the www proxy and
auth's controller all still exist, but every TypeScript client bypassed them when
`managerUrl` was removed in favour of talking to Parse directly. It was migrated rather
than deleted, because deleting a published API surface is a separate decision.

### Verification

Provisioning creates 14 rows and is idempotent (`rows_created: 0` on re-call); a missing
reference returns 422. Read/consume/release through the v2 API by reference: `0 → 2 → 0`,
with release clamped. The Brain admin read returns 34 rows with no `registry_id`, and an
audited save still writes its audit row. Test data removed afterwards.

auth 21 pass, coordinator 116 pass, www TypeScript clean, all nine `alloc_v2.ts` copies
still byte-identical. Two pre-existing failures are unchanged and unrelated: `members`
`AfsConnectSettingsSourceKeysTest` (401, verified identical with `parse_alloc` restored)
and www `ExampleTest` (500 on `/`).

---

## I8 — a user with no member could log in, and it silently disabled `require_2fa` — RESOLVED

**Date:** 2026-08-31
**Surfaces:** `w/auth` — `AuthenticationService`, `SecondaryUserService`, `MemberLookupService`;
`w/auth/public/checkpoint/parse/cloud/functions.ts`

### The rule this enforces

The member is the account. Tier, allocation, entitlement, billing and every `MemberResource`
row in Parse are keyed on `reference_num`. A session with no member is authenticated as
somebody the rest of the estate cannot describe — so **no member, no session**.

### What was actually there

Both member checks were gated on the environment:

```php
if ($lookup->isProduction() && $record === null) {      // AuthenticationService
if ($this->memberLookup->isProduction() && $record === null) {   // SecondaryUserService
```

So on every non-production deployment a memberless identity authenticated normally and every
consumer downstream received `member => null`, each one left to invent its own answer to
"what is this user allowed to do".

**The symptom that pointed somewhere else.** In `SecondaryUserService::buildLoginResponse()`
the missing member did not merely pass through — it changed policy:

```php
$memberReferenceNum = (string) ($record['reference_num'] ?? '');
$requires2fa = $memberReferenceNum !== ''
    && $this->resolveRequire2faPolicy($memberReferenceNum, $userId);
```

With `$record` null the reference is `''`, the `&&` short-circuits, and `$requires2fa` is
**false**. A `require_2fa` policy was therefore switched off for every secondary user under
that super user, by a missing row in a different table. Nothing logged it and nothing failed;
the login simply succeeded without the second factor. Anyone debugging that would have started
at the 2FA policy table, which was correct the whole time.

**Three of the four member reads had no check at all.** Only the two shown above were gated;
`SecondaryUserService::validateToken()` and the secondary 2FA completion both read the member
and returned whatever they got. Fixing the login alone would have closed one door out of four
— and `validateToken()` is the one every downstream app actually calls on each request.

### Resolution

Both gates are unconditional, and the two unguarded reads now have one, so a missing member is
refused on all of: `login`, `loginWithUsername`, `ssoExchangeToken`, `completeTwoFactorAuth`,
`verifyToken`, secondary `login`, secondary `validateToken`, secondary 2FA completion.

**`attachMember()`'s `catch (\Throwable)` also had to change.** It logged and continued with
`member => null`, which under the new rule was the remaining way to obtain a memberless
session: take the member database offline and every login succeeds. A lookup that *threw* is
not a user without a member, it is not knowing whether they have one — and on the
authentication edge both must deny. Denying a login is recoverable; admitting an
undescribable session is not. It now throws `AUTH_MEMBER_LOOKUP_FAILED`.

**This is the opposite of the correct posture on the retirement edge**, where a missing
membership row must cause a holder to be *skipped* rather than treated as plan 0. Same
missing row, opposite safe direction. The two must never share a helper.

**`MemberLookupService::isProduction()` was deleted, not left unused.** Its only two callers
were the gates above. A method whose sole purpose was to weaken a security check is a trap if
it stays reachable, so it is gone and a comment stands where it was.
`CheckpointConfig::isProduction()` remains for the things that legitimately differ by
environment — SSO domains, base URLs — none of which are authorisation decisions.

### Found on the way

**User 2 had no member row.** `shekina@cradle2careertx.org` existed in `ext_member_users` with
nothing in `brain_memberships`, which is how the gap surfaced. Given
`m1xGmA7KagS0D` and provisioned through `POST /checkpoint/member/init` — 14 rows created. All
four users now have a member; there are no `brain_memberships` rows with a null `user_id`.

**Signup is unaffected, and it is worth knowing why.** Registration calls
`checkpoint.auth.register()` client-side and never `login`; `register()` does not call
`attachMember()`. The `brain_memberships` row is written afterwards by
`MembershipController::register`, so the member always exists before the first login.

**But the Checkpoint-failure recovery path now matters more.** When the client-side Checkpoint
call fails, the membership row is still created — with `user_id` null — and the failure is
logged to `MembershipCheckpointError` for admin review. Backfilling `user_id` when that user
is finally created is no longer cosmetic: until it happens, that member cannot log in
anywhere. Previously they could, everywhere except production.

**The cloud function's own check was right for the wrong reason.** `resolveCaller()` refuses a
token that resolves to no member, with a comment explaining that Checkpoint only hard-fails in
production. That is no longer true, so the check is now unreachable in practice — it stays,
because it is a different assertion: Checkpoint's answer *arriving intact* rather than
Checkpoint having made the decision. An empty reference reaching `ledgerKey()` would key every
member's usage onto one shared row. Comment corrected.

### Verification

Live against `http://auth.test`, both paths, positive and negative:

| Case | Result |
|---|---|
| User 2 `login` — member present | ✅ `member.reference_num: m1xGmA7KagS0D` |
| User 2 `token/validate` | ✅ `user_id: 2`, same reference |
| Memberless user, correct password, `login` | ✅ **401 `AUTH_MEMBER_NOT_FOUND`** — past credentials, refused on the member |
| Valid unexpired token, member row deleted underneath it | ✅ `valid: false` |
| Secondary `login` — super user's member present | ✅ tokens issued |
| Secondary `token/validate` | ✅ `member.reference_num: m1xTHpRmdtH2Y` |
| Same secondary token, super user's member removed | ✅ `valid: false` |
| Fresh secondary `login`, member removed | ✅ **401 `AUTH_MEMBER_NOT_FOUND`** |

The memberless negative was produced by minting a token while the member existed and deleting
the row underneath the still-valid JWT — which is the case
[`alloc-v2-parse-direct/complete/README.md`](alloc-v2-parse-direct/complete/README.md) had
listed as unverified.

auth 21 tests pass. All test data removed afterwards: the probe user deleted, and both
borrowed password hashes restored and confirmed byte-identical to the originals.

---

## I9 — local clean run — RESOLVED (procedure)

**Date:** 2026-09-05
**Surfaces:** `C:\projects\afs-eco\w\www`, `w/auth`, `w/coordinator`, `w/market`,
`C:\projects\afs-eco\databases\data-only\new`, Laragon MySQL
**Related:** `handoff.md` §5 (superseded by this entry for local work),
`www/builder/v2/complete/00_extras.md` E37–E39 (demo seeder, filtered dumps,
`SeedRunner`), AGENTS.md §5 (migration ownership and rebuild order)

### What it is

The **clean run** rebuilds the local databases from the current migration files,
reloads the archived row data, and runs the seeders. Use it after schema changes,
when test data has drifted, or when an import error needs a known-good baseline.

This entry is the authoritative recipe for **`local`** mode
(`plans/DEVELOPMENT_MODE.md`). It does not apply in `remote` mode — there is no
database to reach from a code-only checkout.

### Databases and who owns the schema

| Database | Migrations run from | Data-only dump |
|---|---|---|
| `testdb_appfactorystudio` | **`www` first**, then **`auth`** (checkpoint tables land on the shared connection) | `testdb_appfactorystudio.data-only.sql` |
| `afs_idpdb_test` | **`auth`** (private Laravel tables **and the IdP admin panel's `admin_users` / `admin_sessions`** — `plans/auth_idp_tables`, 2026-10-10) | `afs_idpdb_test.data-only.sql` (often empty; the lab file is deliberately empty and `db:seed` creates the default `admin`) |
| `testdb_afs_apps_db` | **`coordinator` (fresh), then `dream_graph` + `notes` + `flow` + `pdf_editor` (migrate)** — see the correction in step 1 | `testdb_afs_apps_db.data-only.sql` |
| `testdb_afsdb_market` | **`market`** | `testdb_afsdb_market.data-only.sql` |

> **Corrected 2026-09-05.** This table previously said `testdb_afs_apps_db` migrations
> run "from `coordinator` (five creative apps read this; they ship no migrations)."
> **That is wrong.** `dream_graph` (30 migrations), `notes` (11), `flow` (4) and
> `pdf_editor` (6) each own `graph_*` / `notes_*` / `flow_*` / `pefeditor_*` tables in
> that database. `coordinator migrate:fresh` drops the whole database, so those tables
> vanish and never come back unless the four apps also migrate. A clean run that skips
> them fails the `testdb_afs_apps_db` import on the first missing table
> (`ERROR 1146 … Table 'testdb_afs_apps_db.graph_projects' doesn't exist`).

Always use the files in **`C:\projects\afs-eco\databases\data-only\new\`**. The
parent `data-only\` directory holds older exports aligned to a previous schema;
importing them against the current migrations produces hundreds of errors that
look like data problems but are column/table mismatches.

### The procedure — five steps, fixed order

**Prerequisites:** Laragon (or local MySQL) is up. Root, no password, TCP
`127.0.0.1:3306`. Run every `php artisan` command from the **repo root**
(`w/www`, `w/auth`, …), never from `w/`.

#### 1. Fresh migrations — sequential, never parallel

```powershell
cd C:\projects\afs-eco\w\www
php artisan migrate:fresh --force

cd C:\projects\afs-eco\w\auth
php artisan migrate:fresh --force

# testdb_afs_apps_db is shared by FIVE Laravel apps. migrate:fresh from ONE of
# them, then `migrate` (never `migrate:fresh`) from the other four — a second
# fresh would drop the first app's tables again.
cd C:\projects\afs-eco\w\coordinator
php artisan migrate:fresh --force

cd C:\projects\afs-eco\w\dream_graph
php artisan migrate --force
cd C:\projects\afs-eco\w\notes
php artisan migrate --force
cd C:\projects\afs-eco\w\flow
php artisan migrate --force
cd C:\projects\afs-eco\w\pdf_editor
php artisan migrate --force

cd C:\projects\afs-eco\w\market
php artisan migrate:fresh --force
```

**`www` before `auth` is mandatory.** Both touch `testdb_appfactorystudio`.
Running them in parallel has been observed to drop checkpoint tables auth just
created (`checkpoint_feature_flags`, …) while `www` is still in its drop phase.
The shared import then fails on missing tables even though each migration
reported success.

**The four creative apps after `coordinator`, in `migrate` not `migrate:fresh`.**
`coordinator migrate:fresh` drops every table in `testdb_afs_apps_db`, including
the `graph_*` / `notes_*` / `flow_*` / `pefeditor_*` tables those apps own. They
must migrate to recreate their tables before the import; a *second* `migrate:fresh`
would wipe `coordinator`'s `pm_*` / `afsconnect_*` tables straight back out. This is
the same "one schema, one owner" hazard as `AGENTS.md` §5, one database lower.

`coordinator` and `market` must also be refreshed (or truncated — see residue
below) before their data-only imports. Only refreshing `www`/`auth` leaves rows
in the apps and market databases; with plain `INSERT INTO` that surfaces as
`ERROR 1062 Duplicate entry '1' for key '…PRIMARY'`.

#### 2. Import row data

Use the Laragon MySQL client and **cmd** redirection (PowerShell `<` is not
reliable for stdin redirect):

```cmd
C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe --user=root --host=127.0.0.1 --database=testdb_appfactorystudio < C:\projects\afs-eco\databases\data-only\new\testdb_appfactorystudio.data-only.sql

C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe --user=root --host=127.0.0.1 --database=afs_idpdb_test < C:\projects\afs-eco\databases\data-only\new\afs_idpdb_test.data-only.sql

C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe --user=root --host=127.0.0.1 --database=testdb_afs_apps_db < C:\projects\afs-eco\databases\data-only\new\testdb_afs_apps_db.data-only.sql

C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe --user=root --host=127.0.0.1 --database=testdb_afsdb_market < C:\projects\afs-eco\databases\data-only\new\testdb_afsdb_market.data-only.sql
```

Each file is **data only** — no `CREATE TABLE`. It assumes step 1 already
created an empty schema. The dumps use plain **`INSERT INTO`**, not
`INSERT IGNORE INTO`. That is deliberate: the import is only run against empty
tables, and a duplicate key or constraint violation must **stop** the import
with a visible error rather than silently skipping rows. That behaviour exposed
a missing `brain_memberships` block that `INSERT IGNORE` had been hiding.

The files set `FOREIGN_KEY_CHECKS = 0` because tables are emitted in name order,
not dependency order.

#### 3. Seeders — auth, then www

```powershell
cd C:\projects\afs-eco\w\auth
php artisan db:seed --force

cd C:\projects\afs-eco\w\www
php artisan db:seed --force
```

Optional, for a populated market dev catalogue:

```powershell
cd C:\projects\afs-eco\w\market
php artisan db:seed --force
```

**Do not** run `php artisan db:seed --class=SeedRunner` as a separate top-level
command unless you mean to. In `www`, `DatabaseSeeder` already calls
`SeedRunner` first; a standalone `SeedRunner` that also called `DatabaseSeeder`
recursively caused an infinite loop (fixed 2026-09-04).

#### 4. Spot checks (optional but fast)

```powershell
# Shared DB — membership + portal site properties
& "C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe" -uroot -h127.0.0.1 -N -B -e "SELECT COUNT(*) FROM testdb_appfactorystudio.brain_memberships; SELECT COUNT(*) FROM testdb_appfactorystudio.subdomain_properties;"

# Demo ecommerce catalogue (dev seeder)
cd C:\projects\afs-eco\w\www
php artisan tinker --execute="echo json_encode(['store'=>\App\Models\Externals\EcommerceStore::where('reference_num','demo-store-test')->first(['reference_num','website_reference_num']),'products'=>\App\Models\Externals\EcommerceProduct::where('store_reference_num','demo-store-test')->count()]);"
```

Expect **3** `brain_memberships` rows, **28** `subdomain_properties` rows, and
a demo store stamped with the Brain portal membership ref (`appfactorystudio`).

#### 5. Assets (only when the UI looks wrong)

Admin portal and storefront pages load compiled JS/CSS from `public/build/`.
If translations or admin views 404 stale bundles, run `npm run build` in `www`
— **never** `npm run dev` / Vite HMR (AGENTS.md §1). Delete a stale
`www/public/hot` if present.

### What the seeders add on top of the dump

| Seeder | Repo | When it runs | What it creates |
|---|---|---|---|
| `SeedRunner` | `www`, `auth`, … | Always (all envs) | `www`: super-admin and admin users from `.env` (`SUPER_ADMIN_*`). **`auth`**: `AdminUserSeeder` creates the hard-coded IdP account `admin` / `admin123` (`SUPER_ADMIN_*` do not exist in auth); since 2026-10-10 that account is made to choose a new password at its first sign-in (`plans/auth_idp_tables`) |
| `InitSeeder` | `www` | Dev only | Default mail provider, chat config, guarded bootstrap rows |
| `DemoStoreSeeder` | `www` | Dev only | Store `demo-store-test`, direct payment `demo-direct-payment`, 5 products + variants + DP items; stamps `website_reference_num` / `website_domain` from `subdomain_properties` + `brain_memberships` so the store appears in the admin ecommerce dashboard |
| Module seeders | `www` | Dev only, empty tables | Sponsorships, notifications, analytics events |
| `DatabaseSeeder` chain | `market` | Dev only | Categories, listings, orders, reviews (dependency order) |

Production: `DatabaseSeeder` runs `SeedRunner` and returns before dev seeders.

The dump restores Brain control-plane rows, builder published pages, processors,
direct payments created through the UI, and member data. The seeders restore
what a fresh schema never had or what `migrate:fresh` just wiped — admin logins,
demo sellable catalogue, and optional fake module data.

### Traps that cost time (read before improvising)

| Symptom | Actual cause |
|---|---|
| Shared import fails on missing `checkpoint_*` table | `www` and `auth` `migrate:fresh` ran **in parallel** |
| Apps/market import: `Duplicate entry '1' for key 'PRIMARY'` | Those databases were not emptied; only `www`/`auth` were refreshed |
| `brain_memberships` empty after “successful” import | Dump had a section header but **no INSERT** (filter dropped it); not an `IGNORE` issue |
| Admin ecommerce dashboard empty despite demo store in DB | `DemoStoreSeeder` left `website_reference_num` NULL (or stamped `superadmin` instead of the portal membership); admin routes filter on `EcommerceAuthorizesSites` |
| Seeder hangs / runs forever | Recursive `DatabaseSeeder` → `SeedRunner` → `DatabaseSeeder` loop (fixed) |
| Import “succeeds” but rows missing | Old dump used `INSERT IGNORE INTO`, which skipped duplicates and FK violations without stopping |
| `testdb_afs_apps_db` import: `ERROR 1146 … 'graph_projects' doesn't exist` | The four creative apps were not migrated after `coordinator migrate:fresh` (step 1, corrected) |
| `testdb_afs_apps_db` import: `ERROR 1146 … 'engine_admins' / 'notes' doesn't exist` | Dump drift — see below. Fixed 2026-09-05 |

### Regenerating the `data-only\new` files

When migrations rename columns or drop tables, the archived dumps drift. The
filter pass that produced `data-only\new` is described in
`www/builder/v2/complete/00_extras.md` **E38**: read the current schema, drop
INSERT blocks for missing tables/columns, write new files beside the originals
without editing the archive exports in place.

The test for "does my migration change need a regen" is narrow: **only when a
table that has an INSERT block in `data-only\new` loses a column or is dropped.**
Adding columns is safe — every INSERT names its columns, so MySQL fills the new
ones with their defaults. Renaming a column on a table that is *not* in the dump
(e.g. `laravel_builder_routes`, which has no INSERT block) needs nothing.

**Fixed 2026-09-05 — `testdb_afs_apps_db.data-only.sql`:** removed six orphan
INSERT blocks with no table in any `w/` repo —
`engine_admins`, `engine_releases`, `engine_release_downloads`,
`engine_release_download_targets`, `engine_users` (these belong to
`C:\projects\afs-eco\JoystickEngine_v2`, which is not part of this workspace and
not part of the clean run), and `notes` (singular — the `notes` app renamed it to
`notes_notes`). The `.bak` beside the file is the pre-edit copy. After the fix the
apps import runs clean with plain `INSERT INTO`, no `--force`.

After regenerating, verify at least:

- Every `brain_*` table that had rows in the source export still has an INSERT
  block (not just a comment header).
- Removed columns are stripped from column lists (`parse_alloc` on
  `brain_memberships` is gone — I7).
- Legacy column renames are applied (`date` → `created_at` / `updated_at` on
  `subdomain_properties`).
- No INSERT block targets a table that no `w/` migration creates — grep each
  `INSERT INTO \`x\`` against `information_schema` on a freshly migrated DB.
- The orphaned `-- Data for table \`laravel_systemsusers_config\`` header in
  `testdb_appfactorystudio.data-only.sql` (no such table; the INSERT under it is
  actually `laravel_systemsusers_sso_integrations`) — mislabel, harmless, worth
  fixing on the next pass.

### Relation to `handoff.md` §5

That section describes an older four-step flow (`migrate` not `migrate:fresh`,
separate `legacy-schema.sql` / `legacy-columns.sql` merges, `INSERT IGNORE`).
The current estate folded legacy columns into migrations and consolidated the
data-only path under `data-only\new` with strict `INSERT INTO`. For local
rebuilds, **this entry replaces §5**; keep §5 as historical context for how the
exports were originally produced.

### Verification (2026-09-05, re-run after the step-1 and dump fixes)

Full clean run — `www`→`auth` fresh, `coordinator` fresh + `dream_graph`/`notes`/
`flow`/`pdf_editor` migrate, `market` fresh; four imports from `data-only\new`;
`db:seed` in `auth` + `www`:

- All migrations: OK
- `testdb_appfactorystudio` import: **clean, 0 errors** (`brain_memberships` 3,
  `subdomain_properties` 28, 205 tables, 12 `checkpoint_*`)
- `afs_idpdb_test` import: clean
- `testdb_afs_apps_db` import: **clean, 0 errors, no `--force`** after the step-1
  correction + the six orphan blocks removed (`graph_projects` 2, `graph_nodes` 8,
  `notes_notes` 1, `pm_projects` 6, `pm_tasks` 75, `afsconnect_settings` 1)
- `testdb_afsdb_market` import: clean
- `auth` `db:seed`: OK (non-fatal `admin_users` warning as before — the table was in the wrong database; fixed 2026-10-10, `plans/auth_idp_tables`)
- `www` `db:seed`: OK — `SeedRunner` → `InitSeeder` → `DemoStoreSeeder` (5 products,
  store stamped `website_reference_num = appfactorystudio`) → `MailHandlerSeeder`, no loop

**One expected non-result:** `laravel_site_users` / `_modules` / `_identities` are
**empty** after the run (101 rows in `laravel_systemsusers_users` from the dump).
The `2026_08_31_180000_create_site_users_schema` migration's backfill runs during
`migrate:fresh` when the legacy tables are still empty, so it no-ops. This is
consistent with the P2 cutover decision (everyone logged out); a populated Site
Users dataset needs either `laravel_site_user_*` INSERT blocks in the dump or a
dedicated `siteusers:backfill` command.

### Residue

1. **`truncate_apps_market.py`** in `data-only\new\` truncates
   `testdb_afs_apps_db` and `testdb_afsdb_market` without dropping schema — an
   alternative to step 1 for those two databases when you cannot run
   `migrate:fresh` in `coordinator` / `market`.
2. **`handoff.md` §5 and the parent `data-only\` headers** still mention
   `INSERT IGNORE`; only `data-only\new` reflects the current rule.
3. **Five Laravel apps share `testdb_afs_apps_db`.** `coordinator` owns `pm_*` and
   `afsconnect_*`; `dream_graph`/`notes`/`flow`/`pdf_editor` each own their own
   prefix. One `migrate:fresh` then four `migrate` — step 1 above. (`truncate_apps_market.py`
   is the fallback when you cannot migrate the apps at all.)
4. **`testdb_afs_apps_db.data-only.sql.bak`** — pre-2026-09-05 copy, before the six
   orphan blocks were stripped. Delete once the fix has bedded in.
5. **Parse registry / MongoDB** are outside this procedure. Member allocation
   rows live in Parse, not MySQL; a member can authenticate against the shared DB
   after a clean run but has **no allocation** until Parse is reprovisioned
   (see I3, I7).


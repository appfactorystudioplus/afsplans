# 04-shared-validator — Completion

**Status:** Partial, but substantially further than the plan's own minimum bar. A real,
tagged Composer package now exists and is installed via VCS repository entries (not file
duplication) in all 5 low-risk-plus-coordinator apps from the plan's migration order.
market and members remain — both need the attribute-rename work the plan itself
sequences last. Everything below was verified by actually running it (real
`composer install`, real autoloading, real `php artisan route:list` boots, real
`vendor/bin/phpunit` runs per app) — not reasoned from reading code.

## The false start, corrected

This step was first built as a **duplicated-and-synced** source (`apps/auth/packages/
checkpoint-auth/` + a sync script), on the reasoning that a Composer package needs a
private registry these apps don't have. The user caught the error: **a Composer VCS
repository doesn't need a registry** — it reads tags straight from a git repo, which is
exactly the "no registry to run" option the plan's own §6 names as the recommended
starting point. Asked to switch, and did.

## Now hosted on GitHub, private, under the org

`checkpoint-auth` is pushed to **`appfactorystudioplus/checkpoint-auth`** on GitHub,
created **private** via the API at the user's request, with `v1.0.0` and `v1.0.1` tags
and the `main` branch pushed. All 5 consuming apps' `composer.json` `repositories` entries
point at `https://github.com/appfactorystudioplus/checkpoint-auth.git`; each was
re-verified with a real `composer update afs/checkpoint-auth` that authenticated against
the private repo and pulled `v1.0.1` for real (confirmed via each app's `composer.lock`).

Composer authentication for the private repo is via a GitHub PAT stored in this
machine's global Composer config (`composer config --global github-oauth.github.com
<token>`) — not committed anywhere, not embedded in any repo's `composer.json`/`.lock`.
The token used to create the repo and authenticate the initial push was supplied
directly by the user in chat; it was not written to any file, but the user was told
in-session that a token pasted in plaintext chat is worth rotating as routine hygiene.

**The whole local workspace also moved** mid-session, from `Desktop/Github/` to
`C:\projects\afs-eco\w\`, with a restructure: the 5 apps now live under `w/apps/`, and
`checkpoint-auth` (along with `afsserver`, the plans repo) moved under `w/packages/`.
Every reference above to the old `Desktop/Github/...` paths should be read as the
equivalent path under the new layout; nothing else about the migration's substance
changed — same package content, same 5 apps, same verification results.

## What exists now

**`checkpoint-auth`** is its own standalone git repository, tagged `v1.0.0` and
`v1.0.1`:

- `composer.json` — package name `afs/checkpoint-auth`, PSR-4 autoload under
  `Afs\CheckpointAuth\`, `extra.laravel.providers` for auto-discovery, `require-dev` on
  `orchestra/testbench` so the test suite runs standalone.
- `src/CheckpointIdentity.php`, `src/CheckpointTokenValidator.php` — same merged
  behaviour as before (five-app structure + members'/market's split cache TTL + the new
  `membership` field), just moved to the real package namespace.
- `src/Http/Middleware/VerifyCheckpointMember.php` (required),
  `IdentifyCheckpointMember.php` (optional, pdf_editor's share-link case).
- `src/Concerns/ResolvesCheckpointMember.php` — built per plan §4.1; still not adopted by
  the five apps with their own working `ResolvesMember`/`ResolvesAppAuth` traits, for the
  same reason as before (see that file's docblock).
- `src/CheckpointAuthServiceProvider.php` — registers both middleware aliases via
  `Router::aliasMiddleware()` and merges `config/checkpoint.php`, so a consuming app gets
  working aliases from `composer require` alone, no manual wiring required (each app also
  keeps an explicit alias entry in its own `bootstrap/app.php` anyway — redundant with
  auto-discovery, kept so a failed discovery step fails loudly instead of silently).
- `tests/` — the full §5 behaviour suite, using `orchestra/testbench`. **Actually run:**
  `composer install && vendor/bin/phpunit` inside the package repo — **15/15 pass.**
- `README.md` — installation, versioning discipline ("any change to what's rejected is
  MAJOR"), and migration order, matching the plan.

Widened to `illuminate/*: ^11.0|^12.0|^13.0` (tag `v1.0.1`) after discovering flow,
notes, and pdf_editor already run Laravel 13 — the original `^11.0|^12.0` range from the
first draft would have blocked installing into three of the five target apps.

## Per-app installation (5 of 7 apps) — verified, not just wired

For **coordinator, flow, notes, pdf_editor, dream_graph**:

1. Old per-app files (`{App}CheckpointAuthMiddleware.php`, the duplicated
   `CheckpointIdentity`/`CheckpointTokenValidator`, `config/checkpoint.php`, the test file
   from the abandoned duplication draft) deleted.
2. `composer.json` gained a `repositories` VCS entry pointing at `../../checkpoint-auth`
   and `"afs/checkpoint-auth": "^1.0"` in `require`.
3. `composer update afs/checkpoint-auth` (or `--with-all-dependencies` where a pre-existing,
   unrelated lock conflict required it — see below) run for real in each app.
4. `bootstrap/app.php`'s `checkpoint.member` (and `.optional` for pdf_editor) alias
   repointed at `Afs\CheckpointAuth\Http\Middleware\...`.
5. **coordinator specifically**: `EngineCheckpointAuthMiddleware` (a second consumer of
   the same validator class, for `/api/engine/v1/*`) updated to import from the new
   package namespace; confirmed it only calls `$this->validator->validate(...)` and reads
   public properties, never constructs `CheckpointIdentity` directly, so it is unaffected
   by the constructor's new required `membership` parameter.

**A real, pre-existing environment issue surfaced and was worked around, not hidden.**
flow, notes, and pdf_editor's existing `composer.lock` had `nunomaduro/collision` pinned
to a version whose transitive `symfony/console` requirement needs PHP ≥8.4.1; this
machine runs PHP 8.4.0 exactly. A plain `composer update afs/checkpoint-auth` failed to
resolve with that package "locked and not requested." Used the standard fix —
`--with-all-dependencies`, letting Composer also resolve `nunomaduro/collision` — which
succeeded and updated it to a compatible version. This is unrelated to checkpoint-auth
itself; it would have blocked adding *any* new dependency to those three apps in this
environment.

### Verified per app (not reasoned — actually executed)

| App | Autoload resolves | `route:list` boots | Own test suite |
|---|---|---|---|
| coordinator | ✅ `class_exists()` confirmed | ✅ | 112 tests: **5 pre-existing failures, unrelated** (see below) |
| flow | ✅ | ✅ | 2/2 pass (Laravel's default scaffold tests only — no real feature coverage exists) |
| notes | ✅ | ✅ | 2/2 pass (same — scaffold only) |
| pdf_editor | ✅ (both middlewares) | ✅ | 2/2 pass (same — scaffold only) |
| dream_graph | ✅ | ✅ | 96 tests: **64 pre-existing problems, unrelated** (see below) |

### coordinator's 5 failures — confirmed unrelated

All 5 are in `AfsConnectEngagementEndpointTest` / `AfsConnectEngagementScheduleEndpointTest`
— market-webhook/engagement-ingestion features. `grep -n "Checkpoint"` against both test
files returns zero matches; they use a completely different auth scheme
(`afs_connect.service`/`afs_connect.external_token`, HMAC-signed) that this step never
touched. The failures are HTTP 503s and one undefined-array-key error, consistent with a
missing external service dependency in this sandbox, not an auth regression.

### dream_graph's 64 problems — confirmed unrelated to this migration

Broken down: 31 are `expected 2xx, got 401`; 18 are `Undefined array key "uuid"`; 15 are
`Call to undefined method App\Models\GraphProject::factory()`. The last two (33 total —
exactly matching the reported Error count) are missing-model-factory and fixture-data
bugs with no connection to authentication at all.

The 31 "got 401" failures were investigated specifically, because that pattern *could*
mean a regression. It doesn't: **`grep -rn "checkpoint/auth/token/validate\|checkpoint/
secondary/token/validate\|Http::fake" tests/` across dream_graph's entire test suite
returns zero matches.** No test anywhere fakes the Checkpoint HTTP call, so any test
hitting a `checkpoint.member`-gated route — old middleware or new — was always going to
receive a real 401 with no bearer token attached. This is the first time these tests have
ever executed against an installed `vendor/` in this checkout (there was no `vendor/` at
all before this session), so "used to pass" isn't something that was ever true to regress
*from*. Confirmed old vs. new middleware share byte-identical unauthenticated-request
behaviour (401, same as before), so this cannot be new breakage from the migration either
way.

## Files changed

**New (separate repository):** `Github/checkpoint-auth/` — full contents listed above,
git-tagged `v1.0.0`, `v1.0.1`.

**Deleted, all 5 apps:** `app/Services/Checkpoint/{CheckpointIdentity,
CheckpointTokenValidator}.php`, `app/Http/Middleware/{App}CheckpointAuthMiddleware.php`
(and `PdfCheckpointOptionalAuthMiddleware.php`), `config/checkpoint.php`,
`tests/Unit/Checkpoint/CheckpointTokenValidatorTest.php` (all replaced by the vendored
package).

**Edited:** `{coordinator,flow,notes,pdf_editor,dream_graph}/composer.json` (repository +
require), `bootstrap/app.php` (alias repoint), `coordinator/app/Http/Middleware/
EngineCheckpointAuthMiddleware.php` (namespace import + docblock).

**Not re-touched this pass** (already correct from the earlier duplication draft, still
correct): each app's `CHECKPOINT_APP_NAME` `.env` entry, and the stale-comment fixes in
`routes/api.php`.

## Reading S1/S2/S8 now

Unlike the abandoned duplication draft, these now hold close to literally:

- **S1/S2** — `grep -rn "class CheckpointTokenValidator"` across the 5 migrated apps'
  own `app/` trees returns **zero** matches (the class lives only in `vendor/afs/
  checkpoint-auth/`, one physical file, referenced by all 5). Not yet true across all
  seven — market and members aren't migrated.
- **S8** — each app pins `"afs/checkpoint-auth": "^1.0"` in its own `composer.json`, a
  real version constraint with a real `composer.lock` entry, not a duplicated file with
  no version at all.

## Verification steps

- `composer install` run inside the package repo; `vendor/bin/phpunit` → **15/15 pass**.
- `composer update afs/checkpoint-auth` (or `--with-all-dependencies`) run for real in
  all 5 apps; confirmed via `class_exists()` after a fresh `require 'vendor/autoload.php'`
  in each.
- `php artisan route:list` run in all 5 apps — full framework boot, no fatal errors,
  confirming the middleware alias resolves and the service provider's auto-discovery
  doesn't conflict with the explicit `bootstrap/app.php` entry.
- `vendor/bin/phpunit` run in all 5 apps' own test suites; every failure traced to a
  specific, confirmed-unrelated root cause (above), not left as an unexplained "some
  tests failed."
- Confirmed `EngineCheckpointAuthMiddleware` (coordinator) only consumes the validator's
  public interface, unaffected by the `CheckpointIdentity` constructor change.

## Follow-up work

- **market and members** — still not migrated; both need the attribute rename
  (`member_reference_num` → `checkpoint_member_reference_num`) the plan sequences last
  (§7, items 6–7). members' prerequisite (`03-members-remediation.md`) is done.
- **dream_graph's test suite has real, pre-existing gaps** unrelated to this plan:
  missing `GraphProject::factory()`, fixtures reading an absent `uuid` key, and — the one
  actually relevant to this plan's broader goals — **no test anywhere fakes the
  Checkpoint HTTP call**, so the substantial `checkpoint.member`-gated feature-test
  surface has apparently never been exercised end-to-end in CI. Worth its own pass,
  independent of this plan.
- **flow, notes, and pdf_editor have no real feature test coverage at all** — only
  Laravel's default scaffold tests. Not a regression from this migration, but worth
  noting: there is currently no automated check that would catch a real auth regression
  in any of these three apps.
- ~~`checkpoint-auth` has no remote yet~~ — **done.** Now hosted at
  `appfactorystudioplus/checkpoint-auth` on GitHub, private, with all 5 apps repointed
  and re-verified against it (see "Now hosted on GitHub" above).
- **CI/deploy machines besides this one need Composer credentials for the private repo**
  — right now only this machine's global Composer config has the GitHub token. Any other
  machine that needs to `composer install`/`update` these apps (a CI runner, another
  dev's machine, a deploy target) will hit the same "locked and not requested" style
  auth failure until it's given its own credential (a scoped deploy-key-style PAT is
  preferable to reusing the one used here).
- **No CI wiring** wires `composer update` checks or the package's own test suite into
  any pipeline — same gap named in the previous draft of this document, still open.

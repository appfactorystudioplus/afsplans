# 04 — One validator, seven apps

## Completion document

When this plan step is completed, write `complete/04-shared-validator.complete.md` with
status, summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly using PHPDoc. The package's public surface is
consumed by seven repositories that upgrade independently — every method documents its
contract, and any behaviour change documents which version introduced it.

---

## 1. The situation

One responsibility — "ask Checkpoint whether this token is good, and who it belongs to" —
exists as seven files in seven repositories.

| App | Class | Lines |
|---|---|---|
| coordinator | `App\Services\Checkpoint\CheckpointTokenValidator` | 201 |
| notes | same FQN | 184 |
| dream_graph | same FQN | 184 |
| pdf_editor | same FQN | 184 |
| flow | same FQN | 184 |
| members | `App\Services\Checkpoint\CheckpointTokenVerifier` | — |
| market | `App\Services\Portal\CheckpointTokenVerifier` | — |

Plus five near-identical middlewares (`MemberCheckpointAuthMiddleware`,
`NotesCheckpointAuthMiddleware`, `DreamGraphCheckpointAuthMiddleware`,
`PdfCheckpointAuthMiddleware`, `FlowCheckpointAuthMiddleware`) that differ only in a
constant and an error-code prefix, and five copies of `CheckpointIdentity`.

**This was a known, accepted trade-off.** S0.5g's completion notes say so directly: *"same
file, three copies — no shared PHP package across these deploys."* Under time pressure,
with three apps to fix and no packaging infrastructure, copying was the right call. This
document revisits it now that the count is seven.

## 2. Why now

**Nothing has drifted yet.** Diffing every copy: coordinator's 17 extra lines are comments;
notes and dream_graph are byte-identical; pdf_editor and flow differ from notes by one
comment line each; members and market differ by a namespace and a config key.

That is the argument. Consolidating today is a mechanical extraction. Consolidating after
one app patches a bug the others do not is a merge, and after two it is an archaeology
project.

There is also a concrete near-miss on record. The S0.5 notes describe a **cache-key bug**
in the original engine-only validator: it keyed on `hash(token)` alone, ignoring the
required app name, so once a second caller existed a result cached for one app would be
served for another. It was caught during generalisation. Had that version been copied five
times first, it would have needed fixing five times — and the fix would have had to be
noticed five times.

## 3. What the package contains

`afs/checkpoint-auth`, one Composer package.

```
src/
  CheckpointIdentity.php          value object: valid, reason, memberReferenceNum,
                                  checkpointUserId, userType, appRole, membership
  CheckpointTokenValidator.php    the HTTP call, the cache, the primary/secondary split
  Http/
    VerifyCheckpointMember.php    required-bearer middleware
    IdentifyCheckpointMember.php  optional-bearer middleware (pdf_editor, market)
  Concerns/
    ResolvesCheckpointMember.php  the accessor trait ([02](02-two-patterns.md) §4.1)
  Exceptions/
    CheckpointUnavailable.php
config/
  checkpoint.php                  publishable: base_url, app_name, timeouts, cache TTLs
tests/
  …                               the behaviour suite of §5
```

### 3.1 Configuration, per app

```php
// config/checkpoint.php
return [
    'base_url'  => env('CHECKPOINT_BASE_URL'),
    'app_name'  => env('CHECKPOINT_APP_NAME'),   // MEMBERS | COORDINATOR | NOTES_EDITOR | …
    'timeout'   => 8,
    'cache' => [
        'success_ttl' => 60,   // seconds
        'failure_ttl' => 5,    // §4.2 — deliberately shorter
    ],
];
```

The app name moves from a PHP constant in each middleware to config. That is what lets one
middleware class serve all seven apps.

**Note the existing config-key divergence:** the five apps read
`config('afs_connect.checkpoint_base_url')`; members reads
`config('services.checkpoint.base_url')`; market reads `config('market.urls.auth')`. All
three resolve to the same `CHECKPOINT_BASE_URL` env var. The package standardises on
`config('checkpoint.base_url')`, and each app keeps its old key as a fallback during
migration so a missed deployment config does not fail closed on day one.

## 4. Merged behaviour

The package is the five-app validator, with one change taken from members.

### 4.1 Kept from `CheckpointTokenValidator`

- Cache key includes the required app name (§2's near-miss).
- Primary/secondary routing via an **unverified** JWT peek, documented as a routing hint
  that never grants access — a malformed token routes to the normal endpoint and is
  rejected there. Fails closed either way.
- `wrong_app` for secondary tokens; **never** for primary tokens, which carry no
  `app_name` claim at all.
- `null_member` rejection, because `apps/auth` only hard-fails a memberless identity in
  production.
- `post()` returning `null` on every failure path — exception, non-200, or
  `success: false` — so all failures are indistinguishable and all mean "invalid".
- Typed `reason` strings: `missing_token`, `invalid_token`, `null_member`, `wrong_app`.

### 4.2 Taken from `CheckpointTokenVerifier`

**Split cache TTLs.** The five-app validator uses `Cache::remember`, which stores whatever
the closure returns — including failures — for the full 60 s. members deliberately caches
a failure for 5 s, with the reasoning in its comment: a single transient failure must not
read as a minute-long outage.

```php
$result = $this->validateUncached($token, $appName);

Cache::put(
    $key,
    $result,
    $result->valid ? config('checkpoint.cache.success_ttl')   // 60
                   : config('checkpoint.cache.failure_ttl'),  // 5
);
```

This matters most right after login, when a freshly minted token can briefly race auth's
own commit — the exact window members' comment calls out.

### 4.3 Added

`membership` on `CheckpointIdentity`, clamped 0–4 with missing ⇒ 0
([02](02-two-patterns.md) §4.2), so the plan tier comes from the same verified response as
the identity.

## 5. The behaviour suite

The package ships tests that every consuming app runs. These encode the four `apps/auth`
properties from [01](01-platform-audit.md) §1.1 so no app can regress them independently.

| Test | Asserts |
|---|---|
| auth returns `{valid:false}` with **HTTP 200** | Rejected — property P1 |
| auth returns `{valid:true, member:null}` | Rejected `null_member` — P2 |
| Secondary token, matching `app_name` | Accepted; `userType: 'secondary'`; member is the **super user's** — P3 |
| Secondary token, different `app_name` | Rejected `wrong_app` |
| **Primary token, any required app** | **Accepted** — P4, the easiest thing to break while tightening |
| Malformed / undecodable JWT | Routed to the normal endpoint, rejected there |
| auth times out / connection refused | Rejected; fails closed |
| Same token, two different required apps | Two distinct cache entries |
| 50 sequential validations, one token | One outbound HTTP call |
| Failed validation, then retry after 6 s | Second call reaches auth (5 s failure TTL) |
| Successful validation, retry after 6 s | Served from cache (60 s success TTL) |
| `membership` of `null`, `"3"`, `9`, `-1` | Plan `0`, `3`, `4`, `0` |

### 5.1 Prior art: the TypeScript sync script

`apps/afsserver/bin/sync-checkpoint-client.sh` already solves this exact problem for the
TypeScript client — it syncs four files into seven apps and has a `--check` mode for CI.
Its header documents the drift this plan fears, found for real: six identical copies, one
app a whole generation behind, another missing a file entirely, and nothing anywhere
reporting any of it.

Two lessons for the PHP package:

- **Its file list is deliberately narrow.** The script refuses a whole-directory rsync
  because the source app’s copies of several files are *older* than the consuming apps’ —
  a blind sync would delete working authentication code. The same caution applies here:
  verify per file that each copy is identical, or that the source is newest.
- **The `--check` mode is what makes it real.** Without CI enforcement it is a script
  someone forgets to run.

It does **not** cover `market`, and cannot cover the C++ or Dart clients
([12](12-game-engine-client.md) §2.4). Both gaps are addressed there.

## 6. Distribution

The seven apps are separate deployments, so the package needs a real distribution channel.

| Option | Assessment |
|---|---|
| **Private Composer registry** (Satis / Packagist private) | Cleanest. Version constraints, `composer update` per app, an audit trail of who is on what |
| **Git repository via VCS repository entry** | No registry to run; each app pins a tag in `composer.json`. Adequate, and the likely starting point |
| Path repository / symlink | Only viable if the apps share a filesystem. They deploy independently — do not rely on it |
| Copy the file, again | The status quo. Rejected |

Start with the **VCS + tag** option: it gives version pinning immediately with no new
infrastructure, and can move behind a registry later without any app-side change beyond
the `repositories` block.

### 6.1 Versioning contract

Semantic versioning, with one rule that matters more than the others:

> **Any change to what the validator rejects is a MAJOR version.** Tightening a check is
> a breaking change for a consuming app even when it is obviously correct — because the
> app may have traffic that currently passes.

Apps pin `^1.0`, and the app-scoping enforcement in [02](02-two-patterns.md) §5.1 ships as
an opt-in config flag before it ever becomes a default.

## 7. Migration order

Lowest risk first, so the package is proven before it reaches the app with the most
surface.

| # | App | Change | Risk |
|---|---|---|---|
| 1 | **flow** | Smallest (11 routes, 0 ungated). Delete local validator/identity/middleware, install package, set `CHECKPOINT_APP_NAME=FLOW_EDITOR` | Low — proves the package end to end |
| 2 | **notes** | Same shape | Low |
| 3 | **pdf_editor** | Also exercises `IdentifyCheckpointMember` (the optional variant) | Low |
| 4 | **dream_graph** | Largest of the five (81 routes) | Low |
| 5 | **coordinator** | Also has `EngineCheckpointAuthMiddleware` and two other schemes to leave untouched | Medium |
| 6 | **market** | Pattern switch + attribute rename across portal API controllers | Medium |
| 7 | **members** | After [03](03-members-remediation.md) lands | Highest |

**Attribute names do not change for apps 1–5**, so their controllers are untouched — the
migration is deleting three files and adding a dependency. That is what makes the first
four low risk.

## 8. What this does not solve

- **The desktop and mobile clients** speak to these APIs and are not PHP. They are
  unaffected — the package is server-side only and the wire contract does not change.
- **The `checkpoint_client` Dart package** in the mobile app and the TypeScript
  `resources/js/checkpoint/` clients are separate implementations of the *client* side.
  Consolidating those is a different problem with a different answer, and is out of scope.
- **`apps/auth` itself** is untouched.

## 9. Acceptance

| # | Criterion |
|---|---|
| S1 | `grep -rn "class CheckpointTokenValidator"` across all seven repos returns exactly one definition, in the package |
| S2 | Same for `CheckpointIdentity` |
| S3 | The five `{App}CheckpointAuthMiddleware` classes are deleted or reduced to configuration |
| S4 | All seven apps declare `CHECKPOINT_APP_NAME` |
| S5 | The §5 suite passes in every consuming app's CI |
| S6 | A primary token is accepted by every app regardless of its required app name |
| S7 | Negative results cache for 5 s, positive for 60 s |
| S8 | Each app pins an explicit package version; none floats on `dev-main` |

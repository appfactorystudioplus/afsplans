# 08 — Enforcement scheduled in `members` — completion

**Done 2026-09-25** in `www` and `members`. Plan: [`../08-brain-enforcement.md`](../08-brain-enforcement.md).
This adds to [`08-brain-enforcement.complete.md`](08-brain-enforcement.complete.md) (the ladder itself), which
is unchanged. Committed to the local `main` branches of `www`, `members` and this plans repo (not pushed).

## What was built

### `members`
| File | What |
|---|---|
| `routes/console.php` | `billing:enforce-membership` every fifteen minutes, `withoutOverlapping(14)`, `onOneServer()`, `runInBackground()` |
| `app/Console/Commands/BillingEnforceMembership.php` | Options `--dry-run`, `--member=`, `--force`. Off unless `BILLING_ENFORCEMENT_ENABLED=true` (or `--force`). Sends one signed request, prints the Brain's output; exit 0 on success, when disabled, or on `409` (a run already in progress); exit 1 when `www` is unreachable, refuses the call or reports a failure |
| `app/Services/Www/BrainSurfaceClient.php`, `BrainSurfaceSignature.php` | The signed client. Never throws for a transport or HTTP failure; a fresh nonce per call |
| `config/brain_surface.php` | `WWW_BRAIN_SURFACE_URL`, `WWW_BRAIN_SURFACE_KEY`, `WWW_BRAIN_SURFACE_APP_NAME`, timeout, the kill switch |
| `tests/Feature/BrainSurfaceEnforcementTriggerTest.php` | 11 tests: shared signature vector, one signed POST, fresh nonces, kill switch, `--force`, no key, `401`/`500`/`409` handling, `--member`, the schedule |

### `www`
| File | What |
|---|---|
| `config/brain.php` | `is_brain` (the `IS_BRAIN` flag, plus a hard `APP_URL` host check in production), `members_url`, `surface.apps.MEMBERS` keys |
| `app/Http/Middleware/BrainOnly.php` (`brain.only`) | `404` unless `config('brain.is_brain')` |
| `app/Http/Middleware/VerifyBrainSurfaceSignature.php` (`brain.surface`) | Known app, key configured, signature and skew, nonce not seen (`Cache::add`, 2× skew) |
| `app/Services/Brain/BrainSurfaceSignature.php` | Same construction as `members`' copy: `HMAC-SHA256(key, "{ts}.{nonce}.{METHOD}.{path}.{sha256(body)}")` |
| `app/Http/Controllers/Internal/BrainBillingEnforcementController.php`, `routes/api.php` | `POST /api/internal/brain/billing/enforce` `{dry_run?, member?}`. Runs the existing command, returns `{success, exit_code, dry_run, duration_ms, output}`. A cache lock (840 s) makes a second call `409` |
| `app/Console/Commands/BillingEnforceMembership.php` | Guard is now `config('brain.is_brain')` then `config('billing.enforcement_enabled')` then `brain_billing_settings.enabled`. `--explain` says which one stopped it |
| `routes/console.php` | The enforcement schedule entry **removed**; a comment says why |
| `app/Services/Billing/BrainGuard.php` | **Deleted.** The manage-billing URL in `NotificationDispatcher` and `DunningService` now comes from `config('brain.members_url')` (so the lab no longer emails links to production) |
| `_env/{prod,dev,local,vagrant}/.env`, `.env` | `IS_BRAIN=true`, `BRAIN_SURFACE_KEY_MEMBERS(_NEXT)`. Random keys generated for `local` and `vagrant`; `dev` and `prod` are **empty** and must be provisioned. `_env/www/.env` (the tenant default) untouched |
| `tests/Feature/Brain/BrainSurfaceEnforcementTest.php`, `tests/Unit/Brain/BrainConfigTest.php` | 28 tests: unsigned, wrong key, stale, replayed, unknown app, missing header, no key, rotation key, opposite-direction key, non-Brain `404` even when signed, `409` overlap and release, bad `member` `422`, kill switch; the `IS_BRAIN` matrix (absent/false/junk, production host exact-match, look-alike hosts, missing `APP_ENV`); the shared signature vector |

`members` env sets got `WWW_BRAIN_SURFACE_URL`, `WWW_BRAIN_SURFACE_KEY` and `BILLING_ENFORCEMENT_ENABLED=false`.

## Deviation from the plan

The plan's default was a `members` **evaluator** (about 1,200 lines of ladder, policy and tier logic ported
with their own read models) plus a `www` **applier**. Built instead: the **trigger-only** design the plan
listed as its fallback — `members` schedules and triggers; `www` runs the existing, tested logic. Chosen
because the tier write, state row, event row and notice must commit together on the Brain anyway, and a copy
of the ladder in `members` would need a billing-cycle parity proof for no behavioural gain. Consequences:

- **No "compare the `members` dry-run with `www`'s over one billing cycle"** — there is one implementation.
- **The `www` command is kept** (it is what the route runs); only `BrainGuard` was deleted.
- The plan documents were rewritten for this design (`08`, `01`, `00` D18, `09`, `11`, `17`, `README`, and the
  `account_close` / `www_brain` / `direct_payments` references).

## Verification

| Check | Result |
|---|---|
| `members` tests | 11 new pass |
| `www` tests | 30 new pass; full suite 614 tests with the same 3 unrelated failures as before this work |
| Lab, unsigned `POST` to the surface | `401 Invalid service signature.` |
| Lab, `members` → `www` with `--force --dry-run` and a real run, settings row enabled for the test then restored to `0` | The Brain's report came back through `members` (`Candidates: 1 Skipped (unresolved tier): 1 …`), exit 0. The real (non-dry) run wrote an `evaluation_skipped` event and state row for the one lab member whose tier cannot be resolved — expected, and the only lab data it touched |
| Lab, cron | With `members/.env` `BILLING_ENFORCEMENT_ENABLED=true` and the `laravel-cron.sh` entry, the `:30` tick ran `billing:enforce-membership` in the background and `www` logged `[Billing][Enforce] triggered … duration_ms 573`. The flag was set back to `false` afterwards |
| Lab, DNS | The lab VM could not resolve `*.appfactory.test1` (its resolver had reverted to the host's ISP DNS — the known issue in `AFS-DEPLOYMENT-VAGRANT.md` §8); re-applied the documented `nmcli` fix |

## Not done

- **Production is not switched on.** `dev` and `prod` env sets have empty surface keys; nothing calls `www` from
  a real environment. The switch-on order is [08 §8.12](../08-brain-enforcement.md); the billing-cycle soak in
  [11 §11.4](../11-rollout-and-testing.md) has not started.
- `account_status` 4/5 members are **not** yet excluded from `candidates()` — that lands with account closure
  ([account_close 05 §5.7](../../account_close/05-purge-engine.md)).
- The route has no automated test that runs a real dunning transition through it (the ladder is covered by the
  earlier build's checks); the tests here cover the surface and the trigger.
- Verified against a lab database with one candidate member; no member was actually downgraded or restored.

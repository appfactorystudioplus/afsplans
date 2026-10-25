# 03 — Cron Bootstrap — Complete

## Status

Done.

## Summary

Installed the idempotent crontab bootstrap and wired it into the Direct Payments admin
save, and registered both `billing:*` scheduled tasks (implemented in steps
[24](../24-notification-scheduler.md) and [`../../billing/08-brain-enforcement.md`](../../../billing/08-brain-enforcement.md)) in
`routes/console.php` per §3.4's unconditional-registration rationale.

## Files changed

| File | Change |
|---|---|
| `www/core/scripts/commands.sh` | Appended `--update-cron-task` block (§3.3), verbatim from the plan, following the existing `$action`/`$paramN` convention, terminal `exit 0` per **B17** |
| `www/app/Http/Controllers/Externals/DirectPayments/DirectPaymentsController.php` | Added `use App\Http\Controllers\FunctionsController;`; added the guarded `RunCommandBG('--update-cron-task', …)` trigger at the end of `saveDirectPayment()`, after the item/tier rebuild and immediately before the response is returned (§3.1). Two-argument form used (`SystemUser`, resolved `subpath` with the `www15 → dev/www15` mapping), per §3.2 |
| `www/routes/console.php` | Added `Schedule::command('direct_payments:subscription-notify')` and `Schedule::command('billing:enforce-membership')`, both `everyFifteenMinutes()->withoutOverlapping(14)->onOneServer()->runInBackground()` (§3.4) |

## Notes on ordering vs. the plan text

§3.1 says the trigger fires *after* `syncConnectConfig()`, `syncNotificationConfigs()` ([23](../23-notification-config.md)) and `syncAdvancedConfig()` ([26](../26-advanced-policy.md)). Only `syncConnectConfig()` exists yet at this point in execution. The trigger is placed as the last statement before `return` today; when steps 23 and 26 add their sync calls, they must be inserted **before** this trigger, not after — flagged as a required follow-up edit in those steps' completion docs.

## Verification performed

- `php -l` on both changed PHP files — no syntax errors.
- `sh -n core/scripts/commands.sh` — no syntax errors.
- Manual read-through of the guard: `saveDirectPayment()` now calls `FunctionsController::GetManagedDomain()` and only fires `RunCommandBG` when `access === true` and `SystemUser` is non-empty, matching §3.1's path-traversal guard.

## Known interim state / follow-ups

- `direct_payments:subscription-notify` and `billing:enforce-membership` are registered in the scheduler but their Artisan command classes do not exist yet — they land in steps [24](../24-notification-scheduler.md) and [`../../billing/08-brain-enforcement.md`](../../../billing/08-brain-enforcement.md). Until then, `php artisan schedule:list` will fail to resolve these two entries. This is expected interim state per the plan's own step ordering (§3.4 registers unconditionally by design) and is not a defect in this step.
- Not verified live against a real crontab/`afs-website` binary (no such host in this environment) — verification per §3.5 (`crontab -l`, `cron_task.log`, `schedule:list`) is a deployment-time check, out of scope for this local execution.
- The `--remove-cron-task` mirror block is deliberately not implemented, per §3.3 "Deliberately not handled".

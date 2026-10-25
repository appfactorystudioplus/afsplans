# 03 — Cron Bootstrap

Installing `php artisan schedule:run` into the system crontab, idempotently, from the
Direct Payments admin save.

## 3.1 The trigger

In `DirectPaymentsController::saveDirectPayment()`, **last** — after `syncConnectConfig()`,
`syncNotificationConfigs()` ([23](23-notification-config.md) §5.6) and `syncAdvancedConfig()`
([26](26-advanced-policy.md) §12.8) — and **only when the save succeeded**:

```php
/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/21-cron-bootstrap.md §3.1.
 *
 * Saving a Direct Payments configuration is the moment this deployment first
 * needs a scheduler. The block invoked here is idempotent (§3.3), so calling
 * it on every save is deliberate: it is also the repair path for a crontab
 * that was cleared by a server rebuild or a tenant migration.
 *
 * Fire-and-forget by design — RunCommandBG returns as soon as the background
 * process is spawned, so an unreachable shell never blocks an admin save.
 */
$managed_domain = FunctionsController::GetManagedDomain();
$domain         = $managed_domain["Domain"];
$shParams       = "--update-cron-task '" . $managed_domain["SystemUser"] . "'";
FunctionsController::RunCommandBG($shParams, $managed_domain, null, true);
```

That is the call exactly as specified. §3.2 explains the one place it needs an extra
argument and why the block defaults to the specified behaviour without it.

**Guard it.** `GetManagedDomain()` can return `['access' => false, 'sub' => …]` when
`subdomain_properties` has no row. Calling `RunCommandBG` with that array produces
`/home//websites//core/scripts/commands.sh` — a path traversal into nothing at best.
Wrap:

```php
if (($managed_domain['access'] ?? false) === true && !empty($managed_domain['SystemUser'])) {
    // … RunCommandBG …
}
```

## 3.2 The subpath problem

The requested crontab line is:

```
* * * * * cd /home/<systemuser>/websites/www/ && php artisan schedule:run >> /dev/null 2>&1
```

That is correct for production (`Subdomain = "www"`). It is **wrong for the dev Brain**:
`RunCommandBG` itself contains

```php
$subpath = $managed_domain["Subdomain"];
if ($subpath == "www15") { $subpath = "dev/$subpath"; }
```

so on `www15.appfactory.studio` the Laravel root is `/home/appfactorystudio/websites/dev/www15/`,
and a cron line pointing at `websites/www/` would run the *production* application from a
dev-triggered save — or fail, if that directory does not exist on that box.

**Resolution.** The block accepts an optional second parameter:

```
--update-cron-task '<systemuser>' ['<subpath>']
```

`$param2` defaults to `www` when absent, so the one-argument call in §3.1 behaves exactly
as specified. The recommended call passes the resolved subpath:

```php
$subpath = $managed_domain["Subdomain"];
if ($subpath === "www15") { $subpath = "dev/$subpath"; }
$shParams = "--update-cron-task '" . $managed_domain["SystemUser"] . "' '" . $subpath . "'";
```

Pick one and use it consistently. The two-argument form is the one to ship; the
one-argument form is kept working so nothing that already calls it breaks.

## 3.3 The `commands.sh` block

Appended to `www/core/scripts/commands.sh`, following the file's existing conventions
(`$action`/`$paramN` positional parsing, `create_log_directory`, `&>> $logfile`, terminal
`exit 0` per finding **B17**).

```sh
# AFS_BILLING_MODIFIER_ADDITION - plans/www/direct_payments/21-cron-bootstrap.md 3.3
#
# Install the Laravel scheduler crontab entry for one system user, idempotently.
#
#   sh commands.sh --update-cron-task appfactorystudio
#   sh commands.sh --update-cron-task appfactorystudio dev/www15
#
# param1  systemuser  - the site's system user; the crontab is installed FOR
#                       this user, so artisan runs with the same ownership as
#                       the web process and never leaves root-owned files in
#                       storage/logs or bootstrap/cache.
# param2  subpath     - path segment under ~/websites/. Defaults to "www".
#                       Pass "dev/www15" for the dev Brain (see 3.2).
#
# Idempotent: the exact command string is grepped for first, and only appended
# when absent. This block runs on EVERY Direct Payments save, so a
# non-idempotent version would multiply the entry without bound.
if [ "$action" = "--update-cron-task" ]; then
    systemuser="$param1"
    subpath="$param2"

    if [ -z "$systemuser" ]; then
        exit 1
    fi
    if [ -z "$subpath" ]; then
        subpath="www"
    fi

    logfile="/mnt/${STORAGE_LOCATION}/$systemuser/logs/cron_task.log"
    create_log_directory $systemuser $logfile
    now="$(date +"%r")"
    echo "[Appfactory]${now}: --update-cron-task ${systemuser} ${subpath}" &>> $logfile

    site_dir="/home/${systemuser}/websites/${subpath}"

    # Do not install a scheduler for a site that is not there. A crontab entry
    # cd-ing into a missing directory emails cron failures to root every minute.
    if [ ! -f "${site_dir}/artisan" ]; then
        echo "[Appfactory]${now}: no artisan at ${site_dir} - skipping" &>> $logfile
        exit 0
    fi

    cron_line="* * * * * cd ${site_dir}/ && php artisan schedule:run >> /dev/null 2>&1"

    # crontab -l exits non-zero when the user has no crontab yet; || true keeps
    # `set -e`-style shells and the read below happy on a first install.
    current="$(crontab -u ${systemuser} -l 2>/dev/null)"

    # -F fixed-string: the line contains * and / and must not be read as a regex.
    if echo "${current}" | grep -qF "${cron_line}"; then
        echo "[Appfactory]${now}: entry already present - no change" &>> $logfile
    else
        tmpcron="/mnt/${STORAGE_LOCATION}/${systemuser}/temp/crontab_${systemuser}.tmp"
        mkdir -p "/mnt/${STORAGE_LOCATION}/${systemuser}/temp"
        printf '%s\n' "${current}" > "${tmpcron}"
        printf '%s\n' "${cron_line}" >> "${tmpcron}"
        # Strip any blank leading line left by an empty `current`.
        sed -i '/^$/d' "${tmpcron}"

        crontab -u ${systemuser} "${tmpcron}" &>> $logfile
        rm -f "${tmpcron}"
        echo "[Appfactory]${now}: entry installed" &>> $logfile

        # As specified. crond re-reads /var/spool/cron on mtime change, so this
        # is belt-and-braces rather than required - it is cheap and it makes the
        # behaviour identical on hosts where crond was started with an inotify
        # watch disabled.
        systemctl restart crond &>> $logfile
    fi

    exit 0
fi
```

### Why `crontab -u {systemuser}` and not `/etc/crontab`

The requested line has **no user field**, which makes it a *user* crontab line — writing it
into `/etc/crontab` (which requires a user field) would break that file's syntax. Beyond
syntax:

- Running `artisan` as the site's own user keeps `storage/logs/laravel.log`,
  `storage/framework/cache` and `bootstrap/cache` owned correctly. A root-run
  `schedule:run` creates root-owned files that the web process then cannot write, which
  fails *later* and confusingly.
- `crontab -u` is atomic: it validates and installs, or leaves the old crontab in place.
  Editing `/var/spool/cron/{user}` in place can leave a half-written file that crond
  rejects wholesale.

`afs-website` is setuid root (finding **B15**), so `crontab -u` and `systemctl` both work.

### Edge cases the block handles

| Case | Handling |
|---|---|
| User has no crontab yet | `crontab -l` → empty + non-zero; `2>/dev/null` and the blank-line `sed` cover it |
| Entry already present | `grep -qF` → log and exit, no restart |
| Same user, different subpath (a box hosting both `www` and `dev/www15`) | Different `cron_line`, so both entries coexist. Correct: each site needs its own scheduler |
| Site directory missing | Skips, logs. Prevents per-minute cron failure mail |
| `systemuser` empty | `exit 1` before touching anything |
| Called 50 times in a minute by 50 saves | Only the first appends; the rest hit the grep. Concurrent `crontab -u` calls are serialised by `crontab(1)`'s own lock |

### Deliberately not handled

- **Removing** the entry when the last DP config is deleted. A scheduler that runs and
  finds nothing to do costs one indexed query per 15 minutes ([00](../../billing/00-overview.md) §6);
  removing it would break `members`' storage sweeps if they ever share the line. If a
  `--remove-cron-task` is wanted later it is a mirror of this block using `grep -vF`.
- **`cron` vs `crond`.** The service is named `crond` on RHEL/CentOS/Rocky, which is what
  these hosts run (`commands.sh` already uses `/usr/sbin/postmap`, `expect`, and
  `/etc/postfix/vmailbox` — a RHEL-family layout). On a Debian host the unit is `cron` and
  the restart line silently fails; the crontab install still succeeds, and crond picks it
  up anyway. Not worth branching on.

## 3.4 Registering the schedule

`www/routes/console.php` — following `members/routes/console.php`'s established shape:

```php
use Illuminate\Support\Facades\Schedule;

/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/24-notification-scheduler.md §6.1.
 *
 * Subscription lifecycle notifications. Runs on the Brain AND on every tenant
 * clone, and does nothing at all unless a laravel_direct_payment_notifications
 * row is active (§6.2) — see the command's own header for the guard.
 *
 * The crontab entry this depends on is installed by
 * commands.sh --update-cron-task (plans/www/direct_payments/21-cron-bootstrap.md).
 */
Schedule::command('direct_payments:subscription-notify')
    ->everyFifteenMinutes()
    ->withoutOverlapping(14)
    ->onOneServer()
    ->runInBackground();

```

`www/routes/console.php` registers only tenant-generic tasks, because it is evaluated on every
tenant clone. appfactory-studio-specific enforcement (`billing:enforce-membership`) runs in
**`members`** (`members/routes/console.php`) — see [`../../billing/08`](../../billing/08-brain-enforcement.md).

Registration is unconditional and the **guard lives in the
command**, not in the schedule — `routes/console.php` runs for every artisan invocation including
`migrate` and `config:cache`, and doing a database lookup at registration time makes `php artisan`
fail on a box whose database is down.

## 3.5 Verification

```bash
crontab -u appfactorystudio -l
```
```bash
tail -n 50 /mnt/node1/appfactorystudio/logs/cron_task.log
```
```bash
cd /home/appfactorystudio/websites/www && php artisan schedule:list
```

Expect exactly one matching cron line no matter how many times a DP config is saved, and
both `billing:*` commands listed at `*/15 * * * *`.

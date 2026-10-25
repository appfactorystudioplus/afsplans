# 10 — Everything runs as apache: web, scheduler and queue, Brain and tenants

Status: the repo changes of section 3.2 are DONE 2026-10-15 in `w/www`; nothing is changed on any server, and 3.2a, 3.3, 3.4 and the checks of section 5 are not done.
See "Done 2026-10-15" at the end. Commands and code above that section show the design; the scripts are the source of truth.

## 1. Decision (owner)

PHP-FPM already runs as `apache` (`user = apache`, `group = apache` in the `[www]` pool). The scheduler (`schedule:run`),
the queue (`queue:work`, plan 08) and the Brain commands (plan 09) also run as `apache`, for the Brain and for every
tenant. One user per machine means one owner of `storage/` and `bootstrap/cache/`, so there is no mixed-owner problem
and no pool, ACL or setgid change.

This drops the earlier ideas of a separate `[brain]` pool and of cron running as `appfactorystudio` or as each tenant's
system user.

## 2. What exists now (facts read from the repo)

| Fact | Where |
|---|---|
| The tenant installer sets each tenant home to `apache.apache` with mode 775, recursively | `members/core/scripts/remote/builder/app_install.sh:573-574` |
| The scheduler entry is installed for the tenant's **system user** by `commands.sh --update-cron-task <systemuser> [subpath]` (called on each Direct Payments save), as `cd /home/<systemuser>/websites/<subpath>/ && php artisan schedule:run >> /dev/null 2>&1` | `w/www/core/scripts/commands.sh:1278-1343`, `plans/www/direct_payments/21-cron-bootstrap.md` |
| That block reads and rewrites one user's crontab with no lock | same block |
| `laravel-cron.sh` checks, installs or removes the same entry for a given user and app path, one entry per app per user, and warns when the user's shell is `nologin` | `w/www/core/scripts/laravel-cron.sh` |
| The Brain's scheduler is not running (owner) | owner |

## 3. What has to change

### 3.1 apache's cron shell

`apache` normally has a `nologin` shell, and cron silently skips jobs for such a user. The fix is one line at the top of
apache's crontab: `SHELL=/bin/sh`. (`laravel-cron.sh` already names this fix.)

### 3.2 `laravel-cron.sh` takes the user and the group, and becomes the one installer

Owner requirement: `laravel-cron.sh` takes a parameter for the user and the group the scheduler belongs to. Today it takes
`--user` only (the crontab owner) and `commands.sh --update-cron-task` carries a second, separate crontab editor
(`commands.sh:1278-1343`). The plan makes `laravel-cron.sh` the only implementation.

Changes to `w/www/core/scripts/laravel-cron.sh` (shown as the new or changed pieces; nothing is edited yet):

```sh
#   sh laravel-cron.sh --install --user apache --group apache --path /home/tenant1/websites/www
#
#   --group GROUP   the group the app's writable folders belong to. Default: the user's primary group (`id -gn USER`).
#   --set-owner     also make storage/ and bootstrap/cache/ belong to USER:GROUP (and ug=rwX,o=), so the scheduler, the queue
#                   and the web process write the same files. Off unless asked; it touches nothing but those two folders.
```

* `--group` is checked the way `--user` is (characters, and `getent group GROUP` must exist), with exit code 2 on a bad
  value. If it is not given it is the user's primary group, so every existing call behaves as before.
* The crontab entry itself is unchanged (`crontab -u USER`); cron cannot set a group per job. The group matters for
  `--set-owner` and for `--verify`, which runs `schedule:list` as `USER:GROUP` (`runuser -u USER -g GROUP`, falling back to `su`).
* The crontab edit is wrapped in a lock (`flock /var/lock/laravel-cron.lock`), because one shared apache crontab now serves
  every tenant and two installs at once must not overwrite each other.
* For a user whose shell is `nologin` or `false`, `--install` adds `SHELL=/bin/sh` at the top of that crontab, once, instead
  of only warning. `--check` reports whether it is there.
* The entry is written as `{ cd PATH/ && PHP artisan schedule:run; } >> LOG 2>&1`, so a later-deleted tenant folder is silent.
  The matcher still finds it by `cd PATH/ &&`.

`commands.sh --update-cron-task` shrinks to one call and loses its own crontab code:

```sh
if [ "$action" = "--update-cron-task" ]; then
    systemuser="$param1"
    subpath="${param2:-www}"
    [ -z "$systemuser" ] && exit 1

    # the site directory comes from the tenant's system user; the scheduler belongs to apache:apache
    sh /home/${systemuser}/websites/${subpath}/core/scripts/laravel-cron.sh \
        --install --user apache --group apache --path "/home/${systemuser}/websites/${subpath}" --php /usr/bin/php \
        &>> "/mnt/${STORAGE_LOCATION}/$systemuser/logs/cron_task.log"
    exit 0
fi
```

It keeps the "does `artisan` exist" skip, because `laravel-cron.sh` already refuses a path with no `artisan`. The Brain goes through the
same call (`--update-cron-task appfactorystudio` or `... dev/www15`), so its entry lands in apache's crontab with the Brain's path.

### 3.2a The path passed to `--update-cron-task` is wrong on dev

`DirectPaymentsController::saveDirectPayment()` (lines 574-584) builds the second argument from the Brain's `Subdomain`, and for `www15` it sends `dev/www15`
(lines 578-579). The Brain's real folder on dev is `dev/www` (`www15.appfactory.studio` serves `.../dev/www/public`), so `/home/appfactorystudio/websites/dev/www15/artisan` does not exist
and the `commands.sh` block logs "no artisan ... skipping" and exits. **A Direct Payment save on dev therefore never installs the scheduler.** That matches the owner's
observation that the Brain's scheduler is not running. The fix is the one in plan 11 section 5.4: take the folder from `base_path()` (what `laravel-cron.sh --path` also takes) and
stop deriving it from the label.

### 3.3 The members app

Members is not a Direct Payments site, so `--update-cron-task` never installs it. Install its entry once, as root:
`laravel-cron.sh --install --user apache --group apache --path <members folder> --php /usr/bin/php`, then confirm apache's crontab has
`SHELL=/bin/sh`. The nine Brain commands of plan 09 are scheduled from there.

### 3.4 Entries that already exist under tenant users

Installed crontabs have never been inspected (the Direct Payments consolidation plan says so). On each server:

1. `laravel-cron.sh --list` (as root) shows every scheduler entry in `/var/spool/cron`.
2. For each tenant entry found, run `--update-cron-task` once so it is added to apache's crontab, then
   `laravel-cron.sh --remove --user <tenant> --path <site>` for the old one (`--group` is not needed for `--remove`). For a moment both exist; the scheduled
   commands take their own locks, so this is harmless for one minute.

## 4. What the other plans now assume

* Plan 08: the `queue:work` line runs from apache's crontab, as the same user as the web process.
* Plan 09: the Brain commands run as apache in both ways (inside the request, or detached). The user question is closed.
  The one open check stays: any SSH key or file those commands read must belong to, or be readable by, apache (a key in
  `/home/appfactorystudio/.ssh` is not), or be moved to a path apache owns.

## 5. Checks before relying on it

* `cat /etc/cron.allow /etc/cron.deny 2>/dev/null`: apache must not be listed in `cron.deny` (or must be in `cron.allow`
  if that file exists).
* `--set-owner` is opt-in and only for the two writable folders. Say if you want it to cover the whole tree instead (the installer already sets tenants to `apache.apache` 775).
* Where the home directory of `apache` is, for any key that has to live there (`getent passwd apache`).
* One tenant: `ls -ld /home/<tenant>/websites/www/storage` and that it is `apache` owned; the installer implies it.

## 6. Open

* The SSH keys and files the nine Brain commands use (plan 09 section 8).
* Whether every tenant has the installer's `apache.apache` ownership today; the older description in this thread was
  `<tenant>:apache`.
* Many tenants mean many `php artisan schedule:run` processes starting in the same second each minute from one crontab.
  This is the same load as before (one per tenant); it is only worth staggering if it ever shows up as a spike.

## Done 2026-10-15

Committed in `w/www`, nothing pushed, nothing run on any server.

* **`core/scripts/laravel-cron.sh`:** `--group GROUP` (checked like `--user`; default the user's primary group, so a call without it behaves as before; exit 2 on a bad or missing group); `--set-owner`
  (with `--install`: `chown -R USER:GROUP` and `chmod -R ug=rwX,o=` on `storage/` and `bootstrap/cache/` only, off unless asked); the crontab read, change and write of `--install` and `--remove` under
  `flock -w 30 /var/lock/laravel-cron.lock` (a warning and no lock when `flock` is missing); for a user whose shell is `nologin` or `false`, `--install` adds `SHELL=/bin/sh` at the top once and `--check`
  reports it (exit 1 when it is missing); the entry is written as `{ cd PATH/ && PHP artisan schedule:run; } >> LOG 2>&1`; entries written the older way (bare `php`, no braces) are still recognised, so there is no duplicate;
  `--verify` runs `schedule:list` as `USER:GROUP` through `runuser -u USER -g GROUP`, falling back to `su`. The lock file, spool and temp folders can be set by environment variables (used by the tests).
* **`core/scripts/commands.sh --update-cron-task`:** its own crontab editor is gone; after the "is there an `artisan`" skip it runs
  `sh <site>/core/scripts/laravel-cron.sh --install --user apache --group apache --path <site> --php /usr/bin/php` and logs the exit code. `members` has its own `commands.sh` without this action, so nothing changes there.
* **Tests** (`tests/Unit/Scripts/LaravelCronScriptTest.php`, 13, they run the real script with stand-ins on `PATH` for `id`, `getent`, `crontab`, `flock`, `chown`, `chmod`, `runuser`; removing the nologin
  detection fails 5 of them): install adds SHELL, comment and entry under the lock; a second install changes nothing; two apps share one crontab with one SHELL line; an old-style entry prevents a duplicate;
  `--check` and the missing SHELL line; a real-shell user gets none; the group default and refusal of a bad group; `--set-owner` touches only the two folders and only when asked; `--remove` keeps the other entries and
  the SHELL line; `--dry-run`; `--verify`; the help text; and that the `commands.sh` block has no crontab code and installs for apache.
* **Not done here:** 3.2a (the `dev/www15` path passed to `--update-cron-task` by `DirectPaymentsController::saveDirectPayment()`; plan 11 section 5.4 replaces those literals with one helper based on `base_path()`, so it is
  done there); 3.3 (install the members entry once, as root); 3.4 (list the existing tenant entries and move them to apache's crontab); section 5 checks on each server (`cron.allow` / `cron.deny`, apache's home directory,
  a tenant's `storage` ownership). All of them need a server and your word.
* **Not verified:** the script on a real CentOS host with real `crontab -u`, `flock` and `runuser`; the tests use stand-ins.

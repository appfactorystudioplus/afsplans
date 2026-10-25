# 10-run-as-apache — completion record

**Plan file:** [`../10-run-as-apache.md`](../10-run-as-apache.md)
**Repo:** `w/www`
**Date:** 2026-10-15
**Status:** 🟡 The repo changes are complete; nothing is done on any server. Nothing is pushed.

## What shipped

* `laravel-cron.sh` is the one scheduler installer: `--group`, `--set-owner`, a lock on the shared crontab, `SHELL=/bin/sh` added for a nologin user (and reported by `--check`), a braced entry that stays silent when the folder is gone, old-style entries still recognised, `--verify` as `USER:GROUP`.
* `commands.sh --update-cron-task` no longer edits a crontab; it calls `laravel-cron.sh --install --user apache --group apache` for the site.

## How it was checked

* `LaravelCronScriptTest` (13 tests) runs the real script against stand-ins for the root-only and host commands; breaking the nologin detection fails 5 of them. `sh -n` on `laravel-cron.sh` passes.

## Not verified

* The script on a real host (real `crontab -u`, `flock`, `runuser`); `commands.sh` itself (bash 4 only; the Mac's bash 3.2 cannot parse it, so only the block's text is tested).

## Residue (all need a server and your word)

* Install the members entry once, as root; list and move the existing tenant entries (3.3, 3.4); the section 5 checks; the wrong `dev/www15` path (plan 11 section 5.4).

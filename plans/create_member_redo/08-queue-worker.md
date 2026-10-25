# 08 — Running the delayed mail job: one scheduled line

Status: the scheduled line is DONE 2026-10-15 in `w/www/routes/console.php`; installing the scheduler on the servers is not done and waits for your word (section 3). See "Done 2026-10-15" at the end.

Needed by [07-direct-payment-email.md](07-direct-payment-email.md) §2.1: `ChargeSucceeded::handle()` queues
`SendDirectPaymentMail` with a delay (30 seconds proposed). Something has to run queued jobs when they come due.

## 1. Use the scheduler that already exists

`core/scripts/laravel-cron.sh` installs the one crontab entry Laravel needs. Under [10-run-as-apache.md](10-run-as-apache.md) the user is always `apache`:

```
* * * * * cd /home/USER/websites/www/ && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Every minute cron runs `schedule:run`, and Laravel runs whatever `routes/console.php` says is due
(`direct_payments:subscription-notify`, `ecommerce:expire-holds`, `domains:*`, ...). So the queue is run the same way:
add one scheduled command to `routes/console.php`, in the style of the existing ones.

```php
/**
 * Runs the queued jobs that are due (the delayed Direct Payments mail, ExecutePendingTransfersJob, ...).
 * Processes for up to 55 seconds, then stops, so the next minute's run starts clean.
 */
Schedule::command('queue:work --stop-when-empty --max-time=55')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground();
```

No systemd unit, no second cron entry, no worker process to keep alive.

## 2. How it behaves

* A payment is recorded and `ChargeSucceeded::handle()` inserts a job row with `available_at = now + 30 s`.
* Within the next minute `schedule:run` starts `queue:work`, which runs every job whose time has come and exits.
* The mail therefore goes out between 30 and about 90 seconds after the charge (the delay, plus waiting for the next
  minute). It is not a precise delay; it is "after other actions have had time to finish".
* `queue:work` with no `--queue` listens to the connection's own default queue (`DB_QUEUE` in each server's `.env`),
  so no queue name has to be written anywhere and it works on every server whatever its `.env` says.
* A job that throws goes to the failed-jobs table; plan 07's job runs once (`tries = 1`), so it is never sent twice.

## 3. What has to be true on each server

1. The scheduler entry above is installed in apache's crontab (plan 10: everything runs as apache). `laravel-cron.sh --check --user apache --group apache --path APP_DIR`
   reports whether it is, and `--install` adds it (this edits a crontab as root, so it is done only after you say so).
2. `QUEUE_CONNECTION=database` in that server's `.env` (with `sync`, a delay is ignored and the job runs at once).

That is the whole requirement. **Status (owner):** the scheduler entry is not installed yet, because nothing needs it until
this feature is built. Installing it (`laravel-cron.sh --install --user apache --group apache --path APP_DIR`, as root, on each server; on a tenant `commands.sh --update-cron-task` does this on a Direct Payments save)
is a step of the rollout of plan 07, not something done now. Until it is installed, the scheduled commands in
`routes/console.php` do not run on that server either.

## 4. What this replaces

The earlier version of this plan (a systemd service with its own unit file, queue names, restart settings and a
monitor) is dropped. It was only needed if the scheduler could not be used.

## 5. Open

* Install the scheduler entry on each server as part of the rollout of plan 07 (not before).
* DECIDED: a delay of 30 to about 90 seconds is acceptable.

## Done 2026-10-15

Committed in `w/www`, nothing pushed, nothing installed on any server.

* **Added** to `routes/console.php`: `Schedule::command('queue:work --stop-when-empty --max-time=55')->everyMinute()->withoutOverlapping(2)->runInBackground()`, exactly the line in section 1 (no `--queue`).
* **Tests** (`tests/Feature/DirectPayments/QueueWorkerScheduleTest.php`, both pass on a throwaway local MySQL, with the Direct Payments and Unit suites): the schedule holds exactly one `queue:work` event, every
  minute, without overlapping, in the background, without `--queue`; and a job delayed 30 seconds stays in the queue table when `queue:work --stop-when-empty` runs before it is due, then runs and leaves the table (and
  `failed_jobs`) empty when the clock is past it. That test is the "run the job through `queue:work` from the console" test plan 07 asked for, using `SendDirectPaymentMail` with an invoice that no longer exists,
  so it proves the queueing and the worker, not a mail send.
* **Not done (needs you):** installing the scheduler entry in apache's crontab on each server (`laravel-cron.sh --install --user apache --group apache --path APP_DIR`, or `commands.sh --update-cron-task` on a tenant),
  and checking `QUEUE_CONNECTION=database` in each server's `.env` (this repo's `.env` already has it, with table `laravel_queue_jobs` and queue `laravel_queue_default`). Until then the delayed mail does not go out
  on that server, and neither do the other scheduled commands.
* **Not verified:** a real delivery on a server through cron; the 30 to about 90 second timing in practice.

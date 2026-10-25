# 08-queue-worker — completion record

**Plan file:** [`../08-queue-worker.md`](../08-queue-worker.md)
**Repo:** `w/www`
**Date:** 2026-10-15
**Status:** 🟡 Complete in code; not installed on any server. Nothing is pushed.

## What shipped

* One scheduled line in `routes/console.php`: `queue:work --stop-when-empty --max-time=55`, every minute, without overlapping, in the background, on the connection's default queue.

## How it was checked

* `QueueWorkerScheduleTest`: the schedule entry's expression and flags; a delayed `SendDirectPaymentMail` stays in `laravel_queue_jobs` until due, then `queue:work` runs it from the console and the table and `failed_jobs` are empty.
* Direct Payments and Unit suites pass on a throwaway local MySQL.

## Not verified

* The scheduler entry in apache's crontab on any server, and the delay in practice (30 to about 90 seconds).

## Residue

* Install the entry on each server as part of the rollout of plan 07 (needs your word); confirm `QUEUE_CONNECTION=database` there.

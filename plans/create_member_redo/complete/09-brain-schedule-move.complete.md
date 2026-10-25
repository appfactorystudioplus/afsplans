# 09-brain-schedule-move — completion record

**Plan file:** [`../09-brain-schedule-move.md`](../09-brain-schedule-move.md)
**Repos:** `w/www`, `w/members`
**Date:** 2026-10-15
**Status:** 🟡 Complete in code; not installed, so none of the nine commands runs yet. Nothing is pushed.

## What shipped

* www: `POST /api/internal/brain/commands/{name}` (`BrainCommandsController`): an allowlist of the nine commands, a lock for the eight that run in the request, a detached start for `domains:deploys`; signed with the MEMBERS key, Brain only.
* members: `brain:run {name}` (`BrainRun`) and nine schedule lines with the old frequencies, each running in the background.
* www's `routes/console.php` no longer schedules the nine; it keeps `queue:work`, `direct_payments:subscription-notify` and `ecommerce:expire-holds`.

## Deviations from the plan text

* A protected `spawn()` seam for the detached start, replaced in tests so no process is started.
* The members lines do not carry `onOneServer()` (the old www ones did).

## How it was checked

* www `BrainCommandsEndpointTest` (9 tests) plus `BrainStorageHealthTest`, `BrainSurfaceEnforcementTest` and the queue schedule test; members `BrainRunTest` (6 tests) and the existing enforcement trigger test, run with www's phpunit on a throwaway local database.

## Not verified

* A real call from members to the Brain; the CLI php binary on the Brain; apache's access to what the commands use; the commands running on a schedule.

## Residue

* Install the members scheduler and stage the first runs (needs your word); confirm which `domains:*` commands are wanted.

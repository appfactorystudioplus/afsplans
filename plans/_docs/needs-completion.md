
Here are the steps that are not complete. The plans folder has 30+ plans, and I could not read every one. For anything outside this session's plans, "not complete" means a numbered step file has no completion doc, and some of those may be done without one. I skipped overview and audit files, since those are documents rather than work.

## Plans from this session
**`account_close`: nothing is built**
1. Pre-flight checks (P0).
2. Admin soft delete and the engine skeleton (P1).
3. Hard delete and the purge (P2).
4. Member self-service closure (P3).
5. Notifications and the scheduled run (P4).
6. Confirm `members` has a working `schedule:run` cron.
7. Build the `members`→`www` signed channel and the new credentials.

**`www_brain`: nothing is built**
1. Pre-flight checks (P0).
2. App registry and manifest, plus the `brain.only` and `brain.app` guards (P1).
3. Settings, with `notes` as the pilot (P2).
4. Mail handlers per app (P3).
5. Roll out to the remaining apps (P4).
6. More controls, only where you name a need (P5).
7. Implement `IS_BRAIN` and `config/brain.php`. Set it in the four Brain `.env` sets and leave it out of `_env/www/.env`.
8. Replace `BrainGuard` and the `NotificationDispatcher` domain check with `IS_BRAIN`.

**`billing`: the enforcement move to `members` is not built** (08 §8.12)
1. Confirm the `members` cron is running.
2. Build the shared `members`→`www` channel and the applier route.
3. Add the `members` command and evaluator, and compare its dry-run with `www`'s over one full billing cycle.
4. Switch over, and remove the `www` schedule entry in the same change.
5. Delete the old `www` command and `BrainGuard`.
6. `11-rollout-and-testing` has no completion doc.

**`www/direct_payments`**
1. Dunning as a Direct Payments feature is not started. It is a TODO entry, with no plan document yet.
2. Optional class renames were skipped by decision.
3. Remaining checks: `schedule:list` was verified in the lab, but installed server crontabs and queued jobs holding old names have not been inspected.

**Loose ends from this session's code**
1. Everything is uncommitted, with the renames staged and other edits unstaged, mixed with earlier uncommitted `www` work.
2. The `afsemail.ts` variables change type-checks, but the Vite bundle build was never confirmed.
3. No scheduler entries exist in the lab, so scheduled commands don't run there.
4. Market's unauthenticated Brain endpoints (`directpayments/invoice`, `create-intent`) were flagged but never confirmed or fixed.

## Other plans with no completion doc for a step
- `afs-connect-desktop/account-and-cloud-sync`: steps 01–05.
- `afs-connect-www`: 02, 03, 05, 06.
- `alloc-v2-parse-direct`: 02, 03, 04, 05.
- `checkpoint-sso-session`: 03, 04, 05.
- `checkpoint-server-auth`: 12 (game engine client). This is marked not started in `TODOS.md`.
- `cloud-storage`: 01 and 13.
- `market`: 19 (end-to-end verification) and 20 (retire legacy Stripe scripts).
- `members_brain`: 08 (rollout).
- `membership_change`: 02, 04, 05, and the feature files `23-voip_numbers_count` and `3-domain_email_sends_monthly`.
- `brain_membership_identity`: 04 (rollout).
- `video_communications`: 07 (rollout).
- `www/ecommerce`: 10 (storefront and SDK) and 11 (admin dashboard).
- `www/builder/v1`: overview and audit only.
- `www/connections`, `www/forms`, `www/site_users`: overview and audit only.

Within plans that are marked complete, deferred items are listed in `plans/TODOS.md`. I did not re-audit those individually.



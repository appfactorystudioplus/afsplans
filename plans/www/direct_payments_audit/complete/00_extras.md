# Extras — work outside the numbered steps

## E1 — 2026-10-17 — Cross-check against `create_member_redo`, and a member-with-membership test

* `create_member_redo` plans 01 (payer per website), 04 (charge hook), 05 (webhook registration) and 07 (receipt e-mail) all touch what these steps changed. The existing tests for each (`PaymentGapsTest`, `ChargeSucceededHookTest`, `ProcessorWebhookRegistrationTest` after the `&p=` URL change, `DirectPaymentMailTest` after the receipt rule) pass. Plan 00's "Cleanup item — appfactory concepts inside Direct Payments code" table is now fully done (steps 09, 13, 14).
* New `tests/Feature/Membership/MemberWithMembershipSignupTest` (4): a paid signup by the subscription reference, by a provider subscription id, a replayed handle (lands free), and a free signup. It stops at the legacy storage-provisioning step (it needs a real database connection), so it asserts the member row, tier and payer, not the HTTP answer.
* `startSubscription` now also returns `subscription_reference` so a signup can use the stable handle.

## E2 — 2026-10-17 — Renewal page pre-fill

The hosted renewal page (`/portal/dp/renew/{token}`) now pre-fills the subscriber's name and e-mail in Stripe's payment element (the info endpoint returns `payer: {name, email}`). Wallets first on Square remains undone. Not tried in a browser.

## E3 — 2026-10-17 — Test and code fixes found by the whole-suite run

`SaveInvoiceRecordsPayerTest` and `DirectPaymentSubscriptionUpdateMembershipTest` built hand-made table subsets that no longer match what the code reads; they now use the real create migrations. `BrainStaffSubscriptionController` no longer reads the removed `$event->warning` (it checks the member's tier itself and reports `membership_tier_write_failed`).

## E4 — 2026-10-17 — Server access tooling (`packages/afsserver/bin`)

`remote-run.sh` can log in with a passphrase-protected key through the Windows ssh-agent, falls back to the `sftp.json` password through `_ssh_askpass.sh` when `expect` is absent (Windows), and is unchanged on macOS (which has `expect`). `_ssh_askpass.sh` must keep its execute bit (`git update-index --chmod=+x`).

## E5 — 2026-10-17 — Clean-database import check and the Direct Payments rows in `testdb_appfactorystudio.data-only.sql`

Rebuilt a local MariaDB database from nothing (`php artisan migrate` in `www` and in `auth` — auth's `checkpoint_*` tables live in the main database, set through `MAIN_DB_*`), then loaded `core/config/testdb_appfactorystudio.data-only.sql` exactly the way `build_sites2.sh` does (`mysql --force`, one pass): **0 refused statements** (before the auth migration: the 3 `checkpoint_*` inserts failed). `build_sites2.sh` itself was not run; it only runs on the server.

The imported Direct Payments rows were old-shaped, so a block was appended to the SQL file (marked, every statement only fills an empty value): tiers get their canonical price (amount, currency, interval) from their active plan; tier bindings get `processor_plan_id`; Direct Payments and processors get `currency = usd`; each existing subscription gets its contract's first leg and price snapshot (the result is identical to what `SubscriptionContracts::ensureLeg` produces — checked). The processors' encrypted secrets decrypt with the `APP_KEY` shared by every `_env` profile.

Checked against that imported database through the real HTTP kernel (Stripe is not called): `checkout-config` and `capabilities` answer for `vfPSdk2xj`; `priceFor` resolves all four tiers; saving a signup invoice stamps the processor, `usd` and a new payer; `memberships/register` with that invoice and the imported subscription's reference creates the member with the paid tier (the posted tier is ignored), the invoice's payer, and the subscription claimed by that payer.

One signup bug fixed on the way: a free signup (or `testing`) with no `mail_handler_reference_num` posted threw a TypeError in the welcome-email test block AFTER the member row was written, so the visitor saw a 500 and a retry said the username was taken. The block itself is untouched (owner's instruction); `store()` now logs and carries on if it fails.

Not done: the SDK still stores and posts the provider subscription id (the server resolves it to the same subscription); the dev server was not touched.

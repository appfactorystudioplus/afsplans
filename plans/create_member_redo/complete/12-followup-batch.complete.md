# Follow-up batch: payment gaps, Name.com by environment, scheduled commands, install hardening

**Repos:** `w/www`, `w/members`
**Date:** 2026-10-15
**Status:** 🟡 Code complete and tested locally; nothing pushed, nothing run on a server, nothing committed.

## What shipped

### Name.com API by environment (members)
* `AppEnvironment::nameComApi()` picks `api.dev.name.com` or `api.name.com` from `VITE_SUBDOMAIN` (the `.env`); the three Name.com calls in `DomainController` use it. Dev never touches the real API.

### Payment system (www)
* Charge hook: one mail per charge (lock + `mail_queued_at`), `direct_payments:repair-charge-hooks` every 5 minutes for charges that missed the hook.
* Subscriptions: `SubscriptionInvoiceLinker` ties recurring invoices (Stripe, Square, PayPal) to the subscription's checkout invoice and adopts invoices recorded before the link existed.
* Webhooks: Square (`payment.updated`, `invoice.payment_made`, refunds, disputes, orders), PayPal (`PAYMENT.SALE.COMPLETED`, sale refunds/reversals, order approved/processed, denied/declined/pending, subscription expired/re-activated), Stripe redelivery.
* Square card payments: new `POST /portal/api/v1/processors/square/create-payment`.
* Closed hole: `updateInvoiceStatus` no longer lets the browser mark an invoice paid; it needs a recorded charge or the provider's confirmation.
* Tests: `PaymentGapsTest` (23).

### Scheduled commands (www + members)
* Only `brain:storage-prune` and `brain:storage-mark-stale` run through the Brain; the seven `domains:*` commands are not scheduled and are refused (404). Reason: they act on one server's domain files and the old install flow does not use them.

### Install hardening (members `core/scripts`)
* New `remote/builder/build_checks.sh`; `app_install.sh` runs preflight (tools, disk, MySQL root, Git), checks every step's exit code and output, tests Apache/nginx configs before reload (old files restored on failure), asks the site to answer before `complete`, leaves a `.afs-build-done` marker, makes `www-own` and the user/database/`.env` steps re-runnable.
* Secrets: root password in a 0600 options file (never a command line), no credential echoes, git push credentials via environment, job file removed and old job folders swept on exit; `commands.sh` drops the DNS password echo, makes the job folder 0700, deletes it after step 3.
* Brain facts come from the Brain's `subdomain_properties` (V5): system user, domain, subdomain, `api_url`, name servers; tenant `Username` is the member reference, `Domain2` is `{sub}.sites.{BrainDomain}`.
* Progress: each build has its own token (hash stored, wrong token gets 403); the script retries transient failures three times; a build silent for 90 minutes (`progress.stale_minutes`) shows as failed.
* Tests: `BuildChecksShellTest` (10), `ProgressTokenTest` (6), `DomainBrainSurfaceTest` (17), `BrainRunTest` (5).

## Not verified
* Everything that needs a server, provider or browser: `app_install.sh` and `commands.sh` end to end (they were only syntax-checked, on bash 3.2 after converting `&>>`), real webhooks from PayPal/Square/Stripe, Name.com dev calls, the progress bar.

## Residue (needs your word)
* Install the Brain crontabs, rebuild the dev database, do a real dev domain run and purchase, register webhooks with the providers' sandboxes, send a real mail.

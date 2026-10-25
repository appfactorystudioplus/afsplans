# billing — Planning Index

> **Split 2026-09-22.** This folder used to hold two products at once: the generic Direct
> Payments subscription lifecycle (notifications, cron, money, subscription actions — every
> tenant's own feature) and appfactory studio's own billing enforcement (`brain_memberships`).
> The generic half moved to
> [`../www/direct_payments/`](../www/direct_payments/README.md) (steps 20–29 there, plus its
> own rollout doc) — every tenant's website shares that infrastructure, so it belongs beside
> the other tenant-facing module plans, not inside appfactory studio's own billing folder.
> **What's left here is exactly the part that reads or writes `brain_memberships.membership`,
> or that resolves a member's tier through the Brain regardless of which deployment is
> running:** the Brain-only enforcement cron, the entitlement endpoint, the members
> dashboard, the per-domain processor/DP allocation limits, and the still-unbuilt fix for
> self-service tier changes. See the step table below for exactly what stayed and what moved.

This is now specifically about **appfactory studio charging its own members** — a Brain-only
scheduled task and the database rows it needs, plus the members-facing dashboard and the
entitlement plumbing that makes the tier it writes actually mean something elsewhere in the
ecosystem.

**Brain billing enforcement.** A scheduled task, **Brain-only**
(`www.appfactory.studio` / `www15.appfactory.studio`, nowhere else), that connects billing
to the rest of the ecosystem: when a member stops paying, it walks a dunning ladder and
finally writes `brain_memberships.membership` down. That single column is what
`alloc_v2`, cloud-storage's `QuotaService::planFor()` and afs-connect's entitlement layers
already read — so making it *correct* is the whole integration, and it closes the gap
`StorageRetentionSweep`'s own docblock names ("*a billing hook on membership change would
be the primary trigger … but no such hook exists in this app to attach to*"). It rides the
same crontab entry that [`direct_payments`'s subscription-notify task](../www/direct_payments/21-cron-bootstrap.md)
installs — see [01-architecture.md](01-architecture.md) for how the two share one `schedule:run`.

> ### ⚠ Four things are broken today that this plan must fix before anything else works
>
> 1. **`laravel_processor_subscriptions` has no link to a member or a Direct Payment**, and
>    `current_period_end` is populated only by a webhook that may never have fired. A
>    notification configured on a Direct Payment has, today, no query that reaches its own
>    subscriptions. See [20-current-state-audit.md](../www/direct_payments/20-current-state-audit.md) §2.1.
> 2. **`members`' `BillingApp.ts` reads `member.next_charge_date` and
>    `member.current_period_end`** — neither column exists on `brain_memberships`, so the
>    "next charge" line has always rendered blank. §2.5.
>
> 3. **`BillingApp.cancelSubscription()` makes no network call.** It writes to
>    `localStorage` and tells the member they cancelled while the provider keeps charging
>    them. See [28-subscription-actions.md](../www/direct_payments/28-subscription-actions.md) §14.1 (**B27**) —
>    **fix this before anything else in this plan.**
>
> 4. **Signup writes whatever tier the client claims.** `MembershipController::store()` takes
>    `membership` straight from the request body, sourced from `sessionStorage`, with no
>    check against the subscription paid for — and the free path never reaches a payment.
>    See §2.5d (**B38**) and [22](../www/direct_payments/22-subscription-state.md) §4.2.5.
>
> Step [22](../www/direct_payments/22-subscription-state.md) (in `direct_payments` now)
> supplies the data the first two need — the link table and a normalised `next_charge_at`.
> Step [10](10-members-dashboard.md) is where the dashboard starts reading it.

Read in this order:

| Step | Document | Contents |
|---|----------|----------|
| — | [README.md](README.md) | This index |
| 00 | [00-overview.md](00-overview.md) | Problem statement, goals, non-goals, glossary, the eighteen decisions |
| 01 | [01-architecture.md](01-architecture.md) | The two tasks, where each lives (subscription-notify in `www`, enforcement in `members`), push-vs-pull, fail-closed rules |
| 08 | [08-brain-enforcement.md](08-brain-enforcement.md) | `billing:enforce-membership` — scheduled in `members`, run on the Brain over a signed call: the three kill switches, the dunning ladder, the downgrade write |
| 09 | [09-entitlement-integration.md](09-entitlement-integration.md) | How the downgrade reaches alloc_v2, afs-connect and cloud-storage — and the server-side control mechanism that does not exist yet |
| 10 | [10-members-dashboard.md](10-members-dashboard.md) | **The members billing dashboard** — `BillingApp.ts` end to end: real subscription reads, plan change, cancel, cards-only payment methods, billing history with refunds, dunning banner |
| 11 | [11-rollout-and-testing.md](11-rollout-and-testing.md) | The appfactory-specific phases (P0, P6, P7, P8), the sandbox test matrix, what to verify on the first live tenant. Prerequisite generic phases now live in [`direct_payments/30`](../www/direct_payments/30-subscription-notify-rollout-and-testing.md) |
| 16 | [16-allocation.md](16-allocation.md) | **alloc_v2 limits** — how many processors and Direct Payments a member's tier allows, sourced from the member's `brain_memberships` tier fetched via the Brain API regardless of which deployment runs the check, and the three call sites each needs |
| 17 | [17-self-service-tier-changes.md](17-self-service-tier-changes.md) | **§17.3 implemented 2026-09-22** (see [complete/17](complete/17-self-service-tier-changes.complete.md)) — a member's self-service upgrade/downgrade through the dashboard now writes `brain_memberships.membership` synchronously. Found while tracing whether [../membership_change/09](../membership_change/09-billing-tier-change-hook.md)'s hook would even have anything to react to; that hook (fast notification) and [../membership_change/10](../membership_change/10-preview-endpoint.md) (the pre-confirm preview) are still unbuilt |

**Moved to [`../www/direct_payments/`](../www/direct_payments/README.md)** — generic Direct
Payments infrastructure every tenant's website shares, not specific to appfactory studio's
own billing:

| Was here | Now | Contents |
|---|---|---|
| 02 | [`direct_payments/20`](../www/direct_payments/20-current-state-audit.md) | What exists today across `www`, `members`, the external SDK, the public signup, and the 41 defects this plan must work around or fix |
| 03 | [`direct_payments/21`](../www/direct_payments/21-cron-bootstrap.md) | `commands.sh --update-cron-task`, the idempotent crontab edit, and the `saveDirectPayment` hook that calls it |
| 04 | [`direct_payments/22`](../www/direct_payments/22-subscription-state.md) | Schema changes + the provider sync service that puts a trustworthy `next_charge_at` in the database |
| 05 | [`direct_payments/23`](../www/direct_payments/23-notification-config.md) | The Direct Payments notification/event configuration — tables, validation, admin API |
| 06 | [`direct_payments/24`](../www/direct_payments/24-notification-scheduler.md) | `direct_payments:subscription-notify` — the 15-minute task, its dedupe ledger, and the mail-handler send path |
| 07 | [`direct_payments/25`](../www/direct_payments/25-direct-payments-api.md) | The external SDK surface: `dp.notifications`, `dp.subscription`, and the events an integrator can hook |
| 12 | [`direct_payments/26`](../www/direct_payments/26-advanced-policy.md) | **Direct Payments Advanced** — per-DP policy (starting with the non-payment grace period), Brain-editable today, decoupled by construction |
| 13 | [`direct_payments/27`](../www/direct_payments/27-provider-retry-policy.md) | What Stripe, PayPal and Square each do about a failed subscription charge, and the per-provider configuration objects for the one that lets you change it |
| 14 | [`direct_payments/28`](../www/direct_payments/28-subscription-actions.md) | **Cancel, update, change payment method** — encapsulated in the Direct Payments API, cards only, each with its own configurable email |
| 15 | [`direct_payments/29`](../www/direct_payments/29-money-and-refunds.md) | **Financials → Money** — one ledger for every money movement, refunds for single/subscription/Connect charges, and an aggregate-only projection into afs-connect |
| 11 (generic phases) | [`direct_payments/30`](../www/direct_payments/30-subscription-notify-rollout-and-testing.md) | Phases P1a–P6f and their test matrix |

Internal `§N.x` section numbers inside each moved document are unchanged from its old step
number (e.g. `direct_payments/22-subscription-state.md` still uses `§4.x` throughout) — only
the filename, folder and the step number used to *link* to it changed.


## Completion documents

Each numbered document above gets a completion document in [`complete/`](complete/) when
its work lands. Name it with the source basename plus `.complete.md` — for example
`04-subscription-state.complete.md`. Include status, summary, files changed, verification
steps, and follow-up work.

## Code documentation standard

Document all new and changed code thoroughly using block comments — PHPDoc for PHP, JSDoc
for TypeScript, `#` block headers for shell. Every file, exported symbol, public function,
non-obvious private helper, route handler, migration and non-trivial type must explain
purpose, parameters, return values, side effects, and plan references. Inline `//`
comments are for brief local clarification only — they do not replace block documentation.

New and changed code carries the `AFS_BILLING_MODIFIER_ADDITION` marker (see
[../markers.md](../markers.md)) followed by the plan reference, e.g.
`AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/24-notification-scheduler.md §6.4`.

### Markers coexist — never replace one

Most of the files this plan edits already carry a marker from an earlier effort. **Add
this plan's marker alongside the existing one. Do not replace it, and do not reword the
comment it belongs to.** Those comments record why the earlier code is shaped the way it
is; billing's changes are additive to that history, not a supersession of it.

Both markers go on the same block, each with its own plan reference:

```php
/**
 * MARKET_MODIFIER_ADDITION — plan step 08 §08.5.7.
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/22-subscription-state.md §4.6.
 *
 * <the existing explanation, unchanged, then what billing changed and why>
 */
```

Oldest marker first, so the block reads in the order it was built.

When billing adds a wholly new block to a file that already has markers elsewhere, that
new block carries **only** `AFS_BILLING_MODIFIER_ADDITION` — the coexistence rule is about
blocks both efforts genuinely touch, not about the file as a whole.

Files this plan edits that already carry a marker:

| File | Existing | Billing touches it for |
|---|---|---|
| `www/app/Http/Controllers/Externals/DirectPayments/DirectPaymentsController.php` | `MARKET_MODIFIER_ADDITION` | Cron hook ([21](../www/direct_payments/21-cron-bootstrap.md) §3.1), `syncNotificationConfigs()` ([23](../www/direct_payments/23-notification-config.md) §5.6) |
| `www/app/Http/Controllers/Externals/Processor/ProcessorWebhookController.php` | `MARKET_MODIFIER_ADDITION` | Normalised subscription writes ([22](../www/direct_payments/22-subscription-state.md) §4.6), event dispatch ([24](../www/direct_payments/24-notification-scheduler.md) §6.7) |
| `www/app/Http/Controllers/FunctionsController.php` | `MARKET_MODIFIER_ADDITION` | The **B9**/**B10** mail-path fixes ([24](../www/direct_payments/24-notification-scheduler.md) §6.6) |
| `www/routes/portal.php` | `MARKET_MODIFIER_ADDITION` | Notification, mail-handler-list and entitlement routes |
| `www/resources/js/portal/admin/controllers/direct_payments/directpayments.ts` | `MARKET_MODIFIER_ADDITION` | Notifications card wiring ([23](../www/direct_payments/23-notification-config.md) §5.7); the `#dpAdvancedSection` card and its `level == 99` visibility gate ([26](../www/direct_payments/26-advanced-policy.md) §12.7) |
| `www/resources/js/portal/admin/html/views/directpayments-form.html` | `MARKET_MODIFIER_ADDITION` | The `#dpNotificationsSection` ([23](../www/direct_payments/23-notification-config.md) §5.7) and `#dpAdvancedSection` ([26](../www/direct_payments/26-advanced-policy.md) §12.7) cards |
| `www/resources/js/portal/admin/controllers/processors/ProcessorDetailView.ts` | `MARKET_MODIFIER_ADDITION` | The provider retry-policy panel ([27](../www/direct_payments/27-provider-retry-policy.md) §13.6) |
| `www/resources/js/external/direct_payments/api.ts` | `MARKET_MODIFIER_ADDITION` | `dp.notifications`, `dp.subscription`, events ([25](../www/direct_payments/25-direct-payments-api.md)) |
| `www/resources/js/external/direct_payments/README.md` | `MARKET_MODIFIER_ADDITION` | Subscription-lifecycle section ([25](../www/direct_payments/25-direct-payments-api.md) §7.5) |
| `www/resources/js/external/processors/PROCESSOR_DOCUMENTATION.md` | `MARKET_MODIFIER_ADDITION` | Normalised-field section ([25](../www/direct_payments/25-direct-payments-api.md) §7.5) |
| `www/resources/js/external/portal.d.ts` | `MARKET_MODIFIER_ADDITION` | Type declarations — the **only** thing a consumer links to; copied per app, never imported from source ([25](../www/direct_payments/25-direct-payments-api.md) §7.5) |

Files with **no** existing marker — `commands.sh`, `routes/console.php`,
`ProcessorSubscriptionController.php`, `ProcessorSubscription.php`,
`MailHandlerController.php`, `BillingApp.ts`, and everything new — take
`AFS_BILLING_MODIFIER_ADDITION` on its own.

## One-paragraph summary

Subscriptions are created and charged today and then forgotten: nothing tells a member a
charge is coming, nothing notices when one fails, and nothing changes what the member can
*do* when they stop paying. This plan installs one crontab line
(`* * * * * cd /home/{systemuser}/websites/{subpath}/ && php artisan schedule:run`) the
first time an operator saves a Direct Payments configuration — written idempotently by a
new `--update-cron-task` block in `core/scripts/commands.sh` — and hangs the scheduled
tasks off it (the crontab line serves `www`'s `direct_payments:subscription-notify`; enforcement is scheduled separately in `members`, which needs its own `schedule:run` cron). The first, **`direct_payments:subscription-notify`**, runs every fifteen minutes,
does **nothing** unless a `laravel_direct_payment_notifications` row says otherwise,
refreshes each live subscription's normalised `next_charge_at` from Stripe/Square/PayPal,
and emails the member through the operator-chosen `laravel_mail_handlers` row at up to
three configured day-offsets (1–7 days) before that charge — each send written to a
uniqueness-constrained ledger keyed on
`(config, subscription, period_end, days_before, recipient)` so a 15-minute tick can never
become 96 emails. The second,
**`billing:enforce-membership`**, which is scheduled in `members` and triggers the enforcement on `www` over a signed call, walks a member
whose payment failed through a 14-day dunning grace with its own notification ladder, and
then writes `brain_memberships.membership` down — one column, which is the *only* write the
rest of the ecosystem needs, because cloud-storage's retention sweep, afs-connect's
entitlement layers and `alloc_v2`'s tier index all already read it. What that write cannot
do is stop a forged client-side tier, so step 09 adds the Brain-side entitlement endpoint
and audit log that give apps a server-derived answer to "what tier is this member, really".

Two supporting steps make the ladder configurable rather than hardcoded.
[26](../www/direct_payments/26-advanced-policy.md) puts the grace period and its reminders in
`laravel_direct_payment_advanced` — a **Direct Payments** table with no knowledge of the
Brain, edited from the ordinary Direct Payments form behind a single `level == 99` gate, so
opening it to everyone later costs three deletions and no migration.
[27](../www/direct_payments/27-provider-retry-policy.md) supplies the number that grace period has to be read
against: Stripe retries for a configurable 1–8 weeks but only from its Dashboard, PayPal
retries on a 5-day cadence up to a per-plan threshold this application can actually PATCH,
and Square retries on days 3, 6 and 9 and cannot be configured at all — so each provider
gets a configuration object shaped like its own API, including an empty one.

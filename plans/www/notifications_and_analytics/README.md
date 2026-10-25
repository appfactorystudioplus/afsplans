# notifications_and_analytics — Planning Index

> **Status (2026-10-19): planned; steps 02 (the target model), 03 (the admin inbox), 04 (the dispatcher extraction — Direct Payments now runs on the general system) 05 (analytics), 06 (the ActivityPublisher fan-out and the one `payment_succeeded`), 07 (the per-event switches editor) and 08 (the data lifecycle: `data:prune`, shipped disabled, and the quarterly analytics report) are built, see [`complete/02-target-model.complete.md`](complete/02-target-model.complete.md), [`complete/03-admin-inbox.complete.md`](complete/03-admin-inbox.complete.md), [`complete/04-dispatcher-extraction.complete.md`](complete/04-dispatcher-extraction.complete.md) and [`complete/05-analytics.complete.md`](complete/05-analytics.complete.md); step 09 (the rollout and testing gates) is recorded in [`complete/09-rollout-and-testing.complete.md`](complete/09-rollout-and-testing.complete.md); and **step 11 (module adoption) is built for all seven modules, with the payment façade refactor of Events, Appointments and Sponsorships — see [`complete/11-module-adoption.complete.md`](complete/11-module-adoption.complete.md); off-plan fixes are in [`complete/00_extras.md`](complete/00_extras.md). Nothing runs on a server yet and the new screens were not opened in a browser; the retirement of the old senders (M8) waits one release.** This folder is an audit of the three things that today answer "something happened, who should
> know?" in `w/www` — the admin dashboard notification inbox, the Direct Payments notification dispatcher with its e-mail path, and analytics — and the plan
> to turn them into one general system any module can use. Every finding was read from the code on 2026-10-19; **nothing was run against a server** (the
> environment mode is `vagrant`, and no request was made). Where a finding is inferred from reading and not proved by a test, the finding says so, and
> [09](09-rollout-and-testing.md) phase P0 begins by proving it. Paths are relative to `w/www/` unless stated otherwise. Line numbers drift; symbol names will not.

## What this plan is for

Today, when a payment succeeds, three unrelated pieces of code each decide for themselves what to do about it:

- `ChargeSucceeded` queues a receipt e-mail ([`app/Externals/DirectPayments/ChargeSucceeded.php`](../../../../../w/www/app/Externals/DirectPayments/ChargeSucceeded.php));
- `NotificationDispatcher` may send a second, configurable e-mail about the same payment (Direct Payments only);
- nothing writes an admin-dashboard notification, and nothing records an analytics event — analytics events are only ever sent by the visitor's browser.

The goal, in the owner's words (2026-10-19):

> `NotificationDispatcher` becomes a **general system that other modules use**, with two jobs. **One:** update the admin dashboard notification system —
> however that system is managed today, merged into this, because the name implies it already handles system notifications. **Two:** send the e-mail
> notification, which is what it already does. Abstract it out of the Direct Payments implementation now; adopting it in the other modules comes later.
> **Analytics** is planned in the same folder: it must **not** be part of the same class, but the two must **work hand in hand** — a payment made should
> trigger a notification *and* an analytical record.

**Added the same day — the database must stay small.** Notifications and analytics write rows continuously, and several existing tables already do. A scheduled
cleanup deletes log-style data that is not permanently needed (the e-mail send ledger, the admin inbox, webhook logs, expired sessions and tokens, and **raw analytics
events after three months**), a **quarterly analytics report** is saved first so the overall picture can be viewed at any time, and a test stops a new unbounded table
from being added unnoticed ([08](08-data-lifecycle.md)).

## Scope rules

- **Direct Payments stays Direct Payments.** The general system knows no payer, processor or subscription. Direct Payments plugs into it through
  interfaces, exactly as it plugs into a host application through [`../direct_payments_audit/09-extension-points.md`](../direct_payments_audit/09-extension-points.md).
  `tests/Unit/DirectPayments/LayeringTest.php` keeps passing, and a new gate keeps the general namespace free of Direct Payments names ([09](09-rollout-and-testing.md) §9.4).
- **Notifications and analytics are two systems, never one class.** They share one *input* — an `Activity` (something happened) — and nothing else
  ([06](06-activity-fan-out.md)).
- **No `app()` service location in anything this plan produces.** Constructor injection, `handle()` injection for jobs and commands, explicit
  registration in one service provider. (Owner decision; `w/www/app` was refactored accordingly in www commit `12975edab`.)
- **Rapid data is bounded.** Anything that grows with activity has a retention policy, or is listed as kept with the reason. Detail is deleted only after the summary that
  replaces it exists ([08](08-data-lifecycle.md)).
- **Out of scope of steps 02-10:** adopting the system in Forms, Events, Appointments, Ecommerce, Site Users, Sponsorships and Subscribers — now planned module by module in
  [11](11-module-adoption.md) (it supersedes the table in [06](06-activity-fan-out.md) §6.6); the `members` app (the Brain's own dashboard); `packages/wwwafsportal_v1` (permanently out of scope).

## Reading order

| Step | Document | Contents |
|---|---|---|
| — | [README.md](README.md) | This index |
| 00 | [00-overview-and-verdict.md](00-overview-and-verdict.md) | What exists, what is wrong, the target in one picture, the rules the rest of the plan follows |
| 01 | [01-audit-findings.md](01-audit-findings.md) | Every finding with file, line, consequence and severity: **N1–N10** (admin inbox), **M1–M10** (dispatcher and e-mail), **A1–A9** (analytics), **D1–D8** (data growth), **X1–X3** (cross-cutting) |
| 02 | [02-target-model.md](02-target-model.md) | The model: `Activity`, the event catalogue, rules, channels, the ledger, the analytics record — names, tables, columns |
| 03 | [03-admin-inbox.md](03-admin-inbox.md) | Merging the dashboard notification inbox into the general system: schema, API, client, the three existing producers |
| 04 | [04-dispatcher-extraction.md](04-dispatcher-extraction.md) | Taking the Direct Payments implementation apart: what is generic, what stays Direct Payments, the data move, what must not change |
| 05 | [05-analytics.md](05-analytics.md) | Server-side recording, rollups, a summary that scales, the broken connect provider, hardening the public endpoint |
| 06 | [06-activity-fan-out.md](06-activity-fan-out.md) | `ActivityPublisher`: how one thing that happened reaches both systems; the payment walk-through; what each other module would publish |
| 07 | [07-admin-ui.md](07-admin-ui.md) | The per-event switches (inbox and e-mail), the generic rule editor, the client changes |
| 08 | [08-data-lifecycle.md](08-data-lifecycle.md) | Retention for every table that grows with activity: the `data:prune` job, the 90-day analytics rule, the quarterly analytics report, disk space, a test that stops new unbounded tables |
| 09 | [09-rollout-and-testing.md](09-rollout-and-testing.md) | Phases **P0–P7**, ordering gates, test matrix, grep gates, rollback |
| 10 | [10-open-questions.md](10-open-questions.md) | Owner decisions, each with a recommendation |
| 11 | [11-module-adoption.md](11-module-adoption.md) | **Planned, not built.** Moving the other seven modules onto the system, one after another — Forms, Sponsorships, Ecommerce, Events, Appointments, Site Users, Subscribers: what each does today (read from the code), the events it would announce, who is told, what stays out (secrets, bulk mail), the shared groundwork that must land first, the money rule (modules manage no money; Direct Payments knows no module) and the payment refactor of Events, Appointments and Sponsorships that follows from it, tests, gates, order |

## Relationship to other plan folders

| Folder | Relationship |
|---|---|
| [`../direct_payments/`](../direct_payments/README.md) steps 23, 24 | Designed the Direct Payments notification config and scheduler. Their decisions **D2** (zero work when nothing is configured), **D7** (the unique send ledger), **D8** (at most three reminder days) and the `B9`/`B24`/`B25` send fixes are kept — see [04](04-dispatcher-extraction.md) §4.5 |
| [`../direct_payments_audit/`](../direct_payments_audit/README.md) steps 09, 15 | Extension points and default e-mails. The default templates and their manifest stay in `…/defaults/dp/`; a generic reader serves any module's folder ([04](04-dispatcher-extraction.md) §4.2) |
| [`../events/`](../events/README.md) and [`../appointments/`](../appointments/README.md) step 13 | Module reporting built from the module's own tables. This plan leaves that rule alone ([05](05-analytics.md) §5.1) |
| [`../../billing/`](../../billing/README.md) | appfactory studio. `BillingMailer` and the dunning ladder keep working; they become a channel consumer later, not now |
| `brain:storage-prune` (`app/Console/Commands/BrainStoragePrune.php`) and `plans/create_member_redo/09-brain-schedule-move.md` | The one existing prune command and the rule for scheduling Brain-only commands. Left as they are; the new job follows the same shape and the same scheduling path ([08](08-data-lifecycle.md) §8.11) |
| [`../externals_security/`](../externals_security/) | Route authentication. The public analytics endpoint is hardened here ([05](05-analytics.md) §5.6) because it is part of analytics; the policy file it changes belongs there |

## Conventions for the code this plan will produce

- PHPDoc/JSDoc on every new or changed symbol; the **proposed** marker `AFS_NOTIFICATIONS_MODIFIER_ADDITION` followed by the plan reference
  (`plans/www/notifications_and_analytics/04-dispatcher-extraction.md §4.2`), to be added to [`../../markers.md`](../../markers.md) in phase P0 — see
  [10](10-open-questions.md) OQ12.
- **Vocabulary:** a *step* is a numbered file in this folder; a *phase* (`P0`, `P1`, …) is a division of work inside [09](09-rollout-and-testing.md).
- One completion record per finished step, in `complete/`, named `NN-<same name as the plan file>.complete.md`; off-plan work goes in `complete/00_extras.md`.
- Tests under `tests/Feature/Notifications/` and `tests/Feature/Analytics/`; the existing Direct Payments tests stay green throughout.
- Uncommitted until the owner says so.

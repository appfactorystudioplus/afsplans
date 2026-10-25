# 07 — Notifications and scheduling

## Completion document

Not yet carried out. When it is: `complete/07-notifications-and-scheduling.complete.md`,
including the confirmed answer to OQ-H (is `schedule:run` actually on cron for `members`).

## 7.1 The notice ladder

A member is never surprised. Every boundary in the self-service ladder ([06 §6.1](06-member-self-service-closure.md))
is announced **before** it happens and confirmed **after**.

| Notice key | When | To | Says |
|------------|------|----|------|
| `closure_requested` | at request | the member | The closure dates; what is kept until when; what is deleted at the purge (incl. the websites' own visitors' data, OQ-M); the subscription is cancelled; **how to cancel**; how to ask for a copy of their data (§6.9); tell your team (§6.7) |
| `grace_reminder` | **7 days** and **1 day** before `login_block_at` (`grace_reminder_days`) | the member | "Login stops on …", with the cancel link |
| `closure_cancelled` | on cancel | the member | Confirmed; the subscription is **not** restarted |
| `login_blocked` | at status 4 | the member | Login is now blocked; the data is kept until `purge_after`; how an operator can reactivate |
| `retention_reminder` | **7 days** and **1 day** before `purge_after` (`retention_reminder_days`) | the member | "Your data will be permanently deleted on …" — the last chance to ask for reactivation |
| `purged` | at `finalize` | the member | Confirmation that the account and data are erased |
| `soft_deleted_by_admin` | on an admin soft delete, **if the admin leaves "Notify the member" ticked** | the member | The account has been closed by an administrator; contact support |

`purged` and the reminders are sent to **`closure.email_snapshot`** — the address captured at request
time — because by then the `brain_memberships` row may be gone ([03 §3.3](03-database-schema.md)).
`purged` is sent **before** the snapshot is nulled at `finalize`; if the address was a mailbox on a
domain the purge just removed, that mail cannot be delivered and the send is logged, not retried.

The admin flows send only `soft_deleted_by_admin`. **A hard delete sends nothing** — it is an
operator action on a member who was either already closed or is being removed for cause (abuse,
fraud, a legal order), and notifying them is the operator's decision, not the system's.

*Small addition to the schema and modal:* `options` ([03 §3.3](03-database-schema.md)) carries
`notify_member` (default `true`), and the soft-delete modal gets a fourth line — *"Notify the member
by email"* — beside the three access-layer options.

## 7.2 The sweep

### 7.2.1 Where it lives — `members`, entirely ([D17](00-overview.md))

The schedule entry **and the engine it runs** are in `members`. The entry goes in
**`members/routes/console.php`**, beside `storage:retention-sweep`, `storage:tombstone-sweep`,
`storage:session-sweep` and `email:retention-sweep`:

```php
// members/routes/console.php
Schedule::command('account-close:tick')
    ->everyFiveMinutes()
    ->withoutOverlapping(4)
    ->onOneServer()
    ->runInBackground();
```

**`www`'s `routes/console.php` gets nothing**: it is evaluated on **every tenant clone** — its own
header says the subscription notifier "runs on the Brain AND on every tenant clone" — so it holds only
tenant-generic tasks (`direct_payments:subscription-notify`). A Brain-only task there needs a runtime
guard to stay Brain-only, which is why appfactory-specific enforcement runs in `members`
([billing/08](../billing/08-brain-enforcement.md), billing D18). A command that permanently erases a
member must not depend on a guard being remembered. `members` runs once.

`account-close:tick` (`members/app/Console/Commands/AccountCloseTick.php`) runs the whole sweep and
the purge slices **in-process**. It calls out only for what `members` cannot do itself
([D18](00-overview.md)): a Brain-surface call to `www` (status, provider cancel, notices), `auth`,
and the satellite apps — the same calls [05 §5.4](05-purge-engine.md) specifies for any purge step.

### 7.2.2 There is no queue and no worker

The design uses no queued job and needs no `queue:work` process ([D17](00-overview.md)). A purge is
advanced in **time-budgeted slices** by a service,
`PurgeRunner::advance($closureId, $budgetSeconds = 50)` ([05 §5.6](05-purge-engine.md)), called from
exactly two places, **both in `members`**:

1. **The tick** — every five minutes, for every `purging` closure.
2. **An admin's hard delete** — `www` proxies `POST /hard-delete` to `members`, which returns **202**
   and runs the *first* slice in its own PHP process after the response is sent
   (`dispatchAfterResponse`), so the admin sees the first steps start immediately without any worker.

The tick then carries it on. A purge that fits in a slice or two never waits for the next tick;
a long one (a large `/mnt/node1/{ref}`) progresses one slice per five minutes. Nothing is lost by
either path, and there is no queue table to fall behind.

### 7.2.3 What a tick does

Each step **in order, each idempotent**, under a single `Cache::lock("account_close:tick")` so
overlapping ticks (or a manual run) never double up:

| # | Action | Query (indexes from [03 §3.3](03-database-schema.md)) |
|--:|--------|-------|
| 1 | **Send due reminders** | closures in `grace` with `login_block_at − now ≤ N days` for each N in `grace_reminder_days`; likewise `closed` with `purge_after`. Sent-ness is recorded in a `notices` JSON column on the closure so a reminder never sends twice. Each send is a Brain-surface notice call ([§7.3](#73-delivery--and-why-not-the-silent-path)) |
| 2 | **Grace → Closed** | `phase = grace AND login_block_at ≤ now` (`members_aclosures_block_idx`) → `MembershipLifecycle::block()` — which asks `www` to set status 4, then runs the [02 §2.6](02-state-model-and-enforcement.md) revocation — then `login_blocked` |
| 3 | **Closed → Purging** | `phase = closed AND purge_after IS NOT NULL AND purge_after ≤ now` (`members_aclosures_purge_idx`), skipping legal holds → start the purge |
| 4 | **Re-queue failed purge steps** | steps `failed` with backoff (15 m, 1 h, 6 h, 24 h, 24 h), max five attempts ([05 §5.6](05-purge-engine.md)) |
| 5 | **Recover stuck steps** | steps `running` beyond their timeout → `failed` |
| 6 | **Advance purges** | every `purging` closure — one `PurgeRunner::advance` slice each, until the tick's own budget (~4 min) is spent; this is the **primary** path, not a fallback |
| 7 | **Prune watermarks** | `phase = completed AND purge_completed_at ≤ now − 35 days` → ask `auth` to delete the retained `checkpoint_primary_logout_watermarks` row for `closure.user_id` ([03 §3.6](03-database-schema.md), [08 §8.7](08-security-and-safety.md)) |
| 8 | **Reconcile — alert only** | any `brain_memberships` row at status 3, 4 or 5 with **no open closure** → operator alert, **no change** ([02 §2.9.1](02-state-model-and-enforcement.md)). The engine never auto-corrects a status it did not set |

Rules:

- **`purge_after IS NOT NULL`** is what guarantees an **admin soft delete never auto-purges**
  ([D4](00-overview.md)): its `purge_after` is `NULL` and no query above ever matches it.
- **`php artisan account-close:tick --dry-run`** reports what each step *would* do and writes nothing
  — no status calls, no notices, no deletes. It is the safe first invocation on any new environment.
- **Cadence is five minutes** — cheap when nothing is due (two indexed queries, [03 §3.3](03-database-schema.md)),
  and short enough that a multi-slice purge finishes in a reasonable time. The reminders and the
  block are date-scale events and do not need it; the purge slices do. An admin's hard delete does
  **not** wait for a tick — it runs its first slice immediately ([§7.2.2](#722-there-is-no-queue-and-no-worker)).
- **The tick is safe to call by hand** (`php artisan account-close:tick` on the `members` box) —
  idempotent, and serialized by the lock.
- **Time zone.** All timestamps are stored and compared in UTC; the notices render dates in the
  member's locale, and a boundary is described to the day, never the minute, so a five-minute tick
  never makes a stated date wrong.
- The sweep touches **status 3/4/5 only**. It cannot affect an Active member.
## 7.3 Delivery — and why not the silent path

The notices go through the **mail-handler system** ([D12](00-overview.md)) — an operator edits the
wording in the same `afsemail` builder as every other handler
(`MAILHANDLER_IMPLEMENTATION.md`) — **with a guaranteed fallback**, because
`MAILHANDLER_IMPLEMENTATION.md §6` records the property that makes the plain path unsafe here:
**with no configured handler, nothing is sent, silently.** That is tolerable for an order receipt
and not for *"your data will be deleted tomorrow."*

**Who sends.** `members` has **no mail sender at all** ([AC26](01-current-state-audit.md)), and the
mail-handler system, `BillingMailer` and the SendGrid configuration are all `www`'s
([D18](00-overview.md)). So the engine never sends mail: it makes a **signed Brain-surface call**,
`POST /internal/brain/notices/send` `{ notice_key, to, variables, handler_reference_num }`
([05 §5.4](05-purge-engine.md)), with `to` = the closure's `email_snapshot` and the handler reference
read from its own settings row; **`www` sends.**

**The path in `www`:** a new `AccountCloseMailer` in `www/app/Mail/` (where every mail-sending class
now lives), one method per notice in §7.1. Each:

1. Takes the handler reference `members` supplied — read there from
   `members_account_closure_settings.mail_handler_*` ([03 §3.9](03-database-schema.md)) — Brain-wide, like
   `BrainBillingSettings::default_mail_handler_reference_num` for the welcome email
   (`MembershipController::store`), **not** per member.
2. **Sends it through `BillingMailer::sendOne`**, not `MailHandlerSender::send`. `BillingMailer`
   (`app/Mail/BillingMailer.php`) is the hardened variant of the same flow: it always
   injects the provider token, guards a `null` `getBrainConfig()` (lines 62–68, finding B24), and
   never writes the debug file `MailHandlerSender` still writes to a hard-coded path. Using the
   hardened sender for a notice of this weight is deliberate, and it also keeps this plan from
   depending on `MailHandlerSender`'s open defects.
3. **If the handler reference is unset, or the send throws or reports failure, sends the built-in
   fallback** — a plain Blade mailable in `app/Mail/modules/AccountClose/` carrying the essential
   facts in fixed wording — and **records the failure** on the closure (`last_error`) and in the log.
   This is the one place in the codebase where the `Mail::to()` path is *kept on purpose* rather
   than left as `_obsolete`.
4. Never throws into the lifecycle. A failed notice does not stop a boundary from happening (the
   date passing is a fact, not a mail event) — but it **raises an operator alert**
   (`operator_alert_email` on the settings row) so the missing notice is noticed and can be sent
   by hand.

**Variables** are strings and numbers only ([`MAILHANDLER_IMPLEMENTATION.md §5`](../www/MAILHANDLER_IMPLEMENTATION.md)),
prefixed `account_close_`, and are added to the catalogue `app/Mail/variables.json` in the existing
`{ "name", "value" }` shape so the builder's variables dialog lists them:

`account_close_member_name`, `account_close_kind`, `account_close_reference`,
`account_close_requested_date`, `account_close_login_block_date`, `account_close_purge_date`,
`account_close_days_remaining`, `account_close_cancel_url`, `account_close_support_email`,
`account_close_reason` *(admin soft delete only)*.

The cancel URL is the members dashboard's close-account section
(`…/settings/general#close-account`, [06 §6.2](06-member-self-service-closure.md)); it is built from
configuration, never from request input.

## 7.4 Preconditions to verify before P2

With [D17](00-overview.md) there is **one** thing to confirm, not two — and it is not in `www`:

- **`members` must have `schedule:run` on cron, every minute.** `members/routes/console.php` says
  so itself and says this is **"not yet confirmed configured"** (lines 11–16). Every platform
  sweep already registered there — storage retention, tombstones, sessions, email retention —
  silently does nothing if it is not. This plan adds a fifth that would fail the same way.
- **There is no queue worker to confirm.** [§7.2.2](#722-there-is-no-queue-and-no-worker).
- `www`'s own cron is **irrelevant** to this feature; `www` is only ever *called*.

**Verify in the lab (`vagrant` mode — the servers are reachable)** and record the answer in the
completion doc. The `members` app's deployment path and cron user are not stated in the code, so
locate them first:

```bash
vagrant ssh web -c "sudo crontab -l; sudo ls /etc/cron.d; for u in appfactorystudio apache root; do echo \"== \$u\"; sudo crontab -l -u \$u 2>/dev/null | grep -i schedule; done; ps -eo pid,lstart,cmd | grep -E 'schedule:(run|work)' | grep -v grep"
```

**If `schedule:run` is not scheduled for `members`, the plan does not ship past P1.** Soft delete
needs no scheduler (it is an immediate state change); a hard delete's *first slice* runs in the
admin's request but its later slices would never run; and the self-service ladder would strand
members in "Closing" forever. Getting `members`' scheduler running is then the first task, and it
fixes four sweeps that are already silently idle.

## 7.5 Configuration

Behaviour is in `members_account_closure_settings` ([03 §3.9](03-database-schema.md)). What is *not*
a setting is credentials, and **the engine needs several `members` does not have today**
([AC27](01-current-state-audit.md), OQ-P). Names to add to the `_env/<mode>/.env` profiles through
`use-env` (`AGENTS.md §6`):

| Where | Needs | For |
|-------|-------|-----|
| `members` | `CHECKPOINT_SYSTEM_KEY` | `auth`'s `access` / `erase` routes ([05 §5.4](05-purge-engine.md)) |
| `members` **and** `www` | a **new** `members`→`www` key pair, `_NEXT` for rotation, and an app name, in the shape of `MARKET_INTERNAL_API_KEY(_NEXT)` — **not** `WWW_INTERNAL_API_KEY`, which authenticates the *opposite* direction and must never be reusable as this one ([D11](00-overview.md)) | the Brain surface |
| `members` | one signed-call key per satellite app that has no inbound channel yet (`notes`, `flow`, `dream_graph`, `pdf_editor`); `market` and `coordinator` already have theirs | the `app:*` erasers |
| `members` | `ACCOUNT_CLOSE_HMAC_KEY` | the tombstone and purge-log HMACs ([03 §3.5, §3.8](03-database-schema.md)) — these are `members`-side tables now |
| `members` | the tenant/DNS shell environment `RunCommandBG2` already relies on (`SUBPATH`) | the remote commands |

**The secrets go in the `_env/<mode>/.env` profile files — not in code, not in this plan.** A
frontend bundle bakes `VITE_*` values at build time; none of these are `VITE_*`.

## 7.6 Observability

- **Log lines** with a stable prefix (`[account_close]`) at every transition and every step,
  carrying the closure reference (never the email).
- **The closures view** ([04 §4.8](04-admin-soft-and-hard-delete.md)) is the operator's window: state,
  timers, every step, the last error.
- **Alerts** to `operator_alert_email` for: a purge step that exhausted its five attempts; a notice
  that fell back or failed; a purge stopped by a blocker that appeared after confirmation; a
  closure that has sat in `purging` longer than 24 h; **a status 3/4/5 with no open closure**
  ([§7.2.3](#723-what-a-tick-does) step 8). `members` cannot send mail, so an alert is a notice
  through the same Brain-surface call (`notice_key = operator_alert`) **and** is always written to
  the log as `[account_close][ALERT]` — so it is still findable when `www` is unreachable, which is
  exactly when a purge step that needs `www` is most likely to be the thing that failed.

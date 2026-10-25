# 07 — Admin UI: Per-event Switches

The owner's requirement (2026-10-19): **per-event switches.** This step makes every event show its own switch for each channel — *Dashboard* and *E-mail* —
in one reusable editor, instead of a form where an operator has to know to add a row per event.

## 7.1 How it works today

| Piece | Today |
|---|---|
| Where | A card `#dpNotificationsSection` in the Direct Payments form (`resources/js/portal/admin/html/views/directpayments-form.html` ~217–230) |
| Model of use | The operator presses **Add Notification** (`#dpAddNotificationBtn`) and picks an event from a `<select>` of fourteen; no row means no e-mail. The empty state reads *"No notifications configured — nothing will be emailed for this Direct Payment."* |
| A row | Event · "Send from" mail handler · **Active** switch · Recipients (`customer` / `handler's own list` / `custom`) · custom addresses · "Days before" checkboxes (1–7, ≤3) for `upcoming_charge` and `trial_ending` — template `#dpNotificationTemplate` |
| Code | `controllers/direct_payments/directpayments.ts`: `addNotificationRow()` (~860–930), `applyNotificationsVisibility()` (~611), `applyEventOptionsForPayType()`, a `mailHandlersCache: any[]` filled from `GET /portal/api/v1/email-handlers/list` (~942) |
| Vocabulary copies in this file | `SINGLE_PAY_NOTIFICATION_EVENTS` (436), `ALWAYS_ON_NOTIFICATION_EVENTS` (437), `SCHEDULED_NOTIFICATION_EVENTS` (~607) — plus the `<select>` options in the HTML, `DirectPaymentNotification::EVENTS` in PHP and `defaults.json` ([01](01-audit-findings.md) M7) |
| Save | `dpObj.notifications = rows.map(...)` (~1270–1285) sent with the Direct Payment; the server rejects bad events/days, deactivates rows the operator removed (an *omitted* key is "untouched"), and `updateOrCreate`s the rest |
| Sends panel | `GET /directpayments/{ref}/notifications/sends` exists (`listSends`); **no admin script or view calls it** — `grep -rn "notifications/sends" resources/js/portal/admin` is empty. The "Recent notifications" panel `24-notification-scheduler.md` §6.8 describes was not built |

## 7.2 Endpoints — a generic API, with the Direct Payments save path kept

All under `externals.dashboard`, added to `RoutePolicy` and to the route-policy test; named so they cannot collide with `/notifications/{id}`
(the collision behind [01](01-audit-findings.md) N1).

| Method and path | Purpose |
|---|---|
| `GET /portal/api/v1/notification-catalog?module=` | Modules and their events: `key`, `label`, `appliesTo`, `scheduled`, `alwaysOn`, `inboxDefault`, `mailDefault`, `mailDelaySeconds`, `variables`. Built from `ActivityCatalog` — **the single source of the vocabulary** (replaces the five copies) |
| `GET /portal/api/v1/notification-rules?module=&scope_ref=` | The rules for one scope, with each event's effective state (`rule` / `default`) so the UI can show "Using default: on" |
| `PUT /portal/api/v1/notification-rules` | Body `{module, scope_ref, rules: [...]}`. Same semantics as the Direct Payments save: validates against the catalogue (events, ≤3 `days_before` in 1..7, `alwaysOn` cannot be switched off, handler exists, `custom` needs addresses); **a rule present is upserted; a rule omitted is deactivated** (both switches off), never deleted, so history and the unique key survive |
| `GET /portal/api/v1/notification-rules/sends?module=&scope_ref=&event=&status=` | The ledger, newest first, 50 per page — the generic form of `listSends` (the old URL stays) |
| `POST /portal/api/v1/notification-rules/sends/{id}/resend` | **New, optional (P5):** clears a `failed`/`skipped` ledger row so the dispatcher can send again; audited. This is the "button" `24` §6.10 reserved a place for |
| `GET /portal/api/v1/email-handlers/list` | Unchanged URL and summary-only response |
| `GET /portal/api/v1/directpayments/{dpRef}/notifications` (public) | Unchanged shape `{event, days_before, active}`; `active` = either switch on |

The Direct Payments form keeps saving through `dpObj.notifications` ([04](04-dispatcher-extraction.md) §4.3 step P2c), so no existing client breaks; the
generic `PUT` is for the editor when it is used by a module that has no such save path.

## 7.3 The editor — `NotificationRulesEditor`

A TypeScript class in `resources/js/portal/admin/components/notifications/NotificationRulesEditor.ts`, constructed with `{container, module, scopeRef,
payType?}`. It replaces the inline code in `directpayments.ts`.

**Layout — every event is listed, each with its own switches** (the change from "Add Notification"):

```
 Notifications                                                              [Show advanced]
 ┌───────────────────────────────┬────────────┬────────────┬──────────────────────────────────┐
 │ Event                         │ Dashboard  │ E-mail     │ Details                          │
 ├───────────────────────────────┼────────────┼────────────┼──────────────────────────────────┤
 │ Payment succeeded             │ ▢ off      │ ▣ on       │ Default · built-in receipt   ▾   │
 │ Payment failed                │ ▣ on       │ ▣ on       │ Customer · built-in email    ▾   │
 │ Subscription canceled         │ ▣ on       │ ▣ on       │ Default                      ▾   │
 │ Upcoming charge               │ ▢ off      │ ▢ off      │ Send 7, 3 days before        ▾   │
 │ Payment details required      │ ▢ —        │ ▣ always   │ Required during a processor change│
 └───────────────────────────────┴────────────┴────────────┴──────────────────────────────────┘
```

- A row shows the **effective** state. A row with no stored rule is labelled *"Using default"* with the catalogue default switches; changing any control
  creates the rule. This removes the "nothing is configured, so nothing happens" trap ([01](01-audit-findings.md) M3) *visibly*.
- The two switches are the per-event switches. An `alwaysOn` event shows the e-mail switch locked on and no inbox switch unless the module defines one.
- The **Details** drawer holds what the old row held: mail handler, recipient mode, custom addresses, `days_before` (scheduled events only), and the
  optional inbox title/body. Only the events that apply to the pay type are shown (`appliesTo`), replacing `applyEventOptionsForPayType()`.
- `collect()` returns the rules that differ from the default or carry custom settings, in the shape `dpObj.notifications` uses today (plus `inbox_active`),
  so the Direct Payments form keeps one save.
- An optional **Recent sends** drawer under the editor reads the ledger endpoint and, from P5, offers *Resend* on failed rows — the panel that was
  planned and never built.

**Code rules for the new TypeScript** (the owner's standing rules for `afs-eco` TypeScript): no optional chaining; no `any` — the catalogue, rule and send
shapes are declared interfaces; the editor obtains `container`, the catalogue and the mail-handler list once and throws a clear `Error` if one is missing,
instead of silently doing nothing. The mail-handler cache is typed (`MailHandlerSummary[]`), ending `mailHandlersCache: any[]`.

## 7.4 Other modules

Each later module adopts the editor with three lines in its admin controller (`new NotificationRulesEditor({ container, module: 'forms', scopeRef: formRef })`)
and a card in its HTML. This plan builds the editor and wires **Direct Payments only**; the others are listed in [06](06-activity-fan-out.md) §6.6.

## 7.5 Translations

New strings use the `notifications.*` namespace in `locales.json` (rows: `event`, `dashboard`, `email`, `using_default`, `always_on`, `details`, `resend`, …).
The existing `directpayments-form.notification_*` keys stay for one release as aliases. `locales.json` edits follow the standing rule: anchor to line start
and exact indentation, diff against a baseline before and after, never a loose regex (the 2026-10-13 incident).

## 7.6 The dashboard inbox view

`settings-notifications` (`notificationsView.ts`) keeps working. It gains a module filter (from the `module` column, [03](03-admin-inbox.md) §3.2), a severity
colour (from `severity`), and a deep link from each notification to its subject through the module registry ([03](03-admin-inbox.md) §3.5).

## 7.7 Acceptance

- Opening a Direct Payment that has no stored rules shows every applicable event with its default switches and the text *Using default*.
- Turning the **E-mail** switch of `payment_failed` off and saving stores one rule with `mail_active = false`, leaves `inbox_active` as it was, and the
  next failed payment sends no e-mail but still creates the bell notification.
- An `alwaysOn` event's e-mail switch cannot be turned off in the UI and a hand-built request that tries is rejected with a clear error code.
- The event list in the form is identical to `GET /notification-catalog` with no hard-coded event string left in `directpayments.ts` or the form HTML (grep gate).
- Saving from the editor and saving from the old Direct Payments payload produce identical rows.

# 07 — Admin UI: Per-event Switches (complete — the generic API, the editor and the Direct Payments form are built; proved by tests and a type check, NOT yet seen in a browser)

## Status

**Built 2026-10-19, nothing committed.** Every event of a module is now listed with its own **Dashboard** and **E-mail** switch, showing what applies *now* — including *"Not configured — nothing is sent"* and *"Using default"* — instead of a form where the operator had to know to press *Add Notification* and pick an event.
The Direct Payments form uses it; the vocabulary is read from the server and **no event name is written in the form or its script any more**. **Nothing was opened in a browser** (I do not log in as the agent): the editor's draw-and-listen class was type-checked, its logic and the API are tested, but the screen itself needs the owner's eyes.

Everything is in `w/www`.

## Phases

| Phase | Piece | State | Where |
|---|---|---|---|
| P5 | `NotificationRulesService`: catalogue, a scope's rules with each event's effective state, validated atomic save, ledger, clearing | **built** | `app/Services/Notifications/NotificationRulesService.php` |
| P5 | The API: `GET /notification-catalog`, `GET`/`PUT /notification-rules`, `GET /notification-rules/sends`, `POST /notification-rules/sends/{id}/resend`; all behind `externals.dashboard`, in `RoutePolicy` | **built** | `NotificationRulesApiController.php`, `routes/portal.php`, `RoutePolicy.php` |
| P5 | `NotificationRulesEditor` (draws and listens) and its pure model | **built** | `resources/js/portal/admin/components/notifications/` |
| P5 | The Direct Payments form uses the editor; its five hard-coded event lists and the row template are gone | **built** | `directpayments.ts`, `directpayments-form.html` |
| P5 | Translations: the `notifications.*` rows in the five admin language files | **built** | `resources/js/portal/admin/i18n/{en,de,es,fr,zh}.json` |
| P5 | "Recent sends" drawer with *Allow resend* (the panel `24` §6.8 planned and never built) | **built** | the editor |
| §7.6 | Dashboard list: a module filter (severity colour and deep links were step 03) | **built** | `NotificationService.ts`, `controllers/settings/notifications.ts`, `settings-notifications.html` |
| — | Other modules adopting the editor | **not in this plan** (07 §7.4: three lines each, later) | — |

## What was built

- **The API** (07 §7.2). *Catalogue*: modules and their events with `appliesTo`, `scheduled`, `alwaysOn`, both defaults, `mailDelaySeconds`, `variables`, `hasDefaultEmail`, `attachedHandlers` — straight from the `ActivityCatalog`, the single source. *Rules*: one entry per event with the rule stored for exactly this
  scope (or `null`) and `effective` (`rule` / `module` / `default` / `none`, plus whether the item's attached mail handlers apply). *Save* (`PUT`): validates every rule against the catalogue (unknown event, ≤3 distinct `days_before` in 1..7 and required for a scheduled event, existing mail handler,
  `custom` needs an address, an always-on e-mail cannot be switched off — each with its own error code `NR_E_…`), **writes nothing when any rule is refused**, upserts present rules keeping their reference (the ledger stays attached), and switches *both* switches off for rules left out — never
  deleting them — except that a compulsory e-mail stays on. The dashboard switch and wording are optional: a caller that does not send them leaves them as they were. *Sends*: the ledger, newest first, 50 a page, filterable by scope, event and status. *Resend*: clears one `failed` or
  `skipped` row (never `sent` or `pending`) and writes an audit log line.
- **One save, two doors.** A test saves the same configuration through the old Direct Payments payload and through the generic API and compares the stored rows: identical (07 §7.7).
- **The editor.** A table, one row per event: the event, a Dashboard switch, an E-mail switch (locked on for a compulsory e-mail), and a *Details* control that opens a drawer holding what the old row held — mail handler, recipient mode, custom addresses, days before (scheduled events only, never more than three), and the
  optional dashboard title and text. A row with no stored rule says *Using default* / *Not configured — nothing is sent* / *Using the module-wide setting* / *Sends the mail handlers attached to this item*; changing any control makes it the item's own. Only rows that are stored, touched or carry custom settings are sent
  back (`collect()`); untouched defaults stay defaults. The events shown follow the pay type through `appliesTo` (replacing `applyEventOptionsForPayType()`). It obtains its container, the catalogue and the mail-handler list once and throws a clear `Error` if one is missing; no optional chaining and no `any`.
- **The Direct Payments form.** The card is now a container for the editor. On save the form asks the editor for problems (shown in an alert, save stopped) and sends `dpObj.notifications = editor.collect()`; **if the editor never loaded, the key is left out**, and an omitted key means "untouched", so a failed catalogue fetch cannot blank the rules an operator has. For a Direct Payment that is not saved yet the
  editor shows the module's current state and stores nothing as its own.
- **Translations.** 24 keys under `notifications.*` in English, German, Spanish, French and Chinese. Event labels come from the server and are English. The old `directpayments-form.notification_*` keys are left in place for a release.
- **The inbox list** gets a module filter, offered only when more than one module has published.

## Tests

| File | Tests | Proves |
|---|---|---|
| `Feature/Notifications/NotificationRulesApiTest` | 24 | the catalogue; one module / unknown 404; every event listed with its effective state (none / default / rule / module); save keeps references; one switch leaves the other; left-out = off, kept; scopes are separate; **an always-on e-mail is refused with its code and survives a save that leaves it out**; every validation; one bad rule = nothing saved; days stored descending and only for scheduled events; recipients/wording/send window; the ledger listed, filtered, paged; a failed or skipped row cleared and logged, others never; routes carry the dashboard login and do not collide with the inbox |
| `Feature/DirectPayments/DirectPaymentsNotificationModuleTest` | +1 | **the generic save and the old Direct Payments payload produce identical rows** |
| `Feature/Externals/RoutePolicyTest` | — | the five new actions are listed and carry the login |
| `admin/tests/notificationRulesModel.test.ts` | 23 | visible events per variant; rows built from the server; status text; switches (always-on locked); days (max three, sorted); what is collected and in what shape; problems; **the form and the editor mention no event name (G4)** |

## Final run

Run once at the end, one process at a time, against MariaDB 11.4.5: `tests/Unit`, `Feature/Notifications`, `Feature/Analytics`, `Feature/Externals` and the notification-related Direct Payments files (module, save, default e-mails, characterization, fan-out, fold, charge hook) — **739 tests, 2320 assertions; the only errors are the 18 of the known Windows `LaravelCronScriptTest` setUp problem**. TypeScript: `test:admin` 69, `test:external-api` 22, `test:builder` 481, all pass; `tsc --noEmit` clean. Not run: the rest of `Feature/DirectPayments` and `Feature/Processor` (nothing in this step touches them; step 06 ran them), and the other Feature folders.

## Deviations from the plan text

1. **Resend clears, it does not send.** The ledger row is removed so the same send is allowed again; the next time the event happens (a scheduled reminder: on its next run) it is sent. The plan's wording ("clears … so the dispatcher can send again") matches; the button is labelled *Allow resend* so it does not promise an immediate e-mail.
2. **No separate "Show advanced" toggle**; each row has its own Details control.
3. **The editor shows the module's state for an unsaved Direct Payment** (it asks for the module-wide scope) rather than hiding itself.
4. **Defaults for days are 3 and 1** when an operator turns on a scheduled event with none chosen.
5. **The form's own `payment_succeeded` label** in the server catalogue is the manifest's "Payment received"; the older hand-written drop-down texts are gone.
6. **Other modules are not wired** (the plan's §7.4: this plan builds the editor and wires Direct Payments only).

## Not verified

- **The screen was never opened.** The form, the switches, the drawer, the *Recent sends* list and the module filter on the inbox list are type-checked and their logic tested, but no browser ran them. Things to look at first: the table renders in the Direct Payments form; switching a pay type hides subscription-only events; saving a Direct Payment stores the rules you see; a compulsory e-mail's switch is locked; the translations appear.
- No real e-mail, webhook or payment was involved.
- `npm run build` (Vite) was not run; `tsc --noEmit` is clean.

## Next

Step **08** — the data lifecycle: `data:prune` (shipped disabled), the 90-day raw analytics rule gated on the rollup and the quarterly report, the inbox and ledger retention, and the test that stops a new unbounded table. Step 09 is the rollout/test plan.

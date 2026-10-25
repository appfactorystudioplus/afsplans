# 03 — Admin Inbox (complete — the inbox is built and tested; nothing publishes to it through the new model yet)

## Status

**Complete 2026-10-19.** Nothing is committed. This step makes the dashboard's notification inbox (`laravel_notifications`) a first-class destination of the general model built in
[02](02-target-model.complete.md): the table gained the columns a module-neutral inbox needs, the API was repaired and extended, an `InboxChannel` exists for step 04/06 to call, the
three existing producers write real columns, and the dashboard client escapes everything it draws, shows the server's unread count, pages, and routes clicks through a registry.
**No Direct Payments behaviour changes.** The existing producers (Forms, Sponsorships, Sponsor portal) keep producing; they now write more complete rows.

Everything below is in `w/www`, plus the SDK type copies listed under "Client".

## Phases

| Phase | Piece | State | Where |
|---|---|---|---|
| P0 | N1 route order: `unread-count`, `mark-all-read`, `archive-all` before `{id}`; `{id}` is numeric | **fixed + tested** | `routes/portal.php`, `RoutePolicy.php` |
| P0 | N2 stored XSS in the dropdown and list | **fixed + tested** | `notificationMarkup.ts`, `notificationHelpers.ts` |
| P0 | N6 sample data and sponsor `events` table bug | **fixed** | `NotificationService.ts`, `SponsorPortalController` |
| P1 | Extended table (module, event, severity, subject_ref, scope_ref, dedupe_key, recipient_ref + 4 indexes) | **built** | migration `2026_10_19_000002_extend_laravel_notifications.php`, `Notification.php` |
| P1 | `InboxChannel` + `InboxMessage` / `InboxMessageBuilder` / `ChannelResult` | **built** | `app/Services/Notifications/` |
| P1 | `EventDefinition` inbox wording + severity | **built** | `EventDefinition.php` |
| P1 | API: list filters + paging, `unread-count`, `archive-all`, dedupe on create | **built** | `NotificationApiController.php` |
| P1 | Producers write real columns | **built** | Forms job, `SponsorshipApplicationController`, `SponsorPortalController` |
| P2 | Client: escaping, server unread count, paging, polling back-off, navigator registry, "Archive all" | **built** | `resources/js/portal/admin/services/` |
| P2 | SDK: `NotificationRecord` fields, `unreadCount()`, `archiveAll()`, in `api.ts` and **nine** `portal.d.ts` copies | **built** | see below |

## What was built, in detail

- **Schema.** A guarded `ALTER` (re-runnable; returns if the table is missing): `module`(64), `event`(64), `severity`(16, default `info`), `subject_ref`(128), `scope_ref`(128), `dedupe_key`(128), `recipient_ref`(64),
  indexes `notifications_module_idx`, `notifications_subject_idx`, `notifications_dedupe_uq` (unique), `notifications_created_idx`. `down()` removes them.
- **`InboxChannel`.** Claims a `NotificationSend` (channel `inbox`, recipient `admin`, the fixed one-off bucket) then creates the row. A duplicate `dedupe_key` (MySQL 1062) records the send as skipped and returns
  `DEDUPED`; any other error records `failed` and returns `FAILED` — **it never throws**, so a broken inbox cannot break the event that caused it.
- **`InboxMessageBuilder`.** Wording precedence rule > event definition > label; `${name}` placeholders (unknown → empty); title and link are collapsed to one line, control characters stripped; lengths clipped (title 255, body 2000, link 255).
- **API.** `list` takes `page`, `limit` (≤ 100), `category`, `module`, `severity`, `unreadOnly`, `archivedOnly`; `GET unread-count` → `{count}` (unread and not archived, the whole table); `PATCH archive-all` → `{success, archived}`;
  `create` validates the new fields and returns the existing row (200) on a repeated `dedupe_key`. Both new routes are in `RoutePolicy::MUST_REQUIRE_LOGIN`.
- **Producers.** Forms (`ProcessFormSubmission`) uses `firstOrCreate` on `forms.submission_received.<reference>`; Sponsorships writes `module`, `event`, `subject_ref`, `severity`; the Sponsor portal `notifyEvent` now looks the event up in the
  real `Event` model (the table it queried did not exist, so the endpoint always failed).
- **Client.** `notificationHelpers.ts` (escape, tolerant mapper), `notificationMarkup.ts` (pure HTML builders — **every notification string is escaped here, once**), `notificationNavigation.ts` (registry; Forms and Commerce navigators;
  any other module falls back to `settings-notifications:detail`). `NotificationService.ts` was rewritten around them: the badge shows the **server's** count and is re-asked after each action; one poll tick asks the cheap count and reloads the list only when it moved;
  15 s visible / 60 s hidden, immediate catch-up on `visibilitychange`; 100 per page with a "Load more" row; the sample data is gone; "Clear all" (which only emptied the browser copy, so rows came back at the next poll) now calls `archive-all`
  and is labelled **Archive all** in the five locales (the i18n key `settings-notifications.clear_all` is unchanged).
- **SDK types.** `NotificationRecord` / query / update types extended, `unreadCount()` and `archiveAll()` added in `resources/js/external/api/notifications/api.ts`, and the same block updated in all nine `portal.d.ts` copies
  (www ×2; coordinator, dream_graph, flow, notes, pdf_editor, market, members), each file's line endings preserved (coordinator is CRLF). `PortalNotificationsAPI` also now declares the existing `markRead` and `markAllRead`.

## Tests

PHP on MariaDB 11.4.5 (port 3307), one run at the end with progress: **OK — 107 tests, 327 assertions** (`tests/Unit/Activity`, `tests/Unit/Notifications`, `tests/Feature/Notifications`, `tests/Feature/Externals/RoutePolicyTest.php`, `tests/Unit/Database`).

| File | Proves |
|---|---|
| `Feature/Notifications/NotificationApiTest` | route order (N1), paging, filters, true unread count, archive-all, dedupe on create, validation, auth required |
| `Feature/Notifications/InboxChannelTest` | row written, wording precedence, placeholders, one-line title, clipping, dedupe, ledger rows, failure never throws, long identity hashed |
| `Feature/Notifications/SponsorshipNotificationTest`, `FormsNotificationProducerTest` | producers write the real columns; forms replay creates one row |
| `Unit/Activity/EventDefinitionTest` | inbox wording and severity validation |
| `Unit/Notifications/GeneralLayerTest` | gates G1/G3 still hold with the new classes |

TypeScript (`npm run test:admin`, new script): **37 tests, 0 failures** — `notificationHelpers.test.ts` (escape, severity, mapper incl. garbage input), `notificationMarkup.test.ts` (**hostile title, message, id, time and colour come out as text, not markup — N2**),
`notificationNavigation.test.ts` (registry, fallback, Forms, Commerce). `tsc --noEmit --strict` on `NotificationService.ts` and the modules it imports: clean. MariaDB was shut down cleanly afterwards; no `mariadbd`, `node` or Electron process left.

## Deviations from the plan text

1. **`metadata` is handled by an Eloquent `Attribute`, not an `array` cast.** The column is text and existing producers pass JSON strings; a plain cast would double-encode them. The accessor stores arrays as JSON and strings as given.
2. **`subject_ref` is 128 characters, not 64**, to match 02's reference limit.
3. **`EventDefinition` gained `inboxTitle`, `inboxBody`, `inboxLink`, `severity`** (03 §3.3 listed the wording; the definition now carries it).
4. **One-off ledger identity.** Inbox sends use a fixed bucket (`NotificationSend::ONE_OFF_BUCKET`) and the dedupe key (or `module.event.subject`, or `none.<uuid>`; hashed over 128) as subject, so a replay dedupes without a time window.
5. **Navigation fallback is the notification's own detail view**, not "a view named after the module" (a module key is not guaranteed to be a view).
6. **"Clear all" became "Archive all"** (server-side) instead of being kept as a local clear; the plan only said the local clear was misleading.
7. **No `notifications:prune` command here.** Retention of the inbox is [08](../08-data-lifecycle.md)'s `data:prune`; nothing in this step deletes rows.
8. **The Forms job changed to array metadata + `firstOrCreate`** (it passed a JSON string and had a stray `)`; the dedupe key makes its retries safe).
9. **The test trait needs a `site_users` schema** (`laravel_api_guards` joins it); `tests/Concerns/CreatesNotificationTables.php` creates it.

## Built on assumptions the owner has not yet confirmed

As in [02](02-target-model.complete.md): OQ1 (keep the name `NotificationDispatcher`), OQ3 (new migration + copy), OQ7 (defaults off), OQ9 (module-wide rules), OQ12 (marker). Nothing in this step depends on OQ1, OQ7 or OQ9; OQ3 shows up as the "extend" migration being a new file rather than an edit.

## Not verified

- **Nothing was run on a server or in a browser.** The new migration has only run on the local test database; the dev server will apply it with its next `php artisan migrate --force`. Until it is applied there, the producers' new columns do not exist — a deploy must migrate first.
- The dashboard client was type-checked and its pure parts tested; the DOM wiring (dropdown, list, polling, Load more, Archive all) was **not exercised in a browser**.
- The nine `portal.d.ts` copies were patched by script with exact-match assertions; they were not compiled in their own apps.
- The generated `resources/js/external/api/build/` declarations were not rebuilt.

## Next

Step **04** (the dispatcher extraction) is where `MailChannel` is built, the Direct Payments rows are copied into `laravel_notification_rules`, and `DirectPaymentsActivityModule` registers — and where `InboxChannel` first gets a real caller.

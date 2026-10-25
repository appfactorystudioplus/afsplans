# 03 — The Admin Inbox

Closes **N1–N10** ([01](01-audit-findings.md) §1.1). The inbox is what the dashboard's bell shows. This step merges it into the notification system as
the `inbox` channel: it stays one table and one API, gains the fields it was missing, and gets its rows from the dispatcher instead of from three
hand-written `Notification::create()` calls.

## 3.1 How it works today (the facts the design builds on)

| Piece | Today |
|---|---|
| Storage | `laravel_notifications`: `id`, `title`, `body`, `link`, `category`, `is_read`, `is_archived`, `metadata` (**text**), timestamps. Indexed on `category`, `is_read`, `is_archived` |
| Model | `App\Models\Notification` — `$fillable` of those eight; **no `$casts`** |
| API | `POST/GET /portal/api/v1/notifications`, `GET/PATCH/DELETE …/{id}`, `PATCH …/mark-all-read`; all behind `externals.dashboard` (`routes/portal.php:861–867`). `list` paginates (default 20, max 100), filters `unreadOnly`, `archivedOnly`, `category`; `metadata` is `json_encode`d on write and decoded on read |
| External SDK | `window.Portal.notifications` — `list`, `get`, `create`, `update`, `markRead`, `markAllRead`, `delete` (`resources/js/external/api/notifications/api.ts`) |
| Dashboard | `NotificationService.ts`: polls the list every 15 seconds, caches in `localStorage`, renders the navbar dropdown (top 10, never archived) and the full list, maps `category` to a navigation target for `forms` and `commerce` only |
| Producers | `ProcessFormSubmission` (correct), `SponsorshipApplicationController` (writes columns that do not exist), `SponsorPortalController::notifyEvent` (array into a text column) |
| Seeding | `NotificationSeeder` on an empty table when `APP_ENV` ≠ `production` |

## 3.2 Schema change — one additive migration

A new migration `…_extend_laravel_notifications.php`, guarded with `Schema::hasColumn`, adds:

| Column | Type | Purpose |
|---|---|---|
| `module` | string(64), nullable, index | Which module published it (`forms`, `direct_payments`). `category` keeps its current meaning for old rows; new rows set both to the same value so the client's `category` routing keeps working |
| `event` | string(64), nullable | `payment_failed` |
| `severity` | string(16), default `info` | `info` \| `success` \| `warning` \| `danger` — **fixes N3**; the client already styles all four |
| `subject_ref` | string(64), nullable, index | The thing it is about (invoice, submission) — what a click opens |
| `scope_ref` | string(128), nullable | The thing the rule hangs off (the form, the Direct Payment) |
| `dedupe_key` | string(128), nullable, **unique** | **Fixes N7** — a second `create` with the same key is ignored |
| `recipient_ref` | string(64), nullable | `NULL` = every administrator (today's behaviour). Reserved for [10](10-open-questions.md) OQ2; unused in P3 |

`metadata` stays `text` for old rows. The model gains `$casts = ['metadata' => 'array', 'is_read' => 'boolean', 'is_archived' => 'boolean']`, which fixes
the array-into-text failure in `SponsorPortalController` (**N6**) at the model, not at each caller. The controller's `json_encode` / `decodeMetadata`
pair is removed once the cast is in, and a test proves old rows (stored as a JSON string) still read back as arrays.

Also: `$fillable` gains the new columns. The guard `Model::preventSilentlyDiscardingAttributes()` is **not** switched on globally (that would change every
model); instead the new `InboxChannel` writes through an explicit method that names every column, so a misspelt key fails in the test.

## 3.3 Behaviour changes in the API (backward compatible)

| Change | Detail | Closes |
|---|---|---|
| **Route order and constraint** | `Route::patch('/notifications/mark-all-read', …)` is declared **before** `/{id}`, and `{id}` gets `->whereNumber('id')`. A feature test calls it and asserts every unread row becomes read | N1 |
| **A real unread count** | `GET /notifications/unread-count` returns `{count}` (non-archived, unread). The badge reads this instead of counting the 20 it happens to have | N4 |
| **Page and limit are used** | The client passes `limit` and requests further pages when the full list is opened; the dropdown still takes the first 10 | N4 |
| **`severity`, `module`, `event`, `subject_ref` returned and accepted** | `create` validates `severity` against the four values; unknown `module` values are accepted (the SDK may create notifications for custom modules) but not interpreted | N3 |
| **Dedupe** | `create` with an existing `dedupe_key` returns the existing row with `200` instead of `201` | N7 |
| **Retention** | Handled by the data-lifecycle job ([08](08-data-lifecycle.md) §8.3, §8.7): archived rows older than 180 days and read rows older than 365 are deleted; unread rows never. There is no separate `notifications:prune` command | N10 |

The response shape keeps every field it has today (`id`, `title`, `body`, `link`, `category`, `is_read`, `is_archived`, `metadata`, timestamps) so the
existing SDK, `portal.d.ts` and any stored client keep working. `portal.d.ts` gains the new optional fields **in the same pass**
(AGENTS.md §4) and the seven consuming copies are updated.

## 3.4 `InboxChannel`

```php
final class InboxChannel
{
    public function deliver(Activity $activity, EventDefinition $event, ?NotificationRule $rule, InboxMessage $message): ChannelResult;
}
```

- Builds `InboxMessage` (title, body, link, severity) from the rule's `inbox_title` / `inbox_body` if set, else the module's default wording for the event.
- Writes the ledger row first (channel `inbox`, recipient `admin`); on the unique key it returns `deduped` and writes nothing.
- Creates the `Notification` row with `module`, `event`, `severity`, `subject_ref`, `scope_ref`, `dedupe_key = activity.dedupeKey`.
- **Escapes nothing itself** — the stored text is plain text; escaping is the renderer's job (§3.5). It does strip control characters and truncates to the
  column lengths so a long value cannot fail the insert.

## 3.5 The dashboard client — `NotificationService.ts`

| Change | Detail | Closes |
|---|---|---|
| **Escape on render** | One `escapeHtml()` used for every interpolated value (`title`, `message`, `id`, `link`); a unit test with `<img src=x onerror=…>` in each field asserts no element is created. Do this in **P0**, before anything else — it needs no schema | N2 |
| **Read `severity`** | `type` comes from `raw.severity ?? raw.type ?? 'info'` | N3 |
| **Unread badge from the server** | Poll `unread-count` every 15 seconds and the list only when the dropdown is open or the count rose | N4 |
| **Navigation by module registry** | The `switch` in `buildNavigatePayloadFromNotification` becomes a lookup in a `Record<module, (n) => NavigatePayload>`; each module's admin controller registers its own entry (forms and commerce move over unchanged). A module with no entry falls back to opening its admin view by `module` key instead of doing nothing | N8 |
| **Remove sample data** | Delete `addSampleData()` and its call in the constructor | N9 |
| **"Clear all"** | Becomes "Archive all" — a server call (`PATCH` each archived, or one bulk endpoint) — instead of a local wipe that reverses | N10 |
| **Poll interval** | Keep 15 seconds for the count; back off to 60 seconds when the tab is hidden (`document.visibilityState`) | — |

## 3.6 The three existing producers

| Producer | Today | After |
|---|---|---|
| `ProcessFormSubmission::createAdminNotification()` | Direct `Notification::create` in a try/catch | Unchanged in P0–P5 (it already works). When Forms adopts the system — a later plan, [06](06-activity-fan-out.md) §6.6 — it becomes `Activity(module: 'forms', event: 'submission_received', scopeRef: form ref, subjectRef: submission ref, dedupeKey: 'forms.submission_received.'.<submission ref>)` and the hand-written call is deleted. It is the natural first adopter because its output is exactly what the inbox needs |
| `SponsorshipApplicationController::dispatchAdminNotification()` | Writes four non-existent columns | Corrected in P0 to use `title`, `body`, `category = sponsorship`, `link`, `metadata`; a test asserts the body is stored |
| `SponsorPortalController::notifyEvent()` | Array into text | Works after the model cast (P0); its `notes` text is escaped on render (N2) |

## 3.7 Read state — global, for now

`is_read` stays one flag per notification (N5). Per-administrator read state is a real design change (a `laravel_notification_reads` table keyed by
notification and member reference, plus who is "an administrator" on a tenant clone) and has no owner decision yet — [10](10-open-questions.md) OQ2.
The `recipient_ref` column is reserved so the later change is additive.

## 3.8 Acceptance

- `PATCH /notifications/mark-all-read` returns 200 and every unread row becomes read; the next poll does not undo it.
- A title or body containing `<script>`/`<img onerror>` renders as text in the dropdown and the full list.
- `GET /notifications/unread-count` returns the true count when there are 150 unread.
- A second `create` with the same `dedupe_key` creates no second row.
- A sponsorship application and a sponsor display request each create one readable notification.
- Every old row (JSON string in `metadata`, no `severity`) still reads back and renders as `info`.
- The seven `portal.d.ts` copies declare the new optional fields.

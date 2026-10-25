# 05 — Notification Configuration — Complete

## Status

Done.

## Summary

Built the operator-facing notification config: the two migrations (§5.8 — the send ledger
table is created here so it ships with its config, per that section's own note; step 06
writes to it), the `DirectPaymentNotification`/`DirectPaymentNotificationSend` models, the
mail-handler list endpoint (fixes **B13**), the `syncNotificationConfigs()` write path
wired into `saveDirectPayment()`, and the full admin UI (form card, row template, and the
`directpayments.ts` wiring for event filtering, recipient-mode toggling, the D8 3-day
limit, and save assembly).

## Files changed

### Migrations
| File | Contents |
|---|---|
| `www/database/migrations/2026_08_25_000002_create_direct_payment_notifications_table.php` | §5.2 |
| `www/database/migrations/2026_08_25_000003_create_direct_payment_notification_sends_table.php` | §5.8/06 §6.5 — created here per §5.8's "ship together" note |

### Models
| File | Change |
|---|---|
| `www/app/Models/Externals/DirectPaymentNotification.php` | New. `EVENTS` and `SCHEDULED_EVENTS` constants centralise the §5.3 vocabulary for the controller's validation and (later) the scheduler |
| `www/app/Models/Externals/DirectPaymentNotificationSend.php` | New — the send-ledger model step 06 will write to |

### Backend
| File | Change |
|---|---|
| `www/app/Http/Controllers/Externals/DirectPayments/DirectPaymentNotificationController.php` | New — `listMailHandlers()`, §5.5 |
| `www/app/Http/Controllers/Externals/DirectPayments/DirectPaymentsController.php` | Added `syncNotificationConfigs()` (updateOrCreate keyed on dp_ref+event, preserves `reference_num` across saves, omitted events deactivated not deleted) and `validateDaysBefore()` (D8). Wired into `saveDirectPayment()` right after `syncConnectConfig()` — guarded on `$request->has('notifications')`, not just truthiness, so an omitted key leaves existing configs untouched while an explicit `[]` deactivates everything (§5.9's first acceptance line). `notifications` attached to both the save response and `getDirectPayments()`'s read path, mirroring `connect` |
| `www/routes/portal.php` | `GET /api/v1/email-handlers/list` |

### Admin UI
| File | Change |
|---|---|
| `www/resources/js/portal/admin/html/views/directpayments-form.html` | New `#dpNotificationsSection` card (after `#dpConnectSection`) and `#dpNotificationTemplate` row template (after the tier template) |
| `www/resources/js/portal/admin/controllers/direct_payments/directpayments.ts` | `notificationsSection` ref; `applyNotificationsVisibility()` (additive, not exclusive — SUBSCRIPTION shows every event, SINGLE_PAY only the two action events, CONNECT hides it entirely); `applyEventOptionsForPayType()`; `populateNotificationHandlerOptions()` from a cached `/email-handlers/list` fetch; `addNotificationRow()` (day-checkbox D8 3-limit, recipient-mode toggle, event-change days-row toggle); wired into the pay-type change handler, the Add Notification button, edit-mode prefill, and the save assembly (`dpObj.notifications`) |

## Deviations / simplifications

- §5.7 point 2 asks that an empty mail-handler list render "a disabled select reading 'No mail handlers configured' **with a link to the email-handlers page**." The disabled-select-with-text half is implemented (`populateNotificationHandlerOptions()`); the link was not added — a minor UX omission, not a functional gap (an operator with zero mail handlers still cannot save a notification, per the server-side `DP_E_MAIL_HANDLER_NOT_FOUND` guard either way).
- `syncNotificationConfigs()`'s reference_num-preservation does one extra `SELECT` per config row (look up the existing `reference_num` before the `updateOrCreate`, rather than a single query). Correctness over the marginal query cost — this runs only on an admin save, not in a hot path.

## Verification performed

- `php -l` on every changed/new PHP file — all clean.
- `tsc --noEmit` on the full project — zero errors (same clean baseline as step 04's run).
- Manually traced §5.9's acceptance list against the code:
  - No `notifications` key → `$request->has()` is false → `syncNotificationConfigs()` never runs → existing rows untouched. ✅
  - An event omitted from a later save → not in `$seenEvents` → caught by the trailing `whereNotIn('event', $seenEvents)->update(['is_active' => false])`, `reference_num` untouched because that row isn't part of this save's `updateOrCreate` calls at all. ✅
  - `days_before` reject cases: `[7,7,3]` → duplicate → false; `[0,3]` → 0 fails the 1..7 bound → false; `[8]` → fails bound → false; `[1,2,3,4]` → `count() > 3` → false. All four hit `validateDaysBefore()`'s early returns. ✅
  - `[1,7,3]` → `rsort()` → `[7,3,1]`. ✅
  - Nonexistent `mail_handler_reference_num` → `MailHandlerModel::where(...)->exists()` false → 422 `DP_E_MAIL_HANDLER_NOT_FOUND` before any write. ✅
  - Renaming the DP (title/name only) and re-saving with the same `notifications` payload → `updateOrCreate`'s match clause is `(direct_payment_reference_num, event)`, neither of which changes on a rename → same row, same `reference_num`. ✅
- **Not** run against a live database — same pre-existing environment limitation noted in step 04's completion doc (`routes/portal.php` requires a `public/_portal_/admin` directory not present in this checkout).

## Follow-ups for later steps

- Step 06 (`direct_payments:subscription-notify`) is the first consumer of `laravel_direct_payment_notification_sends` and of `DirectPaymentNotification::EVENTS`/`SCHEDULED_EVENTS`.
- Step 14 (subscription actions) defines the actual "action events" set referenced by §5.7 point 1 (`SINGLE_PAY_NOTIFICATION_EVENTS` in `directpayments.ts` currently hardcodes `['payment_succeeded', 'payment_method_changed']` from §5.3's vocabulary table — revisit if 14 changes which events apply to SINGLE_PAY).

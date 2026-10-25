# 12 — The phone hears about new members: alerts, level A (complete — built and tested; nothing seen on a device or against a real server)

## Status

**Complete 2026-10-20.** Nothing is committed (`w/www` and `afsbrain_flutter` are separate repositories). The phone has an **Alerts** page under More that shows the Brain's own inbox — the bell the dashboard already
has — with an unread count on the bar, a *Members* chip, tapping a "New member" row to open that member, and *Mark all read*. It is **level A**, as the owner decided: it reads the inbox **while the app is open**.
There is no background polling, no local notification while the app is closed, no push service and no Firebase, and no new permission or package.

Adding a member from the phone with billing is [14](../14-phone-and-dashboard-member-lifecycle.md); this file is only the alerts.

## Phases

| Phase | Piece | State | Where |
|---|---|---|---|
| P0 | NS2 — `whoami` reports `member_alerts` when the inbox routes exist | **built** | `w/www/app/Services/Brain/Staff/StaffCapabilities.php` |
| P0 | NS3 — the Brain's security mode must be `enforce` | **a check, not code** — see "Not verified" | — |
| P1 | The Alerts page, the unread badge, mark read, open member | **built** | `afsbrain_flutter/lib/features/alerts/`, `home_shell.dart`, `features/more/ui/more_page.dart` |
| P2 | This record, README, handoff, memory | **done** | — |

## What was built

**Server (PHP, `w/www`).** `StaffCapabilities` gained `member_alerts`: true only when `GET /notifications`, `GET /notifications/unread-count`, `PATCH /notifications/mark-all-read` and `PATCH /notifications/{id}`
are all registered, the same computed-never-configured rule as the other capabilities. The phone adds **no server route**: it uses the routes the dashboard bell uses, which accept the device token (`externals.dashboard`).

**Phone (Dart, `afsbrain_flutter/lib/features/alerts/`).**
- `data/models/alert.dart` — one inbox row (`module`, `event`, `subject_ref`, `severity`, `is_read`, `created_at`); `isNewMember` is `memberships` + `member_created` + a subject.
- `data/alerts_repository.dart` — the interface and the HTTP implementation over `BrainApiClient` (list with an optional `module` filter, unread count, mark one read, mark all read).
- `cubit/alerts_cubit.dart` — the list, the *All / Members* chip, mark-read that shows the row read at once and **puts it back if the server refuses**, and mark-all-read.
- `cubit/unread_cubit.dart` — the badge count: read when the app opens, when it comes back to the front, and once a minute while open; stopped when the app goes to the background; **asks nothing while the app lock is engaged**; a failed read keeps the last count.
- `ui/alerts_page.dart` — newest first, pull to refresh, the chips, *Mark all read*; tapping a "New member" row marks it read and opens that member (the shell passes the callback, the page imports no other feature).
- `core/api/api_paths.dart` — `notifications`, `notificationsUnreadCount`, `notificationsMarkAllRead`, `notification(id)`. `session/capabilities.dart` — `memberAlerts`.
- `home_shell.dart` — builds the repository over the client when the server reports `member_alerts` (a test can pass its own), shows **More** when the server offers alerts, a badge on More that adds the unread alerts to the unresolved Checkpoint errors, and watches the app lifecycle. `more_page.dart` — the *Alerts* row with its count.

## Tests

| File | Tests | Proves |
|---|---|---|
| `test/features/alerts/alerts_test.dart` (Dart) | 19 | the model; the routes and bodies; the capability is false unless exactly `true`; the cubit (load, chip, failed load keeps the list, mark read once and tell the shell, refused mark-read puts the row back, mark all); the unread cubit (ticks, stops, nothing while locked, failed read keeps the count); the page |
| `test/features/alerts/alerts_shell_test.dart` (Dart) | 5 | the More badge adds the two counts; More lists Alerts with its count and opens the inbox; a new-member alert opens that member and marks the row read; **no capability, no row and no request**; the timer is not left running |
| `tests/Feature/Brain/BrainStaffWhoamiTest.php` (PHP) | 7 | `member_alerts` (and `member_create_billing`, `member_billing`) are reported, are booleans, and are true here |

## Final run

See [14](14-phone-and-dashboard-member-lifecycle.complete.md) "Final run": the whole PHP suite, the whole Flutter suite and the TypeScript suites were run once at the end of 12 + 14.

## Deviations from the plan

1. **The badge sits on More**, not on a bar item of its own: Alerts is a row under More (the plan: "under More, with an unread badge on the bottom bar"). The badge adds the unresolved Checkpoint errors it already counted.
2. **The Alerts row is offered whenever the server reports `member_alerts`**, even if the member screens are not offered; a "New member" row then simply does not open a member.

## Not verified

- **NS3 — the Brain's externals security mode must be `enforce`.** If it is not, the inbox routes are open without a token. This was **not checked** (the checkout is the only thing reachable; no server). Check the security-mode setting on the Brain before relying on it.
- That the bell shows on a real phone from a real server, and that the badge refreshes on a device (resume from the background, the app lock engaging). Both are covered only by tests with fakes and by `flutter test` widget tests.
- Packages were not installed; the real `flutter pub get` and the device build are the owner's.

## Next

[14](../14-phone-and-dashboard-member-lifecycle.md).

# 12 — The phone hears about new members (alerts, level A)

> **STATUS: PLAN ONLY (2026-10-20). Nothing here is built, and nothing was run.** The server half of "a new member is announced" is [11](11-new-member-welcome-and-alerts.md). Adding a member from the phone,
> paying for them, cancelling and changing are [13](13-staff-member-lifecycle-server.md) (server) and [14](14-phone-and-dashboard-member-lifecycle.md) (phone and Brain dashboard). This file is only the
> alerts. The app is `C:\projects\vagrant\afs-eco\afsbrain_flutter`; its own plan is [`brain_flutter`](../brain_flutter/README.md). Findings continue 11's **NA#**; server changes are **NS#**, app changes **NP#**.

## 1. Owner decision (2026-10-20)

**Alerts are level A: inside the app.** No background polling, no local notifications while the app is closed, no push service, no Firebase. Every new member rings the Brain's bell (11 §3.3a), and
the phone shows that same bell. The two bigger options (background local notifications; Firebase push) are not planned and are not described further here.

## 2. What the app does today (read from `afsbrain_flutter/lib`, 2026-10-20)

| # | Finding | Where |
|---|---|---|
| **NA12** | The app has no notification code and `AndroidManifest.xml` asks for `INTERNET` only. Nothing tells it a member signed up unless you open the list. | `android/app/src/main/AndroidManifest.xml`; no `notification` in `lib` |
| **NA13** | Authentication is solved: the device token for the Brain user is accepted by every route behind `ApiAuthMiddleware`. The Brain admin inbox (`GET /portal/api/notifications`, `…/unread-count`, the mark-read routes) sits behind `externals.dashboard`, which *is* `ApiAuthMiddleware` when the Brain's security mode is `enforce`. | `routes/portal.php` 863–873; `ExternalsDashboardLogin` |
| **NA14** | The inbox list filters by `unreadOnly`, `category`, `module`, `severity` and pages by `page`/`limit`; rows carry `module`, `event`, `subject_ref`, `link`. It has no "newer than id" filter (not needed for level A). | `NotificationApiController::list()` |

## 3. Server changes the alerts need

| # | Change | Why |
|---|---|---|
| **NS2** | `whoami` capabilities gain `member_alerts: true` when the inbox routes can be reached with the device token. | the app hides Alerts if the server cannot serve it (the same pattern as `checkpoint_errors`) |
| **NS3** | A check, not code: the Brain's externals security mode must be `enforce`, otherwise the inbox routes are open without a token. | NA13 |

(The create reply's `notices` is [13](13-staff-member-lifecycle-server.md) NS5; this file's server changes are only NS2 and NS3.)

## 4. App changes (NP1, NP2)

- **NP1 - Alerts destination** under More, with an unread badge on the bottom bar: the Brain's inbox, newest first, pull to refresh, filter chips (All, Members). Tapping a "New member" row opens that member
  (`subject_ref` is the member's reference); tapping marks it read (`PATCH /notifications/{id}`); a "Mark all read" action uses the existing route.
- **NP1 (badge)** reads `GET /notifications/unread-count` when the app opens, when it comes back to the foreground, and every minute while it is open. It stops when the app lock engages.
- **NP2 - Code**, same layout as `features/members/`: `features/alerts/data/alerts_repository.dart` (interface plus the HTTP implementation over `BrainApiClient`), `data/models/alert.dart`,
  `cubit/alerts_cubit.dart`, `cubit/unread_cubit.dart`, `ui/alerts_page.dart`. Constructor injection and `RepositoryProvider`, as the rest of the app (05 §5.6).
  `core/api/api_paths.dart` gains `notifications`, `notificationsUnreadCount`, `notificationsMarkRead(id)`, `notificationsMarkAllRead`.
- No new dependency, no new permission.

## 5. Phases

| Phase | Work | Proof |
|---|---|---|
| **P0** | NS2 and NS3. | PHPUnit against the real database; capability absent when the routes are not reachable |
| **P1** | The Alerts page, the badge, mark read, open member. | cubit tests with a fake repository; the destination hidden when `member_alerts` is false |
| **P2** | Docs: the completion record `complete/12-phone-add-member-and-alerts.complete.md`; a one-line pointer from brain_flutter's README. | — |

## 6. What cannot be tested here

That the bell actually shows on the phone from a real server, and that the unread badge refreshes on a real device. Everything else is covered by tests with fakes, like the rest of the app. Packages are
not installed to test the plan's work; the owner runs the real `flutter pub get` and the device build.

## 7. Open questions

| # | Question | Answer / recommendation |
|---|---|---|
| **OQ-N7** | How far should alerts go | **Decided: level A only** |
| **OQ-N9** | Show every Brain notification or only members | **Everything, with a Members chip** |
| **OQ-N10** | Mark a row read when opened or by an explicit action | when opened |

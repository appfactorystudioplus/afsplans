# 11 — A new member is finished properly, welcomed, and announced (complete — built and tested; the new e-mail and page not seen on a server)

## Status

**Complete 2026-10-20.** Nothing is committed. Both ways of creating a member — the public signup and the operator's create (the Brain admin form and the phone app) — now finish the member the same way, and announce
`memberships.member_created` once: the welcome e-mail goes to the **member's own address**, the Brain's bell rings for **every** signup, and the signup is counted. An operator can pick how the password comes about:
typed now, a set-your-password link, or both. The Memberships module exists **only on the Brain**.

Work is in `w/www` and `w/auth` (two repos). **Not done here, by design:** the phone and the dashboard create forms do not send the new fields yet (`password_mode`, `send_welcome`) — that is
[14](../14-phone-and-dashboard-member-lifecycle.md). The paid side (free periods, cards, Stripe) is [13](../13-staff-member-lifecycle-server.md).

## Phases

| Phase | Piece | State | Where |
|---|---|---|---|
| P0 | Characterization | **partly** — see Deviation 1 | `tests/Feature/Membership/*` |
| P1 | One "finish creating a member" routine for both paths; the operator's create gains payer, e-mail propagation and storage | **built** | `w/www/app/Services/Brain/Memberships/MemberProvisioning.php`, `MemberStorageProvisioner.php`, `MembershipController` |
| P2b | `auth`: the page the reset e-mails link to, a set-password e-mail, a system-key route; the Brain's client for it | **built** | `w/auth` (below), `CheckpointServerClient::requestPasswordSetup` |
| P2 | The Memberships module, Brain-only registration, built-in welcome e-mail | **built** | `app/Services/Brain/Memberships/Notifications/*`, `ActivityServiceProvider`, `…/defaults/memberships/` |
| P3 | `finish()` announces; the operator's create takes `password_mode` and `send_welcome`; the dead welcome block, `contructMailHandler()` and `store2()` deleted | **built** | `MembershipController` |
| P4 | The settings card on the Brain Memberships screen | **built** (not opened in a browser); no new i18n keys, see Deviation 6 | `brain_membership.ts` |
| P5 | This record, README, memory | **done** | — |

## What was built

**The shared routine (`MemberProvisioning`).** Constructor-injected (`PayerService`, `MemberStorageProvisioner`, `ActivityPublisher`, `MembershipsActivities`); no `app()`.
- `payerFor($data, $createdBy, $reservedReference)`: the payer the member hangs off — the signup's own invoice payer first (the old `store()` logic, unchanged), else one payer per e-mail. The public signup passes `afs_signup`, the operator's create `admin_create`.
- `finish($member, $data, $payer, $sendWelcome)`: (1) propagate the e-mail to the payer and its provider customers, (2) create the storage from the **stored** reference, (3) announce. Each step is best-effort: a failure is logged and
  reported in the returned notices (`payer_email`, `storage`, `welcome`); it never turns a created member into an error.
- `MemberStorageProvisioner` is the old two shell calls (`GetManagedDomain()` + `RunCommandBG('--create-afs-member-storage …')`) moved into a class of their own, behaviour unchanged, so creating a member can finally be tested.

**The operator's create (`adminStore`).** Now: gets a payer, gets storage, announces; takes `password_mode` — `set_now` (default, the old behaviour), `send_link` (registered with a random 40-character password nobody sees, then Checkpoint e-mails the
member a link; **no session is handed back**), or `both` — and `send_welcome` (default true; false = no e-mail, bell and count remain). The reply gains `notices {payer_email, storage, welcome, password_link}`.

**The module.** `memberships.member_created`: bell on, e-mail on, template `memberships/member_created`, variables `memberships_full_name`, `…_username`, `…_plan`, `…_login_url`, `…_free_period`. The Activity carries `member` (reference), `plan` and `quiet` only — no
name, no address, no password. The recipient resolver returns the member's address, or nothing for a quiet create. The sign-in address is `config('memberships.login_url')` from `.env` (`MEMBERSHIPS_LOGIN_URL`); unset falls back to the application URL
with a log warning. `config/memberships.php` is the only place `env()` is read. Registered in `ActivityServiceProvider` only when `config('brain.is_brain')` is true.

**`auth` (Checkpoint).** `GET /checkpoint/auth/reset-password` — a page that did not exist (the reset e-mails linked to it and got a 404) — shows a choose-your-password form and posts selector, token and password to the existing `/password/reset`;
the page decides nothing about the pair. `POST /checkpoint/member/password-setup` (system key, like `member/init`) → `AuthenticationService::requestPasswordSetup` (24-hour request) → `EmailService::sendPasswordSetupEmail` ("Set your password").
The "continue to sign in" link on the page comes from `CHECKPOINT_AFTER_RESET_URL` and is absent when unset. The pair never reaches the Brain.

**Removed.** The old welcome block in `store()` (needed a mail handler no client sent; sent to a fixed address), `contructMailHandler()` (with its blanked card variables), and the unrouted `store2()`.

## Tests

PHP on MariaDB 11.4.5 (port 3307, `testdb_phpunit`); `auth` on its own SQLite in-memory suite. Run once, at the end, progress shown.

| File | Tests | Proves |
|---|---|---|
| `w/www/tests/Feature/Membership/MembershipsAdoptionTest` | 12 | operator create → payer set, storage once from the stored reference, welcome to the member's address with the right name, plan (the server's label) and sign-in address from config, one bell, one analytics count; the public signup gets the same steps; `send_welcome=false` sends nothing but bell and count remain; announcing twice is one e-mail and one bell; a storage failure leaves the member created, welcomed and reported; `send_link` registers a random password, asks for the link, returns no session and never shows the password; `both` uses the typed password and sends the link; `set_now` never asks for a link; a link that cannot be requested is reported, not thrown; no password enters the Activity, the ledger, the bell, analytics or the mail variables; the module exists on the Brain and not on a tenant; the dead code is gone |
| `w/www/tests/Feature/Membership/MembershipTierAndStatusTest` | edited | its D2 test (`level` is never taken from the create body) now needs the payments tables and a do-nothing storage stand-in, because the operator's create now makes a payer and storage; its assertions are unchanged |
| `w/auth/tests/Feature/PasswordSetupTest` | 7 | the page exists, carries the pair, escapes what the link carries, sends no caching headers; the continue link is config-driven; the route needs the system key; an invalid address is refused; a valid request asks the service once and returns neither selector nor token; "too many requests" is reported |
| existing | — | `Feature/Membership`, `Unit/Notifications` (the G10 no-secret scan covers the new Activity), `Unit/Activity`, `Feature/Notifications` (catalogue tests) |

## Final run

Below-normal priority, one process at a time, MariaDB 11.4.5; MariaDB shut down cleanly afterwards.

| Pass | Suites | Result |
|---|---|---|
| PHP, `w/www` | `tests/Feature/Membership`, `tests/Unit/Notifications`, `tests/Unit/Activity`, `tests/Feature/Notifications` | **OK: 288 tests, 1064 assertions** |
| PHP, `w/auth` | `tests/Feature/PasswordSetupTest` | **OK: 7 tests, 22 assertions** |
| TypeScript | `tsc --noEmit`; `npm run test:admin` | clean; **81 of 81 pass** |

Not run: the rest of the `w/www` suites (Direct Payments, Processor, Forms, Ecommerce, Events, Appointments, Site Users, Subscribers, Connections, Externals) and the rest of `w/auth` — nothing in this step touches them, but I did not run them.

## Deviations from the plan

1. **P0 characterization of `store()` was not possible before the change.** The plan said to record what `store()` does first. `store()` could not be run end to end in tests (its storage step opens a real MySQL connection through `GetManagedDomain()`, a limit its own test files document). The seam (`MemberStorageProvisioner`) had to come first, and the tests describe the new behaviour. The pieces of `store()` that were testable (reservation, the failed-Checkpoint path, the payer composition) have their existing tests and they pass unedited.
2. **A storage failure no longer turns a completed signup into a 500.** It is logged and reported as `notices.storage = failed`, in both paths (the plan said the storage step "keeps its current behaviour"). The member exists either way, which is the same reasoning the old welcome block already used.
3. **Config:** only `memberships.login_url` was added. The plan's `password_set_url` is not needed (the page lives in Checkpoint, whose own `.env` names its host) and `card_page_url` belongs to step 13.
4. **The bell has no link.** The plan said "link to the member"; I could not verify an admin path for a member screen, so the notification carries none rather than a wrong one.
5. **`memberships_free_period` is declared and always empty** until step 13 gives it a value; the template line is blank until then.
6. **No new i18n keys.** The settings card reuses the existing `notifications.*` strings, so the plan's "5 languages" item is empty. The Memberships event's own label and bell text are the module's English strings, like every other module's.
7. **The welcome subject is plain** ("Your new account is ready"), not `Welcome to ${name}`: I could not confirm the default-email code substitutes brand names in a subject.
8. **`send_welcome` is also honoured on the public signup** (it shares `wantsWelcome`). A public caller can therefore ask not to be welcomed; harmless.
9. **The set-password e-mail and page are new in `auth`**, which the plan expected (P2b); the reset e-mail's old link finally has a page too, which fixes forgot-password for everyone, not just new members.

## Not verified

- **No server, no real e-mail.** The tests use a recording mail seam. Whether SendGrid is configured in Checkpoint (it skips sending, with a log line, when no key is set) and whether the Brain's own mail provider token is valid are untested.
- **delight-im's `forgotPassword` may refuse an account whose e-mail is not verified**, and Checkpoint swallows that and still answers "sent". If operator-created accounts are registered unverified, **the link would silently not be sent**. I did not check whether `registerUser(... requireEmailVerification: false)` marks the account verified. This is the first thing to try on the development environment.
- **The reset page was not opened in a browser.** Its HTML and its escaping are tested; its script was not run.
- **The Memberships settings card was not opened in a browser** (`tsc` clean, `npm run test:admin` passes, 81 of 81).
- **`.env` values to set before it works:** `MEMBERSHIPS_LOGIN_URL` (www/Brain) and, optionally, `CHECKPOINT_AFTER_RESET_URL` (auth). Deploy `auth` before `www`: the Brain's new call goes to a route that does not exist on an older `auth`.
- **Neither the phone nor the dashboard create form sends `password_mode` or `send_welcome` yet** (step 14); until they do, an operator-created member is welcomed and gets a password exactly as typed.
- **The welcome e-mail and the Direct Payments receipt are two separate e-mails** for a paid signup; I did not look at how they read together.

## Next

[13](../13-staff-member-lifecycle-server.md) (billing through Direct Payments, `paused`, tax) and [14](../14-phone-and-dashboard-member-lifecycle.md) (the screens that send the new fields).

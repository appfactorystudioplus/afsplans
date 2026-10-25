# AFS Mail: building the app and investigating "email not arriving" (2026-10-21 / 22)

## Summary

- **AFS Mail** (Flutter, `afsmail_flutter`) was built and installed on the moto g play against the **dev** Brain (`https://www15.appfactory.studio`).
- Sending from the app works end to end: the app posts to the website's mail API, the server hands the message to SendGrid, SendGrid accepts it, and the Sent copy is filed.
- Messages to `james.mitchell2@yahoo.com` were slow or missing because **Yahoo is throttling SendGrid's sending IPs** (`421 4.7.0 [TSS04]`). It is a temporary deferral, not a bounce, a block, a stale key, or an app fault. The web client is affected the same way.
- The investigation used the server's logs, the dev database, and a read-only PHP script that asks SendGrid's API for suppression lists and per-message event history. That script is documented below.

Nothing was committed. Dev-server changes made: two code files uploaded, one tenant row inserted (both below). Everything else was read-only.

## 1. What was built

### Brain (repo `w/www`, marked `AFS_MAIL_MODIFIER_ADDITION`)

| File | Purpose |
|---|---|
| `app/Http/Controllers/Brain/BrainMailTenantController.php` | `GET /portal/api/brain/v1/mail/tenant?domain=` returns the tenants that may host the mailboxes of an email domain |
| `routes/brain.php` | the route: `brain.only` + `throttle:30,1`, public |
| `tests/Unit/Brain/BrainMailTenantDomainTest.php` | domain validation (12 tests, pass) |

Why it returns a **list**: `brain_http_server_tenants` can hold several rows for one domain (`laurensmenu.net` has three clones with separate databases), and `www` / `www15` Brains each have their own rows. The lookup is scoped to the Brain's own subdomain, newest first, and exposes only `reference_num`, `domain`, `address`, `active`, never the tenant `json` (it holds database credentials). The app tries each candidate's login until one accepts.

### App (`afsmail_flutter`, Android + iOS)

- Sign-in: email domain, then Brain lookup, then `POST {address}/portal/mail/login` per candidate, then the tenant and credentials are saved in the platform keystore.
- Mail calls go to that tenant's `/portal/mail/api/*`: folders, fetch, move, delete, create-folder, send. Sending posts `{to, subject, body}` to `send-email`; the **server** sends it.
- **Several accounts**: add, switch, sign out of one.
- The session cookie (`portal_mail_auth`, 120 min) is held in memory; the app signs in again with the saved credentials when the website says it expired.
- Quirk handled: the mail API reports most failures as HTTP 200 `{"status":"error"}`.
- Verified: `flutter analyze` (style hints only), 13 tests with a stubbed HTTP client.
- Plan and as-built notes: `plans/afs_mail/01-afs-mail-app.md`, `plans/afs_mail/complete/01-afs-mail-app.md`.

## 2. Changes made on the dev server (`root@45.79.42.137`, `/home/appfactorystudio/websites/dev/www`)

1. **Tenant row** inserted into `brain_http_server_tenants` in `testdb_appfactorystudio` (the table was empty): `appfactory.studio`, subdomain `www15`, reference `uv6Op6gaAGdg`. Built from the `subdomain_properties` rows with `SubDomain='www15'`, in the same format as `core/config/db.sql`. The same row is in `core/config/testdb_appfactorystudio.data-only.sql`.
2. **Two files uploaded** with `upload-changed.sh --vscode`: `BrainMailTenantController.php` (new) and `routes/brain.php`. The replaced `routes/brain.php` is backed up in `w/.upload-backups/20261021-230152/`. `php -l` passed; no route/config cache exists so no `artisan` step was needed. The endpoint returned 200 for `appfactory.studio` and 404 for an unknown domain.
3. The app's `.env` was switched to `_env/development/.env` (Brain `https://www15.appfactory.studio`).

## 3. The email problem: how it was investigated

### 3.1 How a send flows

```
phone app ──POST /portal/mail/api/send-email {to,subject,body}──▶ tenant website (www15)
   MailMuaController::sendEmail
     ├─ UsersEmail::canSend()                        (403 ACCOUNT_DISABLED if not)
     ├─ active row in laravel_mail_providers          (503 NO_MAIL_PROVIDER if none)
     ├─ key = brain_config.controller.email_providers[0].token   (local table, no cache)
     ├─ FunctionsController::sendEmailWithIntegration ──▶ SendGrid HTTPS API
     ├─ status ≥ 300  ─▶ log "[MailMua] send failed" + HTTP 500 "Failed to send email"
     └─ status < 300  ─▶ copy appended to INBOX/Sent over IMAP, reply "Email sent"
```

The server's own postfix is not in the outbound path, so its mail log shows nothing for these messages.

### 3.2 Timeline of findings

1. **First symptom:** "I'm having trouble sending email." `laravel.log` showed two failures at 02:42–02:43 UTC: SendGrid `401 Maximum credits exceeded` (the old key / account quota).
2. **Stale key theory:** the owner had updated the SendGrid key. `brain_config` has one `default` row (dated 2026-05-24), read directly with no cache. Its token is 69 characters ending `…FFTI`, which the owner confirmed is the new key. So the dev site had the right key and the theory was ruled out.
3. **Same code path:** nginx access log showed the app (`Dart/3.13`) and the browser both getting `HTTP 200` with the same 43-byte body (`{"status":"success","message":"Email sent"}`) after the key update. No new `[MailMua] send failed` lines after 02:43.
4. **Run on the device:** the app was driven over `adb` (see section 5). It was signed in as `james1@appfactory.studio`, not `james@`; after switching to `james@appfactory.studio` a test to `james.mitchell2@yahoo.com` returned HTTP 200 and the copy appeared in Sent. A copy in Sent proves SendGrid returned a status below 300 (the server appends it only after the status check).
5. **Server mail log (`/var/log/maillog`):** postfix and dovecot active; no activity at the send times, no outbound to Yahoo, **0 bounced and 0 deferred** on Oct 22. A Yahoo message to `james@` was delivered at 05:11. So no bounce email is expected: SendGrid reports bounces on its side, not into the sender's mailbox.
6. **SendGrid side** (section 4): suppression lists empty; per-message events show Yahoo deferrals.

## 4. Checking SendGrid's side with a script

### 4.1 Approach

SendGrid's data is only reachable with the API key, and the key lives on the dev server in `brain_config`. So the script runs **on the server**, boots Laravel, reads the key with `FunctionsController::getBrainConfig()`, and calls SendGrid's API with PHP curl. It never prints the key. It only issues `GET` requests.

How it was run, without quoting problems or leaving a file behind:

```bash
B64=$(base64 -w0 sg_check.php)
./remote-run.sh --key-only -t 100 "echo $B64 | base64 -d > /tmp/sg_check.php && php /tmp/sg_check.php; rm -f /tmp/sg_check.php"
```

`remote-run.sh` starts in the project folder on the server (`.../dev/www`), which is why `require 'vendor/autoload.php'` works. The script is passed base64-encoded so no shell quoting or backslash can be altered, and it is removed afterwards. (Scratch copies are in the session scratchpad, not in the repo.)

### 4.2 Bootstrapping and reading the key

```php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$c   = App\Http\Controllers\Portal\FunctionsController::getBrainConfig();
$key = (string) ($c['controller']['email_providers'][0]['token'] ?? '');   // same lookup the mail code uses
```

### 4.3 Step 1: suppression lists (is anything permanently blocking the recipient?)

`GET https://api.sendgrid.com/v3/suppression/{bounces,blocks,invalid_emails,spam_reports,unsubscribes}?limit=500&start_time=<14 days ago>` with `Authorization: Bearer <key>`. Entries mentioning `yahoo` or `appfactory.studio` are printed with date, status and reason.

Result: all five returned **HTTP 200 with 0 entries** in the last 14 days. This also proved the key is valid and has the right scopes.

### 4.4 Step 2: Email Activity (what happened to each message?)

`GET /v3/messages?limit=20&query=to_email="james.mitchell2@yahoo.com"` returns per-message `status` (`processing`, `delivered`, `not_delivered`), `from_email`, `subject`, `opens_count`. The account has this feed available (HTTP 200). Result: 9 messages, 8 still `processing`, 1 `delivered`.

### 4.5 Step 3: event history of each message

`GET /v3/messages/{msg_id}` returns `events[]` (`processed`, `deferred` with `reason`, `delivered`, `open`, `bounce`). Printed per message with the time, event and first 260 characters of the reason.

Key evidence:

```
Hey James (james@, 04:24)   processed 04:24:09 -> deferred x5 -> delivered 04:32:28 "250 ok dirdel" (~8 min)
Hey there James (04:33 web) processed 04:33:09 -> deferred ... still deferred at 05:08
Hey James (james3@, 04:22)  deferred ... "421 4.7.0 [TSS04] Messages from 159.183.224.105 temporarily
                            deferred due to unexpected volume or user complaints" (postmaster.yahooinc.com/error-codes)
```

Other deferral lines read "IPs were throttled by recipient server".

### 4.6 How to read the result

| SendGrid shows | Meaning |
|---|---|
| in a suppression list (bounce / block / invalid) | permanent; SendGrid will not retry; a bounce email may be generated |
| `processing` + `deferred` events | accepted, but the receiving server answered "try again later"; SendGrid retries for up to its retry window (typically 72 h) |
| `delivered` + `250 ...` | accepted by the receiving server |
| 401 / 403 from the API | key invalid or missing the scope |

Here it is the second row: Yahoo returns `421 4.7.0 [TSS04]` (throttled by sender IP reputation / volume / complaints), so nothing bounces and no bounce email is ever produced.

## 5. Driving the phone

`adb` is not on PATH; it is in `%LOCALAPPDATA%\Android\Sdk\platform-tools\adb.exe`. Used: `monkey` to launch the app, `exec-out screencap -p` for screenshots, `input tap/swipe/text` to operate it (device `ZL8322LHPP`, 720x1600). No credentials were typed; the app was already signed in and the account switch used credentials the app had saved. At the end `adb kill-server` and the Gradle/Kotlin daemons were stopped.

## 6. Conclusions and recommendations

1. **App and server are not at fault.** Sends return 200, SendGrid accepts them, Sent copies are filed, the key matches the new one.
2. **Yahoo is deferring SendGrid's IPs.** Wait for retries, or check the SendGrid Activity feed.
3. To prove the path independent of Yahoo, send a test to a Gmail/Outlook address; it should arrive within seconds.
4. Lasting fix is on the deliverability side: a **dedicated sending IP** and confirmed **domain authentication (SPF/DKIM)** for `appfactory.studio`. Not checked: the plan type, and whether authentication is complete. An earlier "Maximum credits exceeded" suggests a limited plan, probably shared IPs.
5. App improvement to consider: the server returns "Failed to send email" for every provider failure and the app cannot show why. The real reason is only in `laravel.log`.

## 7. Open items

- Not verified: the Brain lookup against a clean database (done only against the dev server), the message viewer (WebView), and delivery of the two app test messages (still `processing` when last checked).
- Dev database is missing tables `configurations` and `laravel_queue_failed_jobs` (seen in `laravel.log`; unrelated to mail).
- Side note: `afsbrain_flutter/_env/*/.env` token values were printed in this session's output while reading those files; they are gitignored but worth rotating.

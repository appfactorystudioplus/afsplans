# afs_mail 01 — AFS Mail (Flutter app)

A phone mail client for the mailboxes the web client at `/portal/mail` serves (`MailMuaController` in `w/www`, UI in `w/www/resources/js/portal/mail`). App: `afsmail_flutter`.

## Flow

1. Person enters the full address and password.
2. The app takes the domain and asks the Brain: `GET {BRAIN_URL}/portal/api/brain/v1/mail/tenant?domain=` (public, throttled, `brain.only`). Reply: every active tenant for that domain on this Brain's subdomain, whitelisted (`reference_num`, `domain`, `address`, `active`) — never the tenant `json`, which holds database credentials.
3. The app signs in to each candidate in turn (`POST {address}/portal/mail/login`) until one accepts; that tenant and the credentials are saved in the platform keystore.
4. Mail calls go to that tenant only: folders, fetch, move, delete, create-folder, and send (`POST /portal/mail/api/send-email`: the server sends and files the copy in Sent).

## Why several candidates

`brain_http_server_tenants` can hold several rows for one domain (`laurensmenu.net` has three: separate clones, separate databases) and one Brain subdomain (`www` vs `www15`) per row. The Brain cannot tell which site holds a mailbox, so it returns all of them (newest first) and the app tries each; a wrong password on every one reports the site's own message.

## Multiple accounts

The device keeps a list of accounts (tenant + credentials) and which is open. Drawer: switch, add (sign-in form over the open mailbox), sign out of one. Each account has its own in-memory session cookie.

## Decisions

- The tenant session is the existing encrypted `portal_mail_auth` cookie (120 min); the app holds it in memory and signs in again with the saved credentials on a 401/redirect. No server change beyond the Brain lookup.
- The API reports most failures as HTTP 200 `{"status":"error"}`; the client treats that as an error.
- Message ids are IMAP sequence numbers; lists are re-read after every change.
- Compose is plain text, escaped and turned into `<br>` HTML (the server sends it as the HTML part). One recipient (the server validates `to` as one address). No attachments, cc/bcc, or drafts (not in the API).
- Message HTML is shown in a WebView with JavaScript off, a CSP, and links opened outside the app.
- Env: `_env/<mode>/.env` copied over `.env`; only `BRAIN_URL`. https only.

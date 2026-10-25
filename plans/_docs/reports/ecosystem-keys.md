# Every key in the ecosystem — what it is, who holds it, how it is checked and rotated

Written 2026-10-22. Secret values are never written in this document; where two values are compared a short fingerprint is mentioned only in the incident notes.
"Dev" means the `_env/dev/.env` files; every environment (`dev`, `lab2`, `local`, `prod`, `vagrant`, `vagrant-lan`) has its own set, and **no environment may share a secret with another** (a production secret that also sits in a lab file is not a secret, because those files are in git).

Where something is **not verified**, it says so.

---

## 1. The ten-second version

- The ecosystem is a set of Laravel apps (PHP) that call each other, plus Flutter apps and a monitor program that call the Brain. Every call between two of them is authenticated by a **shared secret** that **both sides must hold with exactly the same value**.
- A pair that differs fails with a `401`. This is the most common key fault and the hardest to see, because the message names the wrong thing (on 2026-10-22 the phone app said "the device token was rejected" while the real cause was two server files holding different afs-connect secrets — see section 9).
- There are five kinds of key:

| Kind | Who proves what to whom | Example |
|---|---|---|
| **Pair keys** (two or more files must be equal) | one app signs a request, the other checks the signature | afs-connect, Brain surface, the internal keys |
| **Own keys** (one app only, never shared) | encrypts or signs that app's own data | `APP_KEY` |
| **Infrastructure credentials** | an app logs in to a service | `DB_PASSWORD`, `REDIS_PASSWORD`, `MAIL_PASSWORD` |
| **Third-party keys** | an app talks to a provider | SendGrid, Flagsmith, HubSpot, Linode, registrar tokens |
| **Device credentials** | a phone or a server proves who it is | the phone's device token, the monitor key |

- **`bin/set-surface-keys.sh` manages the pair keys** (create, check, repair, rotate). **`bin/use-env.sh` is the only thing that puts an environment's files in place.** Nothing else should edit a secret by hand.

---

## 2. The apps

| App | Folder (`w/…`) | Role | Holds keys of kind |
|---|---|---|---|
| **www — the Brain** | `www` | the admin / control app (`IS_BRAIN=true`), the API the phone app uses | pair keys (owner of several), `APP_KEY`, infrastructure, third-party, Brain settings document |
| auth — Checkpoint | `auth` | login / JWT issuer, hosts the Parse server connection | JWT secret, Checkpoint keys, Parse |
| members | `members` | members' dashboard, billing, storage placement | pair keys, storage keys, `APP_KEY` |
| market | `market` | marketplace | pair keys, `APP_KEY` |
| coordinator | `coordinator` | afs-connect hub between the editors and www / members | afs-connect copies for every app |
| notes, flow, dream_graph, pdf_editor | `notes`, `flow`, `dream_graph`, `pdf_editor` | the editors | afs-connect (own + coordinator's), Brain surface, `APP_KEY` |
| communications | `communications` | calls / video service (Node, not Laravel) | LiveKit, TURN, rejoin token, internal key |
| storage_device | `storage_device` | file-storage agent on each storage server | storage credential, enrolment keys |
| desktop_updates | `desktop_updates` | publishes desktop-app updates | update-signature public keys |
| tenant template | `www/_env/www` | the template each tenant website starts from | `APP_KEY`, passwords only. **Brain keys must never reach it.** |
| *joystick* | not added yet | — | joins by adding lines to `KEY_GROUPS` in `set-surface-keys.sh` |
| afsbrain_flutter | `afsbrain_flutter` | the Brain phone app | device token, monitor device key, Linode token |
| afsmobile_flutter, afsdesktop5 | | client apps | URLs only, no secrets in their env files |

`wwwafsportal_v1` is out of scope and was not looked at.

---

## 3. Pair keys — the ones that must be equal

Notation: **owner** is the app that creates the value; the *places* are every `.env` line that must hold the same value. `SK` = `AFS_CONNECT_SERVICE_KEY`.

### 3.1 afs-connect (app-to-app calls)

**What it does.** Every server-to-server call between the coordinator, www, members, the editors and market is signed with HMAC-SHA256:
`signature = HMAC-SHA256( "<timestamp>.<METHOD>.<path>.<sha256 of body>", sender's secret )`, sent in the headers `X-AfsConnect-App`, `X-AfsConnect-Timestamp` and `X-AfsConnect-Signature` (code: `AfsConnectSignature` in `www/app/Externals/AfsConnect/Coordinator/` and the coordinator's copy; clock skew allowed: `AFS_CONNECT_TIMESTAMP_SKEW`, 300 s).
The **receiver looks up the sender's secret by the `X-AfsConnect-App` name** in its `peer_keys` table (`config/afs_connect.php`) and recomputes. If its copy differs, the answer is `401 Invalid afs-connect signature.`

**Each of these apps owns exactly one secret, and others hold copies.** Format: `afs_` + 43 URL-safe characters, created by the script.

| Secret (owner) | Owner's line | Copies (same value) |
|---|---|---|
| www | www `AFS_CONNECT_SERVICE_KEY` | www `…_WWW`, coordinator `…_WWW` |
| coordinator | coordinator `AFS_CONNECT_SERVICE_KEY` | coordinator `…_COORDINATOR`; `…_COORDINATOR` in **www, members, notes, flow, dream_graph, pdf_editor** |
| members | members `AFS_CONNECT_SERVICE_KEY` | coordinator `…_MEMBERS`, www `…_MEMBERS` |
| notes | notes `AFS_CONNECT_SERVICE_KEY` | coordinator `…_NOTES` |
| flow | flow `AFS_CONNECT_SERVICE_KEY` | coordinator `…_FLOW` |
| dream_graph | dream_graph `AFS_CONNECT_SERVICE_KEY` | coordinator `…_DREAM_GRAPH` |
| pdf_editor | pdf_editor `AFS_CONNECT_SERVICE_KEY` | coordinator `…_PDF_EDITOR` |
| market | market `AFS_CONNECT_SERVICE_KEY_MARKET` | coordinator `…_MARKET` |

- **Directions that matter:** www → coordinator uses www's secret; coordinator → www / members / editors uses the coordinator's; members → coordinator uses members'. An asymmetric break (one direction works, the other fails) is the typical symptom of one copy having drifted.
- **Not required today:** the editors' config also lists each other's keys (`…_NOTES`, `…_FLOW` …) and members' lists too, but they are empty everywhere and traffic goes through the coordinator. This is **assumed, not verified**; if two editors ever call each other directly, add groups for them.
- **Related non-secret settings:** `AFS_CONNECT_APP_NAME` (the name an app signs as), `AFS_CONNECT_COORDINATOR_URL`, `AFS_CONNECT_TIMESTAMP_SKEW`, `AFS_CONNECT_GLOBAL_ENABLED`, `AFS_CONNECT_DEV_ENABLED`, `AFS_CONNECT_*_BASE_URL` (the coordinator's addresses for each editor).
- **Phone app:** holds none of these, but depends on them. The afs-connect screen goes phone → Brain → coordinator.

### 3.2 Brain surface (apps calling the Brain)

**What it does.** The Brain accepts signed calls from the other apps on `/portal/api/internal/brain/*` (for example members asking www to run membership enforcement). **Each app has its own key** so a leaked key can act only as that app.

| App | Brain's line (www) | App's line | Rotation overlap line |
|---|---|---|---|
| members | `BRAIN_SURFACE_KEY_MEMBERS` | members `WWW_BRAIN_SURFACE_KEY` | `…_NEXT` |
| coordinator | `BRAIN_SURFACE_KEY_COORDINATOR` | coordinator `WWW_BRAIN_SURFACE_KEY` | `…_NEXT` |
| notes | `BRAIN_SURFACE_KEY_NOTES_EDITOR` | notes `WWW_BRAIN_SURFACE_KEY` | `…_NEXT` |
| flow | `BRAIN_SURFACE_KEY_FLOW_EDITOR` | flow `WWW_BRAIN_SURFACE_KEY` | `…_NEXT` |
| dream_graph | `BRAIN_SURFACE_KEY_GRAPH_EDITOR` | dream_graph `WWW_BRAIN_SURFACE_KEY` | `…_NEXT` |
| pdf_editor | `BRAIN_SURFACE_KEY_PDF_EDITOR` | pdf_editor `WWW_BRAIN_SURFACE_KEY` | `…_NEXT` |
| market | `BRAIN_SURFACE_KEY_MARKET` | market `WWW_BRAIN_SURFACE_KEY` | `…_NEXT` |

- Format: 64 random hex characters, **one distinct value per app** (the script warns if two are equal).
- The `…_NEXT` lines let a rotation overlap without downtime (the Brain accepts both). **The script never touches them**; `--rotate` replaces the main key everywhere at once, which is why all apps must be redeployed together.
- Config: `www/config/brain.php` (`surface`), `members/config/brain_surface.php`. Must **not** be the same value as `WWW_INTERNAL_API_KEY` or any afs-connect key (the code comments say so).

### 3.3 Internal API keys (one direction, one pair each)

| Direction | Owner's line | Other line | Notes |
|---|---|---|---|
| www → members | www `MEMBERS_INTERNAL_API_KEY` | members `WWW_INTERNAL_API_KEY` | `…_NEXT` overlap line exists on members |
| market → members | market `MARKET_INTERNAL_API_KEY` | members `MARKET_INTERNAL_API_KEY` | `…_NEXT` overlap lines on both |
| marketplace → www | market `MARKETPLACE_INTERNAL_API_KEY` | www `MARKETPLACE_INTERNAL_API_KEY` | market's config says never to reuse `MARKET_INTERNAL_API_KEY` for this |

The script creates 64-hex values for these and rotates them.

### 3.4 Checkpoint (login) keys

| Key | Places | Meaning | Script |
|---|---|---|---|
| `JWT_SECRET` | auth, www, members | the secret that signs and verifies login tokens (JWT). Anyone who has it can forge a login. | **Checked and repaired only.** Rotating it logs everyone out; `JWT_PREVIOUS_SECRETS` (auth) is the graceful-rotation list. |
| `CHECKPOINT_JWT_SECRET` | auth only | Checkpoint's own JWT setting in its env | not managed |
| `JWT_PREVIOUS_SECRETS` / `JWT_PREVIOUS_SECRETS_EXPIRES_AT` | auth | old secrets still accepted until the expiry | not managed |
| `CHECKPOINT_SYSTEM_KEY` | auth, www | www authenticates itself to auth as a system caller | created and rotated by the script (hex) |
| `CHECKPOINT_BRAIN_OPS_KEY` | auth, www | a key for Brain operations through auth; **empty everywhere in dev** (optional) | created / rotated only when it is set |
| `CHECKPOINT_DB_PASSWORD`, `MY_PASSWORD` | auth, members | database passwords used by the Checkpoint code | see section 5 |

### 3.5 Parse and allocation

| Key | Places | Meaning | Script |
|---|---|---|---|
| `PARSE_MASTER_KEY` | auth, www, coordinator, members, dream_graph, pdf_editor | the master key of the Parse server these apps use (it can read and write everything there) | **Checked and repaired, never invented or rotated:** the Parse server has its own copy; rotate it there first, then run `--repair` |
| `ALLOC_SERVICE_KEY` | www, coordinator | the allocation service credential; www's config notes it is "the same credential as auth's Parse app" | **Checked and repaired only**, same reason |
| `PARSE_REST_KEY` | auth (empty) | Parse REST key | unused in dev |

---

## 4. Own keys — never shared

| Key | Where | Meaning |
|---|---|---|
| `APP_KEY` | every Laravel app (auth, coordinator, desktop_updates, dream_graph, flow, market, members, notes, pdf_editor, storage_device, www, and each tenant) | Laravel's encryption key for cookies, sessions and encrypted columns. **Each app must have its own.** Changing it invalidates what it encrypted (sessions, encrypted values). `APP_PREVIOUS_KEYS` (empty in dev) lets old values still decrypt. Not managed by the script, correctly: it must not be equal across apps. |
| `REJOIN_TOKEN_SECRET` | communications | signs the tokens that let a dropped guest rejoin a call as the same participant |

---

## 5. Infrastructure credentials

| Key | Where | Meaning and what to know |
|---|---|---|
| `DB_PASSWORD` | all apps with a database | the same value in every app in dev (they use one database server). `MY_PASSWORD` and `CHECKPOINT_DB_PASSWORD` hold the same value too. **Not managed by the script**: changing it means changing the database user as well. |
| `REDIS_PASSWORD` | all Laravel apps | Redis login. |
| `MAIL_PASSWORD` | all Laravel apps | mail login. **In dev `REDIS_PASSWORD` and `MAIL_PASSWORD` hold the identical value in every app, which looks like a copied placeholder.** Not verified which is real. |
| `SUPER_ADMIN_PASSWORD` | www and the tenant template | the seeded administrator's password. |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` | most Laravel apps | empty in dev. |

---

## 6. Third-party keys

| Key | Where | Meaning |
|---|---|---|
| `SENDGRID_API_KEY` | auth, members, www | e-mail provider. **auth defines it twice with different values** (the later line wins); not checked by the script. |
| `FLAGSMITH_API_KEY` | auth, members, www | feature flags; empty means local flags. |
| `HUBSPOT_CLIENT_SECRET` | www | HubSpot integration. |
| `RESEND_KEY`, `POSTMARK_TOKEN`, `SLACK_BOT_USER_OAUTH_TOKEN` | optional mail / chat settings in the Laravel configs | unset in dev. |
| **Registrar tokens** (Name.com and others) | **not in env files**: the Brain settings document (`brain_config`, reference `default`, `controller.keys`, one entry per service with a `production` and a `development` slot: `username`, `client`, `secret`, `token_name`, `token`) | read by `RegistrarCredentials` / `NameComDriver`. Edited in the Brain's settings screen. |
| **Payment-provider credentials** | **not located** in the env files | the Direct Payments code works from the browser SDK and the Brain's settings; **I did not find or verify where its credentials live**. |
| `LINODE_TOKEN` | afsbrain_flutter `.env` (development) | a Linode account token used by the phone app (Linode management). It is **bundled into the APK**. Treat it as readable by anyone with the app file; use a limited token with an expiry. |

---

## 7. Storage keys (enrolled automatically)

The storage servers and members exchange keys through the Brain at enrolment ("registration must be automatic"). These are not hand-copied and not in `KEY_GROUPS`.

| Key | Where | Meaning |
|---|---|---|
| `STORAGE_CONTROL_MASTER_KEY` | members (optional) | the master from which members derives one control key per storage server; the Brain holds the same master and gives each server only its derived key. With no master set, members fetches each server's key from the Brain. Created with `php artisan storage:control-key-generate`. |
| `STORAGE_TOKEN_KID`, `STORAGE_TOKEN_PRIVATE_KEY` / `…_PATH` | members | the **private** half of the RS256 key members signs upload / download tokens with; storage devices hold only the public half the Brain publishes. With none configured, members makes its own pair, registers the public key with the Brain, and rotates it every 90 days. |
| `STORAGE_MEMBERS_KEY` (+ `_NEXT`) | storage_device | override / bootstrap only: the control key for members → device calls, used while replacing a credential by hand. |
| `STORAGE_SERVER_CREDENTIAL` | storage_device | override only; "leave empty" — the enrolled identity wins unless this is set. |

---

## 8. Other services

| Key | Where | Meaning |
|---|---|---|
| `LIVEKIT_API_KEY` / `LIVEKIT_API_SECRET` | communications | LiveKit server credentials (the webhook points at `/v1/webhooks/livekit`). |
| `TURN_STATIC_AUTH_SECRET` | communications | the fleet-wide `static-auth-secret` every Coturn server uses; clients only get derived, time-limited credentials. |
| `TURN_SERVER_n_TLS_CERT_FILE` / `…_KEY_FILE` | communications | paths to TLS files for TURN servers. |
| `INTERNAL_API_KEY` | communications | `X-Internal-Key` for whatever reports TURN load (heartbeat route). |
| `UPDATES_PUBLIC_KEYS` | desktop_updates | base64 public keys (current, next) that verify desktop update signatures; the private half is made by `afsdesktop5/scripts/updates/gen_keys.js`. Empty skips signature checks (labs only). |

---

## 9. Device credentials

### 9.1 The phone app's Brain token — `BRAIN_DEVICE_TOKEN`

- **What:** the app's permanent sign-in as the Brain member `appfactorystudio`. There is no login screen; this value in `afsbrain_flutter/_env/<mode>/.env` is the whole credential.
- **How it is made:** `php artisan brain:device-token --label="<phone>"` on that environment's Brain (`app/Console/Commands/Brain/BrainDeviceToken.php`). It prints one line once. It can be run remotely with `bin/remote-run.sh 'php artisan brain:device-token --label="moto g play"'`.
- **How it is stored:** **a random 64-character string kept in plain text** in the Brain's `laravel_user_tokens` table (the same shape as a normal portal login token) with a row in the device-token table. Anyone who can read that table can use it. Tokens can have an expiry; the current ones do not.
- **Each environment has its own database, so a token from one does not work on another.** A database reset also removes it (what happened between 2026-10-10 and 10-22).
- **Revoke:** `--list`, `--revoke=<id>`, `--revoke-all`. Retire the old token only after the new build works.

### 9.2 The phone's monitor key (server monitoring, plan 14)

- **What:** a key generated on the phone and kept in secure storage. It never leaves the phone.
- **Per server:** each monitor program receives a **derived** key, `HMAC(device key, "afs-monitor:v1:" + the server's reference)`, so one server's key opens that server only. Every request to the program is signed with it.
- **Also pinned:** the program's TLS certificate fingerprint is saved in the server's record; a different certificate is blocked with no override.
- **Rotate:** re-install or authorize the device from the Program screen (or `install.sh authorize` on the server).
- **Not a key but a switch:** `allow_dangerous` in the program's `agent.conf` (plan 16) and the phone's Settings switch together gate stopping, signalling and rebooting.

### 9.3 Signing the Android app

The app is debug-signed (`signingConfig = debug` in `afsbrain_flutter/android/app/build.gradle.kts`); there is no release keystore in the project. It is installed on one personal phone and is not published.

---

## 10. How to manage the pair keys

### 10.1 The two scripts, and the order

1. `bin/set-surface-keys.sh --env <mode> …` edits **only** `w/<app>/_env/<mode>/.env`. **It never touches an active `.env`.** `--env` is required.
2. `bin/use-env.sh <mode>` copies those files into place (the active `.env`). Only this script does that.
3. Deploy every app of that environment.

### 10.2 What the script can do (`--dry-run` first, always)

| Command | Effect |
|---|---|
| `--env dev --dry-run` | check every group; write nothing; exit 1 if anything is wrong |
| `--env dev` | fill gaps in the Brain's own file; report anything that does not pair |
| `--env dev --sync-apps` | also fill EMPTY copies in the other apps from the owner |
| `--env dev --repair` | make every group equal: the owner's value overwrites an empty or **different** copy. Nothing is rotated. |
| `--env dev --rotate` | **replace every secret it can create, everywhere, together.** Asks for the environment's name (`--yes` skips). Then redeploy everything together. |
| `--keys surface\|connect\|shared\|all` | limit to one kind |

- **What `--rotate` replaces** (19 secrets): the 8 afs-connect secrets, the 7 Brain surface keys, `CHECKPOINT_SYSTEM_KEY`, and the three internal keys. **It keeps** `PARSE_MASTER_KEY`, `JWT_SECRET` and `ALLOC_SERVICE_KEY` (other systems hold them: rotate them where they come from, then `--repair`), and leaves `CHECKPOINT_BRAIN_OPS_KEY` alone while it is unset.
- **Backups:** each written file gets `.env.bak-surfacekeys-<timestamp>` next to it (untracked, **not git-ignored**, they hold the old secrets): delete them once the redeploy works.
- **Adding an app (joystick):** add its lines to `KEY_GROUPS` in the script (owner first, then every place that must be equal). Check, fill, repair and rotate then cover it with no other change.

### 10.3 What the script cannot see

- It reads the files it is pointed at (`--root`). Whatever is deployed elsewhere can only be checked by running it where those files are.
- Secrets that other systems hold (Parse, JWT, third-party keys, database passwords) are not invented.

---

## 11. Symptoms: what a 401 really means

| What you see | Meaning |
|---|---|
| Phone app: "Token rejected" / "Not paired" | the device token is empty, revoked, or from another environment's database (section 9.1) |
| Phone app: "The Brain accepted this phone, but a service it calls refused the Brain ("Invalid afs-connect signature.")" | the Brain → coordinator pair differs (section 3.1). The phone's token is fine. |
| Any app: `401 Invalid afs-connect signature.` | the receiver's copy of the **sender's** secret differs |
| `401 Missing afs-connect auth headers.` | the caller did not sign at all (its own secret is empty) |
| Brain surface call refused | the app's `WWW_BRAIN_SURFACE_KEY` differs from the Brain's `BRAIN_SURFACE_KEY_<APP>` (or one is empty) |
| Logged out everywhere | `JWT_SECRET` changed |
| `AUTH_INVALID_TOKEN` on `/dashboard/v1/auth/me` | the bearer token is not in this database |

---

## 12. What happened on 2026-10-22 (the incident and what was found)

- **Symptom:** the phone's Members screen: "afs-connect device token was rejected".
- **Cause:** the dev coordinator's `AFS_CONNECT_SERVICE_KEY_WWW` differed from www's `AFS_CONNECT_SERVICE_KEY`, so the coordinator answered `401 Invalid afs-connect signature.`; the app turned every 401 into "device token rejected". The app now recognises a 401 relayed from a service the Brain called.
- **Other drift found the same day, on the dev server:** `www`'s copy of the coordinator's secret and of members' secret differed from the real ones; `members` had no copy of the coordinator's secret; notes, flow, dream_graph, pdf_editor and market had no afs-connect keys; `www`'s seven `BRAIN_SURFACE_KEY_*` were empty while the apps' were set. All were made equal on the server by hand (backups `.env.bak.<timestamp>` beside each file).
- **Then:** the script was rebuilt around `KEY_GROUPS` (check, `--repair`, `--rotate`), made environment-only, and a full **dev rotation was run on the `_env/dev` files** (19 secrets, 46 files). **Those new files are not deployed**: until `use-env.sh dev` and a redeploy of every dev app, the running dev servers still use the old values, and the Brain → coordinator calls will fail again if only some apps get the new files.

---

## 13. Open findings (nothing here is fixed)

1. **The dev server and the repository's dev files hold different coordinator and members secrets** (and after today's rotation, different everything for the 19 rotated secrets). Each set is consistent inside itself. **Never copy a single file between them; put a whole environment in place with `use-env.sh` and redeploy together.**
2. **Device tokens are stored in plain text** in `laravel_user_tokens`.
3. **`LINODE_TOKEN` is inside the APK.**
4. **`REDIS_PASSWORD` and `MAIL_PASSWORD` are the same value** in every dev app.
5. **`SENDGRID_API_KEY` is defined twice in auth** with different values.
6. **`DB_PASSWORD` is one value for every app**, and `MY_PASSWORD` / `CHECKPOINT_DB_PASSWORD` repeat it.
7. **`CHECKPOINT_SYSTEM_KEY` and `JWT_SECRET` held the same value in the dev set before the rotation**; the rotation changed only the system key. Not verified whether any code assumed they are equal.
8. **The payment-provider credentials were not located** (see section 6).
9. **Editor-to-editor afs-connect keys** are empty everywhere (section 3.1); fine if everything goes through the coordinator.
10. **The `.env.bak-surfacekeys-*` backups** created by today's rotation are untracked and contain the previous secrets.
11. **Not looked at:** Parse server's own configuration, the Brain settings document's actual contents, the production environment, and anything under `wwwafsportal_v1`.

---

## 14. Files to know

| File | What |
|---|---|
| `packages/afsserver/bin/set-surface-keys.sh` | create / check / repair / rotate the pair keys (`KEY_GROUPS` is the list) |
| `packages/afsserver/bin/use-env.sh` | puts one environment's files in place for every project |
| `packages/afsserver/bin/remote-run.sh` | runs a command on the dev server (used to mint the device token) |
| `w/www/config/afs_connect.php`, `w/coordinator/config/afs_connect.php`, each editor's and `members`' `config/afs_connect.php`, `market/config/market.php` | where each app reads its afs-connect keys |
| `w/www/config/brain.php`, `w/members/config/brain_surface.php` | Brain surface keys |
| `w/www/app/Console/Commands/Brain/BrainDeviceToken.php` | mints, lists and revokes device tokens |
| `w/members/config/storage.php`, `w/storage_device/config/storage_device.php` | storage keys |
| `w/www/app/Services/Brain/Domains/Registrar/RegistrarCredentials.php` | reads registrar tokens from the Brain settings document |
| `afsbrain_flutter/_env/<mode>/.env` | the phone app's environment (device token, Linode token) |
| `packages/afsserver/plans/afs-connect/06-security-and-service-auth.md`, `packages/afsserver/plans/07-www-env-variables.md` | the original key-pairing plans |

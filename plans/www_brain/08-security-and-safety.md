# 08 — Security and safety

A control plane that can change every app is also the most valuable thing to attack and the easiest
way to take everything down by mistake. This document is the threat list and the answer to each.

## 8.1 Who can do what

| Surface | Reached by | Guard | Refuses |
|---------|-----------|-------|---------|
| Staff routes `api/v1/brain/apps/*` ([07 §7.7](07-brain-admin-ui.md)) | Brain staff in the admin | `portal.admin.auth` **+ `portal.level99` + `brain.only`** — authorization is server-side; hiding the sidebar link is not a control | Anyone not level 99; any request on a non-Brain deployment |
| App routes `internal/brain/apps/*` ([02 §2.5](02-app-registry-and-manifest.md)) | A registered app's server | **`brain.only` + `brain.surface`** (signed per-app key, timestamp, nonce, body hash); `{app}` in the path **must equal** the signed `X-Brain-App` | Unsigned, replayed, skewed, wrong-app, disabled-app, unknown-app calls |
| The Brain's own tables | `www` only | `AGENTS.md` §5 | Any other app, even where the database is shared |

Two audiences, two groups, no shared route (B7). The history behind that: `portal.level99` was
disabled on the first `api/v1/brain` group because *service* callers had no user row; putting the
new staff and service surfaces in one group would recreate that trap ([01 W1](01-current-state-audit.md)).

## 8.2 Keys

- **The `BRAIN_SURFACE_KEY_<APP>` key family** (+ `_NEXT` for rotation), one per app, held in
  `www/config/brain.php` (`surface.apps`) and in that app's `.env` as `WWW_BRAIN_SURFACE_KEY`. Built
  for `MEMBERS`; each further app adds an entry.
- **Do not reuse** `AFS_CONNECT_SERVICE_KEY_*` (afs-connect), `MARKETPLACE_INTERNAL_API_KEY`
  (market) or `WWW_INTERNAL_API_KEY`/`MEMBERS_INTERNAL_API_KEY` (the `www`↔`members` pair). Reusing
  a key would let a compromise of one integration configure or mail on behalf of another.
- **Distinct keys per app**: a leaked `NOTES_EDITOR` key can only act as `NOTES_EDITOR`, only on its
  own settings and events.
- **Rotation:** the Brain accepts `KEY` or `KEY_NEXT`; apps roll one at a time; then the old key is
  removed. Same procedure as the other planned channels.
- **Never in the database or the manifest.** Keys are `.env` only ([03 §3.3](03-data-model.md)).
- **One shared signer/verifier** with the `billing` and `account_close` `members`→`www` channel
  ([02 §2.5](02-app-registry-and-manifest.md)) — different key namespace, same code, one place to
  audit.

## 8.3 Tenant clones and `IS_BRAIN` (B10)

*Built 2026-09-25 (`www/config/brain.php`, `BrainOnly`, tests `BrainConfigTest` and `BrainSurfaceEnforcementTest`); `IS_BRAIN=true` is in the four Brain env sets and absent from `_env/www/.env`.*

`www` is deployed to every tenant with the same routes and empty `brain_*` tables
([01 W11](01-current-state-audit.md)). Tenants, however, run on **separate servers with their own
databases and no access to the Brain's database**, so a tenant clone cannot read or change Brain
data whatever its code does. The guard is therefore about **not exposing meaningless Brain-only
surfaces on a tenant**, not about preventing a takeover.

### The rule

```
is_brain =  IS_BRAIN is the boolean true
        AND ( APP_ENV != 'production'
              OR host(APP_URL) === 'www.appfactory.studio' )
```

- **`IS_BRAIN=true|false`** in `.env`, read once into `config/brain.php` and used everywhere as
  `config('brain.is_brain')` (never `env()` in application code, so `config:cache` works).
  Absent, empty or any non-true value means **not the Brain** (fails closed).
- **Production caveat — a hard URL check.** When `APP_ENV=production`, the flag alone is not enough:
  the host of `config('app.url')` must be exactly `www.appfactory.studio` (case-insensitive, scheme
  `https`, trailing slash ignored). A mistyped or copied `IS_BRAIN=true` on any other production
  host therefore still yields *not the Brain*. Outside production (`local`, `staging`, the lab) only
  the flag applies, so `.test`, `appfactory.test1` and `www15` work without special cases.
- **The URL comes from `APP_URL`, not the request's `Host` header.** A `Host` header is
  caller-controlled and absent under CLI; `APP_URL` is set once per deployment and identical in HTTP
  and CLI. (Verified: `_env/prod/.env` has `APP_ENV=production` and
  `APP_URL=https://www.appfactory.studio/`.)
- **`brain.only` middleware** checks `config('brain.is_brain')` first on both route groups and
  answers `404` (not `403`, so the route is not advertised) when false. The Brain admin's Apps
  section is likewise not rendered.

### Where the flag lives (`www/_env/`)

| Env set | Belongs to | `APP_ENV` / `APP_URL` today (verified) | `IS_BRAIN` |
|---------|-----------|------------------------------------------|------------|
| `_env/prod/.env` | Brain, production | `production` / `https://www.appfactory.studio/` | **`true`** (and passes the hard URL check) |
| `_env/dev/.env` | Brain, dev | `local` / `https://www15.appfactory.studio/` | **`true`** (no URL check outside production) |
| `_env/local/.env` | Brain, Laragon | `local` / `https://www.test` | **`true`** |
| `_env/vagrant/.env` | Brain, lab | `local` / `https://www.appfactory.test1` | **`true`** |
| `_env/www/.env` | **the default tenant `.env`** | `local` / *(empty)* | **omitted** — tenants are never the Brain |

Tenant `.env` files simply **omit** `IS_BRAIN`. Because omission means *not the Brain*, nothing has
to be written into a tenant, and the only way to get this wrong is to add `IS_BRAIN=true` to
`_env/www/.env` — say so in a comment beside where the key is documented.

Note that `_env/www/.env` has `APP_ENV=local` even though it is the tenant default; the production
URL check therefore does not protect a tenant, and does not need to — the omitted flag does.

### Why an env flag, not a domain test

The old `BrainGuard` compared `GetManagedDomain()` with `appfactory.studio` and `www`/`www15`.
Environments legitimately differ, so the check gave the wrong answer in every lab, and under CLI it
depended on a `type.txt` file on disk. A flag set once per deployment has neither problem, and the
production URL check restores the safety the domain test provided *only where it matters*.

### One definition of "the Brain"

Every "is this the Brain?" decision in `www` should read `config('brain.is_brain')`. Places that
compute it another way — `BrainGuard`, and the `$isBrain` domain compare in `NotificationDispatcher`'s
manage-billing URL — move to it when built, otherwise two definitions can disagree.

- The migrations still run on tenants and create empty tables — harmless; nothing reads them.

### Open

| # | Question | Default |
|---|----------|---------|
| **OQ-13** | Production: flag **and** URL (as written), or URL **alone** (flag ignored in prod)? | Both — a deliberate act plus a correct host |

## 8.4 Failure modes — the Brain is a dependency of every app

| Failure | Effect | Mitigation |
|---------|--------|------------|
| Brain down / slow | Apps cannot refresh | Last-known-good then compiled defaults; short timeouts; circuit breaker ([04 §4.5](04-settings-and-controls.md), [06 §6.2](06-app-integration.md)) |
| A bad setting saved | An app misbehaves | Schema validation on write (B8); app re-validates on read; rollback; **kill-switch defaults are the safe state** |
| A bad manifest registered | Wrong UI / lost keys | Versions immutable; removed keys become *orphaned*, values kept; schema changes flag *invalid*, app uses default |
| Two staff edit at once | Silent overwrite | Compare-and-set on `revision` (`409`) |
| Mass misconfiguration | Many apps affected at once | Per-setting scope is one app; the History tab lists everything; rollback per row. **Consider a "change window" flag** for risky keys (OQ below) |
| Clock skew | Signed calls rejected | ±300 s; the client logs skew explicitly |
| Key leak | One app impersonated | Per-app keys, rotation, `status = disabled` cuts an app off immediately |
| Mail provider abuse / quota | Platform sender blocked | Per-app and per-recipient limits ([05 §5.7](05-mail-and-notifications.md)); the log shows the offender |

**The rule behind all of these:** the control plane must be able to *fail* without the controlled
thing failing (B2).

## 8.5 Information exposure

- The settings API returns **only that app's** effective settings — never another app's, never
  Brain-internal keys.
- The manifest is app-authored; the Brain treats every string in it as **untrusted text** (escape on
  render; never `innerHTML` a label or description).
- The mail log stores a **hash** of the recipient, never the address, and no message body.
- Settings values are not secrets by contract; a manifest that declares `secret: true` is refused in
  v1 ([03 §3.3](03-data-model.md)).
- Handler HTML is staff-authored and rendered by `MailHandlerSender`; variable values supplied by an
  app are **escaped** when substituted into HTML (an app bug must not become stored XSS in an email).

## 8.6 Audit

Every setting/handler write, rollback, reset, enable/disable and app add/disable is in an
append-only history with the acting staff `reference_num` (B9). App-initiated actions
(manifest registration, mail sends) log the **app name**, never a user. The two are kept distinct so
"who changed this" always has an answer.

## 8.7 Abuse by a staff account

Level 99 is powerful. Mitigations proportionate to v1: every change is attributed and reversible;
`hot` and safety-relevant keys can be flagged `"confirm": true` in the manifest, which the UI turns
into a typed confirmation. Two-person approval is **not** in v1 (OQ-9).

## 8.8 Open questions specific to safety

| # | Question | Default |
|---|----------|---------|
| **OQ-9** | Two-person approval for changes to keys marked `critical`? | No in v1; per-row typed confirmation only |
| **OQ-10** | Rate-limit staff writes? | Yes, modestly (protects against a runaway script with a staff token) |
| **OQ-11** | Should a disabled app get a `503` with a message, or `403`? | `403` with a stable error code the client understands as "use defaults", not "retry" |

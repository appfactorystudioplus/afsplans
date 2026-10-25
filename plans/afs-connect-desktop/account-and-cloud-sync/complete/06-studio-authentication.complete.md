# 06-studio-authentication — Completion

**Completed:** 2026-08-01 (phases C1a and C1a′ only)

**Status:** **Partial.** Two small desktop-side prerequisites are done — the single
production/development switch (**C1a**) and Checkpoint app registration (**C1a′**). The
desktop client half of auth has **not started**: `StudioAuthService` (C1b), the
secondary-user login guard (C1c), SSO wiring (C1d), unifying `settings.ts` onto the new
service (C1e), and 2FA (C2) are all outstanding. No sync UI may ship until C1 lands.

The server-side half of studio auth is complete and is recorded separately in
[07-unified-sync-orchestration.complete.md](07-unified-sync-orchestration.complete.md)
(phases S0.5 and S0.5g).

---

## Summary

Two prerequisites landed, both small and both on the desktop side of the auth story. **C1a**
replaced a hardcoded `isProduction = false` with a single build-time switch read from
`.env`, so one flag now drives every Checkpoint host the desktop app talks to. **C1a′**
registered `APPFACTORY_STUDIO` as a Checkpoint `app_name` across the source of truth and
all ten mirrors, giving the desktop client an identity for the middlewares built in
S0.5/S0.5g to validate against.

Neither phase makes the desktop app able to authenticate — nothing on the desktop side
obtains or presents a bearer yet. That is C1b onward, and it has not started.

## Phase C1a — desktop `IS_PRODUCTION` env flag

Targets `afsdesktop5`.

### What it fixed

`src/renderer/config.ts` had `isProduction` hardcoded to `false`, with a comment saying to
toggle it manually or via build scripts later. `.env` already carried a flag, but nothing
read it — there was no `dotenv` dependency and no webpack plugin wiring it through. The
goal was one switch, set once in `.env`, injected at build time, driving every Checkpoint
host the desktop app talks to.

### Delivered

| File | Change |
|------|--------|
| `package.json` | Added `dotenv` (`^16.4.5`) as a devDependency — already present transitively, but undeclared |
| `webpack.config.js` | `require('dotenv').config()` at the top; computes `IS_PRODUCTION` as the literal string `'true'` or `'false'` (**only** `'true'` maps to true — anything else, including unset, falls back to `'false'`); applies one `webpack.DefinePlugin` via `commonConfig.plugins`, inherited by all four bundles |
| `src/renderer/config.ts` | Rewritten — `isProduction = process.env.IS_PRODUCTION === 'true'`. The `API_URLS` shape is unchanged so no call site needed updating. Added `CHECKPOINT_SSO_DOMAIN` and `CHECKPOINT_BASE_URL` exports, not yet consumed |
| `.env` | Comment header rewritten to describe the flag; value unchanged, still defaulting to dev |
| `.env.example` | New — documents the flag for local dev |

`src/main/index.ts` already imported `isProduction` from `../renderer/config`, a
pre-existing cross-boundary import. Because the `DefinePlugin` is applied at the
`commonConfig` level, the main process gets the same value with no separate
`src/main/config.ts`.

### Exit criteria

| Criterion | Result |
|-----------|--------|
| `.env` unset or default → `isProduction === false` | ✅ |
| `IS_PRODUCTION=true` → `isProduction === true` | ✅ |
| `IS_PRODUCTION=True` (capital T typo) → falls back to `false` | ✅ Fails safe, not open |
| All four webpack bundles receive the `DefinePlugin` | ✅ `main/index.js`, `renderer/index.js`, `renderer/transcriptionWorker.js`, `renderer/syncWorker.js` |
| `config.ts` type-checks | ✅ `tsc --noEmit` clean |

### Verification

```bash
cd afsdesktop5
node -e "const c=require('./webpack.config.js');const dp=c[0].plugins.find(p=>p.constructor.name==='DefinePlugin');console.log(dp.definitions['process.env.IS_PRODUCTION']);"
```

Run with each env state — default, `IS_PRODUCTION=true`, and the `True` typo — confirming
`"false"`, `"true"`, `"false"` respectively. `require()`-ing the webpack config only builds
the config objects; it does not run a build. **No `npm run build` was executed** — no
`node_modules` install was verified in this environment beyond the `dotenv` presence check.

---

## Phase C1a′ — Checkpoint app registration (`APPFACTORY_STUDIO`)

Targets `apps/auth` as source of truth, plus every mirror.

### What it fixed

Checkpoint scopes authorization, entitlement, and secondary-user tenancy by `app_name`, and
there was no identifier for the desktop client. `CheckpointAppName` listed `MEMBERS`,
`COORDINATOR`, `NOTES_EDITOR`, `PDF_EDITOR`, `FLOW_EDITOR`, `GRAPH_EDITOR`, and
`GAME_EDITOR` — nothing for `afsdesktop5`. Every middleware built in S0.5/S0.5g needs a
`REQUIRED_APP_NAME` constant to validate against, and the desktop client will need one to
identify itself once C1b–C1d exist.

### Delivered

`APPFACTORY_STUDIO` added to `auth/app/Services/Checkpoint/CheckpointAppName.php` (constant
plus `all()`), and mirrored into all nine web apps' `resources/js/checkpoint/app-names.ts` —
`auth`, `coordinator`, `dream_graph`, `flow`, `members`, `notes`, `pdf_editor`, `www`,
`wwwafsportal_v1`. Coordinator's copy uses CRLF line endings, preserved during the edit.

`afsdesktop5/src/renderer/checkpoint/app-names.ts` is a **new file** — the vendored
Checkpoint SDK in that app threads `app_name` through login and SSO but shipped no constant
for it, so every call site was passing a bare string or nothing. The new file supplies both
the registry and a `THIS_APP` export identifying the desktop client.

**Deliberately not added:** `APPFACTORY_STUDIO` was not added to `UI_APP_ID_TO_CHECKPOINT`
in any `app-names.ts` — the members app's create-user checkbox list. Doing so would require
a matching `app-checkbox-{uiId}` in the members create-user form template, which is a UI
feature decision, not a registration prerequisite.

### Exit criteria

| Criterion | Result |
|-----------|--------|
| `CheckpointAppName::isValid('APPFACTORY_STUDIO')` returns true | ✅ Added to `all()` |
| All nine web `app-names.ts` files and `CheckpointAppName.php` agree | ✅ |
| Line endings preserved per file (coordinator CRLF, rest LF) | ✅ Verified with `file` after patching |
| `afsdesktop5` has its own copy | ✅ New file |
| Every S0.5/S0.5g middleware's `REQUIRED_APP_NAME` matches a registered constant | ✅ The five web ones were already registered; this phase added the desktop-side name for future use |

### Verification

```bash
grep -c APPFACTORY_STUDIO */resources/js/checkpoint/app-names.ts
```

Returns 2 for each of the nine apps — the constant declaration plus the display label.
`php -l` on `CheckpointAppName.php` reports no syntax errors.

---

## Files changed

**C1a** — `afsdesktop5/package.json`, `webpack.config.js`, `src/renderer/config.ts`,
`.env`, `.env.example`.

**C1a′** — `auth/app/Services/Checkpoint/CheckpointAppName.php`; the nine
`*/resources/js/checkpoint/app-names.ts` mirrors; and the new
`afsdesktop5/src/renderer/checkpoint/app-names.ts`.

## Verification steps

Both phases were verified by static checks only; no application was run.

```bash
cd afsdesktop5
node -e "const c=require('./webpack.config.js');const dp=c[0].plugins.find(p=>p.constructor.name==='DefinePlugin');console.log(dp.definitions['process.env.IS_PRODUCTION']);"
```

```bash
grep -c APPFACTORY_STUDIO */resources/js/checkpoint/app-names.ts
php -l auth/app/Services/Checkpoint/CheckpointAppName.php
```

Full per-phase criteria and results are in the **Exit criteria** and **Verification**
subsections above. Note that **no `npm run build` was run** for C1a and no app was launched
for either phase.

## Follow-up work

### The rest of C1 — not started

- **C1b** — `StudioAuthService`, the desktop service that actually obtains and holds a
  bearer. Nothing on the desktop side presents one yet.
- **C1c** — the secondary-user login guard. This is where "desktop is primary-only" is
  enforced: the desktop app never completes a secondary login. It is deliberately **not** a
  server-side rejection — see the correction recorded in
  [07-unified-sync-orchestration.complete.md](07-unified-sync-orchestration.complete.md).
- **C1d** — SSO wiring, including the `127.0.0.1` loopback flow.
- **C1e** — unify `settings.ts` onto the new service. `src/renderer/components/settings.ts`
  still derives the Checkpoint base URL itself, taking
  `isProduction ? API_URLS.production : API_URLS.development` and stripping a trailing
  `/login`. Wiring it to C1a's new `CHECKPOINT_BASE_URL` / `CHECKPOINT_SSO_DOMAIN` exports
  belongs here, which is why those exports currently have no consumer.
- **C2** — 2FA.

### Deferred registration items

- **Checkpoint's allowed-redirect-domain config** for the loopback SSO flow — data-plane
  configuration on the running service, not a code change, and it depends on C1d existing.
- **`JoystickEngine_v2/libs/checkpoint_cpp/.../AppNames.h`** — unaffected; that mirror only
  needs `GAME_EDITOR`, already present.
- **`afsmobile_flutter`** — needs the same treatment if and when mobile sync is scoped.
  Out of scope while mobile remains auth-only.

## Note on naming

These notes were originally filed as `C1a-desktop-is-production-flag.md` and
`C1a-prime-checkpoint-app-registration.md`, after the phase IDs
[07-unified-sync-orchestration.md](../07-unified-sync-orchestration.md) defines. They are
now filed under the plan document whose work they implement, with the phase IDs kept as
section headings above.

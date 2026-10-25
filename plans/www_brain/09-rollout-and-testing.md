# 09 — Rollout and testing

Phased so each step is useful and reversible, with one **pilot app** end to end before the rest.
Nothing here ships to every app at once.

## 9.0 P0 — Pre-flight (read-only) — done 2026-09-26

| # | Result |
|---|--------|
| 1 | `brain_users_subapps` and `brain_subapp_coordinator` are per-**member** tables (`super_user_id`, username, a password column), not an app registry — so the registry is new |
| 2 | `market` uses its own database (`testdb_afsdb_market`), has Laravel 12 and a working `database` cache; it takes the same client |
| 3 | No private Composer registry; the estate shares PHP through git repositories (`afs/checkpoint-auth`) — hence a package with a path repository until it is published |
| 4 | Every Laravel app uses `CACHE_STORE=database` |
| 7 | The afsemail builder is callback-based (`InitializeEmailCreatorDashboard3(handlers, cbMain, cbSave, cbExit, …)`), so it opens a Brain handler group without any tenant endpoint |
| 5, 6, 8 | Shared signer/verifier and `IS_BRAIN`: done; tenant provisioning and provider sender-domain verification: **not checked** |

The original checklist follows.

| # | Verify | Why |
|---|--------|-----|
| 1 | What `brain_users_subapps` and `brain_subapp_coordinator` hold; whether the registry extends them | Avoid a duplicate table ([01 W6](01-current-state-audit.md)) |
| 2 | Where `market` stores its data and whether it can run the client | [01 W9](01-current-state-audit.md) |
| 3 | Whether a private Composer registry exists (OQ-5) | Decides how the PHP client is shared |
| 4 | Each non-Brain Laravel app has a working cache store | The client's last-known-good depends on it |
| 5 | The `members`→`www` channel work in `billing`/`account_close`: agree one verifier/signer | [02 §2.5](02-app-registry-and-manifest.md), [08 §8.2](08-security-and-safety.md) |
| 6 | **`IS_BRAIN`** — **done** (2026-09-25): `config/brain.php`, `IS_BRAIN=true` in `_env/{prod,dev,local,vagrant}`, absent from `_env/www/.env`; `BrainGuard` deleted and the notification manage-billing URL read from the config. **Still to check:** that tenant provisioning starts from `_env/www/.env` | [08 §8.3](08-security-and-safety.md) |
| 7 | The `afsemail` builder can be opened for a handler that is **not** a tenant handler (its load/save endpoints are tenant-oriented) | [07 §7.5](07-brain-admin-ui.md) |
| 8 | Provider (SendGrid) sender-domain verification and quotas for the platform sender | OQ-4, [05 §5.6](05-mail-and-notifications.md) |

## 9.1 Phases

| Phase | Contents | Status (2026-09-26) |
|-------|----------|---------------------|
| **P1 — Registry & channel** | Registry and manifest tables, `brain.only` + `brain.surface`, `POST …/manifest`, staff list/add/overview | **Built and tested** |
| **P2 — Settings (pilot)** | Settings and history tables, read/write/reset/rollback, the PHP client, the `notes` pilot with `maintenance.*` | **Built, tested and driven end to end in the lab** |
| **P3 — Mail (pilot)** | Handler, history and log tables, `mail/send`, the Mail tab reusing afsemail | **Built and tested up to the transport seam.** A real send is not possible on the lab (§5.10) |
| **P4 — Remaining apps** | Client, manifest, env, per app | **Built for all six** (empty manifests); registration in the lab was run for `notes` |
| **P5 — More controls** | Only against a named need | **None added, by design** |

## 9.2 Tests

### `www` — the Brain side

| Suite | Covers |
|-------|--------|
| `BrainOnlyMiddlewareTest` | `404` when `IS_BRAIN` is `false`, absent, empty or a non-boolean string (fail closed). With `APP_ENV=local` it passes for any `APP_URL` when `true`. With `APP_ENV=production` it passes **only** when `APP_URL`'s host is `www.appfactory.studio` (also `HTTPS://WWW.APPFACTORY.STUDIO/`) and `404`s for `www15…`, a look-alike host, or an empty `APP_URL`. The request `Host` header is ignored |
| `BrainAppAuthTest` | Unsigned, replayed, skewed, wrong-app (`{app}` ≠ header), disabled-app, unknown-app all refused; a key from another integration (afs-connect, market, members↔www) does **not** authenticate |
| `StaffRoutesAuthzTest` | Non-level-99 authenticated user gets `403` on every staff route (crafted URLs) |
| `ManifestRegistrationTest` | New version stored; identical re-post is a no-op; same version different body `409`; invalid `422`; removed key → value kept + orphaned; tightened schema → value flagged invalid |
| `SettingsValidationTest` | Each schema type accepts/rejects correctly; undeclared key `404`; `secret: true` refused |
| `SettingsRevisionTest` | Compare-and-set `409` on stale revision; history row per write; rollback writes forward; reset recorded |
| `EffectiveValueTest` | brain value / default / `default_invalid` and the reported `source` |
| `SettingsEtagTest` | `304` when unchanged; changes when any value or the manifest version changes |
| `MailSendTest` | Handler found → sent; none/disabled → manifest fallback sent; unknown event `422`; disallowed variable type `422`; **idempotent on `request_id`**; per-app rate limit `429` + `skipped` |
| `MailLogPrivacyTest` | The log has a recipient hash and no address, no body |
| `MailEscapingTest` | A variable containing markup is escaped in the rendered HTML |
| `AppsUiApiTest` | List/add/disable/rollback contract for the UI |

### App side (per app, run in that app's repo)

| Suite | Covers |
|-------|--------|
| `BrainClientSigningTest` | Signature format matches the Brain's verifier (shared test vectors) |
| `BrainClientFallbackTest` | Fresh → last-known-good → defaults; never throws; circuit breaker opens and recovers |
| `BrainClientCacheTest` | `304` keeps the cache; TTLs for `hot` vs normal |
| `ManifestDefaultsTest` | The compiled defaults equal the registered manifest's defaults (one source) |

### Cross-app (lab, [`DEVELOPMENT_MODE.md`](../DEVELOPMENT_MODE.md))

Run in the `local` or `vagrant` mode only:

1. Register the pilot; change `maintenance.enabled` in the Brain; watch the app apply it within one TTL.
2. **Stop the Brain**; confirm the running app is unaffected and a cold restart uses defaults.
3. Restart the Brain; confirm the app recovers without a restart.
4. Send a real mail event; confirm one send, the log row, no address stored.
5. Rotate the pilot's key (`KEY_NEXT`) with no downtime.

## 9.3 Acceptance summary

| # | Criterion |
|---|-----------|
| **A1** | Staff can register/list/disable apps; a non-level-99 user cannot, by any URL |
| **A2** | With `IS_BRAIN` not `true`, every new route answers `404` and the Apps section is not shown |
| **A3** | An app can register a manifest; re-registering is idempotent; conflicting versions are refused |
| **A4** | A setting edited in the Brain applies in the app within one TTL |
| **A5** | An invalid value is refused; a later schema change degrades to the default, never an error |
| **A6** | Two concurrent editors cannot silently overwrite each other |
| **A7** | Every change is in history with an actor and can be rolled back |
| **A8** | With the Brain down, running apps are unaffected and cold apps start on defaults |
| **A9** | A handler designed in the Brain is used when the pilot fires its event; disabling it sends the fallback |
| **A10** | An app cannot read or change another app's settings or fire another app's events |
| **A11** | No key from another integration authenticates to the new routes |
| **A12** | The mail log holds no recipient address and no message body |

## 9.4 Risks

| Risk | Mitigation |
|------|------------|
| The control plane becomes a hard dependency | B2: cache + defaults; tested in A8 |
| Settings become a dumping ground that duplicates allocations/billing | [04 §4.3](04-settings-and-controls.md) review rule |
| Eight clients drift | Shared vectors ([9.2](#92-tests)); one source (Composer or sync script) |
| `IS_BRAIN=true` added to a tenant's `.env` (e.g. `_env/www/.env`) | Absent by design; omission = not the Brain; a comment where the key is documented says never add it there. In production the URL check is a second barrier |
| The `afsemail` builder assumes a tenant context | P0 item 7 before P3 |
| Sender reputation harmed by one app | Rate limits and per-recipient caps ([05 §5.7](05-mail-and-notifications.md)) |

## 9.5 Bookkeeping when built

- Add `complete/` docs per phase (`w/AGENTS.md` §4) noting divergences.
- Mark edits in existing files with `AFS_WWW_BRAIN_MODIFIER_ADDITION` and add the marker to
  `plans/markers.md` if that file lists markers.
- Document `IS_BRAIN` in `plans/07-www-env-variables.md` and the `_env` notes (which sets have it, and that `_env/www/.env` must not).
- Update `w/AGENTS.md` §4/§5 plan list to include `www_brain`, and `portal.d.ts` only if the
  externals API surface changes (it should not).

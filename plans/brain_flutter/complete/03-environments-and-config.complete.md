# 03 — Environments and configuration — Completion

**Verified:** 2026-10-04 (`tool/gates.sh` fully green: greps, `check-env.py`, `flutter analyze` clean, `flutter test` 49 passed)

**Status:** **Built, one acceptance item awaiting you.** Everything in 03 exists. The only unproven line is §3.7's last bullet
(an installed build actually reaches lab2 / production), which needs a phone and a minted token.

## What was built

| Plan item | State | Evidence |
|-----------|-------|----------|
| §3.2 file layout, gitignore | ✅ | `afsbrain_flutter/.gitignore` ignores `/.env`, `/_env/**/.env`, `key.properties`, `*.jks`, `*.keystore`; `!.env.example` kept. `_env/{development,production,vagrant,vagrant-lan,lab2}/.env` and `.env.example` already existed (created before the project) |
| §3.3 keys | ✅ | All eight keys are in `.env.example`; `config.dart` reads each |
| §3.4 `config.dart` | ✅ | `lib/config.dart`: `bootstrapConfig()` loads `.env`, requires `IS_PRODUCTION`, `PORTAL_API_URL`, `REGISTRY_URL`, `BRAIN_MEMBER_REF`, `REQUEST_TIMEOUT_SECONDS` (error names the key and says to run `use-env`); `PORTAL_API_URL` must be absolute and `https` when production. `BRAIN_DEVICE_TOKEN`/`ENV_LABEL` may be empty (→ Not paired). `main()` awaits `bootstrapConfig()` then `trustLabCaInDebug()` before any request |
| §3.5.1 `use-env` | ✅ | `afsbrain_flutter` added to `CLIENT_APPS` in `bin/use-env.sh` and `$ClientApps` in `bin/use-env.ps1`. Still run by hand only |
| §3.5.2 `check-env` | ✅ | `bin/check-env/check-env.py` `CLIENTS` has `'afsbrain_flutter': {'PORTAL_API_URL': 'www', 'REGISTRY_URL': 'auth'}`, carrying the `AFS_BRAIN_FLUTTER_MODIFIER_ADDITION` marker |
| §3.6 lab TLS | ✅ | `lib/core/lab_ca.dart` (debug-only, non-production); `assets/lab/mkcert-rootCA.pem` copied from `afsmobile_flutter`; `android/app/src/debug/res/xml/network_security_config.xml` and the debug manifest's `networkSecurityConfig` |
| `pubspec.yaml` assets | ✅ | `.env` and `assets/lab/mkcert-rootCA.pem` |

## Acceptance (§3.7)

| Check | Result |
|-------|--------|
| No hostname/prefix in `lib/` | ✅ gate G1 (`tool/gates.sh`) passes: no `http(s)://`, `sslip`, `appfactory`, `10.0.0.`, `/portal/api` outside `///` comments |
| `check-env.py` passes for all five modes | ✅ `afsbrain_flutter  8 keys in 5 modes  OK`; "all environment checks passed" (run with `--no-php --no-node`, which skips the unrelated PHP/Node host scans) |
| Rebuild with `_env/lab2/.env` / `_env/production/.env` retargets the app | ⏳ lab2 `.env` is in place and the app compiles and analyses clean, but no APK was built or installed; needs a phone and a minted token |

## Divergences

- **The root `.env` was created by copying `_env/lab2/.env` for this app only**, not by running `use-env`. A real `use-env lab2` would also have
  replaced the `.env` of market, coordinator, communications and afsdesktop5 (the dry run listed them), which was more than was needed.
  Equivalent for this app; run `use-env` yourself if you want the whole estate on lab2.
- **`check-env` prints the live-`.env` line for `afsbrain_flutter`** only once the root `.env` exists (it now does).

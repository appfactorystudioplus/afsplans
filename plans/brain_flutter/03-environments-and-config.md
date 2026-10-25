# 03 — Environments and configuration

## Completion document

Built 2026-10-04 (device acceptance pending): [complete/03-environments-and-config.complete.md](complete/03-environments-and-config.complete.md).

## 3.1 The rule

> "Never use hard coded urls, use .env files so that I can switch environments when I publish the whole
> eco system."

This is the estate rule already written down in [`w/ENVIRONMENTS.md`](../../../../w/ENVIRONMENTS.md): everything that
differs between environments lives in `_env/<mode>/.env`; **no hostname, URL, port, path prefix or environment
switch is decided in code**. `afsbrain_flutter` follows it the way `afsmobile_flutter` does
(`lib/config.dart`), with one extension: the **API path prefix** (`/portal/api`) is also configuration, so even a
relocated or re-prefixed Brain is a file change.

What stays in code: route *suffixes* relative to the configured base (`/v1/memberships`, `/v1/brain/staff/whoami`).
Those are the API contract, versioned by the server, not environment data.

## 3.2 The files

```
afsbrain_flutter/
  .env                       ← the ACTIVE environment (use-env copies a mode over this); gitignored
  .env.example               ← committed: every key, empty/placeholder values
  _env/
    development/.env         ← www15.appfactory.studio            (gitignored)
    production/.env          ← www.appfactory.studio              (gitignored)
    vagrant/.env             ← www.appfactory.test1               (gitignored)
    vagrant-lan/.env         ← www.10-0-0-240.sslip.io            (gitignored)
    lab2/.env                ← www.10-0-0-243.sslip.io            (gitignored)
```

The five modes are exactly the ones `afsmobile_flutter` and `afsdesktop5` use; `local` has none (the phone cannot
reach a Laragon `.test` host). **All modes define exactly the same keys; only the values differ** — that is what
makes switching safe, and what `check-env.py` verifies ([§3.5](#35-use-env-and-check-env)).

**The files are gitignored** (`.gitignore`: `/.env`, `/_env/**/.env`; `!.env.example`), unlike the sibling apps,
because here each one carries `BRAIN_DEVICE_TOKEN` ([02](02-authentication-device-token.md)). `.env.example` is the
committed record of the key set. A fresh clone therefore has no `_env/*/.env` until you create them — the first
build fails loudly on the missing keys ([§3.4](#34-config-dart)), never silently.

## 3.3 The keys

| Key | Example (lab2) | Meaning | `check-env` role |
|-----|----------------|---------|------------------|
| `IS_PRODUCTION` | `false` | Compared as the exact string `'true'` (a typo never ships against production — same gotcha and fix as `afsmobile_flutter`) | — |
| `ENV_LABEL` | `LAB2` | Text of the environment badge ([10](10-ui-and-navigation.md) §10.2). Free text | — |
| `PORTAL_API_URL` | `https://www.10-0-0-243.sslip.io/portal/api` | Base of every Brain API call: host **and** the `/portal/api` prefix. Routes in code append `/v1/…` and `/auth/…` | `www` |
| `REGISTRY_URL` | `https://auth.10-0-0-243.sslip.io/checkpoint/checkpoint_registry_v4.json` | The allocation registry (35 resources) used by [08](08-allocations-and-overrides.md). Same value as `VITE_CHECKPOINT_REGISTRY_URL` in `www`'s env | `auth` |
| `BRAIN_MEMBER_REF` | `appfactorystudio` | `brain_memberships.reference_num` the token must resolve to; the handshake refuses a mismatch ([02](02-authentication-device-token.md) §2.5). It is data, not a URL, but it differs if the Brain member is ever renamed in another environment | — |
| `BRAIN_DEVICE_TOKEN` | `<64 chars>` | The device token. **Never** printed, logged, shown in the UI, or put in a crash report | — |
| `APP_LOCK` | `false` | `true` ⇒ biometric/PIN on cold start ([10](10-ui-and-navigation.md) §10.6) | — |
| `REQUEST_TIMEOUT_SECONDS` | `20` | Per-request timeout; the purge/sync endpoints get their own longer one in code via a multiplier, not a second key | — |

Hostnames per mode (from `ENVIRONMENTS.md` §2): `lab2` → `www.10-0-0-243.sslip.io`; `vagrant-lan` →
`www.10-0-0-240.sslip.io`; `vagrant` → `www.appfactory.test1`; `development` → `www15.appfactory.studio`;
`production` → `www.appfactory.studio`. `auth.<same suffix>` for `REGISTRY_URL`.

**Not a key:** no Parse URL, no Stripe key, no database host, no processor id, no Direct Payment reference. The
app learns the Brain's Direct Payment and its plans from the server ([04](04-server-additions.md) BS7), so none of
those are baked into a build.

## 3.4 `config.dart`

Mirrors `afsmobile_flutter/lib/config.dart`:

- `bootstrapConfig()` — `await dotenv.load(fileName: '.env')`, then `_require()` every key above. A missing or
  empty key throws a `StateError` that **names the key and says which `_env/` file to copy** (exception:
  `BRAIN_DEVICE_TOKEN` and `ENV_LABEL` may be empty only in a build explicitly made to show the **Not paired**
  screen — empty token ⇒ that screen, not a crash).
- Typed getters: `isProduction`, `envLabel`, `portalApiUrl`, `registryUrl`, `brainMemberRef`, `deviceToken`,
  `appLock`, `requestTimeout`.
- `portalApiUrl` is validated once: it must parse as an absolute `https` URL when `isProduction`; `http` is
  permitted only when not production (a LAN lab on plain HTTP would need cleartext enabled in the Android debug
  config — not planned, because every lab serves HTTPS).
- `main()` awaits `bootstrapConfig()` **before** `runApp()`, and `trustLabCaInDebug()` (§3.6) after it and before
  any network call.
- `pubspec.yaml` lists `.env` under `flutter: assets:` — the file is bundled, exactly as in the sibling app. This
  is why the token is in the APK ([02](02-authentication-device-token.md) §2.7).

## 3.5 `use-env` and `check-env`

Two small edits to the estate's tooling, both additive, both part of P0:

1. **`packages/afsserver/bin/use-env.sh` and `use-env.ps1`**: add `afsbrain_flutter` to `CLIENT_APPS` /
   `$ClientApps` (currently `afsdesktop5 afsmobile_flutter`). The switch is **still by hand** — nothing runs it
   automatically, including when a lab starts ([standing rule](../../../../w/AGENTS.md)). After switching, **rebuild** the
   app; the `.env` is baked in.
2. **`packages/afsserver/bin/check-env/check-env.py`**: add an entry to `CLIENTS`:
   `'afsbrain_flutter': {'PORTAL_API_URL': 'www', 'REGISTRY_URL': 'auth'}`. This makes `check-env` verify that every
   mode file exists, defines the same keys, and that each URL points at the right *role* of host for that mode (a
   production file that names a lab host, or vice-versa, fails). Because the token files are gitignored the check runs
   on whatever is on disk — it never prints values.

Switching to a different environment is therefore:

```bash
cd C:\projects\vagrant\afs-eco
bash packages/afsserver/bin/use-env.sh prod --skip-sync      # copies _env/production/.env over afsbrain_flutter/.env (and the others)
cd afsbrain_flutter && flutter build apk --release
```

and the lab equivalent with `lab2` and a debug build.

## 3.6 Reaching the lab from the phone (TLS)

The lab's certificate is signed by a private mkcert root. Two layers must trust it, exactly as `afsmobile_flutter`
discovered ([BA17](01-current-state-audit.md)):

| Layer | What | How |
|-------|------|-----|
| **Android / Java** | The platform networking stack and WebViews | Install `lab2/certs/mkcert-rootCA.crt` on the phone once (Settings → Security → Encryption & credentials → Install a certificate → CA certificate; procedure in `lab2/README.md`). The **debug** build's `android/app/src/debug/res/xml/network_security_config.xml` opts the app in to user-installed CAs; **release and profile builds do not**, by Android's design and deliberately |
| **Dart** | `dart:io`'s TLS stack, which carries every `package:http` call, trusts only the system store | `lib/services/lab_ca.dart` (copied from `afsmobile_flutter`) adds the bundled public root `assets/lab/mkcert-rootCA.pem` to `SecurityContext.defaultContext`, **only** when `kDebugMode` and `!isProduction` |

Consequences, stated plainly:

- **Lab = debug build** (`flutter build apk --debug` / `flutter run`). A release build cannot reach the lab, and that is
  fine: lab is where you develop. ([OQ5](00-overview.md) asks whether that is acceptable.)
- **Production = release build** against a publicly trusted certificate; no CA code runs.
- The pem is public (no key). It is bundled in every build but read only in debug.
- A phone away from the home Wi-Fi cannot reach `10.0.0.243`; the app shows **Unreachable** with the host name and
  retries, rather than hanging ([05](05-app-architecture.md) §5.5).

## 3.7 Acceptance for this file

- Searching `lib/` for `http://`, `https://`, `sslip`, `appfactory`, `10.0.0` and `portal/api` finds **no hostname or
  prefix** (only the `.env.example` placeholders and doc comments); a CI-style grep gate is part of
  [12](12-rollout-and-testing.md) P0.
- `check-env.py` passes for all five modes.
- Copying `_env/lab2/.env` over `.env` and rebuilding points the app at the lab; copying `_env/production/.env` points it
  at production. No Dart file differs between the two builds.

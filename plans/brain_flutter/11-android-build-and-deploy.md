# 11 — Android build and deploy (personal device only)

## Completion document

Not yet carried out. When it is: `complete/11-android-build-and-deploy.complete.md`.

The app is **sideloaded onto one phone and never published** (G9). There is no Play Console, no upload key ceremony, no store listing,
no review. What remains is making a build that is installable, reaches the right environment, and is not trivially extractable.

## 11.1 Starting point on this machine

Verified here: Flutter is installed (`C:\flutter\src\flutter\bin` on the shell `PATH`); the Android SDK's `adb` is **not** on the
shell `PATH`; JDK 21 and JDK 25 are installed; `afsmobile_flutter` carries a `flutter_build_jdk21.log`, which suggests JDK 21 is what its
Gradle setup was built with (P0 confirms with `flutter doctor -v`, not by assumption). Nothing was installed or run to produce this plan. P0 begins by running `flutter doctor` and recording
what it reports — if it shows a missing Android toolchain, that is reported to you to install, not installed by the agent
(standing rule).

## 11.2 Identity of the app

| Item | Value |
|------|-------|
| Project folder | `C:\projects\vagrant\afs-eco\afsbrain_flutter` |
| Dart package name | `afsbrain` |
| `applicationId` | `com.appfactorystudio.afsbrain` (differs from `com.appfactorystudio.afsmobileapp`, so both install side by side) |
| Launcher label | **AFS Brain** |
| Version | `0.1.0+1`, bumped per install you care to distinguish |
| `minSdk` / `targetSdk` | Flutter's defaults for the installed SDK; no value is hard-wired |
| Icon | a simple generated glyph, distinct colour from the other app; produced with `flutter_launcher_icons` as a dev-dependency |

## 11.3 Manifest and platform settings

`android/app/src/main/AndroidManifest.xml`:

- `INTERNET` permission — the only one needed. **No** camera, microphone, storage, notification, foreground-service or ad-id
  permissions (the sibling app declares many; this app needs none). `USE_BIOMETRIC` is added by `local_auth` for the production lock.
- `android:allowBackup="false"` and `android:fullBackupContent="false"` (and `dataExtractionRules` excluding everything), so `adb backup`
  and cloud backup cannot copy the bundled `.env` or the list cache ([02](02-authentication-device-token.md) §2.7).
- `android:usesCleartextTraffic="false"` (the default for the target SDK; stated so it is not changed by accident).
- Portrait + landscape allowed; no `FLAG_SECURE` in the lab; **`FLAG_SECURE` on for the production build** so member PII and money
  figures do not appear in the recents thumbnail or screenshots (a `MethodChannel`-free setting via `WindowManager` flags in
  `MainActivity`, enabled from `IS_PRODUCTION`).

`android/app/src/debug/res/xml/network_security_config.xml` (debug only) trusts user-installed CAs — copied from `afsmobile_flutter`; its
`debug/AndroidManifest.xml` points `android:networkSecurityConfig` at it ([03](03-environments-and-config.md) §3.6).

## 11.4 Signing

- **Debug builds** (lab) use Flutter's automatic debug keystore. Nothing to set up.
- **Release builds** (production) need a signing key. Follow the sibling: `android/key.properties` (gitignored) with `keyAlias`,
  `keyPassword`, `storeFile`, `storePassword`, read in `android/app/build.gradle.kts` into `signingConfigs.release`. Because this is a
  personal app with no store, a **new keystore generated for this app** is sufficient (`keytool -genkeypair …`, run by you; the agent
  never creates or sees the passwords). Keep the keystore backed up: if it is lost, the next release can only be installed after
  uninstalling the old one (data loss is irrelevant here — the app holds none).
- `android/key.properties` and `*.jks` are in `.gitignore` from P0.

## 11.5 The two build recipes

Both start by selecting the environment (by hand, per the standing rule) and end by installing.

### Lab (debug)

```bash
cd C:\projects\vagrant\afs-eco
bash packages/afsserver/bin/use-env.sh lab2 --skip-sync        # copies _env/lab2/.env → afsbrain_flutter/.env (and the other apps)
cd afsbrain_flutter
flutter run                                                    # USB-connected phone, or:
flutter build apk --debug && adb install -r build\app\outputs\flutter-apk\app-debug.apk
```

Requires: phone on the home Wi-Fi; `mkcert-rootCA.crt` installed on the phone (`lab2/README.md`); a freshly minted lab token in
`_env/lab2/.env` ([02](02-authentication-device-token.md)). A debug build is slower and larger; that is the accepted cost of reaching
the lab ([OQ5](00-overview.md)).

### Production (release)

```bash
bash packages/afsserver/bin/use-env.sh prod --skip-sync
cd afsbrain_flutter
flutter build apk --release                                    # signed with android/key.properties
adb install -r build\app\outputs\flutter-apk\app-release.apk
```

Requires: `_env/production/.env` with the production URL, `IS_PRODUCTION=true`, `APP_LOCK=true`, and a `--days`-limited production
token. If the shell cannot find `adb`, it is in the Android SDK's `platform-tools`; adding it to `PATH` is yours to do.

### Installing without a cable

Wireless debugging (Android 11+: Developer options → Wireless debugging → *Pair device with pairing code*) then `adb pair` /
`adb connect` works for both recipes. Alternatively copy the APK to the phone and open it (allow "install unknown apps" for the
file manager once). Both are one-time phone settings.

## 11.6 Running both environments on one phone

Installing a release build over a debug build fails (different signing keys): uninstall first. Because both share the application id,
only one environment is installed at a time. If switching often becomes annoying, [OQ5](00-overview.md)'s alternative is two
Android **product flavors** with different application-id suffixes (`.lab`, `.prod`), which gives two icons and an independent
lab-and-production pair — a Gradle change only, deliberately not done up front.

## 11.7 Updating

Rebuild and `adb install -r` (keeps nothing that matters). Bump `version:` in `pubspec.yaml` when you want the Settings screen to show a
different number. The app displays its version, build, environment label and host in Settings, so "which build is on my phone" is
answerable without a computer.

## 11.8 What is explicitly not done

Play Store listing, App Bundle (`.aab`), in-app updates, crash-reporting SDKs (Crashlytics/Sentry — they would ship PII and the
token context off-device), analytics, deep links, App Links, push, ProGuard/R8 shrinking beyond Flutter's defaults.
Code obfuscation (`--obfuscate --split-debug-info`) is **optional** for the production release; it does not protect the `.env`
asset, which is why §11.3's backup rules and the token's finite lifetime matter more.

## 11.9 Acceptance

- `flutter build apk --debug` against `_env/lab2/.env` installs and reaches the Members screen on the phone.
- `flutter build apk --release` against `_env/production/.env` (once production exists) installs and shows a red badge.
- `aapt dump permissions` (or the installed app's permission page) lists only `INTERNET` (+ `USE_BIOMETRIC`).
- `adb backup` of the package yields no usable data.
- A screenshot attempt on the production build is blocked.

# afs_mail 01 — as built (2026-10-21)

Built as planned, plus multi-account. Uncommitted.

- **www** (`AFS_MAIL_MODIFIER_ADDITION`): `app/Http/Controllers/Brain/BrainMailTenantController.php`, route in `routes/brain.php`, `tests/Unit/Brain/BrainMailTenantDomainTest.php` (12 pass; domain validation only).
- **Flutter**: `afsmail_flutter/` (Android + iOS). `flutter analyze`: only `prefer_initializing_formals` infos. `flutter test`: 13 pass, using a stubbed HTTP client (login cookie round trip, expired-cookie retry, HTTP-200 errors, candidate order, multi-account add/switch/restore/remove).

**Not verified:** the Brain query itself (needs a database; mode is `remote`), a real sign-in against any site, the WebView, the keystore, and any build/run on a device. `flutter pub get --offline` was used (nothing downloaded).

**Known limits:** the active `.env` is a copy of `_env/vagrant/.env`; `use-env` does not know this app yet. No push notifications (the Inbox is checked every 60 s while open). A site that has not deployed `/portal/mail/login` answers "does not support AFS Mail yet".

# 09 — Publishing and Versioning — Complete (core)

## Status

Core publish path implemented via `FormPublishService`. `publishForm` creates immutable
`laravel_form_versions` rows, sets `current_version_id`, and marks form `published`. Public
`GET /forms/{ref}` serves the published version envelope only; drafts are admin-only.

## Not yet built

- Pre-publish validation severities (§9.2.1)
- Version history UI and restore
- Signed preview links (§9.7)
- Close/archive scheduling UI

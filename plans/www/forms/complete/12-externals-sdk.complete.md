# 12 — Externals SDK — Partial

## Status

**Core surface:** `window.Portal.forms` via `external/forms/` — `init`, `submit`, `renderForm`,
`initAndRender`. Published-version projection only; no client-side mail/notifications/analytics.

**SDK updates:** `ValidationError` on 422, `onValidationError` callback, `idempotency_key` and
`duration_seconds` on submit, public file upload/delete endpoints wired in `fileHandlers.ts`.

**Types:** `www/resources/js/external/portal.d.ts` copied to all consumer
`checkpointHelper/static/portal.d.ts` mirrors.

## Not yet built

- `resume()` partial saves (§12.3)
- `locales()` helper (§12.3)
- `PortalFormsInstance` destroy/locale API (§12.4)
- Prefill / preview token options (§12.3)

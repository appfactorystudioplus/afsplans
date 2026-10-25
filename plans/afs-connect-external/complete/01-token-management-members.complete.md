# 01-token-management-members — Completion

**Completed:** 2026-07-31

**Status:** Complete for v1 scope. The members-side token UI, routes, and signed proxy to
coordinator all shipped. One item from the plan's own file table was deliberately not
done — the members PHPUnit feature test for the token proxy — and entitlement gating in
the UI rides on the existing settings-panel visibility rather than new code.

## Summary

Members proxies token CRUD to coordinator's internal API and exposes a settings UI for
list/create/revoke. No token hashes are stored in the members database, per the plan's
"No members DB table" decision — coordinator remains the single store and the only
validator.

### UI placement

Built where the plan recommended: the **AFS Connect settings card** in
`settings.html`, managed by `afsConnectSettings.ts` — *not* `MarketController.ts`, which
the plan explicitly ruled out as unrelated to credentials.

### Routes (Checkpoint session required)

All three plan routes registered in `members/routes/web.php:208-210`, matching the
planned paths exactly:

| Method | Path | Controller method |
|--------|------|-------------------|
| `GET` | `/api/v1/afs-connect/tokens` | `AfsConnectTokenController::index` |
| `POST` | `/api/v1/afs-connect/tokens` | `AfsConnectTokenController::store` |
| `DELETE` | `/api/v1/afs-connect/tokens/{id}` | `AfsConnectTokenController::destroy` |

The plan offered a choice — add token methods to `AfsConnectSettingsController.php` or
create a new `AfsConnectTokenController.php`. The second option was taken.

### Delegation to coordinator

`CoordinatorTokenClient` signs requests with the existing HMAC service identity and calls
coordinator's internal token endpoints. `CoordinatorUrl` gained the tokens path helper.
Create sends `member_reference_num`, `name`, and `scopes` — **no checkpoint user id**, as
the plan requires.

### Source registration

`external_sdk` registered as a **hosted** source in
`coordinator/config/afs_connect_sources.php:154`, and added to members'
`MEMBER_SOURCE_KEYS` so it toggles like any other source.

## UX flow

1. User opens **Settings → General → AFS Connect**
2. After settings load, the **API access** panel loads tokens
3. Create token → plaintext shown once with a copy button
4. Revoke removes the token; external callers immediately get `401`

## Files changed

- `members/app/Services/AfsConnect/CoordinatorTokenClient.php` — new, signed HTTP client
  for coordinator's internal token API.
- `members/app/Services/AfsConnect/CoordinatorUrl.php` — tokens path helper.
- `members/app/Http/Controllers/AfsConnectTokenController.php` — new, web API controller
  proxying list/create/revoke.
- `members/routes/web.php` — three token routes registered.
- `members/resources/js/templates/views/settings.html` — API access subsection.
- `members/resources/js/settings/afsConnectSettings.ts` — token list/create/revoke/copy.
- `coordinator/config/afs_connect_sources.php` — `external_sdk` registered as hosted.
- members `MEMBER_SOURCE_KEYS` — `external_sdk` added.

## Verification steps

1. Enable AFS Connect for a test member in members settings.
2. Create a read token in the API access panel; confirm the plaintext appears once.
3. Confirm a subsequent list call shows only the prefix, never the secret.
4. Exercise the token end to end:

```bash
curl -H "Authorization: Bearer afc_live_..." "{coordinator}/api/external/afs-connect/v1/search?q=test"
```

## Follow-up work

- **Members PHPUnit feature test for the token proxy** — the plan's file table lists
  `members/tests/Feature/` for token CRUD; not written. Verified manually via the UI plus
  coordinator's internal API instead.
- **Brain admin token-count dashboard** — deferred; the plan lists it as optional ops
  visibility, and `05-security.md` repeats it as a future item.

`enforce` is now the default for the externals API in `w/www` and for Checkpoint in `w/auth`. I audited the three apps and updated `handoff.md`. Nothing from this round is committed; the earlier commits are unchanged.

## Enforce by default
- **Where:** `EXTERNALS_CORS_MODE` and `CHECKPOINT_CORS_MODE` both default to `enforce`. A site whose saved mode is missing or invalid also falls back to `enforce`.
- **Effect on deploying:** deploying now enforces from the first request. To get a learning period, deploy once with both variables set to `report`. Otherwise list each site's embedding websites in its dashboard before the first deploy.
- **Tests:** 33 `w/www` tests and 13 `w/auth` tests pass.

## Audit of the three apps
Nothing in any of them sets `Origin`, sends cookies to `www`, or embeds the SDK. I read the code and walked the router for every route they can reach. I did not run any of the apps.

| App | Calls | Effect of `enforce` |
|---|---|---|
| `afsbrain_flutter` | `/portal/api/*` with the device bearer token | Nothing refused. Of 131 routes under its prefixes, 99 are server tier (outside the origin check), 28 are dashboard tier, and 4 are public. It calls only the two public reads. |
| `afsmobile_flutter` | Checkpoint, `GET /v1/brain/me/domains`, and a member's own domain at `/portal/api/v1/email-accounts` | Nothing refused. |
| `afsdesktop5` | Checkpoint and the communications service from its renderer; `members` storage from the main process; no calls to `www` | Found a break and fixed it (below). |

The desktop renderer loads from a file, so Chromium sends `Origin: null` to Checkpoint. `enforce` would have refused that and broken sign-in. In `report` mode it got no CORS headers either. `CheckpointCors` now admits `null` and answers `Access-Control-Allow-Origin: null`. A new setting, `CHECKPOINT_CORS_ALLOW_NULL_ORIGIN` (default true), switches this off. Checkpoint's cookies are SameSite=Lax, so allowing `null` adds no cookie risk. The desktop app signs in with the bearer token.

## Not verified
- **Communications service:** I did not read `w/communications`. It must also accept `Origin: null` for the desktop call client.
- **Desktop sign-in:** I haven't tried it against a Checkpoint running `enforce`.
- **Browser apps on another host:** an app such as `members` that calls `www` from a browser must be in `EXTERNALS_TRUSTED_ORIGINS`. `members` has no live cross-origin call to `www` today.

`handoff.md` has the full audit table, the new rollout rule, and the updated gaps. The plan docs are updated too: `00-overview.md` §8, `01-cors-and-nginx.md` §9, and the completion record.

Should I commit these changes?
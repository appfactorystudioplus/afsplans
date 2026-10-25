# 07-desktop-client-auth — Completion

**Status:** Code complete and build-verified. Every acceptance criterion (DA1–DA11) is
either done or implemented; three (DA3, DA7, DA11) need a running app against a real
Checkpoint backend to fully exercise and were not manually tested this session — see
Follow-up work.

## Summary

`afsdesktop5` (the Appfactory Studio desktop client) previously shipped a complete,
unguarded secondary-user login path inherited from the general-purpose Checkpoint SDK,
even though the platform rule is that the desktop app is primary-only. This step removed
that capability outright, added a defense-in-depth identity assertion on every path that
can produce an authenticated session, built the 2FA sign-in flow the app was missing
entirely, and closed a plaintext-token-storage side effect that existed only to serve the
now-removed secondary path.

1. **Deleted the secondary-login capability (§3, DA1–DA2).** `client-handler.ts`'s
   secondary branch of `handlePageLoad()`, `completeAccountSelection()`, the `'secondary'`
   branch of `buildLoginUrl()`, `STORAGE_KEYS` and its plaintext localStorage read/write,
   and the `SecondaryUserAccountOption`/`accounts`/`selectionToken` surface were all
   removed. `ClientUserType` narrowed to `'normal'`. A crafted `user_type=secondary`
   callback now hits an explicit, logged rejection branch returning
   `error: 'SECONDARY_NOT_PERMITTED'`, instead of silently falling through.
2. **`secondary-users.ts` deleted (DA2)**, along with its re-export from `checkpoint/index.ts`.
   Verified by a full `tsc --noEmit` and `webpack` build succeeding — nothing imports it.
3. **`assertPrimary()` added as layer 2 defense-in-depth (§4.1, DA4–DA5)**, in
   `components/settings.ts`. Runs on every path that can produce an authenticated session:
   the direct-login success path (via the new `finishLogin()` helper), the 2FA-completion
   path (also via `finishLogin()`), and — the part in progress when this session was
   interrupted — both session-restore paths, `restoreSessionIfPresent()` and the exported
   `restoreSessionGlobal()`. A secondary identity arriving through any of these throws
   `SecondaryNotPermittedError`, and the stored `user_session` is cleared (`assertPrimary`
   clears it directly; the callers' existing `catch` blocks also clear it, so a stored
   secondary session cannot silently persist across a restart from an older build).
4. **Built the missing 2FA flow (§6, DA7–DA8).** Previously `requires_2fa` threw a
   dead-end error — a 2FA-enabled member could not use the desktop app at all. Added a
   code-entry step to the login modal (`settings.html`, `locales.json` × 4 languages,
   `settings.ts`): `pendingTwoFactorToken` is held in a closure variable only, never
   written to `localStorage` or anywhere persistent (DA8); submitting the code calls the
   SDK's existing `checkpointClient.auth.completeTwoFactor()` and, on success, runs the
   same `assertPrimary()` + persist path as a direct login; a recovery-code toggle is
   wired to the same endpoint per plan §6.3.
5. **Removed the plaintext-token side channel (D3/DA6).** The deleted `STORAGE_KEYS`
   mechanism (`checkpoint_secondary_access_token`, `checkpoint_secondary_refresh_token`,
   `checkpoint_secondary_token_expires`, plus `checkpoint_user_type`/`checkpoint_user_data`)
   existed solely to serve the secondary flow and is gone with it. Sign-out now also
   purges those five keys explicitly (DA9), so an install upgraded from an older build
   that still has them sitting in localStorage gets them cleared the next time the user
   signs out — the keys were never read again by any surviving code path, so this closes
   the leftover rather than fixing a live bug.
6. **Documented `THIS_APP` (D5/DA10).** `app-names.ts` now states plainly that no login or
   API call on the primary path currently sends `app_name`, so the constant has no runtime
   effect on this client's own requests today — it is kept for step 10's server-side
   `primary_only` enforcement, not as evidence that app-scoping is already enforced here.
7. **Two findings not named in the plan's own 2026-08-22 audit**, found via the same
   reachability method the plan itself uses (grep + call-site tracing) and fixed for the
   same reason as the named findings:
   - `src/renderer/checkpoint/sso-handler.ts` — a second, entirely separate
     secondary-login-capable module (`handleSsoAuth`/`buildSecondaryLoginUrl`/
     `getUserTypeFromUrl`), unused by any call site but exported from `checkpoint/index.ts`
     and one UI wire-up away from being real, same as `client-handler.ts`'s old branch.
     Deleted.
   - `src/renderer/_testing/register-secondary.ts` and its `styles/register-secondary.scss`
     — a full secondary-user-registration test harness calling
     `checkpoint.secondaryUsers.register()`. Confirmed excluded from `tsconfig.json` and
     not a webpack entry (so it could not have broken the real build), but deleted anyway
     since it demonstrated exactly the flow this step forbids.
8. **§5 (server-side layers 3–4) deliberately not touched.** The plan states this
   explicitly — layer 3 (`primary_only` middleware option) and layer 4 (never minting a
   Studio-scoped secondary token) belong to step 10. Nothing in this session added or
   changed server-side enforcement.

## Files changed

- `src/renderer/checkpoint/client-handler.ts` — secondary paths removed, rejection branch
  added, `ClientUserType` narrowed, plaintext storage deleted.
- `src/renderer/checkpoint/index.ts` — `SecondaryUsers` and `sso-handler.ts` re-exports
  removed.
- `src/renderer/checkpoint/secondary-users.ts` — deleted.
- `src/renderer/checkpoint/sso-handler.ts` — deleted (undiscovered finding, see above).
- `src/renderer/checkpoint/app-names.ts` — `THIS_APP` documented (D5/DA10).
- `src/renderer/_testing/client.ts` — secondary UI sections removed.
- `src/renderer/_testing/register-secondary.ts`,
  `src/renderer/_testing/styles/register-secondary.scss` — deleted (undiscovered finding).
- `src/renderer/components/settings.ts` — `SecondaryNotPermittedError`, `assertPrimary()`,
  full 2FA step-through UI, `finishLogin()` helper, `assertPrimary()` wired into both
  session-restore paths, legacy-key purge on sign-out.
- `src/renderer/html/settings.html` — 2FA form added to the login modal.
- `src/renderer/locales.json` — 2FA strings added for en/es/de/zh.
- `afsserver/plans/checkpoint-server-auth/07-desktop-client-auth.md` — §7 and §9 tables
  given a status column and a per-row note.

## Verification steps

- `npx tsc --noEmit -p tsconfig.json` — exit code 0, no type errors, across every file
  touched this step.
- `npx webpack --config webpack.config.js --mode development` — full production-config
  build (main, renderer, transcription worker) completed successfully, confirming nothing
  deleted is still imported anywhere in the actual build graph (this is the DA2 check in
  practice, not just a grep).
- `grep -rn "secondary" src/renderer/checkpoint/` (DA1) — every remaining hit is either the
  §3.1 rejection branch, a comment documenting it, or `CHECKPOINT.md` using "secondary
  domain" in its generic cross-domain-SSO documentation (a different, pre-existing concept
  unrelated to the secondary-user-account feature this step removes). Flagged rather than
  silently claimed clean, since DA1's literal wording is "returns only the §3.1 rejection
  branch and its documentation."
- Read-verified (not executed) that `pendingTwoFactorToken` in `settings.ts` is a closure
  variable with no `localStorage`/`safeStorage` write anywhere in its lifecycle (DA8).
- Read-verified that `assertPrimary()` is called from all three producing paths:
  `finishLogin()` (used by both direct login and 2FA completion), `restoreSessionIfPresent()`,
  and `restoreSessionGlobal()` (DA4).
- **Not done this session:** launching the actual Electron app and manually exercising
  DA3 (crafted secondary SSO callback), DA7 (2FA sign-in against a real 2FA-enabled
  account, including recovery code), and DA11 (sign-in against live production and
  development Checkpoint hosts). These need a running app and a real backend/account,
  which this session did not have. The plan's own §8 testing table covers exactly these
  rows.

## Follow-up work

Added to `plans/TODOS.md` under Step 07:

1. Manually run the plan's §8 test table against a running build — in particular DA3
   (crafted `user_type=secondary` callback), DA7 (real 2FA account + recovery code), and
   DA11 (both Checkpoint hosts) — none of which were exercised this session.
2. §5 (server-side `primary_only` middleware option and the layer-4 token-mint refusal)
   is explicitly step 10's scope, not this step's — not started.
3. Step 08 (mobile client, the same primary-only treatment for `afsmobile_flutter`) has
   not been started.

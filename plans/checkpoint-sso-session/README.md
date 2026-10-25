# checkpoint-sso-session — Planning Index

Today, signing in to one AFS app does not sign you in to the others. Every one of the seven
web apps that share Checkpoint's client SDK (`members`, `coordinator`, `dream_graph`,
`flow`, `notes`, `pdf_editor`, `market`) sends the browser to Checkpoint's own `/login` page
whenever it has no local token, and that page always shows the credential form — even
seconds after the same browser authenticated against the same IdP for a different app.

This plan closes that gap, and adds two genuinely new capabilities, for **primary users
only**. **All three are now built and verified end to end against the vagrant lab** — see
[complete/02-design.complete.md](complete/02-design.complete.md):

1. **Silent sign-on** — a re-authentication step added to Checkpoint's login page, backed by
   an SSO session cookie. Scoped to **one browser**: signing in on one app quietly signs in
   the others *in that same browser*; it has no effect on any other browser or device.
   Confirmed live in a real browser: with a valid SSO session, visiting a second app's
   login-redirect flow never renders the credential form and lands authenticated.
2. **Everywhere logout** — a genuinely new, explicit, separate action that ends a primary
   user's session on every browser and device at once, exposed as a "Log Out of All
   Devices" entry point in `members`. The client already called it
   (`checkpoint.auth.logoutEverywhere()`, present unused in every app's SDK copy); this plan
   added the missing server route and the revocation mechanism behind it.
3. **Logout propagation within one browser (G10)** — found by testing after the other two
   shipped: an already-open, already-authenticated tab of a *different* app didn't notice
   ordinary logout at all until manually reloaded. Every app's `ClientHandler` now polls a
   new pure-liveness endpoint on page load and whenever its tab regains focus; a cleared SSO
   cookie makes it drop its own local session and redirect to login — reaching only other
   apps in the *same browser*, never another browser or device.

**Ordinary logout is explicitly unchanged in what it does, and stays scoped to one
session.** An earlier draft of this plan wired the revocation mechanism into the everyday
"Sign out" button, which would have meant logging out of the mobile app also logged out a
browser session elsewhere. The user caught this before any code was written: logging out of
one app, browser, or device must **never** reach any other one — only the distinct action in
point 2 above does that, and only when explicitly triggered. Point 3 does not change this:
it does not touch the revocation watermark or reach any other browser — it only makes other
apps *already open in the same browser* react to a cookie ordinary logout already clears.

**Secondary users are explicitly, deliberately untouched by both mechanisms.** A secondary
user still signs in to each app individually and logging one out never has any cross-app
effect — this is a hard requirement (G6), enforced in code by a `token_kind` claim and an
explicit rejection, not just an absence of new secondary-specific code.

**`www` does not participate at all.** It has its own, separate login system, and that is
correct and intentional (G9) — not a gap this plan closes, and not something this plan
touches. `www` does consume Checkpoint for other functionality; none of that is affected.

**This is otherwise a small, contained change.** It does not touch the ticket-exchange
mechanism the seven apps already use to obtain their per-app tokens (that keeps working
exactly as today), does not touch either native client's own repository at all, and does
not touch server-side bearer *verification's app-scoping logic* —
[checkpoint-server-auth](../checkpoint-server-auth/00-overview.md) explicitly marked
"changing how login works" as a non-goal, and this plan is the first thing to actually
change it, narrowly.

## The platform at a glance

Verified by reading the working trees on 2026-09-18 — not from documentation.

| Piece | State as of 2026-09-18 (before) → now |
|---|---|
| Ticket-based token delivery (`ssoLogin` → redirect with `sso_ticket` → `ssoExchangeToken`) | ✅ worked before, unchanged |
| Per-app token storage (`localStorage`, one origin per app) | ✅ correct and unavoidable — not the problem |
| Login page silent-session check before rendering the credential form | ❌ → ✅ built, verified live in a real browser |
| `setSsoCookie()` / `validateSsoCookie()` / `clearSsoCookie()` | ⚠️ dormant → ✅ wired into `login()`/`loginWithUsername()`/`completeTwoFactorAuth()`/`logout()` |
| `getTokensFromSsoCookie()` | ⚠️ dormant, and the wrong shape for the browser flow — kept as its own capability; a new `getSsoTicketFromCookie()` powers the actual redirect (see complete/02-design.complete.md's divergence) |
| A route exposing silent reauth | ❌ → ✅ `GET /checkpoint/auth/sso/session`, gated by `SSO_SILENT_REAUTH_ENABLED` |
| Ordinary `logout()` clearing the SSO cookie | ❌ → ✅ built; still scoped to one browser, verified by construction (D3/D6) |
| `SSO_COOKIE_DOMAIN` set per environment | ❌ unset everywhere → ✅ set correctly in all four `_env/*/.env` (F4 closed) |
| Any token revocation mechanism | ❌ → ✅ the everywhere-logout watermark, verified live |
| The client SDK's `logoutEverywhere()` method | ⚠️ dormant → ✅ its server route exists, verified live, exposed as a button in `members` |
| A way to tell a primary token from a secondary one on the refresh path | ❌ → ✅ `token_kind` claim |
| Secondary-user login/logout | ✅ already fully independent per app — confirmed unaffected by everything above |
| `www`'s own login | ✅ separate system, correctly untouched by this plan (G9) |
| `afsdesktop5` / `afsmobile_flutter` login | ✅ direct credential POST, never touches Checkpoint's `/login` page — silent sign-on structurally does not apply |
| `afsdesktop5` / `afsmobile_flutter` ordinary logout | ✅ already correctly scoped — calls `logout()`, never `logoutEverywhere()`, unaffected by anything this plan adds |
| An already-open tab of another app noticing ordinary logout happened elsewhere in the same browser | ❌ (only noticed on that tab's *next unrelated* reload, if ever) → ✅ `GET /checkpoint/auth/sso/status`, gated by `SSO_LOGOUT_PROPAGATION_ENABLED`, polled by every app's `ClientHandler` on load and on regaining focus |
| `CheckpointClient`'s public surface for building absolute URLs against Checkpoint's own origin | ⚠️ only `ssoDomain` (a different value) → ✅ new `baseUrl` getter, closing the exact "guess the domain from `window.location.hostname`" bug class found live in `members` while diagnosing this |

## Read in this order

| # | Document | Contents |
|---|---|---|
| — | [README.md](README.md) | This index |
| 00 | [00-overview.md](00-overview.md) | Problem, findings, goals, non-goals, glossary, nine decisions, acceptance criteria — including the corrected logout-scope design |
| 01 | [01-current-state-audit.md](01-current-state-audit.md) | **The evidence.** Exact file:line trace of today's login flow, the dead cookie-mode code, the missing `logoutEverywhere()` server route, and proof that no revocation exists today |
| 02 | [02-design.md](02-design.md) | The mechanism, in three parts, all built: §§1-5 silent sign-on (updated to the shipped ticket-based approach — see the divergence from the original token-pair sketch); §6 logout, split into ordinary (§6.1, unchanged scope) and the everywhere-logout feature (§6.2, including the cookie re-mint gap, now closed for both mint paths); §7 logout propagation within one browser (G10), found by testing after the first two shipped |
| 03 | [03-env-variables.md](03-env-variables.md) | Every SSO-related `.env` variable — cookie domain, and the three independent rollout flags — its correct value in each of the four environments. **All now actually set**, not just designed. |
| 04 | [04-rollout-and-testing.md](04-rollout-and-testing.md) | Phased rollout (local → vagrant → dev → prod), test matrices for silent sign-on, ordinary-logout isolation, and everywhere-logout, rollback |
| 05 | [05-native-clients-and-sdk-copies.md](05-native-clients-and-sdk-copies.md) | `afsdesktop5` and `afsmobile_flutter`: confirms their existing "Sign out" is already correctly scoped and needs no change, and that they already carry the unused everywhere-logout client call too — plus the SDK copy-distribution convention across the seven web apps, and (§9) the one SDK change G10 actually required there |

## Completion documents

Each numbered document gets a completion document in `complete/` when its work lands —
source basename plus `.complete.md`. Include status, summary, files changed, verification
steps, and follow-up work.

**[complete/02-design.complete.md](complete/02-design.complete.md)** — all three mechanisms
are built and verified end to end against the vagrant lab: everywhere-logout (G8), with a
"Log Out of All Devices" entry point live in `members`; silent sign-on (G1), confirmed live
in a real browser session; and logout propagation within one browser (G10), confirmed live
across two real tabs. Read that document for real divergences found only during
implementation — not anticipated by the design's own illustrative code — including a
MySQL-vs-PHP clock mismatch that silently made the revocation check a no-op, a
token-pair-vs-ticket mismatch that would have made the login page redirect to a target app
with no usable session at all, and G10 itself, which the original design didn't anticipate
needing at all until testing the first two mechanisms surfaced it.

## Code documentation standard

Match `checkpoint-server-auth`'s standard: document what new/changed auth code trusts,
what it rejects, and which way it fails. This applies with extra weight to the logout split
in [02-design.md](02-design.md) §6: the code must make it structurally obvious which method
is session-scoped and which one reaches every device, since conflating them once already
was this plan's own first mistake.

## Repository map

| Repo | Role in this plan |
|---|---|
| `apps/auth` | Silent sign-on and both kinds of logout (§§1–6) land only here. G10 (§7) additionally changes this app's own `resources/js/checkpoint/` — the canonical copy — which is why that one change also propagates below. |
| `apps/members`, `apps/coordinator`, `apps/dream_graph`, `apps/flow`, `apps/notes`, `apps/pdf_editor`, `apps/market` | **`resources/js/checkpoint/` unchanged for silent sign-on and logout** — confirmed, not assumed, in [05](05-native-clients-and-sdk-copies.md) §6. Each already redirected to Checkpoint's login page immediately when unauthenticated and already exchanges whatever ticket comes back, so all seven benefit from silent sign-on with zero code changes; each already had the everywhere-logout client call sitting unused, now reachable. **G10 is the one exception**: `client-handler.ts`'s new logout-watch and `index.ts`'s new `baseUrl` getter are copied into all seven, byte-identical to `auth`'s own copy ([05](05-native-clients-and-sdk-copies.md) §9). `members` additionally gained a small, app-specific UI addition (the "Log Out of All Devices" button) — not an SDK change. |
| `apps/www` | **Excluded entirely (G9).** Has its own separate login system; uses Checkpoint for other functionality only. Not one of the seven apps above, and not analyzed as if it were. |
| `afsdesktop5`, `afsmobile_flutter` | **Unchanged, including for G10.** Confirmed in [05](05-native-clients-and-sdk-copies.md): both authenticate via a direct credential POST that never touches Checkpoint's login page or its SSO cookie, so silent sign-on and logout propagation are both structurally inapplicable; both already call ordinary `logout()` (never `logoutEverywhere()`) for their own "Sign out," which is why their existing behavior already matches the corrected, session-scoped design with zero changes needed. |
| `afsserver` | This plan |

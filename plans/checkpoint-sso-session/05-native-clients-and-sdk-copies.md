# 05 — Native clients and SDK copy distribution

Two questions, both raised explicitly and both answered by reading the code rather than
assuming: does `afsdesktop5` or `afsmobile_flutter` need any change for either mechanism in
this plan, and does anything in this plan require editing the `checkpoint/` client SDK that
is copied from `auth/resources/js/checkpoint` into seven other apps' own
`resources/js/checkpoint` folders?

**Answer to both: no, and this document is the verification, not an assumption.** This
document was corrected after the user identified a mistake in an earlier draft: that draft
had ordinary logout reach across devices, which would have meant "Sign out" on
`afsdesktop5` silently ending a browser's session too. The corrected design (D3/D5/D6 in
[00-overview.md](00-overview.md)) means both native clients' *existing, unmodified*
behavior is already exactly right.

## 1. How the SDK-copy convention actually looks, confirmed

```
auth/resources/js/checkpoint/       — the canonical copy (14 files)
members/resources/js/checkpoint/    — same 14 files, plus members' own app-names.test.ts, app-urls.ts
notes/…, pdf_editor/…,
coordinator/…, dream_graph/…,
flow/…, market/resources/js/checkpoint/  — same 14 files as auth, verified by directory listing
```

Confirmed by listing all seven directories (plus `auth`'s own canonical copy) on
2026-09-18: `CHECKPOINT.md`, `alloc.ts`,
`alloc_v2.ts`, `app-names.ts`, `auth.ts`, `authz.ts`, `browser-auth.ts`, `client-handler.ts`,
`http-client.ts`, `index.ts`, `resources.ts`, `secondary-users.ts`, `sso-handler.ts`,
`types.ts` — identical file set in all seven consuming apps. This is the same
hand-maintained-copy pattern `AGENTS.md` §4 documents for `portal.d.ts` (there, one file;
here, a whole folder), just not itself written down anywhere — worth a documentation note
to the user separately, not a deliverable of this plan.

**`www` has its own copy of this same folder too, but is not one of the seven apps this
plan's mechanisms apply to** — see [00-overview.md](00-overview.md) §1/G9. `www` uses
Checkpoint for other functionality; its own sign-in is a separate system this plan does not
touch, so its copy of the SDK is not part of the "seven apps" analysis in this document
either, even though the file contents happen to match.

**`login.ts` is not in this set.** It lives at `auth/resources/js/login.ts`, a sibling of
the `checkpoint/` folder, not inside it, and is the script for Checkpoint's own `/login`
Blade view specifically. It has no copy in any of the seven apps, because none of them serve
that page — only `auth` does.

## 2. Silent sign-on (`02-design.md` §§1–5): confirmed inapplicable to native clients

`checkpoint-server-auth/07-desktop-client-auth.md` §2.1 and
`08-mobile-client-auth.md` §2.1 (verified 2026-08-22, re-confirmed against the same source
here) both record the same shape:

| Client | Login path |
|---|---|
| `afsdesktop5` | `components/settings.ts` calls `checkpointClient.auth.login()` / `loginWithUsername()` directly |
| `afsmobile_flutter` | `AuthRepository.login()` → `ClientHandler.loginNormal()` → `checkpoint.auth.login()` / `loginWithUsername()` directly |

Both are a **direct credential POST to `/checkpoint/auth/login`** — neither ever redirects
a browser to Checkpoint's hosted `/login` page. Since the silent-reauth check in this plan
lives entirely inside `auth/resources/js/login.ts` (§1), and that file only executes when a
browser actually loads that page, **there is no code path in either native client that ever
runs it.** Nothing to build, nothing to skip-and-flag; confirmed absent by design.

**Adjacent but out of scope**: `JoystickEngine_v2` (`checkpoint_cpp`), not named in this
plan's scope, uses a genuinely different mechanism — "Browser/loopback SSO" per
`checkpoint-server-auth`'s README client-apps table, opening the user's actual system
browser to Checkpoint's hosted login page (RFC 8252 loopback pattern). That client *would*
benefit from silent sign-on for free, the same way any of the seven web apps do, since it
goes through the same `/login` page — noted for completeness, not designed here.

## 3. Ordinary logout (G2): both clients are *already* correctly scoped, unmodified

This is the section an earlier draft got wrong, and the correction is good news: it needs
**no code change in either repository**, because their current behavior already matches
the corrected design.

`afsdesktop5`'s own audit (`checkpoint-server-auth/07` §2.1): *"Sign-out — calls
`auth.logout()` server-side, then clears local state — best-effort, with local clearing
regardless."* `afsmobile_flutter`'s shape is the same. Both call the plain `logout()`
method — never `logoutEverywhere()` — for their own "Sign out" action. Per
[02-design.md](02-design.md) §6.1, `logout()` never writes the revocation watermark and
never reaches any other session. So:

- Signing out of `afsdesktop5` does not, and will not, affect a browser session or
  `afsmobile_flutter`'s session for the same primary user.
- Signing out of a browser does not, and will not, affect either native client's session.
- This holds **because** the watermark write lives exclusively inside `logoutEverywhere()`
  (§6.2 of the design), a method neither client's ordinary "Sign out" ever calls.

**Verified, not assumed**: both clients' own SDK copies already have a `logoutEverywhere()`
method distinct from `logout()` (§4 below) and neither production "Sign out" flow calls it
— confirmed by the same `checkpoint-server-auth` audit describing exactly one call
(`auth.logout()`) on the production sign-out path in both clients.

## 4. Everywhere logout (G8): the capability already exists in both clients, unused

Both native clients' own SDK copies already carry the same `auth.ts`/`client_handler.dart`
`logoutEverywhere()` method as every web app (§9 in
[01-current-state-audit.md](01-current-state-audit.md)), calling the same
`POST /auth/logout/all` path this plan adds a server route for. Neither client's production
UI calls it today — but once the server route exists (D5/D7), **either client could expose
a "Log out of all devices" action using a method that is already sitting in their own
codebase**, with zero SDK change, the same way [02-design.md](02-design.md) §6.3 describes
for the web apps. Building that UI is optional and not required by this plan (see
[04-rollout-and-testing.md](04-rollout-and-testing.md) §4) — the point verified here is that
nothing would block adding it later.

### 4.1 The honest propagation bound, if everywhere-logout is ever triggered

[00-overview.md](00-overview.md) A17 states everywhere-logout propagates within the
existing bearer-verification cache window for an *active* session, and only on the next
request/reload for an *idle* one. Native clients are bound by the same rule, with one
concrete detail worth naming: `getAccessToken()`'s local expiry check
(`if (now >= expiry)`, present verbatim in both clients' SDK copies) means a native client
holding an access token that has **not yet locally expired** does not proactively re-verify
it with the server at all — it only discovers a revocation when that token is either
(a) presented to some app's API that itself re-verifies with Checkpoint (subject to that
app's own cache), or (b) locally expires and a refresh is attempted. This is the same
client-side optimistic-expiry design the web apps have, just stated explicitly for a client
with no page-reload moment to force the question.

## 5. The two native clients are a separate copy lineage from the seven web-app folders

`afsdesktop5`'s `src/renderer/checkpoint/` (TypeScript, Electron) and
`afsmobile_flutter`'s `packages/checkpoint/lib/src/` (Dart) are **each their own port**,
confirmed by `checkpoint-server-auth/08-mobile-client-auth.md` §3.2's own description: *"This
is a local copy of apps/auth's SDK, not a shared dependency... deleting from it affects only
this app."* Neither lives under `w/`, and neither is one of the seven folders in §1. This is
a third, adjacent copy-drift surface — already named as a platform-wide risk in
`checkpoint-server-auth`'s F5 finding for the *server-side* validator (copied seven times);
the same shape exists here for the *client* SDK, just not previously written down. Noted for
the record; not a deliverable of this plan, because §§2–4 already establish that this plan
needs no change in either language's copy.

## 6. What §§1–6 of the design required here: nothing, verified by a checklist

Because §§1–6 of [02-design.md](02-design.md) required **zero changes** to
`auth/resources/js/checkpoint/` itself (silent sign-on lives in `login.ts`, outside that
folder; both kinds of logout are entirely server-side PHP, calling a client method — the
everywhere-logout one — that already exists), there was **no propagation step** for either
of those two mechanisms. The verification was the negative check below — since superseded
for the SDK folder specifically by G10 (§7 of [02-design.md](02-design.md); see §9 below),
but still accurate for silent sign-on and everywhere-logout on their own:

1. `git diff --stat` (or equivalent) on `apps/auth` after implementing 02-design.md §§1–6
   touches only: `login.ts`, `AuthController.php`, `AuthenticationService.php`,
   `JwtService.php`, `routes/checkpoint.php`, the new migration, and `config/checkpoint.php`.
   **It does not touch anything under `auth/resources/js/checkpoint/`.**
2. If that check ever fails — if implementation genuinely needs to change something in the
   SDK folder — that is a signal the design has grown beyond what this document specifies,
   and at that point, and only then, the same change must be copied into all seven listed
   web-app folders (`members`, `notes`, `pdf_editor`, `coordinator`, `dream_graph`, `flow`,
   `market`) and, unless that change is itself confirmed structurally inapplicable to a
   native client (as G10 is — see §9), ported into `afsdesktop5`'s TypeScript copy and
   `afsmobile_flutter`'s Dart copy too, per the convention confirmed in §1/§5. Do not let
   such a change land in `auth`'s copy alone and call the plan done. `www`'s copy is out of
   scope regardless (§1).

**This is exactly what happened for G10** — see §9.

## 7. Acceptance criteria (additions to `00-overview.md` §6)

| # | Criterion |
|---|---|
| A13 | Neither `afsdesktop5` nor `afsmobile_flutter` requires any code change for ordinary logout's scoping (G2) or for everywhere-logout's reach (G8) — both already call the same `logout()`/`logoutEverywhere()`/`refreshToken()` methods this plan modifies, with no native-specific token-minting or logout path in `apps/auth`. |
| A14 | Silent sign-on is confirmed structurally inapplicable (not merely unimplemented) to both native clients, since neither ever loads `auth/resources/js/login.ts`. |
| A15 | `auth/resources/js/checkpoint/` is unmodified by silent sign-on or either kind of logout (02-design.md §§1–6). A change made for a *different* goal (G10 — see §9) is verified present in all seven listed web-app copies, and ported into `afsdesktop5`'s and `afsmobile_flutter`'s own copies unless structurally inapplicable there, before that change is considered complete. |

## 8. Testing additions (additions to `04-rollout-and-testing.md` §2)

| Signed in on | Action | Expected |
|---|---|---|
| A browser (e.g. `members`) and `afsdesktop5`/`afsmobile_flutter`, same primary user | Ordinary "Sign out" on the browser | The native client's session is **completely unaffected** — this is the exact scenario the user named and this plan must not regress |
| A browser and `afsdesktop5`/`afsmobile_flutter`, same primary user | Ordinary "Sign out" on the native client | The browser session is **completely unaffected** |
| A browser and `afsdesktop5`/`afsmobile_flutter`, same primary user | Everywhere-logout triggered from *either* one (if/when a UI entry point exists, or via a direct API call for verification purposes) | The *other* one is rejected on its next request/reload — confirms `token_kind: 'normal'` and the watermark are symmetric regardless of which client minted the token or triggered the action |
| `afsdesktop5` or `afsmobile_flutter`, signed in | Checkpoint's `/login` page, opened directly in a browser as the same primary user | Renders the credential form, exactly as it did before this plan — the SSO session cookie was never involved, since the native client never set one (§2) |

## 9. G10 (logout propagation, `02-design.md` §7) — the one place this plan touches the seven web-app copies, and why native clients are still untouched

Unlike silent sign-on and logout, G10's mechanism (`ClientHandler.startLogoutWatch()` /
`checkSsoLiveness()`, plus a `baseUrl` getter on `CheckpointClient`) lives **inside**
`auth/resources/js/checkpoint/index.ts` and `client-handler.ts` — the shared SDK folder §6
otherwise found no reason to touch. Per §6's own rule, this change was copied into all seven
web-app folders (`members`, `notes`, `pdf_editor`, `coordinator`, `dream_graph`, `flow`,
`market`) — confirmed byte-identical to `auth`'s copy (modulo each app's own CRLF line
endings, pre-existing and unrelated to this change) before this plan's implementation was
considered complete for G10.

**Not ported to `afsdesktop5` or `afsmobile_flutter`, deliberately** — confirmed structurally
inapplicable, the same way §2/§3 already established for silent sign-on and native-client
logout:

- The whole mechanism is a reaction to the shared `checkpoint_sso` cookie disappearing.
  Both native clients authenticate via direct credential POST and never set, read, or send
  that cookie (§2) — there is nothing for a native-client port of `checkSsoLiveness()` to
  ever observe.
- `document.visibilityState` and `window.location.href`, the two browser APIs the mechanism
  is built on, have no equivalent in a native client's own session lifecycle in the way this
  design uses them (reacting to a *browser tab* regaining focus).
- This is consistent with A13/A14: G10 sits in exactly the same category as G1 — a browser
  cookie's presence propagating something, well-defined only where cookies and tabs exist.

If a native client is ever given its own "was I signed out elsewhere" check, it would need
its own design (e.g. polling `/checkpoint/auth/token/verify` against the revocation
watermark, which is G8's mechanism, not G10's) — not a port of this one.

### Acceptance criteria (addition to `00-overview.md` §6)

| # | Criterion |
|---|---|
| A21 | G10's `ClientHandler`/`CheckpointClient` changes are present, byte-identical to `auth`'s canonical copy, in all seven web-app folders' `resources/js/checkpoint/`; `afsdesktop5` and `afsmobile_flutter` are confirmed to need no equivalent change, for the reasons in §9, not merely left unexamined. |

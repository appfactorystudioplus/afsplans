# 01 — Web client and guest join

## 1. In-browser call client

LiveKit's JS/web client SDK (same SDK the desktop client uses inside Electron's Chromium
renderer — `../desktop/01-webrtc-client.md` §1) runs directly in
any modern browser tab. No plugin, no download. Camera/mic permission via the standard
`getUserMedia` browser prompt.

## 2. The guest join page — no account required

A stable, public URL — e.g. `members.appfactory.studio/join` or `members.appfactory.studio/j/:code`
if the code is embedded directly in a shareable link (final URL scheme decided at
implementation time against `w/members`'s existing `routes/web.php` conventions) — that:

1. Accepts a join code (typed, or pre-filled from a `:code` URL segment for a clicked link).
2. Asks only for a display name — no email, no password, no account creation step.
3. Calls `w/communications`'s `POST /sessions/join` (`../03-signaling-and-session-model.md`
   §3) directly from the browser and connects.

This route is explicitly **outside** whatever auth-wall middleware gates the rest of
`w/members` — it must be reachable by someone with zero prior relationship to the platform,
matching the requirement that starting a session needs membership but joining does not.

## 3. Screen share

Browsers expose `getDisplayMedia` directly (no Electron-style `desktopCapturer` needed — the
web platform's own screen-picker UI handles source selection). Published to the LiveKit room
identically to desktop's screen-share track
(`../desktop/04-screen-share-in-call.md` §1).

## 4. Signup flow — explicitly unaffected

The existing member signup flow (`w/www/resources/js/afs/create.ts` and the shared
`globalAuth.ts`) is not touched by this plan. Hosting requires being a member, but *becoming* a
member is unchanged — a non-member who wants to host, rather than just join, is directed to the
existing signup flow, not a new one built for this feature.

## 5. Browser compatibility note

WebRTC and `getDisplayMedia` are broadly supported in current Chrome/Edge/Firefox/Safari — no
special-casing expected, but Safari's WebRTC implementation has historically lagged the others
on specific features (e.g. certain simulcast behaviors); this should be verified against
whatever Safari versions the join page needs to support once LiveKit's web SDK is actually
integrated, not assumed clean from this plan alone.

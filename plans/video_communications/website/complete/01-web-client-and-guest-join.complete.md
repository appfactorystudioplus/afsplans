# 01 — Web client and guest join — completion

**Status:** **Partly done** (2026-09-19) — website plan Phase A. The **guest join page** (§2) is built, deployed to the vagrant
lab and verified end to end in a real browser. The **in-browser call client** (§1) exists in its basic form (video grid,
microphone/camera toggles, leave, reconnect). **Not built:** the Safari check (§5).
**Update:** the host UI (`02`) is now built: see [`02-host-controls-and-ui.complete.md`](02-host-controls-and-ui.complete.md). Screen share (§3) and chat are now built too (chat over LiveKit data messages): see [`03-advanced-features.complete.md`](03-advanced-features.complete.md).

## Summary

A visitor with no account opens `https://members.appfactory.<zone>/j/<code>` (or `/join` and types the code), enters a name,
and is in the meeting: they see and hear the others, can mute their microphone and camera, and can leave. If the host runs a
waiting room they wait until admitted or refused. Refreshing the page puts them back in as the **same participant**; a
dropped connection is retried automatically.

The page takes the **whole window** — no dashboard around it. It is written in TypeScript on the existing view framework
(`checkpointHelper/static/appfactory3.ts`) as a separate view set, the way `views/secondary.ts` registers the secondary
dashboard, and it is its own Vite entry (`join.ts`, like `login.ts`), so a guest never downloads the dashboard, never touches
Checkpoint and is never redirected to a login. LiveKit's SDK (~560 kB) is a separate chunk loaded only when a call starts; the
join form itself is 22 kB.

`views.ts` (the dashboard's view registration) was **not** changed: the guest cannot import it (it drags in the whole
dashboard). The host flow (Phase B) will import the same `views/conference.ts` from `views.ts` to swap the dashboard for this
shell, so guests and hosts share one call screen.

## Files changed

**`w/members`** (uncommitted; `main` branch)

| File | What |
|---|---|
| `app/Http/Controllers/ConferenceJoinController.php` (new) | Serves the page; 503 if `COMMUNICATIONS_URL` is empty; `Cache-Control: no-store`, `Referrer-Policy: no-referrer` |
| `routes/web.php` | `GET /join`, `GET /j/{code}` (code constrained to `[A-Za-z0-9_-]{1,32}`), outside every auth group |
| `config/services.php`, `.env`, `_env/vagrant/.env` | `services.communications.url` ← `COMMUNICATIONS_URL` (lab: `https://communications.appfactory.test1`) |
| `resources/views/join.blade.php` (new) | Minimal shell; meta tags carry the service URL and the code; `noindex`; `no-referrer` (the code is the only secret) |
| `resources/js/join.ts` (new), `vite.config.js` | The public entry point and its Vite input |
| `resources/js/views/conference.ts` (new) | The shell: join → waiting → call → ended screens on the view framework |
| `resources/js/conference/*.ts` (new) | `types`, `joinCode`, `communicationsApi`, `joinFlow` (pure logic), `session` (storage helpers), `callController` (LiveKit) |
| `resources/js/templates/views/conference_{join,waiting,call,ended}.html`, `styles/conference.scss` (new) | Markup and styles (dark, brand-coloured, full window, phone-friendly) |
| `resources/js/i18n/en.json` | 69 `conference.*` strings (English only; other locales fall back to English) |
| `resources/js/__tests__/conference/*.test.ts` (new) | 37 unit tests |
| `package.json`, `package-lock.json` | `livekit-client` `^2.22.3` (installed with the user's approval; 12 packages added) |

**`w/communications`** (test tooling only): `scripts/lab-web-join.ts`, `scripts/media-probe/page-main.cjs`,
`scripts/media-probe/web-steps/*.js`, `npm run e2e:web`.

## Differences from the plan text

| Plan said | Built | Why |
|---|---|---|
| `/join` or `/j/:code` | Both | Plan left the final scheme to implementation |
| Calls `POST /sessions/join` | `POST /v1/join` | That is what the service exposes (`/v1` prefix) |
| — | The guest can paste a whole meeting link into the code box | Codes get shared as links |
| — | Refresh → automatic rejoin as the same participant (rejoin token in `sessionStorage`) | Plan `03` §6 reconnection |
| — | Microphone/camera denied or missing does **not** stop the join: the guest is in as a listener with a notice | Real browsers deny permissions all the time |
| — | The service's TURN servers (plan `02`) are handed to LiveKit as ICE servers | Direct paths are preferred; TURN only when needed |

## Verification

- **Unit (`npx vitest run` in `w/members`):** 37 new tests (join-code parsing incl. pasted links; the API client's request shape,
  error mapping, retry-after, network/abort handling; the wait-for-host flow with a fake clock: admit, deny, cancel, timeout,
  rate limit, network blips). The whole members suite passes.
- **Typecheck:** the new files are clean (`tsc -p tsconfig.json --noEmit --ignoreDeprecations 5.0`). Running `tsc` on the project
  as configured fails for a reason older than this work: `tsconfig.json` has `ignoreDeprecations: "6.0"` but TypeScript 5.9.3 is
  installed, and with that option overridden there are 53 unrelated type errors elsewhere in the project.
- **Build:** `npm run build` succeeds (`join` 22 kB, `callController` 562 kB lazy chunk). Vite's dev server was never started.
- **Served by the lab:** the page returns 200 with no login and with the right headers; a malformed code segment is a 404.
- **End to end in real Chromium against the lab (`npm run e2e:web`, 29 checks, phone-sized window too):** the real page and its
  built JavaScript, a real host in the call publishing fake camera + microphone, the real service, LiveKit and database.
  Verified: the form's validation and the service's refusals shown in plain language (bad code, unknown code, room with no
  meeting running; sloppily typed code; a pasted link; the Enter key); joining by link, two tiles with names ("(You)" on yours);
  the host's video decoded and **moving**; the host's audio arriving as **real signal** from the `<audio>` element; the
  connection indicator, the counter, the code and title; the call filling the window with no dashboard in the page; mute/unmute;
  **refresh → back in by itself as the same participant** (same LiveKit identity, one database row); Leave → the ended screen →
  Rejoin with code and name filled in; the database marking the guest `left`; the waiting room (admit → in the call; deny → back
  to the form with "the host didn't let you in", name kept).
- **Looked at:** screenshots of every screen at desktop and phone width, and the error state in the built-in browser.
- **Two real bugs found by this testing and fixed:** (1) LiveKit's SDK disconnects the room when the page unloads, which the
  page treated as the person pressing Leave and so forgot the rejoin token — a refresh could never rejoin; (2) after a refresh-
  rejoin the guest's name was unknown, so "Rejoin" from the ended screen came back with an empty name.

## Not verified / follow-up

- **Only Chromium** (Electron 28, Chromium 120) was used. Firefox, Safari (plan §5 — the plan warns about its WebRTC) and real
  mobile browsers are untested.
- Fake devices only: the microphone/camera **permission prompt and denial paths** are covered by code and unit-level
  classification, not by a run with a real denial. No screen-reader/keyboard-navigation review.
- **Layout with many participants** (the grid rules for 3–9 and 10+) has not been looked at; only 1–2 tiles were seen.
- **A real network drop** (the automatic rejoin loop after LiveKit gives up) has not been exercised; only refresh-rejoin has.
- The waiting screen's Cancel button, the "ends at" time and a member joining *as themselves* (the page never sends an
  Authorization header) are unverified.
- Other environments need `COMMUNICATIONS_URL` in their own env files (`_env/local`, `_env/dev`, `_env/prod`); only the lab's
  were set. Without it the page answers 503 by design.
- Only English text; `es`/`de`/`fr`/`zh` fall back to English until translated.
- ~~Phase B (host UI) needs a member login.~~ Built (see `02`). The owner's own path is checked by `npm run e2e:owner`, which reads the member's credentials from environment variables that **you** set when you run it (I do not enter passwords to authenticate); it has a credential-free `--dry-run`.
- ~~Screen share (§3), chat~~: built in `03-advanced-features` (chat travels as LiveKit data messages because the signaling WebSocket, `03` §5, still does not exist); host controls are done (`02`).
- Nothing is committed in `w/members`.

See also `../../complete/01-architecture-and-media-topology.complete.md` (the service, LiveKit and Coturn this page talks to).

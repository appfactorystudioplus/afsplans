# 03 — Advanced features — completion

**Status:** **Done for everything the plan asks for that can be built and tested today** (2026-09-19): **screen share, in-call
chat, raised hands, reactions and the shared whiteboard**, all verified in real browser pages against the lab. Two items were
**moved to `plans/TODOS.md` on your instruction or because the plan itself defers them**: live captions (needs a speech service
that does not exist) and embeddable pages on members' own domains (the plan calls it v2 and it depends on the website-builder
plan). One deviation from the plan: chat, reactions, hands and the whiteboard travel as **LiveKit data messages**, not the `/signal`
WebSocket the signaling plan describes, because that WebSocket still does not exist.

This step also covers the parts of plan `01` (§3 screen share) and of `../03-signaling-and-session-model.md` §5 (chat) that were
still open after the guest page and the host controls.

## Summary

Everyone in a meeting, guest or member, has these on the call screen:

* **Share screen.** The browser's own picker opens; everyone else sees the shared screen large, with the cameras as a strip under
  it. The sharer sees "You are sharing your screen" and a Stop button (their own share is not shown back to them). The share also
  ends by itself when the browser's own "Stop sharing" bar is used. The button is hidden where the browser cannot capture the
  screen (phones, non-HTTPS pages).
* **Raise hand.** A badge on your tile, visible to everyone, with a spoken announcement for screen-reader users. Someone who joins
  later still sees hands that are already up.
* **Reactions.** Six emoji (👍 👏 ❤️ 🎉 😂 😮) float up the video area with the sender's name.
* **Chat.** A side panel with an unread badge. **Messages are seen only by whoever is in the meeting at that moment and are not
  saved**; a person who joins later sees nothing from before, and the panel says so.
* **Whiteboard.** A shared Excalidraw canvas (shapes, arrows, freehand, text, select/move/delete) that takes the same area a shared
  screen does, with the cameras as a strip under it. Nobody has it forced onto their screen: when someone opens it, the others get a
  notice, a dot on the button and a spoken line, and open it when they like. A person who opens it late receives what is already on
  it. A host or co-host can switch on **"Only hosts can draw"**: everyone else's tools go away, they get a banner, and nothing
  they do changes the board. Nothing is saved; the menu can export an image.

The chat panel and the host panel share the right-hand side of the window (opening one closes the other and the video makes room);
on a phone the buttons wrap onto two rows and the panels stop above them.

## What each plan section became

| Plan | Built |
|---|---|
| `03` §1 Reactions and raised hand, "same LiveKit data-channel mechanism" | **Built** as specified. |
| `03` §2 Meeting whiteboard (Excalidraw, synced over a LiveKit data channel) | **Built.** `@excalidraw/excalidraw` 0.18.1 with React 18 (the versions `afsdesktop5` uses) added to `w/members` with your approval. Loaded **only when somebody opens the whiteboard** (its own lazy chunks; a call that never uses it downloads none of it). Fonts are copied next to the build by `vite.config.js` and served from our own site, not a public CDN. Sharing the component with `afsdesktop5` was not attempted. |
| `03` §3 Live captions | **Moved to `plans/TODOS.md`** (your instruction). Needs a server-side speech service that does not exist; `live_transcription` is still enforced nowhere. |
| `03` §4 Embeddable pages on a member's own domain | **Moved to `plans/TODOS.md`.** In plain words: instead of guests opening `members.<our domain>/j/<code>`, a member could serve the same join page from their own website (for example `meet.theirbusiness.com`) so it carries their brand. The plan calls it a v2 idea "not scoped further" that depends on the website-builder's custom-domain support, so there was nothing to build from. |
| `01` §3 Screen share (`getDisplayMedia` → LiveKit) | **Built.** |
| `../03-signaling` §5 chat over the signaling WebSocket | **Deviation: built over LiveKit data messages.** No service change was needed (tokens already allow publishing data). Consequences: no history and no server-side moderation of chat. Revisit when the WebSocket exists and if stored history is wanted. |
| `01` §5 Safari check | **Not done** (Chromium/Electron only). |

## How messages are trusted (the design decision worth knowing)

Anybody in the room can publish data, so every incoming message is untrusted input.

**Chat, reactions, hands** (`conference/messages.ts`): **who sent it is only ever the identity LiveKit attaches**, never a field in the
message (a test forges `"from":"host"` and it is ignored); size is checked before parsing and shape after; unknown topics, bad JSON,
wrong types, empty or over-long chat (1000 characters) and reactions outside the fixed six are dropped; chat text and names only
reach `textContent`; the time shown is when the message **arrived**; every sender is rate-limited on both ends. Tokens deliberately do
**not** grant `canUpdateOwnMetadata`, which would let a guest overwrite the role the UI uses to label people. A raised hand is state,
not an event, so whoever has a hand up tells each newcomer directly (immediately and again after 2 s, because the newcomer's data
channel can still be opening).

**Whiteboard** (`conference/whiteboardSync.ts`, pure and unit-tested):

* Elements are **rebuilt from an allow-list** of shapes (rectangle, diamond, ellipse, arrow, line, freehand, text) and properties:
  **no images, no embedded pages, no links, no custom data, no file ids**; numbers are finite and bounded, colours are checked, text
  is at most 2000 characters, a stroke at most 3000 points, the board at most 2500 elements. Anything else is dropped, one bad element
  does not cost the good ones in the same message.
* **Merging is order-independent**: Excalidraw's own rule (higher `version` wins, ties go to the lower nonce), so every client settles
  on the same drawing whatever order messages arrive in; deletions travel as kept "tombstones". A test feeds two clients the same
  messages in opposite orders and requires identical boards.
* **The lock is honoured only from a host or co-host**, judged by the role the **server** wrote into that participant's LiveKit
  metadata, never by anything in the message. While locked, drawing from anyone else is ignored by every client, so a modified
  client cannot get past it. A moderator tells each newcomer (and each person who asks for the board) that it is locked.
* Messages are at most ~11 kB (bigger changes are split; an element too large on its own is skipped and the person is told);
  a sender is rate-limited; asking for the whole board is limited to once per 3 s per person.

## Files changed (all uncommitted)

**`w/members`**

| File | What |
|---|---|
| `resources/js/conference/messages.ts`, `dataMessaging.ts` (new) | Chat/reactions/hands protocol, validation, rate limits and sending/receiving (no LiveKit dependency: unit-tested) |
| `resources/js/conference/chatPanel.ts`, `reactionsUi.ts` (new) | The chat panel; the reaction menu and floating emoji |
| `resources/js/conference/whiteboardSync.ts` (new) | The whiteboard's sync, sanitising, merging, lock and late-join catch-up (pure, unit-tested) |
| `resources/js/conference/whiteboard.ts` (new) | The Excalidraw glue and the frame around it (lazy: React, react-dom, Excalidraw and its CSS load on first open) |
| `resources/js/conference/callController.ts` | Data wiring, hands, screen share, whiteboard hooks; the board and chat are set up as soon as the room connects |
| `resources/js/views/conference.ts`, `templates/views/conference_call.html`, `styles/conference.scss`, `i18n/en.json` | Buttons, layouts, panels, notices, strings (English only) |
| `vite.config.js` | Copies Excalidraw's fonts to `public/build/excalidraw/fonts` at build |
| `package.json`, `package-lock.json` | `@excalidraw/excalidraw`, `react`, `react-dom`; `@types/react`, `@types/react-dom` (dev) |
| `resources/js/__tests__/conference/{messages,dataMessaging,chatPanel,whiteboardSync}.test.ts` (new) | 27 + 19 + 24 + 59 tests |

**`w/communications`**: `scripts/lab-web-call.ts`, `scripts/lab-web-board.ts` (new), `scripts/media-probe/web-steps/{call-*,board-*}.js` (new),
`scripts/media-probe/page-main.cjs` (real mouse drags for a canvas), `package.json` (`e2e:call`, `e2e:board`). No service code was needed for this step.

## How it was verified

| Check | Result |
|---|---|
| `w/members` unit tests (whole suite) | **288 pass**, 1 skipped (pre-existing) |
| `w/members` `npm run build` | succeeds (Excalidraw is in lazy chunks; the call screen's own code did not grow much) |
| `w/communications` `npm test`, `tsc` | 188 pass, clean |
| **Deliberate breakage** (each reverted, then all green again) | chat/hands: no receive-side rate limit, no newcomer hand sync, chat as HTML → each caught by exactly one test. **Whiteboard:** anyone may lock, the lock not enforced on incoming drawing, images and embeds allowed → each caught (1, 1 and 3 tests) |
| `npm run e2e:call` (chat, share, hands, reactions; 3 pages) | pass, also **at phone width (390×844)** |
| **`npm run e2e:board`** (whiteboard; 3 pages, real mouse input) | **pass** |
| `npm run e2e:web`, `e2e:record`, `e2e:owner -- --dry-run` | pass (regression of step 02, see its report) |

`e2e:call` and `e2e:board` put three real pages in one lab meeting against the real LiveKit and check, among other things:

* **Chat:** own copy marked "(You)", the reply named by LiveKit, an unread badge and announcement while closed, and a message containing `<img … onerror=…>` shown as plain characters with **no element created and no script run, on either side**.
* **Screen share:** **LiveKit really holds a screen-share track while sharing and none after**; the other page decodes it (frames advance); stopping by button and by the browser's own stop mechanism both end it for everyone.
* **Hands:** a person who joined after a hand went up still sees it, and sees no earlier chat.
* **Whiteboard:** the frame opens on demand where a share goes; the others are **told** and it is not forced on them; Ann's rectangle reaches Bob, Bob's ellipse reaches Ann; **Cy, who joined late, receives the two shapes already on it** and adds a diamond; Ann (a co-host) switches on "Only hosts can draw": Bob and Cy get the banner and lose their tools, **Bob dragging on the canvas anyway changes nothing on anyone's board (still 3 shapes)**, Ann can still draw a line and everyone gets it; after she unlocks, Bob draws again and all three boards hold five shapes.

Screenshots were reviewed. They found real bugs the DOM checks could not: floating reactions drifted **under** the open side panel (fixed);
at phone width the side panel's bottom edge overlapped the top row of the two-row control bar (fixed with a shared `--conf-controls-h`);
and the whiteboard button did nothing for a person who pressed it in the first seconds after joining, because the board was set up only
after the microphone and camera had started (fixed: it is set up when the room connects).

## What is NOT verified

1. **The browser's real screen picker.** It cannot be driven from a script and would put the desk's real pixels into the test, so the sharing page gets a stand-in `getDisplayMedia` returning a moving canvas. Everything after the picker is real; the browser's own "Stop sharing" bar is simulated by ending the capture track. Try it once by hand in Chrome.
2. **Receive-side rate limiting, oversize/malformed message handling and the merge order-independence** are unit-tested, not exercised through real pages.
3. **Other browsers.** Chromium/Electron only (Safari and Firefox unchecked).
4. **A real phone.** Layout was checked at 390 px in Electron; touch drawing on the whiteboard was not tried.
5. **Sharing audio**, and the **floating reaction's motion** (a hidden test window does not advance CSS animations; presence, text and position were checked, and a frame reviewed).
6. **The whiteboard with many people or large drawings** (only three participants and a handful of shapes were tried), and **its behaviour across a reconnect** (it re-fetches the drawing from whoever holds it; not tested).
7. Still open from step 02: the owner's path through the real dashboard needs your `npm run e2e:owner` run.

## Needs you

Nothing blocks this step. Open items are in **`plans/TODOS.md`, "Video communications"**: live captions (which speech service), embeddable
pages (whether and when), retrieving finished recordings, `COMMUNICATIONS_URL` for the other environments, and browser/phone checks.

## Next

Nothing has been started beyond this step.

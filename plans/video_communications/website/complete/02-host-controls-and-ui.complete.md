# 02 — Host controls and UI — completion

**Status:** **Done and verified in the lab, except two things** (listed under "What is NOT verified"): the owner's path through the real
dashboard, which needs a member sign-in only you can give (a script for you to run is written and its dry run passes), and browsers other than
Chromium. **Recording is now verified for real** (2026-09-19, later the same day): the lab runs LiveKit Egress and a meeting was recorded to an
MP4 that plays back; see "Recording (added later)" below. Retrieval of finished recordings is not built (in `plans/TODOS.md`).

## Summary

A signed-in member opens **Meetings** in the dashboard and sees **their meeting room**: one permanent join code and link that never
change, a waiting-room switch, what their plan allows (people, minutes), and **Start meeting**. If their plan does not include
meetings the screen says so; **"See plans" appears only when an upgrade would fix it** (the service says so, using the registry's own
`tier_disabled` distinction), and a plain "not available" appears for every other reason. Start meeting **replaces the whole
dashboard** with the full-window conference (same view framework as the guest page, `views/conference.ts`); **End meeting** returns to
a fresh dashboard.

During the meeting the host, and anyone the host promotes to **co-host**, get a **People** button and a panel: the **waiting room first**
(per-guest Admit / Deny, "Admit all"), the participant list with **Mute**, **Remove** and (host only) **Make co-host / Remove co-host**,
**Mute everyone**, **Start/Stop recording** (only in plans with `cloud_recording`), and (host only) **End meeting for everyone**
(two clicks). A guest waiting to be admitted is impossible to miss: a **banner** across the call ("1 waiting to join — Review"), a badge on
People, and the browser tab title `(1)`; you do not have to open anything to know.

## What each plan section became

| Plan | Built |
|---|---|
| §1 Start-a-meeting screen behind the auth wall, entitlement check, upgrade vs plain denial | `Meetings` entry in the dashboard menu → `meetings/meetingsScreen.ts`, driven by the new `GET /v1/host/status` (creates nothing, so opening the screen never makes a room). Four states: room, denied-with-upgrade, denied-plain, signed-out/error with retry. |
| §2 Room persistence: "your meeting room", stable code/link | The room is created once per member (`getOrCreatePersonalRoom`) and the screen always shows the same code and link, with Copy link. Starting again reuses it; if a meeting is already running the button says **Resume** and offers End. |
| §3 Host controls: admit/deny, mute-all, remove, promote co-host, start/stop recording gated by `cloud_recording` | All built (see below). **Deviation:** the plan says "over the signaling WebSocket". That WebSocket is not built (it belongs to `03-advanced-features`, with chat), so every control is an HTTPS call to `w/communications`, and clients learn about changes by polling (waiting room) and by LiveKit (participant metadata, mute state). The controls are the same API the desktop and mobile clients will call. |
| §4 Waiting-room UI, prominent | Banner + badge + tab title + panel section listed first; verified in a real page. Push (`waiting_room.request` events) arrives with the WebSocket; polling every few seconds until then. |

## The security decision worth knowing about (co-hosts)

A promoted guest usually has **no account**, so "the host's Checkpoint session" cannot be what authorises a co-host. Design:

* Host actions (end meeting, promote/demote, room settings, start) need the member's Checkpoint bearer token.
* **Moderator** actions (waiting room, participant list, admit/deny, remove, mute-all, recording) also accept
  `Authorization: Participant <rejoin token>`. The token is an HMAC over *participant + session* and grants nothing by itself: the service
  **reads the participant's role from the database on every request** (`services/sessionAccess.ts`). Demote or remove someone and their
  next call is refused, with no token to revoke.
* A co-host cannot remove the host or another co-host, cannot promote, cannot end the meeting, cannot change room settings.
* Roles reach every client in LiveKit participant metadata (`{"role":…}`, in the token and, on promotion, pushed with `updateParticipant`), so
  the UI shows controls immediately and hides them again the moment someone is demoted. The UI only decides what to *show*; the service
  decides what is *allowed*.

## Files changed (all uncommitted)

**`w/www`** (owns migrations): `database/migrations/2026_09_19_000001_add_egress_columns_to_members_communications_recordings.php` — guarded add
of `egress_id` (indexed) and `egress_location` to the recordings table. Applied to the lab database; `MigrationOwnershipTest` passes.

**`w/communications`**

| File | What |
|---|---|
| `src/services/sessionAccess.ts` (new) | Who is calling and with what authority; `authorize(caller, session, 'host'\|'moderator')` — the co-host security core |
| `src/services/recordingService.ts` (new) | Start/stop/list recordings, `cloud_recording` gate, Egress webhook handling |
| `src/services/meetingService.ts`, `roomService.ts` | `entitlementFor`, `listParticipants`, `muteAll`, `setRole`, `findPersonalRoom`; role metadata in tokens; co-host removal rules |
| `src/adapters/livekitGateway.ts` | Mute microphones, set participant metadata, Egress client (with a request timeout), egress webhook decoding, `isEgressUnavailable` |
| `src/http/app.ts` | New routes (README table); `Participant` credential |
| `src/adapters/mysqlRepositories.ts`, `ports.ts`, `domain/types.ts`, `config.ts`, `composition.ts`, `index.ts` | Recording rows with egress id; `RECORDING_FILE_TEMPLATE`, `RECORDING_START_TIMEOUT_SECONDS` |
| `tests/` | `hostControls.test.ts`, `hostControlsHttp.test.ts`, `livekitEgress.test.ts`, +5 MariaDB integration tests, fakes |
| `scripts/lab-web-join.ts`, `scripts/lib/pageRunner.ts` (new, shared), `scripts/media-probe/page-main.cjs`, `web-steps/cohost*.js` | `npm run e2e:web` scenario 5 (host controls, two real pages at once) |
| `scripts/lab-owner-path.ts`, `web-steps/owner*.js`, `package.json` | `npm run e2e:owner` — see below |
| `README.md` | Routes, co-host credential, recording config, both e2e checks, honest "not built" list |

**`w/members`**

| File | What |
|---|---|
| `resources/js/meetings/{meetings,meetingsScreen,memberToken}.ts`, `templates/views/meetings.html` | The Meetings screen and its wiring; the member's Checkpoint token read from browser storage and refreshed through the existing `sessionFetch.refreshAuthTokens` |
| `resources/js/conference/{hostApi,hostPanel,dom}.ts` | Typed client for the host routes (member or participant credential); the panel, banner, badge |
| `resources/js/views/conference.ts`, `conference/callController.ts`, `conference/types.ts` | Host mode (`host: { api, payload, joinCode, onDone }`), role from metadata, co-host appears/disappears live, removed/ended screens |
| `resources/js/views.ts`, `main.ts`, `utils/utils.ts`, `templates/dashboard.html`, `i18n/en.json` | `meetings` view registered lazily like every other dashboard view; menu entry; strings |
| `resources/js/templates/views/conference_call.html`, `styles/conference.scss` | Control bar, banner, panel (scoped under `.conf-page` because Tabler's CSS loads after ours) |
| `resources/views/welcome.blade.php`, `routes/web.php`, `config/services.php`, `.env`, `_env/vagrant/.env` | `<meta name="afs-communications-url">` on the dashboard page, from `COMMUNICATIONS_URL` |
| `resources/js/__tests__/conference/*.test.ts` | 104 unit tests across the conference and meetings code |

**Action needed before other environments work:** `COMMUNICATIONS_URL` is set only in `.env` and `_env/vagrant/.env`. `_env/local`, `_env/dev`
and `_env/prod` do not have it yet, so there the Meetings screen says "not configured" (it does not fail, and the guest page returns 503 as
before). I did not invent production URLs.

## How it was verified

| Check | Result |
|---|---|
| `w/communications` unit tests | **188 pass** (in-memory fakes; the real LiveKit SDK for tokens and webhooks) |
| `w/members` unit tests | 288 pass in the whole suite (after step 03 too); `npm run build` succeeds |
| MariaDB integration (real lab database) | pass, including the new recording columns and the "recording row per egress id" mapping |
| `npm run e2e:lab`, `e2e:media`, `e2e:turn` | pass (regression: real LiveKit, real media, real Coturn relay) |
| `npm run e2e:web` scenario 5 | **pass**, now including a real recording — see below |
| **`npm run e2e:record`** | **pass** — a real meeting recorded through Egress and played back; see "Recording (added later)" |
| `npm run e2e:owner -- --dry-run` | **pass** — see below |

Scenario 5 drives two real pages at once in Chromium against the deployed lab, playing a room owner who promotes a guest:

* Before promotion the guest sees no controls; after it, People appears and she is told. LiveKit carries her role in metadata.
* A second guest waits: the banner reads "1 waiting to join", the badge and tab title agree, **Review** opens the panel with the waiting room
  first, holding that guest by name. A co-host is **not** offered End meeting or promotion buttons.
* **Admit** lets the guest in (three tiles). **Mute everyone**: the co-host is told how many, keeps their own microphone, and **LiveKit
  really has the guest's microphone muted**; the guest's page says the host muted everyone.
* **Start recording** on a Professional plan: with Egress present it records for real (see "Recording (added later)"); on a lab with **no** Egress it
  said plainly that recording isn't available right now and left no row and no REC indicator behind (that mode is still in the check and is
  what the page does wherever Egress is absent).
* **Remove**: the database marks the guest removed, LiveKit no longer has them, and their page says "You were removed" with no way to rejoin.
  The co-host has no buttons against the host.
* **Demotion**: the controls vanish immediately, she is told, and she stays in the call.
* Also verified at the service level: a co-host token is refused for host-only actions; a token for a removed participant is refused;
  a Basic-plan host gets `entitlement_denied` with `recording_not_in_plan` and no recording row.

Screenshots of these pages found two visual bugs the DOM checks could not (Tabler CSS overriding the notice's positioning, and the panel
covering the video grid); both are fixed.

### The owner's path: `npm run e2e:owner`

The owner path goes through the members sign-in, and I may not enter your password. So the check is built to be run by you:

```powershell
$env:NODE_EXTRA_CA_CERTS = 'C:\projects\vagrant\certs\mkcert-rootCA.pem'
$env:E2E_MEMBER_USER = 'james2'
$env:E2E_MEMBER_PASSWORD = Read-Host 'Member password'
npm run e2e:owner
Remove-Item Env:E2E_MEMBER_PASSWORD
```

(from `w/communications`; the password is never written anywhere and is typed only into the real sign-in page; accounts with two-factor cannot
be used). It signs in through the auth app the way a person does, opens Meetings, turns the waiting room on if it was off, Starts the meeting,
checks the dashboard is gone, lets a second real page join as "Gwen" and wait, admits her from the banner/queue, mutes everyone, tries
recording, ends the meeting with the two-click confirmation, returns to the dashboard, puts the waiting-room switch back, and checks the
database and LiveKit agree it is over. If the member's plan has no meetings it stops and says which state the screen showed.

**Without credentials** it has a `--dry-run` (`npm run e2e:owner -- --dry-run`) that types nothing and passes today: the dashboard page carries
the service URL; the service answers and refuses a signed-out caller with 401; the built dashboard has the Meetings entry; opening the
dashboard signed out lands on the auth sign-in page with the way back to members; that page has the fields and buttons the run drives.
The rest of the file's page steps are syntax-checked, and every host control it uses was already exercised in scenario 5, but **the owner's
own path has never been run end to end**.

## What is NOT verified

1. **The owner's path through the real dashboard** — needs your run of `npm run e2e:owner` (above). What is unproven is the wiring only the
   owner uses: Meetings → Start → dashboard replaced → End → back. The screen and host panel are unit-tested and the host controls are
   proven with a promoted co-host, but the two have not been run together as the owner. If that run fails, send me its output.
2. **Recording, still not done:** letting a member **get** a finished recording (download or playback), production storage and retention, the
   production Egress deploy files, and telling the host when a recording failed (a Stop pressed within a few seconds of Start aborts the job, and
   the screen still says "the file will be ready shortly"). All are in `plans/TODOS.md`. Capture itself is verified (below).
3. **Other browsers.** Chromium/Electron only. Safari and Firefox are unchecked (also open from `01`).

## Recording (added later): Egress in the lab, verified end to end

The lab now runs **LiveKit Egress**, so "Start recording" produces a file. What was done:

* `provision/deploy-egress.sh` (new, on-demand: `vagrant provision web --provision-with egress`): moves Docker's **and containerd's** storage onto the
  10 GB data disk (`/mnt/node1`), runs its **own Redis** on `127.0.0.1:6380` (the apps' Redis on 6379 is untouched), makes LiveKit use that Redis, and runs Egress
  (`livekit/egress:v1.14.1`, host networking, `--cap-add SYS_ADMIN`, its config readable only by its own user, uid 1001) writing to `/mnt/node1/afs-egress/recordings`.
  `provision/deploy-livekit.sh` adds the `redis:` block when that Redis exists. The exact steps and warnings are in `AFS-DEPLOYMENT-VAGRANT.md`, "Recording".
* The Vagrantfile takes `AFS_WEB_MEMORY_MB` (default unchanged, 2048). **`web` now has 4096 MB and `web2` is halted**, because the 8 GB host cannot run both; Coturn s2 is
  therefore down until `web2` is brought back (steps in the guide).
* The service: new `RECORDING_QUALITY` (`540p15` | `720p30` default | `1080p30`) so a small server can finish a recording (720p30 froze while finishing on the 2-CPU lab VM);
  `RECORDING_FILE_TEMPLATE=/out/recordings/{room_name}-{time}.mp4` and `RECORDING_START_TIMEOUT_SECONDS=30` in the lab's env files. 3 new unit tests.
* `npm run e2e:record` (new) records a real meeting and checks: the row is `recording` with an Egress job id; **LiveKit says the room is being recorded**; after stop the
  **deployed service receives Egress's webhook and moves the row to `ready`** with a size and duration; the MP4 exists on `web` with a valid `ftyp` header; and it **plays in a real
  browser at 1280×720 with a picture that changes over time** (a frame was reviewed: the fake camera's pattern and its running clock).
* `npm run e2e:web` scenario 5 now records for real: the co-host's Start shows "Recording started", the **REC indicator shows for the co-host and for the other guest**
  (consent), Stop clears it, and the row reaches `ready` with a size.

Things found on the way, worth knowing: Egress logs "not enough cpu for some egress types" on a 2-CPU VM (its default estimate for a room recording is 4 CPUs); the lab lowers
the estimate for one recording at a time. A Stop pressed before Egress has really started aborts the job (`Start signal not received`). **My first attempt filled the Windows host's
`C:` drive** (the VM's data disk grows on the host as Docker writes; I did not check the host's free space first) and VirtualBox paused the `web` VM; you freed space and it resumed
with nothing lost. The guide now says to check for 10 GB free before starting.

## Pre-existing problems noticed (not caused or fixed here)

* `w/members/tsconfig.json` has `ignoreDeprecations: "6.0"`, invalid for the installed TypeScript 5.9.3, and about 53 unrelated type errors
  exist in other files; the new code is type-clean and the build (Vite) does not depend on this.
* `npm audit` reports 41 vulnerabilities in `w/members` dependencies.
* `w/members` `GET /login` returns 400 ("Login redirect not configured") for lab hosts; sign-in works because the dashboard redirects to the
  auth app instead. Only production hosts are in `config/host_redirects.php`.
* `w/auth` hardcoded-key issue (a task for a separate session was started earlier).

## Next

`03-advanced-features`: done, see [`03-advanced-features.complete.md`](03-advanced-features.complete.md) (screen share, chat, raised hands and
reactions; whiteboard, captions and embeddable pages are not built and need decisions from you).

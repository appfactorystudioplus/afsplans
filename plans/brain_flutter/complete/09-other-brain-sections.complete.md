# 09 — The rest of the Brain admin — Completion

**Verified:** 2026-10-05 — `tool/gates.sh` fully green (G1–G9, `flutter analyze` clean, `flutter test` **615 passed**, of which 76 are new for this file: 22 Checkpoint errors, 43 afs-connect, 8 shell, 3 add-member).
**Server:** three small `w/www` changes (a capability, an off-plan fix, a new PHPUnit file). `php -l` is clean on every file touched; **the PHPUnit files have not been run** (no reachable MySQL on the authoring PC).
**Not run:** none of these screens has been exercised on the phone, and the afs-connect screens have never talked to a live coordinator.

**Status:** **Complete in code (P7); not verified on a device or against a live server. P9 (Apps control plane) deliberately not built** — §9.5 says "only if wanted after P7", and nothing in the request asks for it. §9.3, §9.4 and §9.6 are, as the plan says, not in the app and not reachable from it.

## What was built

| § | Item | State | Evidence |
|---|------|-------|----------|
| 9.2 | **Checkpoint errors** list | ✅ | `features/checkpoint/`. Opening the screen reloads; unresolved rows first, a "Show resolved" chip for the rest; each row shows who, when, the code and the message; pull-to-refresh; loading / empty / error with Retry; a failed refresh keeps the list and says so |
| 9.2 | **Resolve** | ✅ | A sheet with the full record (copyable email, username, code), then a confirmation that says **it only marks the row — it does not create the member and does not fix Checkpoint**. One request, `resolved_by` = `afsbrain:<device label>` so the record says where it came from; the list is re-read and the row leaves the unresolved list. A lost answer is settled by re-reading, never by sending again |
| 9.2 | **Count on More** | ✅ | The shell loads the list at start (only when the server offers it) and the More tab and its row show the unresolved count |
| 6.4 / 9.2 | **Cross-link from Add Member** | ✅ | A Checkpoint failure (502) on create shows "View Checkpoint errors" under the message when the server has the list; absent otherwise and for any other failure |
| 9.2 | **afs-connect — per member** | ✅ | `features/afsconnect/`: a card on the member's **Details tab, below the plan** (composed by the shell; the members feature does not import it). Entitlement shown in words (Global / Plan / Member / **Effective**), the member switch, every source as a switch grouped Apps / Websites, a "Not in this member's plan" note per source, excluded website domains (one per line). Save is off until something differs from what the server holds; the **whole source map** is sent so a save cannot drop one; turning it off records `afsbrain` as the reason |
| 9.2 | **afs-connect — global flag** | ✅ | More → afs-connect: the estate-wide switch with its description, a confirmation that says it is estate-wide, and on **production a typed confirmation** (`afs-connect`) — 10 §10.2 lists it |
| 9.2 | **Honest failure modes** | ✅ | A coordinator that cannot be reached (the Brain answers 502) is "afs-connect unavailable — the coordinator could not be reached." with the server's own message (the TLS hint included) and Retry, on both screens; `upgrade_required` on save reads "This member's plan does not include afs-connect." and is not a server fault; a save whose follow-up re-read fails stays a successful save |
| 9.7 | **The More tab** | ✅ | Appears only when the server offers Checkpoint errors or afs-connect, and lists exactly the rows it offers; a section whose capability is false is absent, not disabled (BF10). With neither, there is no destination and nothing is requested |
| BF13 | The signed-in member | ✅ | Their afs-connect card is view only (no switches, no Save) |

## Server changes made for this file (all in `w/www`; see `00_extras.md` E2 and E3)

- **E2 — the www afs-connect proxy dropped six of the coordinator's eighteen sources.** `MembershipController::updateAfsConnect` validated twelve source keys by hand and forwarded only validated keys, so `www_money`, `market_orders`, `joystick_engine` and `external_sdk` never reached the coordinator from the Brain admin; the browser's Joystick Engine toggle saved nothing and reported success. The twelve rules are now `'sources.*' => 'boolean'`; the coordinator still validates its own keys. **Until this is deployed, those four toggles in the app will look saved and not be.**
- **E3 — `StaffCapabilities`** now reports `checkpoint_errors`, and `afs_connect` needs the global-flag route too. `BrainStaffWhoamiTest` asserts the key.
- **`tests/Feature/Brain/BrainStaffCheckpointAndAfsConnectTest.php`** (7): the list needs a token; it returns every record newest first with its `resolved` flag; resolve records `resolved_by`; an unknown record is 404; all eighteen sources reach the faked coordinator; a non-boolean source is 422 before the coordinator is called; a coordinator refusal returns `upgrade_required`.

## Divergences

- **"Twelve booleans" is wrong, and the app does not carry a list.** The coordinator builds `sources` from its registry (eighteen today). The app renders whatever the server returns and looks up a display name for the ones it knows (falling back to the key made readable), so a new source appears without an app release. Grouping is by the `www_` prefix, because the response carries no group.
- **The Checkpoint errors route returns resolved records too**; the plan says "unresolved failures". The app filters on `resolved` and shows the rest behind a chip.
- **The More tab holds two sections, not four.** §9.7 lists "Checkpoint errors, afs-connect, Settings, About"; §10.3 makes **Settings its own destination** (with the version in it). The navigation map in file 10 is followed: Settings and About are in Settings.
- **`checkpoint_errors` is a new capability**, not in 04's list; there was no other way for `whoami` to say the routes exist.
- **The per-member card is view only for the signed-in member.** The server does not forbid editing it; the app holds itself to the same rule as everywhere else (BF13).
- **The global switch's typed confirmation is the word `afs-connect`**, since there is no member name or amount to type.
- **These routes sit behind `ApiAuthMiddleware`, not `portal.level99`** (the plan says so in §9.2). Any logged-in portal user can reach them, not only the Brain member. That is the existing gate and was not changed here.

## Not verified — what is left for you

1. **Deploy `w/www`** (E2 and E3 are in it), run the three PHPUnit files for this and the earlier files, and open **More** on the phone: Checkpoint errors should list whatever the lab has (probably nothing, so "No unresolved Checkpoint errors."). To see a row, make an Add Member attempt while Checkpoint is down, or ask for a seeded record.
2. **afs-connect needs the lab's coordinator.** Open a member's Details tab: if the coordinator is up the card shows Global / Plan / Member / Effective; if it is down it must say "unavailable" with a Retry and no error loop. Flip one source and save on a **lab member**, then reload and confirm it held (and, after E2 is deployed, that `www_money` or `joystick_engine` holds too).
3. The **global switch is estate-wide** even on the lab; try it only when that is acceptable.
4. TalkBack labels on the new screens were not reviewed.

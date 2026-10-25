# 10 — UI and navigation — Completion

**Verified:** 2026-10-05 — `tool/gates.sh` fully green (G1–G9, `flutter analyze` clean, `flutter test` **807 passed**, of which 192 are new for this file: 50 accessibility, 41 navigation, 56 contrast, 21 app lock, 11 semantics and haptics, 13 across the app root, Settings and the failure screens).
**Also built:** one debug APK (`flutter build apk --debug`) to confirm the new `MainActivity.kt` compiles. It was **not installed or run** on the phone.
**No server change** for this file.
**Not run:** none of the new behaviour has been exercised on the phone; the app lock has never been shown a real system credential prompt.

**Status:** **Complete in code; not verified on a device.** Every acceptance line in §10.9 is covered by a test; the on-device checks are listed at the bottom. The app icon (§10.8) belongs to file 11 and is not here.

## What was built

| § | Item | State | Evidence |
|---|------|-------|----------|
| 10.2 | **Environment badge** | ✅ | The strip (label and host) was already there. New: its text colour is chosen by contrast (**black on the development amber**, white on red and blue — white on amber was 2.4:1), and **every app bar carries a light tint of the environment's colour**, so a page says where it is acting even with the strip out of mind. Production is red, development amber, labs blue, from `IS_PRODUCTION` and the label, never from the URL |
| 10.2 | **Typed confirmation on production** | ✅ | Cancel subscription (username) and refund (amount) were done in 07; the global afs-connect flag (`afs-connect`) in 09; the interim Remove in 06. **New: removing an override types the member's username.** Test: on production the Remove button stays disabled until the right text is typed, and on the lab no typing is asked |
| 10.3 | **Navigation map** | ✅ | The bar is now **Members · Billing · More · Settings**, each only when the server's capabilities say so. More lists exactly Checkpoint errors and afs-connect (Apps is not built) and is absent when neither is offered; a server that reports nothing leaves Settings alone with no bar and no crash. Detail pages push over the bar and the back gesture returns |
| 10.3 | **State across a restart** | ◐ | The selected destination survives the system killing the app (`RestorationMixin`, `restorationScopeId`), tested with `restartAndRestore`. See divergences for what does not |
| 10.4 | **Splash** | ✅ | `ConnectingPage`: the mark in the environment's colour, "AFS Brain", **"Connecting to LAB2…"** (or PRODUCTION), the strip, a spinner |
| 10.4 | **Failure screens** | ✅ | Wording now follows the plan: unreachable names the host and, on the lab, says to join the home Wi-Fi and check the VM; a certificate failure on the lab says to install `mkcert-rootCA.crt`. Production versions of both do not mention the lab. Only the five token failures show the mint command; none has a text field |
| 10.4 | **Every screen reachable** | ✅ | A shared tour (`test/support/screen_tour.dart`) reaches **25 screens** from the navigation map — the list, the five member tabs, Billing, the ledger, More, Checkpoint errors, afs-connect, Settings, Add member, Edit profile, the plan, status and remove sheets, the cancel and change-plan sheets, the charge and refund sheets, the add-override and adjust sheets and a Checkpoint error — and asserts each was *actually reached* by a marker, not just tapped at |
| 10.9 | **Nothing 09 excludes is reachable** | ✅ | A test reads every file in `lib/` and fails if one names a DNS, HTTP-server, storage-fleet, Apps or tenant route |
| 10.6 | **App lock** | ✅ | `APP_LOCK=true`: locked from the first frame, asks at launch, locks again after **5 minutes in the background**, one locked screen with one Retry button and no text field; the content stays alive underneath (unlocking returns to where the owner was) but is neither painted nor reachable by a screen reader; a phone with no secure lock screen skips the lock and **Settings says "App lock unavailable — no device lock set"**; off in lab builds. Unlocking is the phone's own PIN, pattern, password or biometric; nothing is stored and the device token is untouched. **Any error fails closed** (stays locked), including a missing channel |
| 10.7 | Loading, refresh | ✅ | Already in place and re-checked: the cached first page with "Cached · age", skeletons, pull-to-refresh on every list and detail, no polling, no offline writes |
| 10.8 | **Accessibility** | ✅ | **Tap targets** (48 dp), **labelled tap targets** and **WCAG text contrast** are checked by Flutter's own guidelines on all 25 screens; **text at 200%** is checked on all 25 for overflow. **Screen-reader sentences:** a member row ("james1, …, plan Pro, account status Active, created …", as a button), chips ("Account status: Active", "Plan: Pro"), a usage bar ("Websites / Domains: 3 of 3 used, 100 percent, over limit, plan: Pro (3)", with override and reset when there are any), billing counts ("3 current"), the environment strip. Money values carry their currency ("15.00 USD"). Status is never colour alone (icon and word on every pill). Copy confirmations are snackbars |
| 10.8 | **Haptic tick** | ✅ | `selectionClick` once a destructive action has *completed* — cancel subscription, refund, remove override, mark for deletion or suspend, the global afs-connect flag. Not on asking, and not on a refusal (tests) |

## What the checks found and fixed

These came out of running the guidelines, not from review, and they are the reason those checks exist:

- **Two overflows at 200% text:** the Add override sheet's "Expires" row and the Adjust sheet's dropdowns. Both fixed (a `Wrap`; `isExpanded`).
- **Status colours were too light.** Pills and short status text are 12 sp and need 4.5:1; the stock `shade700` greens, oranges and greys did not reach it on the tinted surfaces sheets use (3.73:1 on the charge sheet), and the usage tile's own screen-reader label had been hiding its text from the contrast check. All ~40 uses now come from one palette (`core/widgets/tone.dart`, five tones, a light and a dark value each), and a unit test checks every tone against five real theme surfaces in both themes.
- **The environment strip's white text on amber** was 2.4:1 (above).
- **Semantics were merging into their parents**, so a screen reader would have read one long label instead of separate items. The chips, billing counts and the strip are now standalone nodes.
- **An app-lock bug, found by its own test:** Flutter sends several "left the app" events (and `hidden` again on the way back); recording the time on each one would have reset the five-minute clock to the moment of return, so the lock would never have fired. It now records only the first.

## Divergences

- **The app lock uses a small Android platform channel (`KeyguardManager`'s device-credential screen), not the `local_auth` package.** The behaviour is what §10.6 describes (the phone's own credential, nothing stored, skipped with no lock screen), and it needs no extra dependency and no `USE_BIOMETRIC` permission. The standing rule against adding packages to test code is why; `local_auth` can replace the channel later without touching the lock logic (`DeviceAuthenticator` is the seam).
- **Process-death restoration is the destination only.** §10.3 says tab, scroll and search text survive "rotation and process death within a session". Rotation is covered: the manifest handles configuration changes without recreating the activity, and the state lives in cubits above the screens. After the system **kills** the app, the person lands on the destination they were on, not inside a member: a pushed member page is bound to a cubit and is not rebuilt, and a restored list reloads from its cache. Restoring those would mean restorable routes for every pushed page.
- **The typed word for the global afs-connect flag is `afs-connect`** and for removing an override is the member's username (10 §10.2 gives no text for either).
- **"Mark for deletion, soft/hard delete"** in the §10.2 list: mark for deletion has its typed confirmation (the interim Remove); soft and hard delete are not built (they need the `account_close` plan).
- **The haptic is `selectionClick`** ("a tick"), not a heavier impact.
- **§10.1's "primary actions in the lower half"** is how the sheets and the bar are laid out; there is no test for it.

## Not verified — what is left for you (10 §10.9)

1. **Install and look.** The splash, the tinted app bars, the dark amber strip text, the More tab and its badge, and the five-tab member detail. In particular the larger text on the real phone (set the system font size to maximum).
2. **The app lock needs a build with `APP_LOCK=true`** (the lab env has it off): launch, see the locked screen, Retry, and the phone's own prompt; background it for five minutes and return; try on a phone with no screen lock to see the Settings line. The Kotlin compiled; it has never run.
3. **Switch to the production env and rebuild** (by hand, per the standing rule): the strip and app bars should be red and the typed confirmations should appear. Do not act on real members to check them.
4. **TalkBack:** turn it on and read a member row, a usage bar, the billing counts and a charge. The sentences are tested; how they sound is not.

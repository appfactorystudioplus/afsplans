# 17 — Linode account management — Completion

**Verified:** 2026-10-06, on the authoring PC (Windows). **Nothing was sent to the real `api.linode.com`, nothing has run on a phone, and the new Brain PHPUnit test was written but not run.** Every Linode call in every test goes to a fake.

| Check | Result |
|---|---|
| App: `flutter analyze` (lib and test) | clean |
| App: the whole suite | **1749 passed** (one full run after the work; includes the new Linode tests and 14 new screen-tour checks: 48 dp tap targets, labels, contrast, text at 200%) |
| Brain: `php -l` on the audit controller and its test | clean |
| Brain: `BrainStaffMonitorAuditTest` (one new test for `linode.*`) | **written, not run** (same reason as plan 16: `phpunit.xml` points at the lab database) |

## What was built (`afsbrain_flutter/lib/features/linode/`)

| Phase | What |
|---|---|
| **P25** | `data/linode_api.dart` (the one client), `linode_models.dart`, `linode_repository.dart`, `linode_links.dart` (match a Linode to a Brain server row by `json.linode.id`, else by `public_ipv4`; fill the id), `cubit/linode_cubits.dart`, list, detail and usage charts (`ui/`). Gated by `LINODE_IN_USE=true` and a token: otherwise no client exists |
| **P26** | `data/linode_actions.dart` (every change as data), `linode_switches.dart` (LD8), `linode_follow.dart` (LD6: 3 s for a minute, then 10 s, to 10 minutes), `cubit/linode_action_cubit.dart` (the flow), `ui/linode_action_page.dart` (form, review, typed confirm, progress, end). Boot, shut down, reboot, create (price shown first), delete (label typed) |
| **P27** | The same catalog: resize, rebuild, rescue, clone, migrate, upgrade, reset root password, rename and tags; backups (enable, cancel, snapshot, restore); disks (add, resize, clone, delete); firewalls (create, replace rules, enable and disable, delete, rule history); volumes (create, attach, detach, grow, delete); IP reverse DNS; SSH keys (add, remove). `ui/linode_area.dart` builds each screen's buttons; `ui/linode_resource_page.dart` is the list screen |
| **P28** | Error wording (`linodeError`: insufficient grants, limit reached, out of stock, payment needed, busy), rate-limit behaviour, Settings rows, tour entries, this document |

Server (Brain): `StaffMonitorAuditController::LINODE_ACTIONS` (33 names) accepted by BS27, and one test. Wiring: `config.dart`, `main.dart`, `app.dart`, `home_shell.dart`, `infra_hub_page.dart`, `settings_page.dart`.

## The rules, and how each is tested

- **Read first (LD3).** Reads use only GET. The only way to change anything is `LinodeApi.send`, called only from `LinodeRepository.perform`, called only by the flow cubit; a test reads the source and fails if another file calls `perform` or `send`.
- **Two-step (LD4).** Every change has a review naming the target, the exact effect and any cost. A dangerous one (delete, rebuild, resize, restore, delete disk, delete volume, delete firewall, cancel backups, reset root password) also needs the target's label typed; a near miss does nothing.
- **Audit first (LD7).** The intent is recorded on the Brain, then the call is sent, then the result is attached. If the record fails nothing is sent (tested). Params never hold a password or an SSH key (tested).
- **Switches (LD8).** "Allow Linode changes" and "Allow destructive Linode actions", off by default, independent; a destructive action needs both; they are read again at confirm, so one turned off during the review stops it (tested). Buttons are disabled with the reason as their tooltip.
- **One send.** A change is never retried: a 429 or a connection error on a change is reported, and a request that got no answer ends as "outcome unknown", not sent again (tested).
- **Follow (LD6).** The poll schedule, done by event, failed by event, an old event ignored, a deleted Linode as the end, a status-only fallback when events cannot be read, and the 10-minute "still in progress" are all tested with a fake clock.
- **Token.** Read only in `main.dart`; no other file mentions it (tested). Never in an error message (tested).

## How the open questions were settled

- **OQ-L1 (token in `.env`):** as answered. The token is packaged in the APK built with it.
- **OQ-L2 (full access):** the scopes and the full-access warning show on the Linode front page and in Settings.
- **OQ-L4 (create):** built in P26 behind the switch, with monthly and hourly price (and the backup add-on) on the review.
- **OQ-L5 (DNS):** out of scope, as answered.

## Where the build differs from the plan

- **A server change was needed** (the audit route refused `linode.*`); see above. A test compares the app's list with the PHP list.
- **Changes need the Brain's record**, so the whole area is read-only when `BRAIN_MONITOR_CONTROL` is off or the account has no `monitor_control` capability. There is no second switch for Linode.
- **Charts are hand-drawn** (`CustomPaint`), not fl_chart: no package was added.
- **Firewall rules are edited as JSON** in a text box, validated, with the rule counts before and after, not rule by rule. History is a read-only list of versions.
- **Images and StackScripts are lists only.** Nothing in the app edits them (they are what a Linode is made from).
- **The Link (fills `json.linode.id`) is the one write that goes to the Brain, not Linode,** and is not in the audit.
- **Volume prices** on the review use $0.10 per GB, a figure written in the app, not read from Linode; the review says "about".
- **Disk clone** has no choice of where the copy goes: Linode puts it on the same Linode.

## Not verified, and nothing here should be called working until it is

1. **Any call to the real Linode API.** Three details are taken from the published spec and not tried: the `X-Filter` for events (`entity.id`, `entity.type`, `+order`), the shapes of `/stats`, `/transfer` and `/regions/availability`, and the exact bodies of `rescue`, `restore`, `rebuild` and `volumes`. A first live run should be read-only (list, detail, usage), then one safe change on a throwaway Linode.
2. **The event follower against real events.** It decides "finished" from events newer than the one seen before the change, plus the status. Linode's real event sequence for reboot, resize or migrate may differ; the worst case intended is "still in progress after 10 minutes".
3. **The Brain's audit PHPUnit test**, and `BRAIN_MONITOR_CONTROL` being turned on anywhere.
4. **Everything on a phone**, including the production app-lock re-ask before a dangerous action.
5. **Billing figures.** The review shows Linode's listed prices; the app does not check them against an invoice.

## Left behind

Nothing at Linode or on any server. Temporary test output is in the PC's temp folder.

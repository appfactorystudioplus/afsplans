# Server monitoring: what it is and how to use it

Plain-language summary of plans 14–17 and what was built on 2026-10-06 (plans 14, 15 and 16). The detail and the test results are in
[`complete/14-server-monitoring-foundation.complete.md`](complete/14-server-monitoring-foundation.complete.md).

## What you get

| Where | What you see |
|---|---|
| **Infra → HTTP servers** (and DNS servers) | A red/green light on every row: a dot **and** a word (Up, Down, Checking…). A server with the program shows its version, uptime and load; a red one says why and whether its web port still answers. Filter by Up/Down. All red means "check this phone's network". |
| **Infra hub** | A small "N up, N down" under the HTTP servers row. |
| **A server → Monitor icon** | Its own view: **Overview** (CPU, RAM, network in/out, load, disks, uptime, health with reasons), **Services** (what should run, what's down), **Checks** (run checks on the server, plus "what can this server reach"), **Program**. |
| **Program settings** | Install, upgrade or remove the program on that server; see which phones it lets in; revoke one. |
| **HTTP servers list → cloud icon** | Install or upgrade on several servers in one go. |
| **Settings** | This phone's monitor-key fingerprint (the key itself is never shown). |

## How it works, in four lines

1. A small PHP program (`w/www/core/scripts/monitor/afs-monitor.php`) runs on each server and answers on port **62443**.
2. It only answers **your phone**: every request is signed with a key that never leaves the phone and is different for each server. Anything else gets **no reply at all**.
3. The phone talks to the server **directly**. Nothing is sent to the Brain, and the Brain never logs in anywhere.
4. Opening a server's view opens a session; leaving it (back, another server, 30 s in the background, app lock) closes it. The program does nothing between visits.

## Installing

- **One server:** Infra → HTTP servers → the server → Monitor icon → Program settings → *Check this server* → *Install…* → confirm. It logs in over SSH with the login in the server's record (`json.users[0]`), writes the program, and saves the certificate fingerprint into the record (you see the change first).
- **Many servers:** HTTP servers list → cloud icon → tick → *Do N servers…*. One at a time; a server that can't be done is skipped with the reason; *Stop after this one* is there. Upgrades work the same way.
- **First time on a server** you're asked to trust its SSH host key.
- After install the server's light uses the program. SSH is used **only** on these two screens.

## Safety, briefly

- Per-server keys: a stolen server key opens that server only.
- Pinned certificate: a different certificate is blocked, no override.
- Five bad requests from one address lock it out for 15 minutes. A plain port check is not a bad request.
- The program runs as root but is boxed in (10% CPU, 64 MB, read-only filesystem except its own folder and `/var/log`), runs commands without a shell, and never connects anywhere the phone names: reach checks are picked **by name** from a list kept on the server.
- A second phone is not added from the app yet (see below).

## Not built yet

- Adding a **second phone** from the app. Today: install from that phone, or run `install.sh authorize` on the server once.
- Fleet-wide health limits (per-server limits in `json.monitor.thresholds` work).
- A program for **DNS servers** (their lights are a plain port check).
- PM2 listing (a PM2 process can be restarted by name, but it is not listed). An editor for the reach-check list. The Linode plan (17).

## Live view and "what is using it" (plan 15, built, not yet run on a server)

Both are opened from a server's view: the chart icon (or **Live view** on Overview) and the leaderboard icon (or **What is using it**).

- **Live:** network in and out, CPU per core, RAM, load and connections, once a second, with a five-minute chart, peak, a since-you-opened total, pause, bytes/bits, notes about anything that has lasted, and a "Right now: what is using it" card (the five busiest by CPU, the five largest by memory, the five busiest by network).
- **What is using it:** CPU, Memory, Network and Disk I/O, ranked by **process, service, tenant or user**, each row with its share of the server, the word that says how it was measured, and an arrow for its trend; "Now" or "This session" (the average since the screen opened); a **What's going on** card that says in words what the numbers say and shows the figures behind each sentence; tap a process for its own page (full command line, user, service, listening ports, memory as PSS and RSS), a service to open it in Services, a tenant to open it in the Brain.
- **How honest it is:** a service's CPU is measured (it counts processes that already exited); a process's CPU is sampled (a short-lived one is missed); memory is PSS for the 25 largest and RSS otherwise; network per process, service or address is sampled from socket counters and under-counts many short connections. A **shared PHP pool** is said to be shared and is never split by tenant. **Per-site web traffic is not collected** on a server until you choose to turn it on (it needs the web server's own log); the screens say so.
- **Cost:** the program samples only while a screen is asking, inside the server's session, and stops 10 seconds after you leave; nothing is stored on the phone or sent to the Brain.
- **It needs program 1.2.0.** The app carries it: upgrade each server from its Program screen (or the cloud icon on the HTTP servers list). An older program says so on the screen.

## Control (plan 16, built, never run on a real server)

Control is behind **a Brain switch that is now ON by default** (`BRAIN_MONITOR_CONTROL`; set it to `false` to switch control off everywhere: without it the app cannot record an action, so it sends none) and, for stopping, signalling and rebooting, **both** Settings → "Allow dangerous server actions" on the phone **and** `allow_dangerous` on the server (`afs-monitor.php --allow-dangerous on`, run by someone logged in there). The server must run program **1.3.0** and give the phone the `control` scope.

- **Where:** a service's sheet on **Services** (Logs, Reload, Restart, Start, Stop), **Overview → Control** (Logs, Test nginx, Cancel a pending reboot, Reboot, **Recent actions**), **Checks** (send this server's reach checks), **What is using it → a process** (Stop this process), **Network** (Turn on per-site traffic).
- **One flow for every action:** the server is asked what it **would** do and shows what is true now (state, connections that would drop, the config test); you tick each warning, give a reason, and on production (and for anything dangerous, and for the Brain's own host) type a phrase; the **Brain records it first** (if it cannot, nothing is sent); the server runs it on its own (it keeps going if the phone disconnects) and checks afterwards that it really worked (a restarted service must still be up ten seconds later); you see before and after, the output and the verification, and a failure shows the service's log without asking.
- **Refused for good:** a configuration that fails its own test cannot be reloaded; `sshd`, the monitor program, `dbus`, `chronyd`, `systemd-*`, pid 1, kernel threads and the program itself can never be stopped or signalled; a pid that was reused since you looked is not signalled. Those have no confirm button at all.
- **A lost answer is never settled by sending again:** the phone reads the server (or **Recent actions**) to find out. One action at a time per server; at most 5 changes in 10 minutes.
- **Per-site traffic** needs `enable_site_log`: it writes one fixed nginx file, tests nginx and reloads it (and puts everything back if the test fails). Until you do that the Network screen says "not collected".

## Where things are

| | |
|---|---|
| Program, installer, protocol, tests | `w/www/core/scripts/monitor/` (`PROTOCOL.md`, `install.sh`, `tests/`) |
| Audit of actions (BS27) | `StaffMonitorAuditController`, migration `2026_10_07_000001_add_result_to_brain_infra_audit`, `BRAIN_MONITOR_CONTROL` |
| Server rules for `json.monitor` | `w/www/app/Services/Brain/Infra/InfraMonitorRules.php` (+ overview fields `monitor_agent`, `monitor_probes`) |
| App | `afsbrain_flutter/lib/features/monitor/` (+ `assets/monitor/`, copies of the program; `tool/sync-monitor.sh` refreshes them, gate G10 checks them) |
| The record keys | `json.monitor.agent {port, tls_sha256, version}`, `probes {ports}`, `services`, `thresholds`, `targets`, `ssh {port}` |

## Tests

PHP: `php core/scripts/monitor/tests/run-tests.php`, `run-collector-tests.php`, `run-attribution-tests.php` and `run-control-tests.php` (130, 168, 94 and 126 checks), and the PHPUnit `InfraMonitorRulesTest` (and `BrainStaffMonitorAuditTest`, written, not yet run).
App: `flutter test` (all passing, including the phone-to-PHP tests over real TLS on loopback).
The program's readers were also run read-only on `lab2-web` (nothing written there).

## Linode (plan 17, not built)

Manage the Linode account from the app: list, power on/off/reboot, create, delete, then resize, rebuild, backups, firewalls, volumes. Read-only first, two-step confirms, two safety switches. Decisions so far: token stays in `.env` (so it's inside the APK; the token has full access, so a limited one with an expiry is advised), you're the account owner, create is in the first release, Linode DNS is out of scope.

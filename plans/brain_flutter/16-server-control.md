# 16 — Controlling the HTTP servers

## Completion document

**Built 2026-10-06 (P23 and P24, BS27), tested against fakes on the authoring PC; no action has been sent to a real server, the Brain's audit test was not run, and nothing has run on a phone. See [the completion document](complete/16-server-control.complete.md), which also records where the build differs from this plan and how OQ-M15 to OQ-M19 were settled.**

> **Revised 2026-10-06 with [14](14-server-monitoring-foundation.md) and [15](15-server-live-and-attribution.md):** actions are carried out by **the small program on the server**, asked by the phone with a signed request in the **`control` scope** inside the server's session. The Brain never runs them and never logs in. **Decided by you:** **reboot exists**; **there are no alerts and no push notifications.**

---

[14](14-server-monitoring-foundation.md) lets you *see* a server; [15](15-server-live-and-attribution.md) shows *what is using it*. This file is the third request: **control** it: restart a service that has failed, reload nginx, stop the process eating the CPU, reboot.

Control is the dangerous half: one mistaken tap can take every website on a server offline. So this file is mostly about **what the program will refuse, what is checked first, and what the screen shows before anything happens**. It reuses the spirit of [13](13-brain-infrastructure.md) §13.3: a preview of the effect, acknowledged warnings, a reason, a typed confirmation on production, an audit row, and "an answer that was lost is settled by looking, never by sending again".

**The program is the only thing that runs a command on a server, and only from a fixed catalog.** The phone asks; the program checks again and does it. The Brain's only part is a **small audit record** of each action (§16.5): the one thing in files 14 to 16 sent to it.

Decisions continue **MD#** and questions **OQ-M#** from 14 and 15. Phases **P23, P24**; server work **BS27**.

## 16.1 What control means here

| Class | Meaning | Examples | Gate |
|-------|---------|----------|------|
| **A — read** | changes nothing on the server | run the checks now; refresh the service list; read the last lines of a log; `nginx -t`; show what a restart *would* affect; run the soft checks | audited; no typed confirmation |
| **B — routine change** | brief, recoverable disruption | reload or restart or start a unit; restart or start a PM2 app; turn the per-site nginx log on or off; change the soft-check list; cancel a pending reboot; and, over SSH, install or upgrade the program or authorize a device (14 §14.5) | preview; acknowledged warnings; reason; **typed confirmation on production** |
| **C — availability or irreversibility** | can take sites down or cannot be undone | stop a unit; stop a PM2 app; send a signal to a process; **reboot the server**; remove the program | everything in B, **plus** the typed phrase **even on the lab**, **plus** both dangerous gates of MD20 |

**Not here, and not to be added without a new decision:** running an arbitrary command; installing or updating packages (`dnf`); editing nginx, PHP, MariaDB or BIND configuration by hand from the monitor (a config change is a different, reviewed operation, and BIND zone data is already 13's P13); firewall changes beyond opening the program's own port at install; deleting files; killing by name (`pkill`); kernel knobs (`drop_caches`, `swapoff`). A "run command" box is the most requested feature of every server-control screen and the most dangerous; if it is ever wanted it is its own plan.

**Moving websites off a struggling server is not an action here.** **Retire**/**edit** the server and **transfer** a tenant are in [13](13-brain-infrastructure.md) (P11, P14); the monitor links to them.

## 16.2 Decisions

| # | Decision | Why |
|---|----------|-----|
| **MD16** | **Every action is a name from a catalog compiled into the program, with typed parameters, and it is checked twice, by the app and again by the program.** A parameter must match a strict pattern (`^[A-Za-z0-9_.@-]+$` for a unit or PM2 name; digits for a pid) **and** exist on that server (a unit in `systemctl list-units`; a PM2 name in `pm2 jlist`; a pid that is still the process it was, MD21). Parameters are passed as separate arguments, **never joined into a string from request text**. There is no exec. A request for an action outside the catalog is treated as malformed and gets no answer (14 §14.4). | The worst a bug or a stolen key can do is something in a short list, with parameters that must match a real thing, validated by two separate pieces of code |
| **MD17** | **Every B and C action is previewed by the program first.** The preview runs read-only commands on the server (`nginx -t`; the connections and tenants a restart would interrupt; the unit's state and why it stopped) and the review screen shows exactly that. Confirming sends the real action with the warning codes acknowledged (`dry_run: false`). | A preview worked out by the app from what it last saw would be a guess; the program can say what is true now |
| **MD18** | **Audit first.** Before a B or C action is sent to the program, the app asks the Brain to **record the intent** (who, which device, the server, the action, its parameters, the reason, the acknowledged codes) and gets an id back; **if the Brain cannot record it, the action is not sent.** When it finishes, the app sends the **result** to the same record. This is one request of under 1 KB before and one after, only when you act, and is the only monitoring traffic that reaches the Brain. | The guarantee 13 gives ("if the audit write fails the change is not made"), with the cost stated: while the Brain is unreachable you cannot act through the app and you do it by hand, as you do today (OQ-M18) |
| **MD19** | **A change runs detached, and its result is a record, not a boolean.** The program starts B and C actions with `systemd-run --no-block --unit=afs-control-<id>` so they **keep running if the phone's connection drops, or if the program itself is restarted by the action**, and keeps the outcome. The result holds the exit status, the **tail of stdout and stderr** (8 KB each, shown in full), the unit's state **before and after**, the duration and a **verification** (§16.3: did the thing actually come back?). **A lost answer is settled by asking `GET /actions/{id}` again, never by sending the action again.** | Re-sending a restart because an answer was lost is how a server gets restarted twice. It is also what makes restarting the program's own server, or the Brain host, safe to attempt |
| **MD20** | **Class C needs two switches, both off by default:** the **app's Settings switch "Allow dangerous server actions"** (per phone) **and** the program's own `allow_dangerous = true` in `/etc/afs-monitor/agent.conf`, which only a person logged in to that server can set. The program refuses a class C request when its own switch is off **even if the signature is valid**. | A mistaken tap, a borrowed phone, **or a stolen device key** each cannot alone stop or reboot a server. The second switch lives on the server, where the phone cannot reach it |
| **MD21** | **Protected targets.** The program refuses, whatever it is told: stopping or disabling `sshd` (it would lock you out); stopping the network or firewall units without the acknowledgement `may_cut_access`; stopping `afs-monitor` itself (the dedicated `remove_monitor_program` is the way); signalling pid 1, its own pid, a kernel thread or `sshd`; acting on a unit not declared for this server (14 §14.9) without `undeclared_unit`. **`signal_process` quotes the start time the screen was drawn from**, and the program refuses if the pid's start time has changed (a pid can be reused in the seconds between seeing it and tapping). The list is configuration (`brain_config.monitoring.protected`, sent with the request) **with a floor compiled into the program that configuration cannot go below.** | The few actions that cannot be undone from the phone that caused them |
| **MD22** | **One action at a time per server, and a rate limit.** The program holds `flock -n /run/afs-monitor/control.lock` for each action, so two phones or a double tap cannot interleave a stop and a restart; the second is answered "busy". The program refuses more than **5 B/C actions per 10 minutes**. | The lock lives where the thing being protected lives |
| **MD23** | **A control action on a server with a transfer running needs an acknowledgement (`transfer_running`).** The app reads 13's recent jobs (the existing job list already carries each job's `from`, `to` and status) and raises it when one involves this server and has not ended. | The two features share the machines |
| **MD24** | **In a production build, B and C need the app lock to have been passed in the last 5 minutes.** | The phone is the key to the fleet; this is the one place the existing lock is worth asking again |
| **MD25** | **Control needs the `control` scope on the device's entry on that server, and an open session.** A key authorized for `ping` and `read` only (for example a second phone you only want to look with) cannot act; every action request is signed like every other. | Looking and acting are separate permissions per device per server |
| **MD26** | **The Brain host is controllable, with an extra warning.** It is a row with `server_type = 'Brain'`; any B or C action on it raises `acting_on_brain_host` ("this machine serves this app's own API; the app will lose its connection while it restarts"), is acknowledged like any warning, and needs the typed phrase **even on the lab**. | The one server whose restart cuts off the thing you are using to restart it. MD19's detached run and read-after-reconnect exist for exactly this case |

## 16.3 The action catalog

**Verify** is the check run after a real action and reported as `verified: true | false` with its output. Actions marked *(SSH)* are the install-time actions of [14](14-server-monitoring-foundation.md) §14.5, done over SSH because the program may not exist yet or may be what changes.

| Action | Class | Parameters | Preview | Warnings it can raise | Verify |
|--------|-------|------------|---------|-----------------------|--------|
| `run_checks` | A | checks or all | — | — | — |
| `run_soft_checks` | A | names from the server's list | — | — | — |
| `refresh_services` | A | — | — | — | — |
| `fetch_logs` | A | `source` (a unit via `journalctl -u`, `nginx_error`, `nginx_access`, `php_fpm`, `mariadb`, `messages`, `dmesg`), `lines` ≤ 500, `since` | — | `log_may_contain_secrets` (information: lines are shown in full, **held in memory only, never cached or logged by the app**) | — |
| `nginx_test` | A | — | the output of `nginx -t` | — | — |
| `reload_unit` | B | `unit` | for `nginx`: `nginx -t` **must pass or the action is refused** (`config_test_failed`, not acknowledgeable); for `named`: `named-checkconf`; for `php-fpm`: the pool config test | `unit_has_tenants`, `transfer_running`, `acting_on_brain_host` | the unit is `active` and its check passes |
| `restart_unit` | B | `unit` | state, uptime, **established connections**, **tenants served**; for `mariadb` the thread count and any long-running query | `unit_has_tenants`, `many_active_connections`, `recent_restart_loop` (≥ 3 restarts in an hour), `transfer_running`, `undeclared_unit`, `last_dns_server_in_family` (13's code and check, for `named` on the last active DNS server of its family), `acting_on_brain_host` | `active` for 10 s and its check passes; on failure the **unit's journal tail** is attached |
| `start_unit` | B | `unit` | state and why it stopped | `undeclared_unit` | as `restart_unit` |
| `pm2_restart`, `pm2_start` | B | `name` | the process, its tenant, uptime and restarts | `unit_has_tenants` (names the tenant) | `online` for 10 s |
| `cancel_reboot` | B | — | the pending shutdown | — | the shutdown is gone |
| `set_targets` | B | the soft-check list (validated: names, kinds, hosts, ports) | the diff against the current list | — | the program re-reads and lists them |
| `enable_site_log` / `disable_site_log` | B | — | **the exact file it will write** (`/etc/nginx/conf.d/afs-sitelog.conf`: a `log_format` that includes the site name and one `access_log` line) and `nginx -t` of the result | `site_log_overridden` (a vhost sets its own `access_log` and will not use it), `unit_has_tenants` | `nginx -t` passes and nginx reloaded; **if the test fails the file is removed and nothing is reloaded.** Writes only this one file, with fixed content; this is what makes per-site web traffic ([15](15-server-live-and-attribution.md) N2) possible; off until you choose it |
| `install_monitor_program`, `upgrade_monitor_program`, `authorize_device` *(SSH)* | B | see 14 §14.5 | what will be written | `opens_port`, `unit_has_tenants` is not involved | the program answers a signed ping |
| `stop_unit` | **C** | `unit` | as `restart_unit`, and "**nothing will start this again until someone does**" | the above, plus `unit_is_critical` (declared critical here), `may_cut_access` | `inactive` |
| `pm2_stop` | **C** | `name` | as `pm2_restart` | `unit_has_tenants` | `stopped` |
| `signal_process` | **C** | `pid`, `started` (the start time **as the app last saw it**), `signal` (`TERM` by default; `KILL` needs `kill_ack`) | user, tenant, unit, command line, age, what it holds open; whether it is a service's main pid ("this will make systemd restart it") | `signal_system_process`, `process_is_main_pid`, `kill_ack`, `acting_on_brain_host` | the pid is gone, or is a different process |
| `reboot_server` | **C+** | `delay_s` (default 60) | tenants served, running transfers, uptime, the declared units that will start on boot (`systemctl is-enabled`) | `reboot_drops_all_tenants`, `transfer_running`, `unit_not_enabled:<unit>` (a declared unit that will **not** come back on boot), `acting_on_brain_host` | the `boot_id` changes and the program answers a ping again within 10 minutes |
| `remove_monitor_program`, `revoke_device` *(SSH)* | **C** | see 14 §14.5 | the files and port it will remove | — | the program no longer answers |

**A reboot has a delay and can be cancelled** (`shutdown -r +1`, `shutdown -c`): the cheapest undo for the most expensive mistake. **Most of these have no rollback, so the screen says what will happen instead of promising an undo:** a restarted unit is already running; a stopped one has a Start button; a signalled process is gone, and if a service owned it, systemd brings it back.

## 16.4 The app

### Where control lives

| Screen | Control |
|--------|---------|
| **Server view · Services** ([14](14-server-monitoring-foundation.md)) | a unit opens a sheet with its details and its allowed actions (**Logs**, **Reload**, **Restart**, **Start**, **Stop**); an action the program will refuse is **disabled with the reason** ("sshd is protected: stopping it would lock you out"), not hidden; PM2 the same |
| **Server view · Overview** | **Recent actions** (who, what, when, result; read from the Brain's audit records, §16.5), **Run checks now**, and **Reboot…** (only when both dangerous gates allow it) |
| **Server view · Checks** | **Run the soft checks**, **Edit the list** (`set_targets`) |
| **Top consumers → a process** ([15](15-server-live-and-attribution.md)) | **Stop this process** (`signal_process`) with the start-time guard |
| **Logs** | a viewer for `fetch_logs`: monospace, searchable, copy, "fetched from the server just now, not stored" |
| **Network screen** | **Turn on per-site traffic** (`enable_site_log`) where it is not collected |
| **Server view · Program** | the install-time actions of 14 §14.5 |

### The flow (one flow for every action)

1. Choose the action → **"Checking what this would do…"** (the program's preview). If the program does not answer: "**The server did not answer. Nothing was changed.**" and Retry.
2. **Review**: what it does in words; the **warnings as checkboxes**, each with its detail ("**nginx** serves **41 active tenants**; about 120 connections will be interrupted"); the **reason**; on production the **typed phrase** (the server's hostname, plus the action's name for class C). A refused action (`config_test_failed`, a protected target) shows its output and **has no confirm button at all**.
3. **Audit** (MD18): "Recording…". If the Brain cannot record it the screen says so and **nothing is sent**.
4. **Running**: the action's state, polled from the program (`GET /actions/{id}`); survives backgrounding and a dropped connection (MD19).
5. **Leaving while it runs.** The action continues on the server whether or not the phone is connected (MD19), so leaving the server view (which ends the session, 14 §14.7) first asks: "This action is still running. It will keep going on the server. You can see the result under Recent actions when you come back." It never cancels the action by leaving.
6. **Result**: before and after state, the **verification**, the output tail in full, the duration; the Services screen refreshes. A failure shows the unit's journal tail without being asked.

The review carries a `client_key` made once per screen, so a retry after a lost answer reads the **same** run, never starts another. **No control UI is offered** when the account is not level 99, when the device has no `control` scope on that server, when the program cannot be reached, or (class C) when either gate of MD20 is off, and each says why.

## 16.5 The one server addition (BS27) and the central switch

| BS | Routes | What |
|----|--------|------|
| **BS27** | `POST /monitor/audit` (creates the intent, returns its id), `PATCH /monitor/audit/{id}` (attaches the result), `GET /monitor/audit?server=` (recent actions) | an audit record per action, stored in 13's `brain_infra_audit` with `resource = server_control`, in the same transaction style as 13 (if the write fails, the answer is an error and the app does not send the action). Level 99, `no-store`. `whoami` gains `monitor_control` |

**One switch on the Brain: `BRAIN_MONITOR_CONTROL` (default ON since 2026-10-07, at the owner's request; was off).** With it off the audit route answers `403 BS_E_DISABLED`, `whoami` reports `monitor_control: false`, and because of audit-first (MD18) **no control action can be sent from any phone** — a central, server-side off-switch for the whole feature. Reading (14, 15) is **not** behind it: it needs no Brain at all.

## 16.6 Phases

| Phase | Name | Delivers | Needs |
|-------|------|----------|-------|
| **P23** | **The safe actions and the audit** | BS27 and the switch; the catalog in the program and the app, the preview → review → audit → run → result flow with the detached run and the lock; class A (checks, soft checks, services, logs, `nginx_test`) and class B (reload, restart, start, PM2, `set_targets`, `enable_site_log`, `cancel_reboot`); the Logs viewer; Recent actions | [14](14-server-monitoring-foundation.md) P17 to P19 |
| **P24** | **The dangerous actions** | class C (stop, PM2 stop, signal, **reboot** with delay and cancel), both gates of MD20 (the Settings switch and the program's `allow_dangerous`), the Brain-host warning, the production app-lock requirement, the per-device `control` scope | P23 |

## 16.7 Tests and acceptance

**The program (PHP, against fixtures; a fake `systemctl`):** an unknown action, a malformed parameter, a unit not present, and an address-like parameter are refused and get no answer **even with a valid signature**; protected targets (`sshd`, pid 1, its own pid, `afs-monitor`) are refused with **no override**; `config_test_failed` cannot be acknowledged; **a class C request is refused when `allow_dangerous` is false even though the signature is valid**; a key without the `control` scope is refused; the preview never runs a write command (a test lists every command a preview can run); a real action without every acknowledged code is refused; the lock refuses a second action ("busy"); the 5-per-10-minutes limit holds; `signal_process` refuses a changed start time; the verification runs and a failed one attaches the journal tail; output tails are bounded at 8 KB; an action started detached **survives the program restarting** and its result is still readable; `reboot_server` schedules with a delay and `cancel_reboot` cancels it; `set_targets` rejects an invalid list and a request that supplies an address to check.

**The phone (a fake `MonitorClient`):** an unknown action and a malformed parameter are refused before anything is sent; protected targets are refused with **no confirm path**; class C is refused unless **both** gates are on; **the audit request is made and answered before the action is sent, and a failed audit means the action is never sent**; the same `client_key` reuses the run and never starts a second; a dropped connection is settled by reading the action's state and **sends nothing**; `transfer_running` is raised from the job list; in a production build B and C need a fresh app lock; the Services sheet disables a protected action **with its reason**; leaving while an action runs asks first and never cancels it; every screen is in the **screen tour**.

**Server (BS27):** the audit route creates, completes and lists records; it is refused with `403 BS_E_DISABLED` when the switch is off; refused for any level below 99; a failed insert returns an error; the record holds the actor, device, reason and acknowledged codes and the result once attached; no record is ever deleted by this code.

**Exit checks (lab, on the phone, on a throwaway lab server first; each action **with your say-so**, since the lab notes are read-only unless you say otherwise):**
1. Fetch the nginx error log; see recent lines; leave the screen and see nothing cached.
2. Break an nginx vhost file on purpose; **Reload** is refused with the `nginx -t` output and **no confirm button**; fix it; Reload succeeds and verifies.
3. Restart `php-fpm`: the review names the tenants and connections; the result shows before and after and `verified: true`; a row appears in Recent actions.
4. Try to **stop `sshd`** and to stop the program itself: refused. Try to stop `nginx` with the gates off: refused with the reason.
5. Turn both gates on **in the app and in the program's `agent.conf`**; stop and start a PM2 app (where one exists); signal a runaway process from Top consumers; watch systemd restart a service whose main pid was signalled. Turn the program's own switch off again and see the same stop refused although the app still allows it.
6. **Reboot** with a 60-second delay and cancel it; do it again and see the server return, the `boot_id` change and the declared units come back.
7. With the Brain switch off, try any action and see it refused before anything is sent.
8. Start a restart and **kill the app mid-action**; reopen it onto the same action from Recent actions and see the outcome without a second restart.
9. Do the same to the **Brain host**: see `acting_on_brain_host`, the typed phrase on the lab, and the result read after the API returns.

## 16.8 Open questions

| # | Question | Needed by |
|---|----------|-----------|
| **OQ-M15** | **The protected list.** Proposed floor: `sshd`, `afs-monitor`, `firewalld`, `NetworkManager`/`network`, `systemd-*`, `dbus`, `chronyd`, plus the declared-critical rule. Anything to add (a unit the whole estate depends on)? | P23 |
| **OQ-M16** | **App lock for control in a production build** (MD24): B and C only, fresh within 5 minutes. Proposed yes. This is also 13's OQ-I2 for control | P23 |
| **OQ-M17** | **The typed phrase** on production: the server's hostname (proposed); for class C also the action's name | P23 |
| **OQ-M18** | **Audit-first stops control while the Brain is unreachable.** Proposed: accept that and act by hand then, as today. The alternative (act first, record later from a queue on the phone) breaks 13's rule that an unrecorded change is not made | P23 |
| **OQ-M19** | **`systemd-run` is assumed on production** (the lab, CentOS Stream 9, has it). The install's preflight confirms it; a server without it cannot run detached actions and the app says so | P23 |

# 16 — Controlling the HTTP servers — Completion

**Verified:** 2026-10-06, on the authoring PC (Windows). **Nothing was run as root on a server, no action was ever sent to a real server, nothing has run on a phone, and the new Brain PHPUnit test was written but not run.**

| Check | Result |
|---|---|
| The program, PHP 8.4 on this PC: `tests/run-control-tests.php` (new: the catalog and every refusal, the preview's read-only command list, the gates, protected targets, ack checks, idempotency, the busy and rate limits, the detached run and its verification, a record that survives a new program instance, `signal_process`, reboot and cancel, `set_targets`, the per-site log and its undo, `fetch_logs`, the routes, the log parser) | **126 passed** |
| The existing program suites after the changes: `run-tests.php`, `run-collector-tests.php`, `run-attribution-tests.php` | **130, 168, 94 passed** (the `run()` tests need Linux and were skipped here) |
| App: `flutter analyze` (lib and test) | clean |
| App: the whole suite, including the screen tour for the three new screens (48 dp tap targets, labels, contrast, text at 200%) | **1629 tests; the one failure of the first full run was the app's copy of the program being stale, fixed by `tool/sync-monitor.sh`, and that file was re-run and passes.** The full suite was not run a second time |
| The phone's client against the **real PHP program over TLS on loopback** (`protocol_test.dart`) | passed (reads version 1.3.0) |
| `php -l` on every PHP file changed on the Brain | clean |

**Not verified, and nothing here should be called working until it is:**
1. **Any action on a real server.** The control engine ran only against fakes (a fake `exec`, a fake `/proc`, a recorded `systemd-run`). In particular **`systemd-run --no-block --unit=afs-control-<id>` from inside the program's own sandbox** (`ProtectSystem=strict`, `NoNewPrivileges`) is untested: the plan assumed it works (OQ-M19). If the transient unit cannot be created, the start answers `spawn_failed` and nothing runs.
2. **The Brain audit route.** `BrainStaffMonitorAuditTest` (10 tests) is written but **not run**: `phpunit.xml` points at the lab database (`192.168.56.11`, `testdb_appfactorystudio`), and the migration `2026_10_07_000001_add_result_to_brain_infra_audit` has not been run anywhere. Until it is migrated the route answers `503 BS_E_NOT_READY`.
3. **The screens on a phone**, and the app lock re-ask (`confirmPresence`) on a real device.
4. **Exit checks 1 to 9 of §16.7 on the lab.** Each acts on a lab server, so each waits for your say-so (and 3 to 6 and 9 need the program installed and `allow_dangerous` switched on there).

## What was built

| Phase | Server | App (`afsbrain_flutter/lib/features/monitor/`) |
|---|---|---|
| **P23** the safe actions and the audit | **BS27**: `POST/PATCH/GET /monitor/audit` (`StaffMonitorAuditController`, table `brain_infra_audit`, `resource = server_control`), the `BRAIN_MONITOR_CONTROL` switch (off by default: `403 BS_E_DISABLED`), the `monitor_control` capability. **Program 1.3.0** (`afs-monitor.php`, class `Controls`): the catalog, strict decoding (anything else gets silence), the preview (read-only commands only, a list that a test enumerates), the audit-id requirement, acknowledged warnings checked again at run time, one action at a time, 5 changes per 10 minutes, `client_key` idempotency, detached run with `systemd-run`, a record per action in `/etc/afs-monitor/actions/`, verification (active and still active 10 s later, its own config test, PM2 `online`, process gone, reboot scheduled), the journal tail on a failure, `fetch_logs`, `nginx_test`, `set_targets`, `enable_site_log`/`disable_site_log`, `cancel_reboot`. `/v1/network` now fills `web_by_site` from the per-site log | `ControlGateway`, `ControlFlowCubit` (preview, review, record on the Brain, send, follow, result, settle by reading), the **flow screen**, the **log viewer** (`LogsCubit`, `LogsPage`), **Recent actions** (from the Brain's audit; tapping one finds out how it ended), the Services sheet actions, Overview's Control section, Checks' "send the reach checks", Network's "Turn on per-site traffic" and the per-site list |
| **P24** the dangerous actions | class C in the program: `stop_unit`, `pm2_stop`, `signal_process` (pid, start time, identity checked at the moment of acting), `reboot_server` (delay, cancel); `allow_dangerous` read fresh from `agent.conf` on every request and reported in `ping`; the compiled floor of protected targets; `--allow-dangerous on\|off` | `DangerousSwitch` and the Settings switch; both gates shown as disabled-with-reason; the typed phrase (`<hostname> <action>` for class C, even on the lab); `acting_on_brain_host` and `transfer_running` raised by the phone; the production app-lock re-ask (`AppLockController.confirmPresence`, 5 minutes); the reboot wait (boot id changes and the server answers); "Stop this process" on the process screen |

Files: Brain `w/www/{config/brain.php, database/migrations/2026_10_07_000001_add_result_to_brain_infra_audit.php, app/Http/Controllers/Brain/Staff/StaffMonitorAuditController.php, routes/portal.php, app/Services/Brain/Staff/StaffCapabilities.php, tests/Feature/Brain/BrainStaffMonitorAuditTest.php, tests/Concerns/CreatesInfraTables.php}`; program `w/www/core/scripts/monitor/{afs-monitor.php, PROTOCOL.md, tests/run-control-tests.php}` (and `run-attribution-tests.php`: one added check); app `data/{control_models,control_catalog,control_gateway,monitor_audit_repository}.dart`, `cubit/{control_flow_cubit,logs_cubit,dangerous_switch,server_view_cubit}.dart`, `ui/{control_flow_page,control_hooks,logs_page,server_view_page,process_page,top_page}.dart`, `protocol/monitor_client.dart` (a per-request timeout), `data/top_models.dart` (the process start time, the per-site rows), `core/app_lock/app_lock.dart`, `session/capabilities.dart`, `core/api/api_paths.dart`, `features/settings/ui/settings_page.dart`, `monitor_kit.dart`, `main.dart`, `home_shell.dart`, `assets/monitor/*` (synced), and the tests and fakes (`control_flow_test.dart`, `control_page_test.dart`, `support/control_fakes.dart`, `shell_rig.dart`, `screen_tour.dart`).

## How the open questions were settled

- **OQ-M15 (protected list):** the proposed floor. The program never stops `sshd`, `afs-monitor`, `dbus`, `dbus-broker`, `chronyd` or any `systemd-*`; stopping `firewalld`, `NetworkManager`, `network` or `networking` needs the acknowledgement `may_cut_access`, and so does **restarting** `sshd` (stopping it is refused outright). The record may add units (`json.monitor.protected`), never remove one.
- **OQ-M16 (app lock):** yes, B and C only, fresh within 5 minutes, in a production build.
- **OQ-M17 (the phrase):** the server's hostname as the program reports it, and for class C also the action's name.
- **OQ-M18 (audit-first):** accepted: with the Brain unreachable or the switch off, nothing is sent.
- **OQ-M19 (`systemd-run`):** assumed; the preview reports `detached` and a start without it answers `no_systemd_run`/`spawn_failed`.
- **OQ-M10 of plan 15 (per-site web traffic):** built here as `enable_site_log` (it writes one fixed file, tests nginx, reloads it, and undoes itself if the test fails). It is off until you choose it per server.

## Where the build differs from the plan

- **`run_checks`, `run_soft_checks` and `refresh_services` are not audited actions.** They already existed as plain reads inside the session (plans 14 and 15) and changing them to go through the Brain would make looking at a server need the Brain. They stay as they were; the audit covers `fetch_logs`, `nginx_test` and every change. The Brain's catalog still lists their names.
- **A class A action is audited but has no review screen** when it has nothing to decide (`nginx_test`); it runs at once from its button. `fetch_logs` goes through the log viewer, not the flow screen.
- **`set_targets` takes the list from the server's record** (`json.monitor.targets`), not from a list editor in the app: the program's `/v1/checks` does not return a target's host and port, so there is nothing on the phone to edit. "Send this server's reach checks from its record" shows the diff in the preview.
- **No `GET /actions/{id}` path with a slash:** the program's routes are exact (`GET /v1/action?id=`, `GET /v1/actions?client_key=`).
- **The lock is the detached run's, not the preview's:** `flock` on `/run/afs-monitor-control.lock` is taken by the run itself (the program cannot create files in `/run` under its own sandbox); the program's busy answer comes from the records of runs that are still alive.
- **`remove_monitor_program`, `revoke_device` and the install-time SSH actions** stay on the Program screen with their own confirms (plan 14); they are not in the audit.
- **`pm2_start` and `pm2_restart` need PM2 in root's `PATH`** (`/usr/local/bin`, `/usr/bin`): a PM2 installed only under a user's home is reported as unavailable.
- **The program is 1.3.0:** every server needs an upgrade from its Program screen before control works on it; an older program says it is too old.
- **`/v1/process` now carries `started`** (the start time in clock ticks), which the signal flow quotes back.

## What is **not** built

1. Anything on the Brain beyond BS27, and the Brain's `.env` switch is **not turned on** anywhere (`BRAIN_MONITOR_CONTROL` defaults to off).
2. A rollback: most actions have none by design; the screen says what will happen instead.
3. `refresh_services`/`run_checks` through the audit (above).
4. An editor for the reach-check list.
5. The install-time actions through the audit.
6. Throttling a second phone: the busy and rate limits are per server, not per phone.

## Rules that are tested by reading the code

`monitor_rules_test.dart` still passes: SSH is imported by one file; the device key is read only by the signing client and the install code; a session is opened only by server-view code (the control cubits go through `ServerViewCubit.post`/`read`, which reuse the view's session); the program carried by the app equals the repository's.

## Left behind

Nothing on any server. On this PC the control test removes its own temporary folder; the older `afs-monitor-collect-*` and `afs-monitor-test-*` folders in the temp directory are not mine.

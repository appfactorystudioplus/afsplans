# brain_flutter — Planning Index

> **Start here for server monitoring:** [server-monitoring-guide.md](server-monitoring-guide.md) (what it is, how to use it, what is not done). Plans 14, 15 (the live view and what is using the server) and 16 (control) are built; plan 17 (Linode) is built.

> **Status (2026-10-05): P0 to P7 built, the whole of file 10 (UI and navigation), and the whole of the new file 13 (the infrastructure console: reads, fleet, config, DNS, jobs, Apps and Storage; DNS apply is not built and starting a transfer is off by default); files 11 and 12, P3b, P5b and P9 are not.** The Flutter project exists
> (`afsbrain_flutter`): config, API client, error model, session handshake, and the members feature (list, detail, add member, edit profile, change plan, change status,
> the interim Remove); subscription control through Direct Payments (read, sync, cancel, change plan, refund) and Billing (overview, ledger); usage, limit overrides and
> single-counter adjust; Checkpoint errors and afs-connect behind a More destination; and the navigation, accessibility and production app lock of file 10. Server side in
> `w/www`: BS1 (device-token command), BS2 (`whoami`), BS3 (list filters), BS4 (staff member detail), BS5 (address edit), BS6 (staff subscription), BS7 (create-options),
> BS8 (payments), BS9 (billing overview), BS10 (`admin_mobile` audit and self-protection for device tokens), BS12, and three off-plan items in `complete/00_extras.md`
> (E1 the money ledger, E2 the afs-connect source whitelist, E3 the `checkpoint_errors` capability). Not built: BS11 and P5b (pricing wrappers), P3b (real delete, needs
> the `account_close` plan), P9 (the Apps control plane, optional), and files 11 (Android build and deploy) and 12 (rollout and testing). **Nothing built after the first
> device run has been verified on a device or against a live server, and the server PHPUnit files have not been run against a database.** Completion documents are
> written one file at a time, when that file is finished: [02](complete/02-authentication-device-token.complete.md), [03](complete/03-environments-and-config.complete.md),
> [04](complete/04-server-additions.complete.md), [05](complete/05-app-architecture.complete.md), [06](complete/06-members-feature.complete.md),
> [07](complete/07-subscriptions-direct-payments.complete.md), [08](complete/08-allocations-and-overrides.complete.md), [09](complete/09-other-brain-sections.complete.md),
> [10](complete/10-ui-and-navigation.complete.md); off-plan fixes in [00_extras](complete/00_extras.md).
>
> *(Original status line, 2026-10-03: "PLAN ONLY. Nothing here is built, and no Flutter project exists yet.")*
> Written against `DEVELOPMENT_MODE = vagrant`, with the **lab2** database
> (`testdb_appfactorystudio` on `10-0-0-243`) read — read-only — to verify every claim about data.
> Every statement about current code cites a file; every statement about data was checked against lab2.

A **personal-device Android app** (`afsbrain_flutter`, sideloaded, never on the Play Store) that does
what the Brain admin in the browser does for **members** — see them all, add, edit, remove — plus the
thing the browser cannot do today: **control a member's subscription through the Direct Payments
layer**. It is signed in permanently as the Brain's own member, **`appfactorystudio`**, with no login
screen.

The source being mimicked is
[`w/www/resources/js/portal/admin/controllers/brain`](../../../../w/www/resources/js/portal/admin/controllers/brain).
Its `BrainController.ts` is only a 50-line proxy helper; the screens that matter are
`brain_membership.ts` (4,357 lines), `apps/brain_apps.ts` and `storage/brain_storage.ts`.

> ### Five things that decide the shape of this plan
>
> **1. "No login" costs one artisan command and zero changes to the auth middleware.**
> Every Brain admin route authenticates a bearer token looked up in `laravel_user_tokens`, and both
> middlewares honour `expires_at = NULL`. The Brain member `appfactorystudio` (`brain_memberships.id 1`,
> `level 99`) is `laravel_users.id 1`. A **device token** — a row for that user, minted by a command *you*
> run, written into the app's gitignored `.env` — is accepted by every existing route, including the
> `portal.level99` groups. The app never sees a password and never shows a login form.
> → [02](02-authentication-device-token.md).
>
> **2. The web Subscription and Payments tabs are dead code — do not port them.**
> They call `GET /processors/billing/payments`, which has no route and no controller method, and they
> send `member.customer_id`, a column that was dropped from `brain_memberships`
> ([audit BA8](01-current-state-audit.md)). Subscription control is built on the **Direct Payments**
> layer instead (`payer_reference_num` → `laravel_direct_payment_subscriptions`).
>
> **3. Direct Payments' subscription actions are self-service only.**
> `cancel`, `update` and `setPaymentMethod` resolve the *caller's own* subscription from the caller's
> Checkpoint bearer and return 403 for any other member. A staff caller cannot use them on someone
> else. The plan therefore adds a **staff subscription surface** in `www` that reuses the same provider
> actions, the same sync service and the same tier-binding resolver — not a second implementation.
> → [04](04-server-additions.md), [07](07-subscriptions-direct-payments.md).
>
> **4. "Remove a member" has no server endpoint.** There is no `DELETE` anywhere in the memberships API;
> `account_status = 3` is a label that nothing enforces. The real feature is the unbuilt
> [`account_close`](../account_close/README.md) plan. The app ships a safe **interim** (suspend / mark for
> deletion) and a **capability-gated** UI for the real soft and hard delete that lights up when
> `account_close` lands. → [06](06-members-feature.md) §6.6.
>
> **5. The phone talks to the Brain's HTTP API, never to MySQL.** Registration into Checkpoint, the audit
> trail, dunning and the Parse allocation ledger all live in `www`. A direct database connection would
> bypass every one of them. → [00](00-overview.md) decision **BF2**.

## Documents

| Step | Document | What it decides |
|------|----------|-----------------|
| — | [README.md](README.md) | This index |
| 00 | [00-overview.md](00-overview.md) | Goals, non-goals, glossary, decisions **BF1–BF14**, open questions |
| 01 | [01-current-state-audit.md](01-current-state-audit.md) | What the Brain does today, the API inventory, the lab data, and the 20 findings **BA1–BA20** |
| 02 | [02-authentication-device-token.md](02-authentication-device-token.md) | The "always logged in as `appfactorystudio`" mechanism, token lifecycle, revocation |
| 03 | [03-environments-and-config.md](03-environments-and-config.md) | `.env` scheme, `_env/<mode>/`, no hard-coded URLs, `use-env`/`check-env`, lab CA |
| 04 | [04-server-additions.md](04-server-additions.md) | Every change needed in `www`, with request/response contracts |
| 05 | [05-app-architecture.md](05-app-architecture.md) | Project layout, packages, networking layer, error model, state management |
| 06 | [06-members-feature.md](06-members-feature.md) | List, search, detail, **add, edit, remove** |
| 07 | [07-subscriptions-direct-payments.md](07-subscriptions-direct-payments.md) | Reading and controlling a member's subscription; billing state; refunds |
| 08 | [08-allocations-and-overrides.md](08-allocations-and-overrides.md) | Usage view and limit overrides (Parse-backed) |
| 09 | [09-other-brain-sections.md](09-other-brain-sections.md) | afs-connect, Checkpoint errors, Apps, Storage, DNS/HTTP — deferred, with the reason for each |
| 10 | [10-ui-and-navigation.md](10-ui-and-navigation.md) | Screens, navigation, the environment badge, confirmations |
| 11 | [11-android-build-and-deploy.md](11-android-build-and-deploy.md) | Creating the project, signing, installing on your phone, lab vs production builds |
| 12 | [12-rollout-and-testing.md](12-rollout-and-testing.md) | Phases **P0–P9**, the test matrix, acceptance |
| 13 | [13-brain-infrastructure.md](13-brain-infrastructure.md) | Managing the Brain's own infrastructure from the app: `brain_config`, HTTP servers, tenants, clusters, regions, DNS, Apps, Storage. Phases **P10–P16**, server work **BS13–BS25** |
| 14 | [14-server-monitoring-foundation.md](14-server-monitoring-foundation.md) | **Built (P17–P19), not yet run on a server or a device; see [its completion document](complete/14-server-monitoring-foundation.complete.md).** Monitoring the HTTP servers with **a small program on each server that answers only your phone** (signed requests, pinned TLS, silent to anyone else), **red/green lights in the server list from asking it with no login**, **login only inside one server's view and logout on leaving**, soft checks run from the server's side, install over SSH from the phone. Nothing is sent to the Brain. What the lab inventory showed. Phases **P17–P19**, server work **BS26** (one known-key change), decisions **MD1–MD15** |
| 15 | [15-server-live-and-attribution.md](15-server-live-and-attribution.md) | **Built (P20 to P22), tested on this PC and read-only on the lab VMs, not yet run as root on a server or on a phone; see [its completion document](complete/15-server-live-and-attribution.complete.md).** The live view (network in/out, CPU, RAM) and **what is using the most** CPU, RAM and network: per process, service, tenant and remote address, with what can and cannot be known (and what the lab's setup rules out). Phases **P20–P22**, no server work |
| 16 | [16-server-control.md](16-server-control.md) | **Built (P23, P24, BS27), tested against fakes on this PC; no action sent to a real server, the audit PHPUnit test not run; see [its completion document](complete/16-server-control.complete.md).** Controlling the servers (reload, restart, stop, signal, **reboot**) through the program, under a safety model with two independent dangerous-action gates and audit-first recording on the Brain. No alerts, no push. Phases **P23–P24**, server work **BS27**, decisions **MD16–MD26** |
| 17 | [17-linode-management.md](17-linode-management.md) | **Built (P25 to P28), tested against fakes only; nothing sent to the real Linode API; see [its completion document](complete/17-linode-management.complete.md).** Managing the Linode (Akamai) account from the app: list, status, usage, **power on/off/reboot, create, delete**, resize, rebuild, rescue, clone, backups, disks, firewalls, volumes, IPs and reverse DNS, SSH keys. Read first; two-step confirms with the name typed for dangerous ones; two safety switches; audit-first on the Brain (BS27 now accepts `linode.*`). Gated by `LINODE_IN_USE`. Phases **P25–P28** |

## Vocabulary (fixed)

- **step** = a numbered file in this folder. **phase** = a division of work inside a file or across
  the rollout, written `P0`, `P1`, `P2`… ([12](12-rollout-and-testing.md)).
- Findings are **BA#** (audit), decisions **BF#** (overview), server changes **BS#**
  ([04](04-server-additions.md)), open questions **OQ#**. Files 14 to 16 (server monitoring) use decisions **MD#** and open questions **OQ-M#**, shared across the three files; file 13 uses **OQ-I#**.

## Conventions

- New and changed **server** code carries `AFS_BRAIN_FLUTTER_MODIFIER_ADDITION — plans/brain_flutter/<file> §<section>`,
  alongside (never replacing) any existing marker (`plans/markers.md`; add this one to it when the first
  phase lands).
- **Dart** code is documented with `///` doc comments on every file, class and public member, including the
  plan reference, matching `afsmobile_flutter/lib/config.dart`'s style.
- Completion documents go in `complete/` **one per finished file**, named
  `<NN>-<same name as the plan file>.complete.md`, written only when that file is finished.
  Off-plan fixes go in `complete/00_extras.md` as dated `E*` sections.
- Standing rules that apply to every phase: never create a branch or commit unless told to; never
  switch an app `.env` automatically (`use-env` is run by hand); never log in as the agent — the
  device token is minted and pasted by you; do not install SDKs or packages to test the plan's work.

## Where things live

| Thing | Location |
|-------|----------|
| This plan | `C:\projects\vagrant\afs-eco\packages\afsserver\plans\brain_flutter` |
| The app (to be created, [P0](12-rollout-and-testing.md)) | `C:\projects\vagrant\afs-eco\afsbrain_flutter` — a sibling of `afsmobile_flutter` and `afsdesktop5` |
| Server changes | `C:\projects\vagrant\afs-eco\w\www` (the Brain) |
| Lab | `C:\projects\vagrant\lab2` — `www.10-0-0-243.sslip.io` |

## Related plans

| Plan | Relationship |
|------|--------------|
| [`members_brain`](../members_brain/README.md) | Owns the allocation console the app's [08](08-allocations-and-overrides.md) reads. Its security work (P0) is why the allocation routes are `level99` |
| [`membership_change`](../membership_change/README.md) | Owns the status/tier endpoints and the `STALE_RECORD` concurrency rule the app reuses ([08-member-management](../membership_change/08-member-management.md)) |
| [`brain_membership_identity`](../brain_membership_identity/README.md) | Why `customer_id` is gone and `payer_reference_num` is the join key ([BA8](01-current-state-audit.md)) |
| [`billing`](../billing/README.md) | The dunning ladder and `brain_membership_billing_state` the app displays |
| [`www/direct_payments`](../www/direct_payments/README.md) | The subscription actions, tier bindings and swap the staff surface reuses |
| [`account_close`](../account_close/README.md) | **Prerequisite for real member removal.** Unbuilt |
| [`www_brain`](../www_brain/README.md) | The Apps control plane ([09](09-other-brain-sections.md) defers it) |

# 00_extras — off-plan work

Work done for `brain_flutter` that is not a numbered plan step: fixes found while building a step, regressions, seeders. Each entry is a dated `E*` section.

## E1 — 2026-10-05 — `MoneyController` resolved members through a column that no longer exists

**Found while:** reading the existing refund and ledger routes for file 07 (`07-subscriptions-direct-payments.md` §7.6).

**The defect.** `w/www/app/Http/Controllers/Externals/Processor/MoneyController.php` `toLedgerRows()` looked members up with `Membership::whereIn('customer_id', …)`.
`brain_memberships.customer_id` was dropped by `2026_09_22_000002_add_payer_reference_num_to_brain_memberships` (brain_membership_identity BM1). On a migrated
database that query throws "Unknown column", so:

- `GET /portal/api/v1/money/ledger` (and the legacy `GET /portal/api/charges` alias) failed on **every** call;
- `POST /portal/api/v1/money/charges/{id}/refund` failed **after** `RefundService` had already refunded the money at the provider, because the response is built with
  `toLedgerRows()`. The caller would have seen a 500 for a refund that had in fact happened. (The audit, `01-current-state-audit.md` BA11, says the ledger and refund are
  authenticated; it does not mention that they cannot run.)

**The fix.** The member is resolved the way the rest of Direct Payments does it, through the payer: provider customer id → `laravel_direct_payment_payer_customers.payer_reference_num`
→ `brain_memberships.payer_reference_num`. Both storage shapes of `ProcessorCharge.customer_id` (the provider's id, as Stripe stores it, and the local `ProcessorCustomer.id`, as
Square and PayPal do) are still handled. A charge whose customer has no payer lists with `member_reference_num: null`. Marked
`AFS_BRAIN_FLUTTER_MODIFIER_ADDITION` in the code. No route, response key or middleware changed.

**Tests.** `tests/Feature/Brain/MoneyLedgerMemberResolutionTest.php` (5): the ledger lists without error and names the member in both id shapes; an orphan charge lists with a null
member; a refund returns the ledger row with the member (the provider refund is faked, so nothing can reach a provider); a provider refusal is a 400 with its message; an unknown charge is a 404.
**Not run:** no database is reachable from the authoring PC (see `04-server-additions.complete.md`).

**Why it matters for the app.** The app's Billing → Ledger and every refund depend on these two routes. Until this change is deployed and its tests are green, **do not refund
from the app**, and expect the ledger to answer 500.

## E2 — 2026-10-05 — the www afs-connect proxy dropped six of the coordinator's eighteen sources

**Found while:** reading `MembershipController::updateAfsConnect` and the coordinator's `AfsConnectSettingsController` for file 09 (`09-other-brain-sections.md` §9.2).

**The defect.** The www proxy validated `sources.*` with twelve rules written by hand. `validate()` returns only keys that have a rule, and the proxy forwards `$validated`,
so every other key in `sources` was silently discarded before the coordinator saw it. The coordinator builds its own whitelist from `config/afs_connect_sources.php`
and currently has **eighteen** sources, so these never reached it from the Brain admin (the browser or the app): `www_money`, `market_orders`, `joystick_engine`,
`external_sdk`. (The browser lists `joystick_engine` as a toggle; flipping it and saving changed nothing and reported success. The coordinator's own comment describes this
exact bug in the members app's proxy — "silently dropped 8 of 12" — which was fixed there and not here.)

**The fix.** `w/www/app/Http/Controllers/Portal/Admin/MembershipController.php` `updateAfsConnect`: the twelve rules are replaced by `'sources.*' => 'boolean'`. The
coordinator still validates and ignores keys it does not know, so www no longer needs to know the list. Marked `AFS_BRAIN_FLUTTER_MODIFIER_ADDITION`. No route, response key or middleware changed.

**Consequence for the plan.** Plan 09 says "twelve booleans". The app does not carry a list: it renders whatever `settings.sources` contains and looks up names for the keys it knows.

**Tests.** `tests/Feature/Brain/BrainStaffCheckpointAndAfsConnectTest.php`: a save carrying `www_money`, `market_orders`, `joystick_engine` and `external_sdk` reaches the (faked)
coordinator with all of them; a non-boolean value is refused with 422 before the coordinator is called; a coordinator refusal comes back with `upgrade_required`.
**Not run:** no database is reachable from the authoring PC (see `04-server-additions.complete.md`).

## E3 — 2026-10-05 — `whoami` had no way to say the Checkpoint error routes exist

`StaffCapabilities` now reports `checkpoint_errors` (both `GET /memberships/checkpoint-errors` and `PATCH …/{id}/resolve` registered), and `afs_connect` now needs the
global-flag route as well as the per-member one, because the More screen uses both. `BrainStaffWhoamiTest` asserts the new key. Without it the app would have had to
guess from `afs_connect` whether to show a list it cannot otherwise detect (BF10: the server decides what exists).

## E4 — 2026-10-06 — Members show their level, and can be filtered by it

The members list and detail show the member's level (0–99, `LevelChip`), the filter sheet has a level range (0–99, fields `level_min` / `level_max`, chip `level_filter_chip`), and the list can sort by level. Server: `MembershipController` accepts `level_min`, `level_max` and `sort=level`. `BrainStaffMembersTest` was updated; **not run** (no database on the authoring PC). App tests pass.

## E5 — 2026-10-06 — The Brain admin DNS tab crashed on open

`imp_brain_dns.ts` read its row templates from `app.extra.config.html.dns_zones_table_row`, which no longer exists (templates moved to `TEMPLATES` in `utils/templates.ts`), so the tab threw `Cannot read properties of undefined` and the DNS settings could not be edited. It now imports `TEMPLATES` and uses `brain_dns_zones_table_row` / `brain_dns_zones_table`; `brainView.ts` got the same fix for `brain_access_email`. **Not type-checked or built here.** Two other uses of the old path remain (`imp_documents.ts:234`, `afsemail.ts:1023`). The same commit also carried the user's own uncommitted work (`updateServer`, `saveSubdomain`, `updateCluster`, `updateRegion` and their routes, the `BrainSave` rewrite).

## E6 — 2026-10-06 — Plans 14 to 17 written; 14 built

Plans for server monitoring (14, 15, 16) and Linode (17) were written, then **14 was built**. See [`14-server-monitoring-foundation.complete.md`](14-server-monitoring-foundation.complete.md) and the plain-language [`server-monitoring-guide.md`](../server-monitoring-guide.md). Plans 15, 16 and 17 are still plans.

## E7 — 2026-10-07 — A chosen interval, a permanent saved copy of the Brain's data, refresh buttons, and the Brain switch on by default

Asked for in one message. Not a numbered plan step.

- **`BRAIN_MONITOR_CONTROL` now defaults to ON** (`w/www/config/brain.php`). It existed as a central kill switch for plan 16; with it off nothing worked until it was set. `false` still switches control off.
- **How often the app asks a server again:** a menu (timer icon) on the HTTP servers list: 3, 5, 8, 10, 15, 30 seconds, 1 minute; default 30; remembered on the phone (`MonitorInterval`). It drives the lights on the lists and a server's own view while it is open (`ServerViewCubit` now reads again on a timer, quietly; it puts up with two missed reads in a row and ends the connection on the third; nothing is asked while the app is in the background).
- **A permanent saved copy of the Brain's answers** (`core/api/response_cache.dart`, used by `BrainApiClient`): the session routes and the Infra, Apps and Storage consoles are saved on every good answer and served when the Brain cannot be reached, with a banner saying when the copy was saved. Copies are never dropped to make room and survive restarts; only a newer answer replaces one. Money, members, subscriptions and jobs are **not** saved on purpose. Once the Brain is found unreachable, reads go straight to the copy for 30 seconds so the app does not wait on it each time; "Try again" (and every refresh button) ends that at once.
- **Refresh buttons** on the Infra hub, every Infra list and every Infra detail page.
- Tests: `test/features/monitor/interval_and_saved_copy_test.dart` (28). The whole suite: **1777 passed**.
- **Not verified:** none of this has run against a Brain that is actually down, or on a phone. The interval menu only covers the HTTP servers list; a Linode's own screens are read when opened and by their reload buttons.

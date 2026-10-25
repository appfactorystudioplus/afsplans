# 01 — HTTP controllers: completion record

Plan: [`../01-http-controllers.md`](../01-http-controllers.md). Done 2026-10-09 in `w/www`, **not committed**. A tarball of `app`, `routes`, `tests`, `config`, `database` before each round is in the session scratchpad (`backup/www_before_controllers.tar`, `…controllers2.tar`).

## What was done

**64 controllers and traits moved** into `app/Http/Controllers/Externals/<Area>` in two rounds (48 + 16), with the namespaces changed to match; areas: `Analytics`, `Appointments`, `Chat` (with `sse/`), `Concerns`, `DirectPayments`, `Documents`, `Ecommerce`, `Events`, `Feedback`, `Forms`, `Listings`, `MailHandler`, `Notifications`, `Processor`, `SiteUsers`, `Sponsorships`, `Subscribers`, `SystemsUsers`. `Portal/Admin/` keeps six files, all application admin screens; `Brain/*` stays (see the plan).

Same tool as step 00 (names resolved in the old layout, files moved, every full name and path rewritten across the repository). It added no `use` line and rewrote no full name — the routes and tests already used explicit imports. 56 + 19 files had text rewritten; 77 + 15 plan documents and 5 comment/README lines in `resources/js` were updated to the new paths.

## What was checked

- `php -l` on every changed PHP file: no errors.
- Class-name check, before and after, over the application's own PHP: **0 new unresolved names**; PSR-4 unchanged (the same two pre-existing exceptions).
- **`php artisan route:list` before and after, compared entry by entry** (domain, method, URI, name, controller@method, middleware): **identical, 730 routes**, after each round. 349 routes (round 1) and 81 (round 2) now point at `Externals` controllers and nothing else about them changed.
- `composer dump-autoload -o` re-run (the autoloader is classmap-optimised); old paths and namespaces no longer appear anywhere in code, routes, tests, config or docs.
- Tests (unit + the externals feature folders, shared lab test database): 706 tests, 330 errors, 9 failures after round 2 — the same failing set as the run before this step (338 test names), plus one test that fails only inside the full run and **passes on its own** (`AppointmentCommunicationServiceTest::test_a_mail_transport_failure_does_not_roll_back_the_booking`; that class gives different database-state results from run to run). No failure message is a missing class or an undefined controller. The failures are the ones recorded in step 00 (tables left in the shared database, MariaDB identifier length, a PDO driver function, tests of other code).

## Not done / to know

- The whole test suite was not run; routes were checked by table comparison, but no HTTP request was made through the moved controllers.
- Chat controllers moved because the owner asked; there is still no `chat` area under `resources/js/external/api`.
- Deploy: the same queue note as step 00 (a queued job that names a moved class cannot be read afterwards); controllers are not queued, so this step adds no new risk of that kind.

# 00 — Externals namespace: completion record

Plan: [`../00-overview.md`](../00-overview.md). Done 2026-10-09 in `w/www`. **Not committed** (the owner commits when told). A tarball of the affected directories before the change is in the session scratchpad (`backup/www_before_externals.tar`, 250 MB); the domains work was committed first (www `18ee01278`) so this change is a clean diff on top of it.

## What was done

Two rounds, the second added by the owner after seeing the first. **214 files moved; 483 files changed in the working tree** (moves, rewritten references, and the plan documents outside `w/www`).

| Moved | To |
|---|---|
| `app/Appointments`, `DirectPayments`, `Ecommerce`, `Forms` | `app/Externals/<same>` |
| `app/Events/Services`, `app/Events/Support` | `app/Externals/Events/…` |
| `app/Services/Processor` | `app/Externals/Processor` |
| `app/Services/Chat`; `app/Events/ChatMessageSent.php`, `ChatSessionCreated.php` | `app/Externals/Chat`; `app/Externals/Chat/Events/` |
| 68 models `Appointment*`, `DirectPayment*`, `Ecommerce*`, `Event*`, `Form*` | `app/Models/Externals/` |
| `FeedbackSubmission`, `Sponsorship`, `SponsorshipApplication`, `SponsorshipLevel`, `SponsorUser` | `app/Models/Externals/` |
| `app/Models/Processor`, `Chat`, `Listing`, `SiteUsers`, `Subscriber`, `SystemsUsers` | `app/Models/Externals/<same folder>` |

Decisions made on the way (the owner can reverse them):
- The two chat events stayed in `App\Events` in the first round; the owner said chat is an externals API and belongs in `Externals\Chat`, so the chat services, models and events all moved there.
- `app/Events/Services` and `Support` moved but the `App\Events` folder itself is now empty of module code; the old `app/Events/` directory is gone.
- Test namespaces and test folders did not move. Controllers under `app/Http`, jobs, mail, console commands, and `app/SiteUsers` (the services) did not move.

The change was made by a tool, not by hand: every class name was resolved in the old layout, the files were moved, the `namespace` lines changed, every full name was rewritten (in code, strings, config, docblocks, comments, blade, and file paths such as `app/DirectPayments/…`), and the tool would have added a `use` line wherever a same-namespace reference lost its neighbour. **It added none** — every reference in the repository was already an explicit import. About 400 files had text rewritten; the reference copies under `public/playground/externals/direct-payments/server/` were rewritten with the rest.

The old paths and namespaces in **about 105 plan and workspace documents** (`packages/afsserver/plans`, `w/AGENTS.md`, `w/CLAUDE.md`) were updated by the same rules; this plan's own "from → to" table was left alone on purpose. `resources/js/portal/admin/chat/README.md` also named the old chat path and was fixed.

## What was checked

- `php -l` on every changed PHP file: no errors.
- **Every class reference in every PHP file under the application's own code (`app`, `routes`, `tests`, `database`, `config`, `bootstrap`, `resources/views`, `public/playground`) was resolved and looked up before and after.** After the change: **0 new unresolved names**, 0 previously-unresolved names that changed. The unresolved ones that exist in both are old noise (60 files).
- PSR-4: every class under `app/` is in the file its namespace names — except the same two classes that were already out of place before (`MainController` declares `EmailManagerController`; `MailHandlerSender` declares `MailHandlerSenderOptions`).
- A search for the old namespaces and paths over the whole repository found none left (outside the plan table); no class names are built from strings, no code scans the model folders, and there is no morph map, policy or factory for any moved model.
- `composer dump-autoload -o` was re-run (the autoloader is classmap-optimised, so the old paths had to be regenerated); the application boots with 730 routes.
- Tests, with the shared lab test database (`10.0.0.243`):
  - Domains purchase tests (42 tests, 280 assertions) and the domains surface test (13 tests, 136 assertions) **pass**. These were also the first run of the invoice-attach tests, which had been written earlier and not run.
  - The unit tests plus the DirectPayments, Ecommerce, Appointments, Events, Processor, Membership, Connections and SiteUsers feature tests were run: 706 tests, 328 errors and 10 failures. **None of them is a class-resolution error** (no "class not found", "target class does not exist", or undefined-method-on-a-moved-class). They are: 235 "table already exists" and 27 duplicate-key errors from tables the test setup leaves behind in the shared database; 57 "identifier name too long" (MariaDB); 6 `sqliteCreateFunction` (the PDO driver); and ordinary failures in tests of other code (builder page paths, a TypeScript file the test expects, a configuration test).
  - The non-database failures were run again on the **committed (old-layout) tree** by stashing the change and restoring it: the same test classes fail there with the same counts, apart from two classes whose database-state errors differ from run to run (`AppointmentCommunicationServiceTest`, `SiteUsersPagePathsTest`). So the refactor did not introduce them, but those two are **not an exact like-for-like match**.

## Not done / to know

- **Not run:** the whole test suite, and the rest of `tests/Feature/Brain`.
- **Deploy:** drain the queues first. A queued job that names a moved class or carries a moved model (`SerializesModels`) cannot be read after the move. The database was not inspected for stored class names (notifications `type`, `jobs`, `failed_jobs`); the code has no morph relations that would store them.
- Other SDK areas whose PHP is still in the application folders (documents, notifications, analytics, the mail handler) were not asked for and have not moved. The `Externals` folders now hold what the owner listed plus chat.
- `w/` holds ten independent repositories; none of the other nine imports this repository's classes, so none changed.

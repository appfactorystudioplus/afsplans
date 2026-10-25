# 00 — Externals namespace: separate the externals-API code from the application code

Status: **plan, 2026-10-09.** Repository: `w/www` (the Brain). The owner asked for this refactor.

## 1. Why

The externals API is what a developer configures on the www admin dashboard and then reaches from the client-side JavaScript SDK on any website: direct payments, processors, ecommerce, events, appointments and forms. Its PHP has grown into `app/` next to the application's own code (members, Brain, billing, chat, site users, …), which makes both harder to read and makes the next phase — deciding the security constraints for the client-side API — hard to scope, because "which code does the SDK reach" has no folder.

This step moves that code under one name, `Externals`. It changes **no behaviour**.

## 2. What moves

Namespaces follow the folders (PSR-4: `App\` → `app/`). Moved in two rounds on 2026-10-09 (the owner added the second list after seeing the first).

| From | To |
|---|---|
| `app/Appointments` (`App\Appointments`) | `app/Externals/Appointments` |
| `app/DirectPayments` (`App\DirectPayments`) | `app/Externals/DirectPayments` |
| `app/Ecommerce` (`App\Ecommerce`) | `app/Externals/Ecommerce` |
| `app/Events/Services`, `app/Events/Support` (`App\Events\…`) | `app/Externals/Events/Services`, `…/Support` |
| `app/Forms` (`App\Forms`) | `app/Externals/Forms` |
| `app/Services/Processor` (`App\Services\Processor`) | `app/Externals/Processor` |
| Chat: `app/Services/Chat`, and `app/Events/ChatMessageSent.php`, `ChatSessionCreated.php` | `app/Externals/Chat`, `app/Externals/Chat/Events/` |
| `app/Models/Appointment*`, `DirectPayment*`, `Ecommerce*`, `Event*`, `Form*` (68 models) | `app/Models/Externals/` |
| `app/Models/FeedbackSubmission.php`, `Sponsorship.php`, `SponsorshipApplication.php`, `SponsorshipLevel.php`, `SponsorUser.php` | `app/Models/Externals/` |
| `app/Models/Processor`, `Chat`, `Listing`, `SiteUsers`, `Subscriber`, `SystemsUsers` (folders) | `app/Models/Externals/<same folder>` |

214 files in all. **Left where they are:** the application's own code — `app/SiteUsers` (services), the controllers under `app/Http`, jobs, mail, console commands, `app/Models` files and folders not listed above (`Brain`, `Integrations`, `Progress`, `User`, `Membership`, `Notification`, `Document`, …) and `tests/` (test namespaces are `Tests\…`). Other SDK areas that have no PHP in the moved folders (documents, notifications, analytics, mail handler, builder) were not asked for and have not moved.

## 3. How (so it can be checked)

A one-off tool (PhpParser, the version already in `vendor/`) does the whole change in one pass, so nothing is edited by hand:

1. **Resolve every class name in the OLD layout** (what `Foo`, `Sub\Foo` and `\App\X\Foo` mean in each file, from its namespace and `use` lines).
2. **Move the files** and change the `namespace` line.
3. **Rewrite every full name** — in `use` lines, `::class`, type hints, strings (`'App\\Models\\X'`), config, routes, blade, docblocks, comments and file paths (`app/DirectPayments/…`) — across the repository (not `vendor/`, `node_modules/`, `storage/`).
4. **Add the `use` lines that a same-namespace reference used to get for free.** A moved class that mentions an unmoved neighbour by its short name (and the reverse) needs an import in the new layout; the tool adds exactly those, and falls back to a full name where an import would collide.

## 4. How it is checked

- **Before and after comparison of unresolved class names.** Every class reference in every PHP file is resolved with the real namespace rules and looked up (declared in the repository, or autoloadable from `vendor/`). The unresolved set is recorded before; after the change nothing new may be unresolved (the pre-existing ones are old noise, mostly third-party code under `core/` and `scratch/`).
- **PSR-4 check:** every class declared under `app/` sits in the file its namespace names.
- `php -l` on every changed file; `composer dump-autoload`; Laravel boots; the route list and the scheduled-command list are identical before and after.
- Targeted tests for the moved areas, and the test paths that mention directories (`tests/Unit/DirectPayments/LayeringTest.php`, `tests/Feature/Appointments/AppointmentsSecurityTest.php`).

## 5. What can bite

- **Queued work in flight.** A queued job whose payload names a moved class or serialises a moved model (`SerializesModels` stores the class name) cannot be unserialised after the move. **Drain the queues before this is deployed** (and the same for failed jobs the owner wants to retry afterwards).
- **Notification `type` values and any stored class names** in the database. None were found for the moved classes (no morph relations, no morph map, no policies or factories for the moved models), but check the `notifications` and `jobs`/`failed_jobs` tables before deploying.
- **Other repositories.** `w/` is ten independent repositories; none of the others imports this repository's classes (each has its own `App\` namespace). The plans folder and `AGENTS.md` mention the old paths; those are documentation and are updated in a separate pass.
- **Reference copies under `public/playground/externals/direct-payments/server/`** declare the real namespace in their header; they are rewritten with everything else.

## 6. Rollback

A tarball of the affected directories is taken first; the working tree is not committed by this step. Reverting is restoring that tarball (or, once committed, reverting the commit).

## 7. Done when

The checks in §4 pass, the owner has the list of what moved, and `complete/00-overview.complete.md` records the result.

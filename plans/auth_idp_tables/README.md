# auth_idp_tables — Planning Index

> **STATUS (2026-10-10, later the same day): BUILT, NOT COMMITTED, NOT YET DEPLOYED TO THE LAB'S RUNNING AUTH APP.** Steps 01 and 02 are carried out ([complete/](complete/)); step 03 is open (sign-in, redeploy and production checks need the owner). The first line of this note was "audit done, plan (Option A), nothing built".
>
> *Original header:* AUDIT DONE, PLAN (Option A), 2026-10-10. Written from a read of `w/auth` (migrations, `AdminUserService`, `AdminController`, `CreateAdminUser`, seeders, the guard test), `w/members` and `w/www` git history,
> `packages/afsserver/plans/00_ISSUES.md` and `DATABASE-SCHEMA-AUDIT.md`, `lab2` (Vagrantfile, import script, dumps), and read-only queries against the lab database (`10.0.0.243`).

## What this folder is

The IDP admin panel (`https://auth.<host>/admin/login`) keeps its staff accounts in `admin_users` and `admin_sessions`. The code reads them from **Auth's own database** (`DB_*`: `afs_idpdb_test` on the lab and dev, `afsdb_identity_provider` in production).
But since 2026-08-31 Auth's migration creates them in the **shared** Brain/members database (`MAIN_DB_*`), because a naming rule swept every `admin_*` table in with the Checkpoint tables. The panel and its tables are in different databases.

**Decision (owner, 2026-10-10): Option A — the IDP admin tables belong in Auth's own database.** This folder audits the problem and plans that change.

## Reading order

| Step | File | What it settles | Phase |
|---|---|---|---|
| 00 | [00-audit.md](00-audit.md) | Findings **AF1–AF12**: how it got this way, what is true now, what else it affects | — |
| 01 | [01-the-change-in-auth.md](01-the-change-in-auth.md) | The migration, the seeder, the command, the guard test — the code change in `w/auth` | P0 |
| 02 | [02-data-and-dumps.md](02-data-and-dumps.md) | Moving existing admin accounts, the lab dumps and import, the documents that state the old rule | P1 |
| 03 | [03-rollout-and-verification.md](03-rollout-and-verification.md) | Order per environment, the production checks, the acceptance run, rollback | P0/P1 |
| 04 | [04-decisions-and-open-questions.md](04-decisions-and-open-questions.md) | What the owner decides (**OD1–OD5**) | — |

## Vocabulary

A **step** is a file; a **phase** is the work inside it: **P0** (needed for the IDP login to work on the lab), **P1** (before production), **P2** (cleanup). Findings are **AF<n>**, decisions **OD<n>**; these are this folder's own numbers.
Terms: **IDP** = the Auth app's staff panel; **Checkpoint** = Auth's identity layer for members and apps (`checkpoint_*`); **shared database** = `testdb_appfactorystudio` (lab) / `afsdb_appfactorystudio` (production), the Brain/members database.

## Code tagging and completion documents

Changed code carries `auth_idp_tables` and `AFS_MODIFIER_ADDITION — plans/auth_idp_tables/<file> §<n>`. A completed step gets `complete/<NN>-<same name as the plan>.complete.md`; off-plan fixes go in `complete/00_extras.md` as dated `E*` sections.

# Account close — Planning Index

**Status: PLAN ONLY. Nothing here has been built.** Written 2026-09-24 against
`DEVELOPMENT_MODE = vagrant`. Every claim about current code cites a file and line; where the
code or docs could not be verified it is listed in [00 §8 Open questions](00-overview.md).

Deleting a member, in three forms, across four repos:

1. **Soft delete** (Brain admin) — the member can no longer log in anywhere; every byte of data
   stays; their email and username stay unavailable to new signups.
2. **Hard delete** (Brain admin) — the member is removed from `brain_memberships`, the Checkpoint
   identity tables (`ext_member_users` and its family — there is no `ext_members` table), and
   every other store that belongs to them, tenant websites and files included.
3. **Self-service close** (members dashboard) — a *progressive* soft delete: a grace period in
   which the member can still change their mind, then login is blocked, then the data is purged.

| Step | Document | Contents |
|------|----------|----------|
| 00 | [Overview](00-overview.md) | Problem, goals, glossary, the decisions (D1–D18), repository map, open questions |
| 01 | [Current-state audit](01-current-state-audit.md) | What exists today: findings AC1–AC24, each with file and line |
| 02 | [State model and enforcement](02-state-model-and-enforcement.md) | `account_status` values, the lifecycle, the login chokepoints, revocation, identifier reservation |
| 03 | [Database schema and purge inventory](03-database-schema.md) | New columns and tables, and the per-store inventory a purge must cover |
| 04 | [Brain admin: soft and hard delete](04-admin-soft-and-hard-delete.md) | Endpoints, the danger-zone UI, the dry-run preview, the confirmation flow |
| 05 | [The purge engine](05-purge-engine.md) | Ordered, resumable, idempotent erasure; per-app erasers; the shell commands that do not exist yet |
| 06 | [Member self-service closure](06-member-self-service-closure.md) | The members-dashboard flow, re-authentication, the grace ladder, cancelling a closure |
| 07 | [Notifications and scheduling](07-notifications-and-scheduling.md) | The sweep command, the notice ladder through the mail-handler system, config |
| 08 | [Security and safety](08-security-and-safety.md) | Authorization, protected accounts, holds, blockers, shell-injection rules, races |
| 09 | [Rollout and testing](09-rollout-and-testing.md) | Phases P0–P4, the test matrix per repo, acceptance criteria A1–A34 |

## Why this is its own folder

It is the first plan to **own `account_status = 3`**, which two existing plans list as unmodelled
and unimplemented: `membership_change/00-overview.md` OQ-E (line 197), `membership_change/
08-member-management.md` MM11 (line 113), and `TODO-member-resources.md` B7. When this plan is
carried out, those three entries should be closed by cross-link to this folder — they are not
edited by this plan.

It also touches four codebases at once (`www`, `auth`, `members`, plus the five satellite apps and
`market`), and the AGENTS.md rule stands: a change spanning apps is several commits in several
repos, each needing its own approval. [00 §7](00-overview.md) is the map of which repo gets what.

## Completion documents

None yet. When a phase is carried out, its completion document goes in `complete/` named
`<basename>.complete.md`, stating what was actually built, the files changed, and the verification
run — and, per this repo's convention, that document supersedes the plan for questions about
current behaviour.

## Code documentation

Code written from this plan carries a marker comment `AFS_ACCOUNT_CLOSE_MODIFIER_ADDITION —
plans/account_close/<file> §<section>`, alongside (never replacing) any existing marker
(`plans/markers.md`). Add the marker to `markers.md` when the first phase lands.

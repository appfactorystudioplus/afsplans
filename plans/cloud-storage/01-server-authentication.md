# 01 — Server-side authentication

> ## ⚠ This step moved
>
> Server-side authentication turned out to be a **platform-wide** problem, not a storage
> one. Five of the seven AFS apps were already fixed; members was the outlier, and the
> same investigation surfaced two divergent auth implementations, seven copies of one
> validator, and gaps in the desktop and mobile clients.
>
> **The work formerly specified here now lives in
> [../checkpoint-server-auth/](../checkpoint-server-auth/README.md).**
>
> That plan supersedes this document entirely. This file remains only so that references
> to "cloud-storage step 01" resolve, and to record what storage specifically depends on.

## Why it moved

This document originally specified a members-only fix, as a prerequisite for storage.
Checking whether the sibling apps shared the bug produced the opposite answer — coordinator,
notes, dream_graph, pdf_editor and flow had already been fixed in August 2026 under
[account-and-cloud-sync](../afs-connect-desktop/account-and-cloud-sync/) Phases S0.5 and
S0.5g, and rather better than the members pattern.

Keeping a members-only description here alongside a platform-wide one in the other folder
would guarantee the two drifted. One description, in one place.

## Where each part went

| Formerly in this document | Now |
|---|---|
| The finding: members' unauthenticated routes | [checkpoint-server-auth/01-platform-audit.md](../checkpoint-server-auth/01-platform-audit.md) §3.1 |
| How Checkpoint auth works; the four `apps/auth` traps | [01-platform-audit.md](../checkpoint-server-auth/01-platform-audit.md) §1 |
| Decision: call auth vs verify JWTs locally | [00-overview.md](../checkpoint-server-auth/00-overview.md) D2 |
| Route restructure, trait, deleting client-supplied identity | [03-members-remediation.md](../checkpoint-server-auth/03-members-remediation.md) |
| Secondary users and storage scope | [02-two-patterns.md](../checkpoint-server-auth/02-two-patterns.md) §4; desktop/mobile in steps 07–08 |
| Long uploads vs 1-hour tokens | §2 below — storage-specific, kept here |
| `JWT_SECRET` question | [05-open-exposures.md](../checkpoint-server-auth/05-open-exposures.md) §5 |

---

## 1. What storage depends on

Storage is a **consumer** of the identity chain. It does not implement any part of it.
These are the guarantees the rest of this plan is written against:

| Guarantee | Source |
|---|---|
| Every storage route sits inside the authenticated group | [checkpoint-server-auth/03](../checkpoint-server-auth/03-members-remediation.md) §2 |
| `checkpoint_member_reference_num` is set from a verified token, never from the request | [02](../checkpoint-server-auth/02-two-patterns.md) §4 |
| `checkpoint_member_plan` (0–4, clamped, missing ⇒ 0) is set the same way | [02](../checkpoint-server-auth/02-two-patterns.md) §4.2 |
| A valid token with no membership record is rejected at the middleware | [01](../checkpoint-server-auth/01-platform-audit.md) §1.1 P2 |
| Auth unreachable ⇒ fail closed | [01](../checkpoint-server-auth/01-platform-audit.md) §5 |
| Secondary users resolve to the primary member's reference num | [01](../checkpoint-server-auth/01-platform-audit.md) §1.1 P3 |

**Attribute naming note.** Earlier drafts of the storage documents referred to
`member_reference_num` and `member_plan`, matching members' old `checkpoint.verify`
pattern. The platform is converging on the `checkpoint_*` prefix
([02](../checkpoint-server-auth/02-two-patterns.md) §4), so storage code reads
`checkpoint_member_reference_num` and `checkpoint_member_plan`. Where a storage document
still shows the unprefixed name, the prefixed one is correct.

## 2. Storage-specific: long uploads versus one-hour tokens

This is the one piece of the original document that is genuinely about storage, so it
stays here rather than moving.

A Checkpoint access token lives 3600 s. A 50 GB upload does not fit in that.

| Concern | Design |
|---|---|
| Token expires mid-upload | Chunk `PUT` returns `401 TOKEN_EXPIRED` — distinguished from a generic 401 so the client refreshes instead of failing the job. The job goes to **paused**, not failed |
| Resuming after refresh | The upload session is bound to `member_reference_num`, **never to a token**. A refreshed token for the same member resumes the same session |
| Refresh | Clients refresh proactively at ~80 % of token life; the 30-day refresh token covers any realistic upload |
| Session ownership | `storage_sessions.member_reference_num` is re-checked on every chunk. Another member's valid token gets **404** on that session id — not 403, which would confirm it exists |
| Desktop / mobile background | Token refresh runs in the desktop main process and the mobile repository layer, not in view code, so a backgrounded UI does not stall refresh |

**The rule: an upload session is owned by a member, never by a token.** That is what lets a
six-hour upload survive six token rotations.

### 2.1 Secondary users and storage

Because auth resolves a secondary user's membership to the **super user's**, a secondary
user's storage scope is the primary member's buckets. Sub-accounts of one business share
that business's storage, which is what the plan tiers are sold against.

Stated explicitly because it means a secondary user can read, overwrite, and delete the
primary member's files with no per-user scoping. `checkpoint_user_id` and
`checkpoint_user_type` are recorded in `storage_nodes` audit columns and in every audit log
line ([12-security.md](12-security.md) §9), so *who* acted is answerable even though *what
they may reach* is not restricted.

Per-secondary-user permissions are out of scope; the audit columns exist so adding them
later is not a data migration.

**Note for the desktop and mobile clients:** neither may sign a secondary user in at all —
[checkpoint-server-auth/07](../checkpoint-server-auth/07-desktop-client-auth.md) and
[08](../checkpoint-server-auth/08-mobile-client-auth.md). So in practice the shared-scope
case above arises only through the web client.

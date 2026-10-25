# Complete — Plan rewrite (prerequisite to execution)

**Date:** 2026-08-31
**Scope:** every document in `alloc-v2-parse-direct/`

## Why the plan was rewritten before it was executed

The folder specified a class called `MemberRegistryCounter` and a `MemberRegistry` anchor
object that kept a readable `used` for display. Neither described what exists. The counter
class had been built as **`MemberResource`**, and `MemberRegistry` had been **deleted
outright** the day before — class, objects, and the `brain_memberships.parse_alloc` column
that addressed it (`../00_ISSUES.md` **I7**).

Executing the plan as written would have produced a second counter class alongside the live
one, and declared a class that no longer exists.

## What changed, and what was checked rather than assumed

| Plan said | Verified reality | Where recorded |
|---|---|---|
| Create `MemberRegistryCounter` | `MemberResource`, live, 62 rows | 03 §3.2.2 |
| `period` is part of the row identity | `period_start` is a **field**; rollover computed on read | 03 §3.2.3 |
| A unique index arbitrates row creation | **No index exists**; class undeclared | 03 §3.2.4 |
| `MemberRegistry` keeps a display projection | Class deleted | 03 §3.9 |
| Counter class can be fully closed | **Clients read the rows** — closing `find` breaks every read | 03 §3.3.1, §3.3.2 |
| `CheckpointAlloc_Log` "deferred, not rejected" | It exists; `alloc_v2` writes it client-side | 03 §3.3 |
| Two writers must move before the lock | **Three** write paths; the master-key ones are unaffected | 03 §3.6 |

The live Parse schema was read directly rather than inferred:

```
MemberResource        public: ACL, addField, count, create, delete, find, get, update
CheckpointAlloc_Log   public: ACL, count, create, delete, find, get, update
Registry              public: count, create, delete, find, get, update
```

`cloud/functions.ts` was confirmed to be the stock scaffold (`hello`, `helloAsyncFunction`,
`beforeSave('TestObject')`), and `cloud/schema.ts` confirmed imported by nothing — both
exactly as the plan's status table claimed.

## The one design difference that mattered

The previous draft made `period` part of the row identity, so a new month was a new row at
zero and rollover logic disappeared. **The built model did not do that**: `period_start` is a
field on one permanent row, and a rolled-over counter is *set* rather than incremented.

Both are defensible. The built one is live in four implementations, so the plan was changed
to match the code rather than the other way round, and `AllocationGateway::increment()` in
`auth` is named as the reference implementation for the Cloud Function to copy.

Getting this wrong would leave a monthly counter either never resetting or resetting on every
call — and neither failure is visible until a month boundary.

## Files

| File | Change |
|---|---|
| `03-parse-cloud-functions.md` | Rewritten. 737 → 644 lines. New §3.3.2 (the read exposure), §3.3.3 (the dead `Registry` class), §3.8.1 (deployment is the constraint), §3.9 (the delta) |
| `README.md` | Rewritten status table and order of work; `parseObjectId` removed from the documented config |
| `01`, `02`, `04`, `05`, `06` | Status banners: what each still describes correctly, and what it does not |

## What was deliberately left alone

`01` §1.1–§1.2 and `05` §5.10–§5.11 are historical records of what was true when the audit was
taken and what was built under the old scope. They are bannered, not rewritten — a plan
folder that silently edits its own history is worth less than one that shows its working.

# Complete — Step 7: drop the `Registry` class — STOPPED

**Date:** 2026-08-31
**Plan:** `03-parse-cloud-functions.md` §3.3.3, §3.8 step 7

> ⛔ **Not done, and should not be forced.** The plan's own instruction is *"verify empty
> first — dropping a class is one-way."* That check is why this step stopped.

## What `Registry` is

The pre-v2 allocation scheme, declared in `config.ts` since before the nested v3 document:

```
name, domains, domain_email_accounts, domain_processors, domain_forms,
domain_subscribers, domain_events, domain_ecommerce, domain_sponsorships, sync_storage
```

A counter there was a **map keyed by SystemUser**, not a row and not a nested resource entry.
Its last consumers were the `MemberRegistryManager` layer in all eight `registry.ts` files —
`GetMemberRegistry`, `RegistryUpdate`, `RegistryCheck`, `SaveRegistry` and a
`createParseAllocation` that wrote `name: "Test Allocation"` — deleted on 2026-08-31
(`../00_ISSUES.md` **I7**). Nothing has read or written it since.

## Why it stopped

```
Registry  count: 2

  {"objectId":"zZuBPcCtBV", "domain_processors":{"default":0}, ...}
  {"objectId":"dtsWSIuvsz", "domain_processors":{"count":5,"appfactorystudio":9}, ...}
```

**It is not empty.** The second object holds what looks like real usage from that scheme —
`appfactorystudio` with 9 processors against a count of 5.

Those numbers are almost certainly stale: they predate the v3 nested document, let alone the
row model, and the live `MemberResource` rows are the authority now. But *"almost certainly"*
is not the standard for a one-way delete, and the data is small enough that nobody has to
guess.

## What was done instead

- **`Registry` removed from `config.ts`'s declaration**, so nothing re-asserts its schema on
  the next deploy.
- **The class and its two objects left on the server.** `deleteExtraFields: false` means
  removing the declaration does not drop the collection — which is precisely the intended
  order: stop declaring it, confirm it is unread, then drop it.

The class also still carries public permissions (`count, create, delete, find, get, update`).
That is unchanged and not worth closing on a class scheduled for deletion; it is listed here so
the next reader knows it was seen rather than missed.

## What is left for a human

Read the two objects and decide. Concretely:

1. Does `{"count":5,"appfactorystudio":9}` correspond to anything real — is there a member
   whose processor usage should have been carried forward, and was it?
2. If not, drop the class:

   ```
   DELETE {PARSE_SERVER_URL}/parse/schemas/Registry
   ```

   after deleting its objects — parse-server refuses to drop a non-empty class, which is a
   second safety net on top of this one.

Until that decision is made, the class costs nothing except a line in a schema listing.

## Why this is recorded as a completion rather than left silent

A step that stops is still an outcome, and the reason it stopped is the useful part. If this
were simply absent from `complete/`, the next person would either redo the check or — worse —
assume it had been done and drop a class holding data nobody had looked at.

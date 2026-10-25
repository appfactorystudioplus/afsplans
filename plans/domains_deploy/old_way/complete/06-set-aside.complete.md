# 06-set-aside — completion record

**Plan file:** [`../06-set-aside.md`](../06-set-aside.md)
**Date:** 2026-10-22
**Status:** ✅ Nothing to build: this step is the list of what stays unbuilt. Closed by checking, item by item, that nothing on it was built by accident, and by recording the two places where an item came back by the owner's own request.

## State of each item against its "comes back if" condition (checked 2026-10-22)

| Item | Condition to come back | State |
|---|---|---|
| Claim-gated entry (`build/begin`, `build/finish`, the claim reference) | The owner wants the old build tied to the claims table | **Still set aside.** `DomainController` has no use of claims; `createWebsite` takes a domain name and, since step 02/03, builds only on a zone the member has on the Brain and only one site per domain at a time. It is the gate step 05 §5.2 requires before member-domain certificates. |
| New identifiers (`u<site_ref>`, sites name equal to the engine's) | The DNS engine runs against the same zones as the old DNS path | **Still set aside.** The engine is not scheduled: the seven `domains:*` commands are refused (`members/routes/console.php` schedules only the two storage commands). |
| The Brain as the only DNS writer | The DNS engine is deployed to production | **Still set aside.** Dev check 2026-10-22: the old path is the only writer of `appfactory.studio.include.zone` (`$ORIGIN sites.appfactory.studio.`, appended `<user> IN A <ip>`, no serial bump). |
| Server reservation and the Brain's selector | More than one tenant host in service | **Came back, by the owner's request, in plan `create_member_redo/11` (2026-10-15).** `BuildServerSelector` (up, under its limit, not draining, nearest by straight line) is part of `build/data`. It reserves nothing: two builds started together can pick the same server. Dev has one eligible `Head` server (`45.79.42.137`). |
| A lifecycle for old-built sites (status, disable, delete, retry, cancel) | The new way's runner is disabled for deploys, or the actions are used on old-built sites | **Still set aside, and now visible as a gap.** Step 03 marks a failed build's website `active = 0` and refuses a second build on a domain while one is live or building, but there is no way to delete, retry or clean up: the screen's *Delete website* only flips `has_website` / `site_enabled` on the zone and removes nothing on the server. A failed or abandoned build leaves its Linux user, database, Git repository and directories (step 03 report, "Not verified"). |
| Calls signed per build; `build/begin`/`finish`; a build record on the Brain | Same as claim-gated entry | **Still set aside.** The per-build token (a different thing: the progress token in the job) is built. |
| Template derives its URLs from the request host (ends `www` / `www-own`) | The owner decides; a template change with its own plan | **Still set aside.** `www` / `www-own` are built as before. |
| Redesigning the old domain screens' Brain calls | A member must use them against a Brain with the level-99 gate | **Came back, by necessity, in plan 11.** `store`, `update`, `destroy`, `search`, `purchase` and the zone reads now go through the signed `zones/*` and `registrar/credentials` routes, with the original unauthenticated calls as a fallback only for a Brain that lacks the routes. Which screens a member sees is decided per member by the Brain's `new_flow` setting (`DomainFlowController`); the new flow does not use these routes. |

The new way's Brain code (`DeployRunner` and `afs-tenant-agent` in `../../new_way/`; the domain surface and the DNS engine in `../../domain_name_management/`) stays in the repository and is not used for deploys.

## Added to the list today

Things found by the audit that belong with the set-aside items because their fix is the same set-aside work (claims, a lifecycle) and not a robustness patch:

* Domain ownership is not checked in the old flow: `store(own=1)` records any name as the member's, and two members can both hold a zone and a website for one name (the later vhost loses the `server_name` race on a shared host).
* `purchase` charges nothing: `POST /api/v1/domains/purchase` buys on the platform's registrar account without checking a payment. The payment-verified purchase is in the new flow only.
* Retry and removal tooling for old-built sites (the lifecycle row above).

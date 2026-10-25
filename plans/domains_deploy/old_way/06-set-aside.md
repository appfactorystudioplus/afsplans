# 06 — Set aside

The first draft of this plan (2026-10-09, kept in the session scratchpad only) proposed more than the owner wants: the old way works and should be made more robust, not redesigned. These items were **removed from the plan**. Each is listed with why it came up and the condition under which it would come back, so nothing is lost and nothing is built by accident.

| Item | Why it came up | Comes back if |
|---|---|---|
| **Claim-gated entry** — `createWebsite` takes a claim reference, a Brain `build/begin` checks "active and proven" and reserves a server, `build/finish` records the result | the claims work (`domain_name_management` 02–03) answers "is this domain really this member's?" and prevents two members building for one name | the owner wants the old build to be tied to the claims table. It is already the gate required before member-domain certificates ([05](05-certificates.md) §5.2) |
| **New identifiers** — `u<site_ref>`, the sites name equal to the one the Brain publishes at claim activation | would make the tenant's sites name match the entry the DNS engine creates | the DNS engine is run against the same zones as the old DNS path (OF17) |
| **The Brain as the only DNS writer** — drop `step2` and `build_dns`, write the tenant row `state = 'active'` so `DomainDnsSync::siteAddress()` publishes | two writers on one zone lose records (OF17) | the DNS engine is deployed to production. Note for then: it reads `brain_http_server_tenants` where `active_claim = <claim>` and `state = 'active'` (`DomainDnsSync.php:155–160`) |
| **A server reservation and the Brain's selector** | `selectTenantServer()` takes the first active `Head` server (no capacity, region or health) | more than one tenant host is in service |
| **A lifecycle for old-built sites** — status, disable, enable, delete, retry, cancel | the screens' website actions call the new way's runner, which cannot act on a site built the old way | the new way's runner is disabled for deploys, or the actions are used on old-built sites. Today the old flow has its own removal scripts (`delete_member.expect`); not audited here |
| **Calls signed per build, `build/begin`/`finish`, a build record on the Brain** | one place for the checks | same as claim-gated entry |
| **Making the template derive its URLs from the request host** (ending `www` / `www-own`) | the long-term fix for the asset problem the owner saw with `SetEnv APP_URL` | the owner decides; a template change with its own plan |
| **Redesigning the old domain screens' Brain calls** (`store`, `update`, `destroy`, `search`, `purchase`) | they make the same unauthenticated calls and are broken the same way (OF1) | a member must use the old domain screens against a Brain with the level-99 gate. Step 02's two routes would need siblings |

The new way's Brain code (`DeployRunner` and `afs-tenant-agent` in `../new_way/`; the domain surface and the DNS engine in `../domain_name_management/`) stays in the repository, unused for deploys.

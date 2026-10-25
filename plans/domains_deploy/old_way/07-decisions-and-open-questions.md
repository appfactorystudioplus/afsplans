# 07 — Decisions and open questions

Numbers are this folder's own (OD), not the D numbers of `new_way` or `domain_name_management`. "Needed before" says which step waits.

| # | Question | Options | Recommendation | Needed before |
|---|---|---|---|---|
| **OD1** | How does `getTenantData` reach the Brain now that the Brain asks for a login? | (A) two signed surface routes returning the same data, with a fallback to the old calls when the Brain lacks them; (B) a service credential for the level-99 routes; (C) members reads the Brain database | **A**. B hands the members app `/config` (every Brain credential) and re-creates the "service identity at a user level" the Brain removed on 2026-08-29; C breaks "the Brain owns its tables". The fallback keeps production working during the release window | 02 |
| **OD2** | The value fixes in [03](03-robustness.md) §3.5: V1 (`reference_num` = the Brain's), V2 (`CreatedBy`), V3 (`owner`), V4 (one generated `db_password`), V5 (literals from the Brain data), and the failed-build website status (§3.3) | do all; do V1, V2, V4 only; none | **V1, V2, V4 and the failed status now** (they are defects); V3 after the template reader check; V5 in P1. The owner confirms each, since the tenant may read them | 03 |
| **OD3** | What is `SUBDOMAIN_PROPERTIES_SUBDOMAIN` on the lab (`www15` today, a folder that does not exist), and which host label does the member's address use? | `www` on the lab (the Brain has rows for both `www` and `www15`); keep `www15` and create `websites/dev/members15`; change the code | **`www`** on the lab, with `SUBPATH` matching the folder that exists — config only. Reading how the template uses the key (`WwwAfsConnectTenantFromRequest` and the other readers) is a task before changing any value in production | 04 |
| **OD4** | How is the wildcard issued and renewed? | (A) the Brain runs the ACME client with a DNS-01 hook through its DNS path; (B) RFC 2136 dynamic update | **A**: a live edit of a zone file is overwritten by the next publish; B needs the sites zone delegated as a zone of its own | 05 P1 |
| **OD5** | The setuid wrapper `afs-website` (compiled with `g++` on every tenant host and used on the members host) | make it work on the lab (compile, root-owned, setuid, local filesystem); replace it by a `sudoers` entry for exactly `commands.sh`; run the pipeline as a user that needs no privilege | decide after the first lab run shows whether the shared folder blocks setuid; do not change production's | 04 |
| **OD6** | `npm ci` or `npm install` for tenants (`plans/TODOS.md`) | ci with the lock file; install | **`npm ci`** once the lock files travel with the template | 04 |
| **OD7** | The Gitea password: keep typing it through `expect` for the push, or a one-shot token header? | keep (minus the log line); header | keep for now; revisit after the first lab run | 03 P1 |

## Questions only the owner can answer

1. **Which Brain release does production run?** Specifically: does it already have the level-99 gate on `config`, `dns/*`, `http/*` (commit `d6e621bed`, 2026-10-06 in the dev tree)? If not, the old flow works there today and step 02 is what keeps it working after the release.
2. How is a member's own-domain certificate obtained in production today, if the script's call is commented out?
3. Where will the production wildcard live on a tenant host, and who issues it?
4. Does production's members tree run the setuid wrapper, and from where?
5. How will Checkpoint learn a tenant's custom domain under `enforce`? (A dependency of production sign-in, not part of this plan: the build could register the host, or Checkpoint could ask the Brain, or Checkpoint stays on `report`.)

## What would change this plan

- Answer 1: if production is not yet gated, step 02 can wait until the Brain release, but the lab still needs it now.
- A real domain for the certificate test ([05](05-certificates.md) §5.3): until one is available, production issuance stays unverified.

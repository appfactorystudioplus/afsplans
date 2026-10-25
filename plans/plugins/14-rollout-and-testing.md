# 14 — Rollout and testing

## Completion document

When this plan step is completed, write `complete/14-rollout-and-testing.complete.md`.

---

## 1. Dependency graph

```
00 (overview) ─▶ 01 (audit) ─▶ 02 (architecture)
                                     │
                    ┌────────────────┼────────────────┐
                    ▼                ▼                 ▼
              03 (schema)     04 (manifest)      05 (security) ◀── the gate everything else waits on
                    │                │                 │
                    └───────┬────────┴────────┬────────┘
                             ▼                 ▼
                    06 (extension points)  07 (lifecycle)
                             │                 │
                    ┌────────┴────────┐        │
                    ▼                 ▼        ▼
             08 (frontend)     10 (review pipeline)
                    │                 │
                    └────────┬────────┘
                             ▼
                    09 (admin UX)
                             │
                    ┌────────┴────────┐
                    ▼                 ▼
        11 (permissions)      13 (observability)
                    │                 │
                    └────────┬────────┘
                             ▼
                     14 (this document — §2 dogfood, §3 acceptance)
                             │
                             ▼
                  12 (billing) — later, independent track
```

**05 is load-bearing for everything downstream of it**, not just architecturally but as a
sequencing rule: no step that lets a plugin actually execute (07, 08, 09) should be built ahead of
05's P0–P2 phases landing, even if it would be faster to build the UI first and "add security
later" — that ordering is exactly how the WordPress ecosystem's own well-known problem happened at
scale, and this plan exists specifically to not repeat it.

## 2. Dogfooding before any external developer sees the marketplace

Per [00-overview.md](00-overview.md) §7's scope decision, the team builds 2–3 first-party
"reference" plugins through the actual submission pipeline (10) before inviting any outside
developer:

1. **A deliberately simple plugin** — e.g. a dashboard widget showing a static, self-generated
   stat, using `plugin_data.read`/`write` and the `dashboard.widget` slot only. Proves the
   happy path: submit → review → install → render, end to end.
2. **[17 — the minimum order guard plugin](17-example-plugin-order-guard.md)**, reacting to
   `order.completed` (notify) and participating in `checkout.validate` (filter,
   [06 §1](06-extension-points-and-hooks.md)). Proves the Plugin Runtime dispatcher's
   synchronous/asynchronous split ([02 §6](02-architecture-overview.md)) actually behaves as
   designed under a real invocation, not just a unit test's mock.
3. **[16 — the cookie-consent plugin](16-example-plugin-cookie-consent.md)**, exercising the
   surface the first two don't: the [public site slot](06-extension-points-and-hooks.md) §2.1,
   `plugin_data.increment`, and the [public, unauthenticated invocation path](05-security-and-sandboxing.md)
   §12. Proves an anonymous site visitor can trigger a plugin capability without ever touching
   anything beyond what `reachable_from_public_site` allows, and that the same iframe/CSP
   machinery §2's plugins use inside the admin dashboard holds up embedded in a real published
   page instead.
4. **A deliberately hostile plugin**, built by the team for testing only, never published to the
   marketplace catalog — attempts every row in
   [05 §8](05-security-and-sandboxing.md)'s "cannot" column: reading another tenant's data by
   guessing a `reference_num`, calling an SDK method for a capability it didn't declare, writing
   to a `plugin_data` collection it didn't declare, attempting `http.egress` to a non-allowlisted
   host, attempting `http.egress` to an allowlisted hostname that resolves to a private/link-local
   address (the DNS-rebinding case, [05 §6.1](05-security-and-sandboxing.md)), attempting to
   exceed its resource limits, attempting to read the admin dashboard's own cookies from inside
   its iframe, attempting to exfiltrate data it legitimately holds through an `<img>`/CSS resource
   load rather than `fetch` (the CSP-completeness case,
   [08 §3](08-frontend-runtime-and-dynamic-loading.md)), and — run as a **second, distinct**
   hostile iframe alongside a legitimate one — attempting to `postMessage` a capability call
   shaped as if it came from the legitimate plugin's install
   ([08 §4](08-frontend-runtime-and-dynamic-loading.md)'s origin/source check). **Every one of
   these must fail**, and this plugin is the basis of the automated regression suite that runs
   against every future change to
   [05](05-security-and-sandboxing.md) or [08](08-frontend-runtime-and-dynamic-loading.md) —
   the same role the "traversal corpus" plays for
   [cloud-storage](../cloud-storage/12-security.md) and
   [storage-sharding](../storage-sharding/08-security.md).

## 3. Acceptance criteria for the first delivery

| # | Criterion |
|---|---|
| A1 | A developer can register, submit a plugin, and see it through automated + manual review to a published state entirely through the submission flow ([10](10-review-and-publishing-pipeline.md)), with no manual database intervention by the platform team |
| A2 | A tenant can browse the marketplace, see an itemized, accurate capability list before installing, install, and have the plugin's nav item and settings screen appear with no admin bundle rebuild |
| A3 | The hostile reference plugin (§2.4) fails every one of its attempts, verified by an automated test suite that runs on every change to the security-relevant steps (05, 08, 10) |
| A4 | Revoking a published release disables it for every tenant that has it installed within the time bound stated in [05 §7](05-security-and-sandboxing.md), with no tenant action required |
| A5 | A tenant can revoke a single capability grant from an active plugin and see the corresponding SDK call start failing for that plugin immediately, without uninstalling it |
| A6 | The circuit breaker ([13 §2](13-observability-and-incident-response.md)) auto-disables an install exhibiting the injected failure patterns in the stated test, with the correct `disabled_reason` |
| A7 | No `plugin_*` table's migration required editing a `laravel_*` or `brain_*` table, and every `plugin_*` migration passes the existing `MigrationOwnershipTest` guardrail ([AGENTS.md §5](../../../w/AGENTS.md)) |
| A8 | The incident-response drill ([13 §5](13-observability-and-incident-response.md)) completes within its own recorded baseline time, exercised at least once before any external developer is invited |
| A9 | A capability callback from the Plugin Runtime presenting a missing, expired, or reused-nonce invocation token is rejected regardless of what tenant/install context it claims ([05 §10](05-security-and-sandboxing.md)) |
| A10 | The egress proxy refuses a connection whose resolved address falls in a private/link-local/metadata range even when the requested hostname is on the approved allowlist, and does not follow a redirect off an allowlisted host to a non-allowlisted target ([05 §6.1](05-security-and-sandboxing.md)) |
| A11 | A plugin iframe cannot exfiltrate data through an `<img>`/CSS resource load, and one plugin's iframe cannot have its capability call honored under a different plugin's grants ([08 §3–§4](08-frontend-runtime-and-dynamic-loading.md)) |
| A12 | A developer suspended for cause who re-registers under a new account is routed to mandatory manual review on first submission, not the normal automated-first path ([03 §1](03-database-schema.md), [10 §2](10-review-and-publishing-pipeline.md)) |
| A13 | The signing service refuses to sign an artifact digest for which no valid reviewer approval assertion exists, even when the request comes from an authenticated review-pipeline call ([05 §11](05-security-and-sandboxing.md)) |
| A14 | A release's signature verifies against the public key its own `signing_key_id` names, and a release signed under a key that was later rotated (routinely, not for compromise) still verifies correctly with no re-signing required ([03 §1](03-database-schema.md), [05 §11](05-security-and-sandboxing.md)) |
| A15 | A Plugin Runtime instance refuses a dispatch it cannot verify as signed by Core Platform, and the Core Platform↔Plugin Runtime transport is encrypted in both directions ([05 §10.1](05-security-and-sandboxing.md)) |

## 4. Test matrix

| Layer | What's tested | How |
|---|---|---|
| Static analysis (10 §3) | Every forbidden PHP/TS construct listed is caught | A fixture corpus of submissions, one per forbidden pattern, each expected to fail automated intake |
| Plugin Runtime isolation (05 §3) | No shared-DB credential, no filesystem escape, no network egress beyond the allowlist | The hostile reference plugin (§2.4), run against the real runtime, not a mock |
| Invocation credentials (05 §10) | A callback cannot assert a tenant/install context other than the one its token was issued for | Inject a callback with a tampered `install_id`/`tenant_reference_num` alongside a validly-signed token issued for a different install; the mismatch is rejected |
| Dispatch authentication (05 §10.1) | A Runtime instance refuses an unsigned or wrongly-signed dispatch | Submit a dispatch RPC directly to a Runtime instance, bypassing Core Platform, without a valid signature; assert refusal and no plugin code execution |
| Signing authorization (05 §11) | The signing service refuses a request with no matching reviewer approval assertion | Call the signing endpoint with an authenticated pipeline identity but a digest no reviewer approved (or a stale/consumed assertion); assert refusal |
| Key rotation (05 §11) | A routine rotation does not break verification of previously-signed releases | Rotate the signing key in a test environment; assert every previously-published release's signature still verifies against its recorded `signing_key_id` with no re-signing step run |
| Egress proxy / SSRF (05 §6.1) | Resolved-IP validation, no redirect-following across the boundary | A test DNS entry resolving an allowlisted hostname to a private-range address, and an allowlisted host configured to redirect to an internal target |
| Capability SDK (05 §4) | Every SDK method enforces tenant and install scoping | Unit tests per method, asserting a call scoped to tenant A never returns tenant B's data even when given tenant B's `reference_num` |
| Frontend sandbox (08 §3–§4) | No cookie/`localStorage`/parent-DOM access from inside the iframe; no `img`/CSS resource-load exfiltration path around the CSP; the postMessage relay rejects a message not from the exact iframe instance it expects | A manual adversarial pass plus an automated browser-level assertion, run in CI if the harness supports a real browser context; two hostile iframes in the same session, one attempting to spoof the other's `install_id` |
| Anti-sybil (03 §1, 10 §2) | A banned identity fingerprint re-registering is routed to mandatory manual review | Suspend a test developer for cause, re-register with the same fingerprint under a new email, assert the new account's first submission does not clear automated-only |
| Lifecycle state machine (07 §2) | Every disallowed transition is rejected | Unit tests enumerating the full transition table |
| Permissions (11) | A `use`-only portal user cannot reach manage-level controls; the static `portal_permissions.php` behavior is provably unchanged | Regression tests against the existing fifteen static keys, plus new tests for the dynamic layer |
| End-to-end | Full submit → review → publish → install → consent → use → audit-log → uninstall walkthrough | One scripted end-to-end test per reference plugin (§2.1, §2.2, §2.3), run against a real environment per the current [DEVELOPMENT_MODE.md](../DEVELOPMENT_MODE.md) posture — verified end to end in `local`/`vagrant` mode, or specified precisely for someone else to run in `remote` mode |
| Public site slot (06 §2.1, 05 §12) | An anonymous request resolves the correct tenant from its domain, not from client input; a capability not marked `reachable_from_public_site` is refused from this path | The cookie-consent plugin (§2.3), driven from a real (or lab) published page, not the admin dashboard |

## 5. What "done" looks like for the first delivery

Every acceptance criterion in §3 passes, the three non-hostile reference plugins (§2.1, §2.2, §2.3) are
genuinely useful enough that the team would keep using them, the hostile reference plugin's test
suite is part of ongoing CI (not a one-time manual exercise), and the incident-response runbook
has been drilled at least once with a real (simulated) revocation. Only then does
[00-overview.md](00-overview.md) §7's "prove the system before any outside developer is invited"
condition close, and the marketplace can be opened to real external submissions.

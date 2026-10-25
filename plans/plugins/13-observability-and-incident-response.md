# 13 — Observability and incident response

## Completion document

When this plan step is completed, write
`complete/13-observability-and-incident-response.complete.md`.

---

G6 ("stopped fleet-wide in minutes") and G2 (no cross-tenant/core-table access) are only real
claims if someone — or something automated — actually notices a problem. This document is how
that noticing happens.

## 1. What gets logged, and what deliberately does not

Every Plugin Runtime invocation and every capability-SDK call writes a `plugin_audit_log` row
([03 §4](03-database-schema.md)): `install_id`, `event_type`, `capability_key`, timestamp, and
outcome (success/error/timeout). **Never logged:** the actual payload of tenant data a capability
call read or wrote, a plugin's `plugin_data` contents, or anything from `http.egress` request/
response bodies — the audit log proves *that* a plugin did something and *whether it succeeded*,
not the tenant data itself, so the audit log's own growth and eventual retention/export
([03 §4](03-database-schema.md)'s note that this table "grows fast") never becomes a second copy
of sensitive tenant data with its own separate leak risk.

## 2. The circuit breaker

Per-install, per-capability failure and timeout counters (windowed, e.g. a rolling 5-minute count)
feed an automatic breaker:

| Condition | Action |
|---|---|
| A hook handler times out repeatedly within the window | That install is moved to `disabled`, `disabled_reason = 'circuit_breaker:timeout'` ([07 §2](07-installation-and-activation-lifecycle.md)) |
| A hook handler errors (uncaught exception inside its sandbox) repeatedly within the window | Same, `disabled_reason = 'circuit_breaker:error_rate'` |
| A `plugin_data` write volume or `http.egress` call volume spikes far past the plugin's own historical baseline for that install | Same, `disabled_reason = 'circuit_breaker:resource_abuse'` — this is the automatic complement to the resource limits in [05 §6](05-security-and-sandboxing.md), catching a plugin that stays under the hard per-invocation ceiling but is still clearly misbehaving in aggregate |

A circuit-breaker trip is scoped to the **one tenant's install** that tripped it — it is not, by
itself, a fleet-wide signal. A pattern across many tenants' installs of the *same release*
tripping the same breaker is exactly the signal that should prompt a human to consider the kill
switch (§5) — this document's job is to make that pattern visible, not to auto-revoke a release
based on breaker trips alone, since a single tenant's own environment (e.g. their own
`http.egress` allowlist target being briefly down) can legitimately trip a breaker with no
platform-wide implication.

## 3. Metrics and dashboards

Per-plugin, per-release aggregate metrics (not per-tenant, to keep the operational dashboard
usable at any marketplace scale): invocation count, error rate, p50/p95 latency, active install
count, circuit-breaker trip count in the last 24h/7d. This is where 00 §6 Q4 (how many plugins a
single tenant may have active at once) gets its actual answer once real usage data exists — set
conservatively at launch and revisited against this data rather than guessed now.

**Getting these numbers to the marketplace at all requires each deployment to report them,
centrally** ([00 §0](00-overview.md)): a deployment's own `laravel_plugin_audit_log` never leaves
its own database on its own, since there is no shared table the marketplace could simply query.
This reuses the same channel as everything else cross-deployment
([02 §8](02-architecture-overview.md)) — a periodic, anonymized report (counts and rates, never
raw log rows or tenant-identifying detail) alongside the app-state reporting
[www_brain 10 §10.5](../www_brain/10-brain-owned-config-and-push.md) already defines for the
Brain→app relationship generally. A deployment that's briefly unreachable just doesn't contribute
to that window's aggregate — it doesn't block or skew anyone else's.

## 4. Retention

`plugin_audit_log` retention is time-bounded (recommended default: 90 days for the raw per-call
rows, indefinitely for the aggregated metrics in §3) — long enough for the incident-response
runbook (§5) to have a useful window to investigate within, short enough that the table's own
growth (03 §4) stays operationally manageable without a bespoke archival project in the first
delivery.

## 5. Incident-response runbook: a plugin reported or discovered to be malicious

1. **Triage** the report against `plugin_audit_log` for the specific install(s) named, and against
   §3's aggregate metrics for the release across every tenant that has it — is this one tenant's
   configuration issue or a release-wide problem?
2. **If release-wide and substantiated:** revoke the release
   ([05 §7](05-security-and-sandboxing.md), [10 §6](10-review-and-publishing-pipeline.md)) —
   this is immediate and does not wait for full forensics; the kill switch exists precisely so
   containment does not wait on investigation.
3. **Notify affected tenants** with the specific, factual reason (05 §7's "clear, non-alarmist
   notice") — not a vague "a plugin was disabled" with no context, which erodes trust in the
   marketplace generally rather than isolating the specific incident.
4. **Forensics**: review `plugin_audit_log` for exactly which capabilities were invoked and how
   often, before the revocation — this is the evidence for whether any tenant's actual data was
   likely accessed inappropriately, bounded by what §1 chose to log (an audit trail of *that* a
   capability was called, even without payload content, is often enough to establish scope of
   exposure — e.g. "this install's `orders.read` was called 40,000 times in an hour" is
   informative even without seeing which orders).
5. **Developer consequence**: suspend the developer account
   (`plugin_developers.status = suspended`, [03 §1](03-database-schema.md)) pending review of
   their other published plugins, per [10 §6](10-review-and-publishing-pipeline.md)'s note that
   suspension does not itself revoke every other release — each is judged on its own evidence
   from this same process.
6. **Post-incident**: if the automated static analysis (10 §3) should have caught what was found,
   add the missed pattern to that stage's checks — every real incident is also a review-pipeline
   improvement, not just a one-off cleanup.

## Phases

| Phase | Deliverable | Done when |
|---|---|---|
| **P0** | `plugin_audit_log` writes on every invocation, with the §1 logging boundary enforced | A capability call's tenant-data payload never appears in the audit log even under a debug-level dump |
| **P1** | Circuit breaker (§2) | A hook handler injected to always time out gets its install auto-disabled within the stated window, with the correct `disabled_reason` |
| **P2** | Metrics dashboard (§3) | A platform operator can see error rate and active-install count per release without querying the database by hand |
| **P3** | Incident-response runbook exercised in a drill | A simulated malicious-release report is triaged, revoked, and tenants notified within the runbook's steps, timed and recorded as a baseline |

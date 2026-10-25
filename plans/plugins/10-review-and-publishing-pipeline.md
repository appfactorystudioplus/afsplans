# 10 — Review and publishing pipeline

## Completion document

When this plan step is completed, write
`complete/10-review-and-publishing-pipeline.complete.md`.

---

D6 is only as strong as this pipeline. "The raw upload never executes" is a promise this document
has to make true in practice, not just in the architecture diagram.

## 1. Stages

```
 upload ──▶ automated intake ──▶ static analysis ──▶ manual review ──▶ signing & publish
              (§2)                  (§3)               (§4)             (§5)
                │ fail any stage → rejected, developer sees why, resubmit as a new attempt
```

No stage is skippable, including for an update to an already-published plugin (04 §6) — a passed
review on version 1.0.0 grants nothing to version 1.0.1.

## 2. Automated intake

- Package structure validation against [04 §1](04-manifest-and-package-format.md)'s layout —
  reject anything with unexpected top-level entries, path traversal in zip entry names
  (`../`-style), symlinks inside the archive, or a size over a stated cap.
- Manifest schema validation — every required field present, `slug` well-formed and either new or
  owned by the submitting developer, `version` strictly greater than the plugin's last published
  version, every `capabilities` entry a real, current key in `plugin_capabilities_catalog`
  ([03 §1](03-database-schema.md)), and every capability the catalog marks `requires_parameters`
  (currently `http.egress`) present with its parameter — a manifest declaring `http.egress` with
  no host list ([04 §3.1](04-manifest-and-package-format.md)) is rejected here, not waved through
  as "capability declared, details TBD."
- **`plugin_data.increment` declaration check**: if `capabilities` includes
  `plugin_data.increment`, every collection the code actually targets with it (found by the §3
  cross-check below) must have an `increment` block in `data_schema`
  ([04 §4](04-manifest-and-package-format.md)) naming its allowed `fields` and a `max_amount` —
  rejected here if missing, the same `requires_parameters`-style pattern as `http.egress`. **A
  collection also marked `reachable_from_public_site`-eligible gets an extra check**: `max_amount`
  must be a small, specific value (not left at a large default) and `fields` must be a short,
  literal list — a public-reachable counter with an unbounded or wildcard-shaped declaration
  defeats the purpose of declaring bounds at all, so a suspiciously loose declaration here is
  routed to mandatory manual review (§4) rather than auto-passing.
- Developer identity check — `manifest.developer` matches the authenticated submitter's
  `plugin_developers` record; a suspended developer (03 §1) cannot submit at all.
- **Registration-time ban check** (new developer accounts only): the signup's computed
  `identity_fingerprint` is checked against `plugin_developer_bans`
  ([03 §1](03-database-schema.md)) — a match doesn't block registration outright (the fingerprint
  is coarse enough, especially before [12](12-billing-and-monetization.md) sharpens it with a
  payment-instrument signal, that an honest collision is possible), but routes every submission
  from that account into mandatory manual review (§4) regardless of what the automated pass finds,
  until a reviewer clears it — closing the trivial path of a suspended developer simply
  registering again under a new email and resubmitting the same code through the normal,
  faster-automated-first path.

## 3. Static analysis

Applied to the raw PHP and TypeScript source inside the submission, before anything is trusted
enough to run even in a sandbox:

**PHP:**

- AST-level scan for forbidden constructs: `eval`, `create_function`, `extract`, variable
  variables used to obscure a call target, `exec`/`shell_exec`/`proc_open`/`popen`, dynamic
  `include`/`require` (a path not knowable statically), reflection used to reach a method not
  otherwise callable, and any reference to a class outside the plugin's own namespace or the
  capability SDK's public interface (05 §4) — a plugin that references
  `Illuminate\Support\Facades\DB` or any Eloquent model class directly is an automatic rejection,
  because it means the code was written assuming direct framework access that the Plugin Runtime
  will never actually grant (05 §2) — catching this at review time, not at first execution,
  is strictly better for the developer too.
- Cross-check every capability-SDK method call found in the code against the manifest's declared
  `capabilities` list (04 §3) — an undeclared capability use is a rejection, not a warning, per
  that section's rationale.
- Cross-check `backend/hooks/` file names against the manifest's `hooks` array
  ([06 §3](06-extension-points-and-hooks.md)) — an undeclared hook file, or a declared hook with
  no corresponding file, is a rejection.
- Composer dependency audit: only a small, platform-maintained allowlist of pure-logic libraries
  (string/date/validation helpers with no filesystem, network, or process capability of their own)
  is permitted (04 §7); anything else is a rejection with the specific disallowed package named.
- **`http.egress` host cross-check**: every literal URL/hostname string findable in the code is
  compared against the manifest's declared host list
  ([04 §3.1](04-manifest-and-package-format.md)) — a literal reference to a host not on the
  declared list is a rejection. This is necessarily best-effort (a host built at runtime from
  concatenation or a variable is not statically resolvable, which is exactly why the *proxy*, not
  this scan, is the actual enforcement boundary — [05 §6](05-security-and-sandboxing.md)); this
  check exists to catch the common, non-adversarial case (a stale or copy-pasted host the
  developer forgot to declare) before it reaches a human reviewer, not to be the last line of
  defense against a deliberately obfuscated one.
- **`plugin_data.increment` field cross-check**: every literal `field` argument findable in a
  `plugin_data.increment` call site (backend or frontend) is compared against that call's target
  collection's declared `increment.fields` ([04 §4](04-manifest-and-package-format.md)) — a literal
  field not on the declared list is a rejection. Same best-effort caveat as the `http.egress`
  check: a dynamically-constructed field name isn't statically resolvable, which is exactly why
  the *dispatcher* (05 §12), not this scan, is the actual enforcement boundary. This check's real
  job is catching the ordinary case — a field the developer typo'd or never declared — before it
  becomes a manual-review round-trip.
- **Proportionality check on `max_amount`**: for a collection reachable from a public site slot
  (04 §4), the reviewer (§4) confirms the declared `max_amount` actually matches what one visitor
  interaction should cause (e.g. `1`, for "one visitor's one choice") — a declared bound that's
  technically present but effectively unbounded (e.g. `999999`) does not satisfy this check even
  though it passes the automated presence check in §2.

**TypeScript (frontend bundle):**

- Bundle-level scan for `eval`, `new Function`, dynamically constructed `<script>` tags, and any
  literal URL not on an allowlisted CDN/asset host pattern.
- Bundle size cap and single-entry-point check (04 §1, [08 §1](08-frontend-runtime-and-dynamic-loading.md)).
- No reference to `window.top`, `window.parent.document`, or any API that would only work outside
  the iframe sandbox (08 §3) — present as an early warning to the developer, since the sandbox
  itself would block it regardless; catching it here saves a failed manual review round-trip.

A submission failing any automated check never reaches a human reviewer — the developer gets a
specific, actionable rejection reason and resubmits.

## 4. Manual review

A human reviewer, working from the automated pass's report plus a checklist:

- Reads `permissions_requested_summary` (04 §2) against the actual capability list and the
  plugin's stated purpose — does the justification make sense, or is a note-taking plugin asking
  for `http.egress`?
- For `plugin_data.increment` on a publicly-reachable collection: confirms the declared
  `max_amount` (04 §4) is actually proportionate to one visitor interaction, not merely present
  (§3's automated check only confirms the field exists) — this is a judgment call the automated
  pass cannot make on its own.
- Spot-checks the hook handler files and any capability-SDK call sites the automated pass flagged
  as needing human judgment (e.g. a `plugin_data` write pattern that looks like it's trying to
  store something capability-scoped data shouldn't hold).
- Reviews screenshots/description for anything misleading about what the plugin does.
- Approves, rejects with specific feedback, or (for a borderline case) requests changes without a
  full rejection cycle.
- **On approval, the reviewer's own action is what issues the reviewer approval assertion**
  ([05 §11](05-security-and-sandboxing.md)) naming the exact artifact digest just approved — not a
  button the pipeline's application code presses on the reviewer's behalf, but the actual output
  of the approval action itself, so a compromised pipeline application has no assertion to replay
  or forge without a reviewer having genuinely approved that digest. Signing (§5) cannot proceed
  without it.

This stage is **required for every submission in the first delivery** — there is no automated-only
fast path yet (00 §6 Q2 records the idea of a trusted-developer track as future work, contingent
on marketplace volume, not a current shortcut).

## 5. Signing and publishing

On approval: re-package (never sign the developer's original zip bytes — repackaging is itself a
normalization step that strips anything the automated scan didn't already catch, like unused
files or metadata), hash, and present the resulting digest plus §4's reviewer approval assertion
to the signing service, which checks the assertion actually names this digest before it signs
anything ([05 §11](05-security-and-sandboxing.md)) — the pipeline application requests a
signature, it does not get to grant its own authorization for one. The signed result, along with
which key signed it (`signing_key_id`), is written into the `plugin_releases` row per
[04 §5](04-manifest-and-package-format.md). The first release of a plugin also flips
`plugins.status` to `published`; a subsequent approved release just updates
`latest_published_release_id` — the plugin doesn't go back through a "was this plugin identity
itself okay" review each time, only "is this specific release okay."

**Staged rollout** (recommended, not required for the first delivery): a newly approved release
can optionally be exposed to a small, platform-designated canary tenant before
`latest_published_release_id` updates for everyone — catching a review pipeline miss before it
reaches the full install base. Left as a configurable option per release rather than a hard
requirement, since it adds latency to every update and the smaller marketplace of the first
delivery may not need it immediately.

## 6. Revocation and abuse reporting

- **Revocation** is the platform team setting `plugin_releases.revoked_at`
  ([03 §1](03-database-schema.md)), triggering the kill switch
  ([05 §7](05-security-and-sandboxing.md)). Grounds: a post-publish discovery that review missed
  something, a tenant abuse report substantiated, or a developer account found compromised.
- **A suspended developer** (`plugin_developers.status = suspended`) cannot submit new releases;
  their already-published releases are not automatically revoked by suspension alone — revocation
  is a separate, per-release decision based on the actual release's behavior, so suspending a
  developer for, say, a billing dispute does not collaterally break every tenant's working
  install of their otherwise-fine plugin. **Suspending for cause** (as opposed to a billing or
  administrative suspension) additionally writes the developer's `identity_fingerprint` into
  `plugin_developer_bans` ([03 §1](03-database-schema.md)) — otherwise the suspension only stops
  the one account, and re-registering under a new email is trivial and free.
- **Abuse reports** from tenants (a plugin doing something its description didn't suggest) feed
  into the same review queue as a new submission, prioritized ahead of routine review — the
  specific triage process and SLA is an operational detail for
  [13 §5](13-observability-and-incident-response.md)'s runbook, not this document.

## Phases

| Phase | Deliverable | Done when |
|---|---|---|
| **P0** | Automated intake + static analysis (§2, §3), including the `requires_parameters` check, the registration-time ban-fingerprint check, the `http.egress` host cross-check, and the `plugin_data.increment` declaration + field cross-check | Every listed forbidden construct and undeclared-capability case is caught in an automated test corpus before any human reviewer sees it; a re-registration attempt by a banned fingerprint is routed to mandatory manual review rather than the normal automated-first path; a manifest using `plugin_data.increment` on a collection with no `increment` block, or a call site naming a field not in that block, is rejected before reaching a reviewer |
| **P1** | Manual review workflow and checklist (§4), including the `max_amount` proportionality judgment for publicly-reachable counters | A reviewer can approve/reject/request-changes on a submission end to end, with the developer notified either way; a technically-present but effectively-unbounded `max_amount` on a public collection is caught here even though §2's automated check already passed it |
| **P2** | Signing and publish (§5), including the reviewer-approval-assertion gate on the signing service itself and `signing_key_id` recorded per release | A signed release's signature verifies against the public key named by its `signing_key_id`, an unsigned or tampered artifact is refused by the Plugin Runtime/Plugin Host (05, 08), and a sign request presenting no valid reviewer approval assertion for the exact digest is refused by the signing service even if the calling pipeline application is itself authenticated |
| **P3** | Revocation and abuse-report intake (§6), including the ban-fingerprint write on for-cause suspension | A revoked release stops working fleet-wide (05 §7); a submitted abuse report reaches the review queue; a developer suspended for cause and then re-registering under a new account is routed to mandatory manual review by the P0 ban check |

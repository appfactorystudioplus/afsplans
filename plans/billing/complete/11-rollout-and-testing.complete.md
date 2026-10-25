# 11 — Rollout and testing — completion (status of each phase)

**As of 2026-09-25.** [`../11-rollout-and-testing.md`](../11-rollout-and-testing.md) is a rollout plan, not
a build step, so this records what has been **done and how far it was checked** for each phase it names — and
what has not. Nothing here is switched on in production.

## Phase status

| Phase | Built? | Checked how | Not done |
|---|---|---|---|
| **P0 — stop the lies** (`cancelSubscription()` / `selectPlan()` no longer fake success) | Yes — [`10`](10-members-dashboard.complete.md) reports both fixed and `saveCurrentSubscription()`/`loadCurrentSubscription()` gone | Per that document; **not re-verified in this pass** | QA against live subscription data (the document's own caveat) |
| **P6 — enforcement** | Yes — the ladder ([`08`](08-brain-enforcement.complete.md)) and, since 2026-09-25, its scheduling in `members` over a signed call ([`08-enforcement-in-members`](08-enforcement-in-members.complete.md)) | Unit and feature tests on both sides (39 new); end to end in the lab through cron: `members` → signed `POST` → `www` ran the command and returned its report | **Not enabled.** The billing-cycle soak (§11.4), the production switch-on order ([08 §8.12](../08-brain-enforcement.md)), and a real downgrade/restore observed against a live subscription |
| **P7 — entitlement** | Yes — [`09`](09-entitlement-integration.complete.md) (the server-derived endpoint; cloud-storage and afs-connect needed no change) | Per that document | Not re-verified in this pass |
| **P8 — dashboard** | Yes — [`10`](10-members-dashboard.complete.md) | Per that document | QA against live data |

## Prerequisites (§11.2, §11.5)

| Item | Status |
|---|---|
| `members` `schedule:run` cron | **Installed in the vagrant lab** for `www` and `members` with `laravel-cron.sh` (the lab had no scheduler entries before) and observed firing every minute, including running a scheduled command. **Not confirmed on any real server** |
| Cache-lock store on `members` | Works in the lab (the `withoutOverlapping` / `onOneServer` scheduling ran) |
| `members` → `www` signing keys | Random keys generated for the `local` and `vagrant` env sets; `dev` and `prod` are **empty** and must be provisioned |
| `direct_payments:subscription-notify` clean for a full billing cycle (P4) | **Not done** — a precondition for P6 |

## The test matrix (§11.6) and rollback (§11.7)

The enforcement rows that concern the trigger and the surface are now covered by automated tests (`401` for
every unsigned/stale/replayed/wrong-key/unknown-app/opposite-direction case, `404` off the Brain, `409`
overlap, kill switches). The ladder rows (`past_due` → downgrade → restore, unresolved tier skipped,
idempotence) were checked when the ladder was built. **Live-provider rows** — a real Stripe/Square/PayPal
subscription going `past_due` — have not been run. Rollback is unchanged and simpler: any one of
`members`' `BILLING_ENFORCEMENT_ENABLED`, `www`'s `IS_BRAIN` / `BILLING_ENFORCEMENT_ENABLED`, or
`brain_billing_settings.enabled = 0` stops it ([08 §8.12](../08-brain-enforcement.md)).

## What this document does not claim

It does not say P0–P8 were re-tested here, that any production switch is on, or that a member has been
downgraded by the scheduled path: in the lab the run found one candidate whose tier cannot be resolved and
skipped it.

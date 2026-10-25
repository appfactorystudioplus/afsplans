# 11 — Rollout and Testing

> **Trimmed 2026-09-22.** The generic phases (P1a–P6f, covering the Direct Payments
> subscription/notification/money infrastructure that moved to `www/direct_payments/`) now
> live in
> [`../www/direct_payments/30-subscription-notify-rollout-and-testing.md`](../www/direct_payments/30-subscription-notify-rollout-and-testing.md).
> What remains here is specifically about appfactory studio's own `brain_memberships`
> enforcement and dashboard — P0, P6, P7 and P8.

## 11.1 Build order

| Phase | Contents | Ships behind |
|---|---|---|
| **P0 — stop the lies** | [10](10-members-dashboard.md) §10.1: `cancelSubscription()` (**B27**) *and* `selectPlan()` (**B37**) both write `localStorage` and claim success. Make them call real endpoints, or disable both buttons until [28](../www/direct_payments/28-subscription-actions.md) lands. **Delete `saveCurrentSubscription()`/`loadCurrentSubscription()`** — the shared root cause | Nothing — this removes falsehoods, it does not add features |
| **P6 — enforcement** | [08](08-brain-enforcement.md): the three tables, `DunningService` and the `www` command (built), the signed Brain surface and `IS_BRAIN` on `www`, and the scheduled `members` trigger (built 2026-09-25; switch-on order in §8.12) | `members/.env` `BILLING_ENFORCEMENT_ENABLED` (off), the `www` env switch **and** `brain_billing_settings.enabled = false` |
| **P7 — entitlement** | [09](09-entitlement-integration.md): the Brain entitlement endpoint | Service-auth only |
| **P8 — dashboard** | [10](10-members-dashboard.md): the full member surface — subscription reads, plan change, cancel, cards-only payment methods, billing history with refunds, dunning banner | Additive; depends on [`direct_payments`'s P1b, P6d and P6e](../www/direct_payments/30-subscription-notify-rollout-and-testing.md) |

**P0 comes first and is not optional** — it removes a falsehood, and every day it ships
later is a day members are told they cancelled while being charged. P0 depends on
[`direct_payments`'s P6d](../www/direct_payments/30-subscription-notify-rollout-and-testing.md)
(subscription actions) landing first. **P6 must not be enabled before
[`direct_payments`'s P4](../www/direct_payments/30-subscription-notify-rollout-and-testing.md)
has run cleanly for a full billing cycle** (§11.4 below), and P6's own prerequisite chain
(P1a→P1b→P1c) also lives in that document.

## 11.2 Deployment prerequisites

Shared with `direct_payments`' checklist
([`../www/direct_payments/30`](../www/direct_payments/30-subscription-notify-rollout-and-testing.md)
§30.2) — `type.txt`, the cache-table migration, `php` on cron's `PATH`, a reachable mail
provider token. **Additionally for P6:** a working `schedule:run` cron and the cache-lock store on **`members`** (installed and verified in the lab with `laravel-cron.sh`; confirm on each real server — [account_close OQ-H](../account_close/00-overview.md)), and the members→www signed channel keys ([08](08-brain-enforcement.md) §8.2). Nothing further for P7/P8.

## 11.3 The `--dry-run` gate

Nothing in P6 is scheduled until it has been run by hand against production data:

```bash
# run from the members app
php artisan billing:enforce-membership --dry-run
```

Read the output row by row. Specifically confirm no member in the `Skipped (unresolved
tier)` bucket who should not be. `direct_payments:subscription-notify --dry-run` (P4) is a separate
gate, covered in
[`../www/direct_payments/30`](../www/direct_payments/30-subscription-notify-rollout-and-testing.md)
§30.3.

## 11.4 The enforcement soak

P6 ships **off** twice over (`.env` and `brain_billing_settings.enabled`). The sequence:

1. **Weeks 1–4:** `enabled = false`. Run `--dry-run` daily via a separate ad-hoc cron that
   only writes the summary line to a log. Watch the `Skipped (unresolved tier)` count. It
   must reach and stay at zero before anything else happens.
2. **Week 5:** `enabled = true` with `default_grace_days = 60`, and any DP-level
   `grace_days` raised to match ([26](../www/direct_payments/26-advanced-policy.md) §12.6 — a DP with its
   own advanced row overrides the deployment default, so raising only the default would
   leave those DPs live). Notifications go out; no member reaches T+60 within the window.
   This proves the ladder and the emails in production without a single downgrade.
3. **Week 7:** restore the real grace periods. Enforcement is live.

The reason for step 2: the dunning emails are the part that is hardest to test and most
damaging to get wrong. A member receiving a "your plan drops on X" email that is wrong, or
that names the wrong date, is worse than no email. Sixty days makes the emails real while
keeping the consequence hypothetical.

## 11.5 Follow-up: the `members` crontab

Finding **B23**: `members/routes/console.php` registers three `storage:*` sweeps and its own
comment says the crontab is *"not yet confirmed configured"*. Those sweeps are how
[09](09-entitlement-integration.md) §9.2's storage retirement actually happens — **a
downgrade with no `members` scheduler retires nothing.**

`commands.sh --update-cron-task` installs a line for the `www` site only, and only when a Direct Payments
save triggers it. `members` needs its own line. Install it with
[`www/core/scripts/laravel-cron.sh`](../../../../w/www/core/scripts/laravel-cron.sh), which checks, installs
or removes the entry idempotently and recognises the line `--update-cron-task` writes:

```
sh laravel-cron.sh --install --user {systemuser} --path /home/{systemuser}/websites/members
sh laravel-cron.sh --check   --user {systemuser} --path /home/{systemuser}/websites/members
```

**Verify this before enabling P6.** It is a `members` deployment concern, not application code. The
service account must have a real login shell (cron will not run a job for a `nologin` user; the script warns).

## 11.6 Test matrix

### Enforcement ([08](08-brain-enforcement.md))

| Case | Expected |
|---|---|
| Tenant clone (`www` code, any domain) | Nothing scheduled — `www/routes/console.php` has no enforcement entry |
| `BILLING_ENFORCEMENT_ENABLED=false` (in `members/.env`) | Exit 0, **no call to `www`** (`--force` overrides for a manual run) |
| `www` env kill switch off, or `brain_billing_settings.enabled = 0` | The trigger succeeds and the Brain reports that nothing was done; no member changes |
| Unsigned, wrong-key, stale, replayed, unknown-app or opposite-direction-key call to the surface | `401`; nothing runs |
| Any call on a deployment where `IS_BRAIN` is not true | `404`, even with a valid signature |
| A second trigger while a run is in progress | `409`; nothing runs |
| `members` cron stopped | Nobody downgraded; missing runs visible in monitoring |
| Sub → `past_due` | State row, **no** tier change |
| +3d / +7d / +12d | One reminder each, never repeated |
| +14d | One downgrade, `membership` written to the resolved policy target, events logged |
| +14d on a DP whose lowest tier is level 1 | `membership = 1`, **not** 0 ([08](08-brain-enforcement.md) §8.4.1) |
| +14d with `nonpayment_action = none` | Notifications sent, `membership` **unchanged** |
| +14d where target already equals current tier | No write, no event row |
| +15d | No-op |
| Pay at +13d | Restored within one tick |
| `plan_id` unmapped | **Skipped**, counted, tier preserved |
| Member with no subscription | Not a candidate |
| 96 runs, stable population | Zero event rows |

### Signup ([22](../www/direct_payments/22-subscription-state.md) §4.2.4–§4.2.5)

The mechanism (the DP link table, `MembershipController::store()` adopting it) lives in
`direct_payments`; the tests below are listed here because what they actually verify is
appfactory studio's own tier-forgery defence (**B38**, **B40**) on `brain_memberships`.

| Case | Expected |
|---|---|
| Post `membership: 4` with no `membership_id` | Member created at tier **0** (**B38**) |
| Post `membership: 4` with a `membership_id` whose tier is 2 | Member created at tier **2**, discrepancy logged, signup **succeeds** |
| Normal paid signup | Posted tier and resolved tier agree; no log line |
| Free signup | Tier 0, no link row, no error |
| Paid signup | Link row's `member_reference_num` adopted in `store()` (**B40**) |
| Link write failed before `store()` | Member lands on tier 0 with `subscription_unlinked` logged — **never** a silent upgrade |
| `direct_payments:backfill-subscriptions` after that | Adopts the orphan and reports it |

### Integration ([09](09-entitlement-integration.md))

| Case | Expected |
|---|---|
| Downgrade a member with buckets 0–3 used | `storage:retention-sweep` retires 1–3 within the hour, **no cloud-storage code change** |
| Same member's afs-connect | `tierIndexFor()` returns 0 within **5 minutes** (`BrainMembershipLookup`'s 300s cache), access closes, **no afs-connect code change** |
| afs-connect immediately after the write | Still open — assert this, so the cache window is a known behaviour and not mistaken for a broken downgrade |
| Restore | Buckets `bucket_restored`; afs-connect reopens within 5 minutes |
| `account_status` after a downgrade | **Unchanged.** Enforcement writes `membership` only ([09](09-entitlement-integration.md)) |
| Entitlement endpoint, unknown member | `404` |
| Entitlement endpoint, no service auth | `401` |

### Members dashboard ([10](10-members-dashboard.md))

| Case | Expected |
|---|---|
| Cancel or change plan, network offline | **Error shown, no success toast, no local state change** (**B27**, **B37**) |
| `localStorage.currentSubscription` from an old build | Cleared on init, never read |
| Active sub, each of three providers | Same card, same code path — no `provider` branch in `BillingApp.ts` |
| Cancelled through this app | **"Features active until {access_ends_at}"** — not a charge date, not blank |
| `cancellation_access_ends = immediately` | Cancel dialog says features end immediately, before confirming |
| Cancelled out of band at the provider | "Ends {next_charge_at}", no renewal-notice line |
| Change plan | Renders the **returned** subscription; new tier resolves in `billing:enforce-membership --dry-run` |
| Downgrade that reduces allocations | Warns with the member's actual usage |
| Add-card element | Offers **only** cards |
| Change payment method on PayPal | Links out; no form shown |
| Remove a card a live subscription uses | Warns and names the subscription |
| Refunded charge | Appears in billing history as refunded, with the net amount |
| `dp.subscription.history()` | Returns only the caller's rows; no `charge_id`, `provider_refund_id`, refund `reason` or `initiated_by` |
| **`grep -rn "from '.*external" members/resources/js/`** | **Empty.** No consuming file imports the externals source ([25](../www/direct_payments/25-direct-payments-api.md) §7.5) — CI check |
| Every method this plan adds to `api.ts` | Declared in `portal.d.ts`, and `members`' copy refreshed — otherwise the consumer falls back to `(window as any)` |
| `past_due` | Amber banner, correct `downgrade_after`, working CTA |
| `downgraded`, no retired buckets | Red banner **without** the storage sentence |
| No subscription | No card, no banner, no error |
| Demo services block | Gone (**B19**) |

## 11.7 Rollback

| Phase | Rollback |
|---|---|
| P0 | Not revertible in the meaningful sense — the previous behaviour was a falsehood. If the endpoint is not ready, leave the button disabled |
| P6 | `BILLING_ENFORCEMENT_ENABLED=false` in `members/.env`, **or** `brain_billing_settings.enabled = 0`. Already-downgraded members are restored with `billing:enforce-membership --member=… --restore` (an admin path worth building alongside P6) |
| P7 | Unregister the route; nothing consumes it yet |
| P8 | Front-end revert; the endpoints are additive |

`direct_payments`' own rollback table
([`30`](../www/direct_payments/30-subscription-notify-rollout-and-testing.md) §30.5) covers
P1a–P6f.

The one rollback that is not clean is a downgrade that has already triggered cloud-storage
retirement. Restoring the tier moves buckets back to `active` untouched
([../cloud-storage/08-storage-retention.md](../cloud-storage/08-storage-retention.md) §6.4)
— **as long as it happens before T+37d hard delete.** That window is the real deadline on
noticing an enforcement mistake, and it is why §11.4's soak is four weeks of dry runs
before a single tier moves.

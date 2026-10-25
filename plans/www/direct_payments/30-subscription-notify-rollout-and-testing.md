# 30 — Rollout and Testing (Subscription Notify, Money, Advanced Policy)

> **Moved from `billing/11-rollout-and-testing.md` 2026-09-22**, split out alongside
> steps [20](20-current-state-audit.md)–[29](29-money-and-refunds.md): these are the phases
> that cover generic Direct Payments infrastructure, usable by any tenant. The phases that
> are specifically about appfactory studio's own `brain_memberships.membership` tier — P0
> (the dashboard falsehoods), P6 (Brain enforcement), P7 (the entitlement endpoint) and P8
> (the members dashboard) — stayed behind in
> [`../../billing/11-rollout-and-testing.md`](../../billing/11-rollout-and-testing.md), which
> now cross-references this document for build order. Internal `§N.x` section numbers below
> are unchanged from the old `billing/11` — only the phase labels (P1a, P2, …) and this
> document's own heading numbers are new.

## 30.1 Build order

Each phase is independently shippable and leaves the system working.

| Phase | Contents | Ships behind |
|---|---|---|
| **P1a — processor layer** | [22](22-subscription-state.md) §4.2.1/§4.3/§4.4: the provider-field migration, `SubscriptionSyncService`, the creation-path fixes, the PayPal `updateOrCreate` fix (**B4**), `processor:backfill-subscriptions`. **Adds no knowledge of Direct Payments** | Nothing user-visible. Additive columns only |
| **P1c — signup integrity** | [22](22-subscription-state.md) §4.2.4/§4.2.5: `MembershipController::store()` adopts the link row and **derives** the tier from the paid subscription instead of accepting it (**B38**, **B40**) | Nothing — closes a hole. Ships with P1b |
| **P1b — DP link** | [22](22-subscription-state.md) §4.2.2/§4.2.3: `laravel_direct_payment_subscriptions`, its controller, the `api.ts` call, `direct_payments:backfill-subscriptions` | Additive |
| **P2 — cron** | [21](21-cron-bootstrap.md): the `--update-cron-task` block, the `saveDirectPayment` hook, `routes/console.php`. Register **only** `direct_payments:subscription-notify`, which exits at phase 0 because no configs exist yet | The phase-0 guard |
| **P3 — config** | [23](23-notification-config.md): both tables, models, the mail-handler list endpoint, the write path, the admin UI card | Operators can configure; nothing sends until P4 |
| **P4 — notify** | [24](24-notification-scheduler.md): phases 1–3, `BillingMailer` with the **B9**/**B10** fixes, `NotificationDispatcher`, the webhook dispatch calls | `--dry-run` first (§30.3) |
| **P5 — SDK** | [25](25-direct-payments-api.md): `dp.notifications`, `dp.subscription`, the event emitter, the playground page, **and `portal.d.ts` + `members`' copy of it** (§7.5) | Additive |
| **P6b — advanced policy** | [26](26-advanced-policy.md): `laravel_direct_payment_advanced`, `DunningPolicyResolver`, `AdvancedConfigGate` + write middleware, the `#dpAdvancedSection` card in the normal DP form | `dunning_enabled = false` per DP — every DP is inert until switched on |
| **P6c — provider retry policy** | [27](27-provider-retry-policy.md): `ProviderRetryProfile`, the three config objects, `laravel_processor_retry_settings`, the processor-admin panels, the **B26** de-hardcoding | Defaults preserve today's behaviour exactly |
| **P6d — subscription actions** | [28](28-subscription-actions.md): cancel / update / setPaymentMethod on the DP API, the card-only enforcement, the four action events | Per-DP notification config; no config means the action works and sends nothing |
| **P6e — money and refunds** | [29](29-money-and-refunds.md): `laravel_processor_refunds`, the Connect columns, the ledger endpoint, the refund action, `money:backfill-charges` | `portal.admin.auth` only — refunds are **not** permission-gated (§15.4); the ledger is read-only until the action lands |
| **P6f — money in afs-connect** | [29](29-money-and-refunds.md) §15.6: the `www_money` rollup source | One registry entry — the registry is already live; `default_on => false`, so no member sees revenue in coordinator until they opt in |

**P1a is a hard prerequisite for everything else here**; P1b depends only on P1a. P2 and P3
can land in either order. **P6b may land before or after `billing/11`'s P6** — until P6
exists nothing reads the policy, and once it does the resolver's inert fallback keeps an
unconfigured DP harmless; see [`../../billing/11`](../../billing/11-rollout-and-testing.md)
§11.1 for how P0/P6/P7/P8 interleave with the phases here. **`billing/11`'s P6 must not be
enabled before P4 has run cleanly for a full billing cycle** (`billing/11` §11.4).

## 30.2 Deployment prerequisites

Confirm on every deployment before P2:

- [ ] `php artisan migrate` has run the cache-table migration
      (`0001_01_01_000001_create_cache_table`) — `withoutOverlapping()` and `onOneServer()`
      need an atomic-lock store, and `CACHE_STORE` defaults to `database`
      ([`../../billing/01-architecture.md`](../../billing/01-architecture.md) §1.6).
- [ ] `php` is on `PATH` for the site's system user under cron. Cron's environment is not
      a login shell; if `php` resolves only via a profile script, the crontab line needs an
      absolute path. **Verify with `crontab -u {user} -l` and one manual run.**
- [ ] `type.txt` exists and holds the correct subdomain — `getSubdomain2()` reads it under
      CLI, and everything in this plan that resolves a managed domain depends on it. This
      is the single most likely cause of "the scheduler does nothing".
- [ ] The site's `laravel_mail_providers` has an active row, **or** the Brain config's
      `email_providers[0].token` is reachable — otherwise sends no-op (**B9**).
- [ ] Timezone: `config('app.timezone')` matches what the operator means by "3 days
      before" ([24](24-notification-scheduler.md) §6.4).

## 30.3 The `--dry-run` gate

Nothing in P4 is scheduled until it has been run by hand against production data:

```bash
php artisan direct_payments:subscription-notify --dry-run
```

Read the output row by row. Specifically confirm: no `WOULD SEND` for a subscription with
`cancel_at_period_end`, and day-offset buckets that match hand-computed dates.
`billing:enforce-membership --dry-run` (P6, run from **`members`**) is a separate gate, covered in
[`../../billing/11`](../../billing/11-rollout-and-testing.md) §11.3.

## 30.4 Test matrix

### Cron bootstrap ([21](21-cron-bootstrap.md))

| Case | Expected |
|---|---|
| First save, user has no crontab | One line added, `crond` restarted |
| Save 50× | Still exactly one line |
| Save on `www15` with the two-arg call | Line points at `websites/dev/www15/` |
| Site directory missing | Skipped, logged, no line |
| Empty `systemuser` | Exit 1, no change |
| `GetManagedDomain()` returns `access=false` | `RunCommandBG` not called at all |

### Sync ([22](22-subscription-state.md))

| Case | Expected |
|---|---|
| Stripe / Square / PayPal active sub | `next_charge_at` populated, identical semantics |
| Webhooks disabled for 24 h | `next_charge_at` still fresh |
| Provider returns 500 | Values unchanged, `last_sync_error` set |
| Provider returns 404 | `status = canceled`, row retained |
| `cancel_at_period_end = true` | `next_charge_at = null` |
| 500 subscriptions, `sync_limit_per_run = 200` | Three ticks to full freshness, no rate-limit errors |
| **Layer boundary** | `grep -rn "direct_payment\|DirectPayment\|Membership" app/Http/Controllers/Externals/Processor/ app/Models/Externals/Processor/` finds nothing this plan added ([22](22-subscription-state.md) §4.9) |
| DP link endpoint down during subscription creation | Subscription still succeeds; `direct_payments:backfill-subscriptions` adopts the orphan |
| DP link row whose processor row is missing | Dropped from the notify join, reported by the backfill, no email ([24](24-notification-scheduler.md) §6.4) |

### Notifications ([24](24-notification-scheduler.md))

| Case | Expected |
|---|---|
| Tenant with zero configs | One query, no output, no provider calls |
| `days_before = [7,3,1]`, sub 7 days out | Exactly 1 email across 96 ticks |
| Same, full period | Exactly 3 emails |
| Delete the ledger row, re-run | Sends again |
| Roll `next_charge_at` +1 month | All three re-arm |
| `cancel_at_period_end` | Nothing sent |
| Mail handler deleted after config saved | `failed` ledger row, other configs unaffected |
| Customer has no email | `skipped` row, `no_recipient`, no retry |
| Webhook delivered twice | One email |
| 300 due sends, cap 100 | 100 this tick, 100 next, none lost |
| `custom` mode, 3 addresses | **3** ledger rows, **3** `sendOne()` calls, never one 3-address call (**B25**) |
| Same, one address invalid | That one row `failed`; the other two `sent` |
| `BRAIN_API_URL` unreachable, local mail row has no key | `no_provider_token` ledger rows, command **exits 0** (**B24**) |
| `BRAIN_API_URL` unreachable, local mail row **has** a key | Sends normally |
| Ledger migration | Every column in `dp_notification_sends_uq` is NOT NULL — assert in a migration test; nullable silently disables dedupe |

### Money and refunds ([29](29-money-and-refunds.md))

| Case | Expected |
|---|---|
| Open Financials → Money | **Loads** (**B32**); legacy `/portal/api/charges` still resolves |
| Issue a refund | `laravel_processor_refunds` row written; ledger shows gross / refunded / **net** (**B34**) |
| Revenue stat card | Shows **net**, not gross |
| Refund issued in the provider's own dashboard | Appears via the existing webhook handlers, on the same row — not a duplicate |
| Refund a charge with no local customer | Succeeds (**B33**) |
| Refund a split charge | Both Connect flags default checked, amounts stated in money, flags recorded on the refund row |
| Uncheck both and confirm | Seller stays paid, platform absorbs it, ledger says so |
| Refund a subscription charge | Subscription **not** cancelled |
| Any authenticated admin operator | **Can refund** — no level-99 requirement (§15.4) |
| Every refund row | `initiated_by` populated, resolved server-side |
| Partial refunds totalling the full amount | Charge status `refunded`, not `partially_refunded` |
| `money:backfill-charges --dry-run` | Reports webhook-missed charges, writes nothing |
| **afs-connect index contents** | No `customer_email`, no `charge_id`, no per-transaction amount — assert against a seeded ledger |
| Adding `www_money` | **One** registry entry plus the provider module; `SourceRegistryConsistencyTest` passes |
| Member with afs-connect on, source not opted in | **No** money rows in coordinator (`default_on => false`) |

### Subscription actions ([28](28-subscription-actions.md))

| Case | Expected |
|---|---|
| Cancel with the network offline | Error shown; **no** success toast, no local state change (**B27**) |
| `localStorage.currentSubscription` present from an old build | Ignored and cleared, not trusted |
| Cancel on Stripe / PayPal / Square | Reaches the provider; status reflected within one sync |
| Cancel on any provider | Billing stops **immediately**; `canceled_at` set |
| `cancellation_access_ends = period_end` | `access_ends_at` = the `next_charge_at` captured **before** the provider call |
| `cancellation_access_ends = immediately` | `access_ends_at = canceled_at`; UI warned before saving that policy |
| Tier before `access_ends_at` | **Unchanged** — the member keeps what they paid for |
| Sweep after `access_ends_at` | One downgrade, one `subscription_access_ended`, `tier_before_downgrade` written |
| Sweep again the next tick | No-op, no event row |
| Cancel then resubscribe before `access_ends_at` | Tier resolves from the **new** subscription; no downgrade (`billing/11`'s Enforcement matrix, §8.4.2) |
| Cancelled member | Receives **no** dunning reminders — cancellation is not the dunning path |
| Cancelled-at-period-end sub | Receives **no** upcoming-charge notice ([24](24-notification-scheduler.md) §6.4) |
| Tier change | `direct_payment_tier_reference_num` updated; `billing:enforce-membership --dry-run` resolves the new `tier_paid` |
| Stripe payment-method change | **`subscription.default_payment_method`** set, not just the customer's (**B31**) |
| Square payment-method change | Actually calls Square (**B30**) |
| PayPal payment-method change | `DP_E_PM_CHANGE_NOT_SUPPORTED`, UI links out — **not** `success: true` |
| Attach `us_bank_account` / `sepa_debit` / `link` | `DP_E_PAYMENT_METHOD_NOT_CARD` at the **write** layer |
| Debit card with `card.funding = 'unknown'` | **Accepted** (§14.6) |
| Cancel twice | One email |
| Action with no notification configured | Succeeds, sends nothing |

### Provider retry policy ([27](27-provider-retry-policy.md))

| Case | Expected |
|---|---|
| `ProviderRetryProfile::for('mystery')` | Throws — a new provider must be described before it can be sold |
| Square panel | Description only, **no inputs**; posting a writable field → `PR_E_NOT_CONFIGURABLE` |
| Stripe panel save | **No outbound Stripe call** — assert with a mocked HTTP client |
| PayPal threshold save, PayPal returns non-`204` | Local row unchanged, `sync_error` populated |
| Stripe field posted to a Square processor | `PR_E_FIELD_NOT_FOR_PROVIDER` |
| Create a PayPal plan without touching the panel | `payment_failure_threshold: 3`, exactly as before (**B26**) |
| Backfill against a plan edited in PayPal's dashboard | Records PayPal's actual value, not the hardcoded 3 |
| **Layer boundary** | No `direct_payment`/`DirectPayment`/`Membership` in the new provider classes ([27](27-provider-retry-policy.md) §13.1) |

### Advanced policy ([26](26-advanced-policy.md))

| Case | Expected |
|---|---|
| **Decoupling grep** | No `brain_`, `Membership` or `member_reference_num` in the model, controller, resolver or migration — CI check (§12.10) |
| **Graduation drill** | Deleting the two server gates and the one UI visibility block makes advanced config editable by any operator, with no migration and no data movement. Do this once, for real |
| `GET /{dpRef}/advanced` as any operator | Succeeds, with `"editable": false` — reads are never gated |
| `POST` from a tenant whose site owner has `level = 0` | `403 DP_E_ADVANCED_WRITE_FORBIDDEN` |
| `POST` from the Brain, site owner `level = 99` | Succeeds |
| Brain API unreachable from a tenant during the gate check | `403` — fails closed |
| Set a test tenant owner's `brain_memberships.level` to 99 | That tenant can now edit, **with no code change** — this is the decoupling working, and is why the check is a level and not a domain |
| Forged `app.window.member.level = 99` in the console | Card appears, save still `403` |
| Non-99 operator saves the DP | Existing advanced row **unchanged** — the payload omits the key |
| DP with no advanced row | Resolves to `deployment_default` |
| Neither row present | Resolves **inert** — enforcement changes nothing |
| `reminder_days: [3,20]` with `grace_days: 14` | Rejects at save |
| `downgrade_to_level: 3` on a DP with levels 0–2 | Rejects at save |
| `grace_days: 1` | Warns in the UI, saves |
| `grace_days: 5` on a Square-backed DP | Shows the 9-day retry-window warning, still saves ([27](27-provider-retry-policy.md) §13.7) |
| Same on a Stripe-backed DP | Warning **plus** the "cannot verify" caveat |
| Two DPs, `grace_days` 7 and 30 | Different ladders in one run; `policy_source` recorded per event |

## 30.5 Rollback

| Phase | Rollback |
|---|---|
| P1a | Migration `down()` drops the columns. The sync service is unreferenced |
| P1c | Revert `store()` to accepting the posted tier — **do not**, without replacing the check; this is a hole, not a feature |
| P1b | Drop `laravel_direct_payment_subscriptions`; the `api.ts` call is fire-and-forget and already tolerates failure |
| P2 | `crontab -u {user} -l \| grep -vF '{line}' \| crontab -u {user} -` |
| P3 | `UPDATE laravel_direct_payment_notifications SET is_active = 0` — P4 goes inert immediately |
| P4 | Same as P3, or unregister the schedule entry |
| P5 | Front-end/SDK revert; every addition is additive |
| P6b | Set `dunning_enabled = false` on every row — the resolver falls back to deployment defaults; drop the table to revert fully |
| P6c | Drop `laravel_processor_retry_settings`; restore the literals in `createPayPalPlan()` (§13.8). PayPal plans already PATCHed keep their new threshold — revert those in PayPal, not here |
| P6d | Unregister the action routes; the SDK methods become no-ops. **Do not** restore the fake cancel |
| P6e | Drop `laravel_processor_refunds` and the Connect columns; Money returns to its empty state |
| P6f | Remove the registry entry; coordinator stops pulling the source |

`billing/11`'s own rollback table covers P0, P6, P7 and P8.

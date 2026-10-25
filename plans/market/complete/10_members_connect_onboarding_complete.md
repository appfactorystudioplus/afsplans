# Step 10 — Members: the Connect dashboard, self-hosted with embedded components — completion report

**Plan:** [10_members_connect_onboarding.md](../10_members_connect_onboarding.md)
**Depends on:** [08](../08_stripe_connect_brain_api.md) (complete), [04](../04_internal_cross_api.md) (complete).
**Blocks:** the paid-listing gate in [06](../06_members_asset_publishing.md)/[07](../07_members_freelance_publishing.md); [16](../16_payouts_fees_and_ledger.md).

---

## What shipped

### §10.1 — what this replaced

Confirmed by direct inspection before writing anything: the hand-rolled bank/PayPal/Stripe payment-method selector (session-backed, masked `****1234`) was **already fully deleted** from `AssetStoreController.php`/`FreelanceController.php`, their TypeScript counterparts, both templates, and `routes/web.php` — all in step 04's own pass, with docblocks already explaining the removal. The one place the deletion was incomplete: `resources/js/i18n/en.json` still carried 38 unreferenced `freelance.payment.*`/`assetstore.payment.*` keys (verified unused by grep against every `.ts`/`.html` file first). Removed.

Two of those existing docblocks (`AssetStoreController.ts`, `AssetStoreController.php`) named the deleted methods literally (`getPaymentMethods`/`addPaymentMethod`/`deletePaymentMethod`), which made this step's own §10.9 grep check (`addPaymentMethod` → zero) fail on prose, not code. Reworded both to describe the removal without repeating the literal strings — same discipline the codebase already applies to the *other* removed API's name in the same comments.

### §10.2/§10.3 — the onboarding UI, three states

`resources/js/market/ConnectOnboarding.ts` — `ConnectService` (no DOM: `getDp()`/`createAccount()`/`getStatus()`/`startOnboarding()`, shared by the full page and the inline widget so there is exactly one implementation of "what does the mode-agnostic call sequence actually do"), `ConnectOnboarding` (the `payouts` view — states 1/2/3), and `mountInlineConnectPrompt()` (the reusable inline widget the publish wizards mount).

- **State 1 (not started):** one button; `dp.connect.createAccount({member_reference_num, email, display_name, country})` — email/display_name/country come from `brain_memberships` via `ConnectController::status()`, never typed by the seller.
- **State 2 (in progress):** `notification_banner` mounted above `account_onboarding`; a plain-language summary (`disabled_reason` shown **verbatim**, requirements listed) from a live `dp.connect.getStatus()` read.
- **State 3 (active):** `account_management`/`balances`/`payouts`/`documents` mounted; `future_requirements` surfaced as a warning when Stripe returns any (best-effort — see gaps).
- The three-line mode-agnostic call from §10.3's own worked example is exactly `ConnectService.startOnboarding()`'s body — `if (handle.mode === 'hosted') redirect; else if 'api' show requirements; else mount`.
- `onExit` is read directly off the mounted `<account-onboarding>` custom element (`ConnectMount` does not expose Stripe's native `exit` event) — the same treatment plan step 09's `connect_setup/` playground already uses for the identical reason.
- Component features: `disable_stripe_user_authentication`/`external_account_collection` were **already wired** in `ConnectAccountService::startEmbeddedOnboarding()` (step 08) — confirmed by reading it before assuming this step needed to add them; nothing to do here.

### §10.4 — the flow, both load-bearing properties

- **"`onExit` is not proof of completion."** Every exit path (embedded `exit` event, and the hosted-redirect re-entry — see below) calls `ConnectController::sync()`, which re-fetches from brain and checks `member_reference_num` before writing anything, never trusts the callback firing or the browser's own claim.
- **"Account events keep the mirror fresh afterwards."** Partially built — see "Known gaps", this is the one property this step does not fully close.

**A real integration gap found and fixed while building this**: `MEMBERS_CONNECT_RETURN_PATH`/`_REFRESH_PATH` (already present in `.env` from an earlier pass) point at `/services/assetstore?connect=return`, not at the `payouts` view. Without a check for that query string, a seller sent through the hosted-mode fallback (§10.2's `proof_of_liveness` case) would land back on the asset listing with no re-sync and no path back to `payouts` — silently stranded. Added the same three-line check to `AssetStoreController.ts` and `FreelanceController.ts`'s `init()`: see `?connect=...`, clear it, navigate to `payouts`. `ConnectOnboarding.init()` then treats that arrival the same as `onExit` — one `syncStatus()` call before rendering.

### §10.5 — the publish gate, three enforcement layers

| Layer | This step's part |
|---|---|
| Market `POST /publish` (`422 MARKET_E_PAYOUTS_REQUIRED`) | Already existed (step 04 §04.4 rule 6, `seller_profiles.charges_enabled`) — untouched here. |
| Wizard inline mount | `AssetPublishWizard.ts`/`GigEditor.ts`'s `payoutsHint` — previously a `disabled` placeholder button with a "not available yet" tooltip — now calls `mountInlineConnectPrompt()` into a div appended *inside* the same gate-error panel. No wizard state is touched; `onReady()` re-attempts `publish()` automatically once `charges_enabled` flips true. |
| Listing-table badge | `AssetStoreController.ts`: exact — `!charges_enabled && status !== 'published' && price > 0`. `FreelanceController.ts`: a documented simplification — gig pricing lives on packages, not the row the services list returns, so the badge shows for any non-published listing while payouts are unset rather than narrowing to "would actually be paid" (see "Known gaps"). |

`GigEditor.ts` had `GATE_ERROR_TAB['MARKET_E_PAYOUTS_REQUIRED']` mapped but never rendered the hint at all (an asymmetry with `AssetPublishWizard.ts`, found while reading both side by side) — added, matching the wizard's own treatment.

### §10.6 — platform obligations (recorded, with an owner)

| Obligation | Owner | Note |
|---|---|---|
| Negative balances land on the platform account | Platform finance / step 16's ledger | `requirements_collector: application` (derived from `dashboard: none`) means a refund/chargeback against an empty seller balance is the platform's, not Stripe's — step 16's ledger must show this honestly, not net it away. |
| Risk monitoring | Platform trust & safety | Stripe does not intervene on `dashboard: none` accounts; the platform owns fraud/abuse monitoring for connected sellers. |
| Requirement-change tracking | Platform ops | Embedded components handle *collection* (a seller sees new requirements in the UI); the platform still owns noticing, communicating, and chasing unresolved requirements — nothing in this step builds proactive outreach (e.g. an email when `future_requirements` first appears). |
| Seller support | Platform support | Stripe will not field a support request from these sellers, and they cannot reach Stripe directly — every payout/KYC question routes to the platform's own support channel. |

Reversibility: switching `integration_mode` to `express` in the processor's Connect profile (step 08) is the only change needed to shed the platform-liability obligations above — no file in `members/` changes, confirmed by construction (this UI never branches on mode, only on `handle.mode`, which is a completely different, orthogonal value).

### §10.7 — earnings and payouts

`resources/js/market/EarningsView.ts` — ledger table, CSV export (client-side `Blob`, no new endpoint), Request Payout (`window.prompt`, proxies to the same not-yet-built market endpoint `AssetStoreController.php`'s own `requestWithdrawal()` already targets — `POST /sellers/{ref}/payouts`, step 16 §16.4). Mounted into state 3 below the balance/payout Stripe components (`ConnectComponents.ts`).

**Deviation, documented**: §10.7 specifies a ledger table of (date, item, gross, platform fee, net, status). `SellerLedgerEntry` (plan step 03) does not carry that breakdown — one signed `amount` per row, plus `type`/`memo`/`balance_after`, no gross/fee split, no status column, and `SalesController::ledger()` (market) returns the rows unjoined. Per `SellerController::dashboard()`'s own existing comment, this granularity is "step 16['s]... authoritative LedgerService," not something to invent here by joining `OrderItem` inside a members-facing read. `EarningsView.ts` renders what the row actually has (date, type/memo as "item", net amount, type as "status") rather than fabricating gross/fee numbers. Flagged as a follow-up for step 16.

---

## Files created

| File | Purpose |
|---|---|
| `members/resources/js/market/ConnectOnboarding.ts` | `ConnectService`, the `payouts` view controller (3 states), `mountInlineConnectPrompt()` |
| `members/resources/js/market/ConnectComponents.ts` | `mountConnectComponent()` — thin wrapper over `dp.connect.mountComponent` |
| `members/resources/js/market/EarningsView.ts` | Ledger table, CSV export, Request Payout |
| `members/resources/js/templates/views/payouts.html` | The `payouts` view shell |
| `members/app/Http/Controllers/Market/ConnectController.php` | `status()`/`sync()`/`ledger()`/`payouts()`/`requestPayout()` |
| `members/app/Services/Market/BrainConnectClient.php` | Unsigned server-side re-verification read from brain |

## Files modified

| File | Change |
|---|---|
| `members/resources/js/controllers/AssetStoreController.ts` | Payouts nav button, "Payouts required" badge (exact price>0 check), hosted-redirect re-entry check, docblock reworded |
| `members/resources/js/controllers/FreelanceController.ts` | Payouts nav button, badge (documented simplification), hosted-redirect re-entry check |
| `members/resources/js/templates/views/assetstore.html` | Nav-button row added |
| `members/resources/js/templates/views/freelance.html` | Payouts button added to existing nav row |
| `members/resources/js/views.ts` | `payouts` subview registered (lazy import, matching `seller_profile`/`gig_editor`'s own pattern) |
| `members/resources/js/i18n/en.json` | 38 stale `*.payment.*` keys removed; ~40 `market.connect.*` keys added *(JSON — no comment tag possible, same unavoidable exception as every prior step's JSON file)* |
| `members/resources/js/market/AssetPublishWizard.ts` | `payoutsHint` button made live (inline mount + auto-retry) |
| `members/resources/js/market/GigEditor.ts` | Same treatment added — was previously missing entirely despite the tab mapping existing |
| `members/config/market.php` | `brain.*`, `connect.*` config sections added |
| `members/routes/web.php` | `ConnectController` import + 5 `/api/connect/*` routes |
| `members/.env` (root only — `_env/dev`/`_env/prod` never touched, per standing instruction) | `VITE_MEMBERS_CONNECT_REFRESH_PATH` (was missing its mirror), `DIRECT_PAYMENTS_REF`/`VITE_DIRECT_PAYMENTS_REF` (not in §10.8's own env list — see "Decisions") |
| `members/app/Http/Controllers/AssetStoreController.php` | Docblock reworded (grep discipline, see above) |
| `market/app/Http/Controllers/Internal/SellerController.php` | `updateConnectStatus()` — see "Decisions" for why this step touches market at all |
| `market/routes/internal.php` | `PUT /sellers/{ref}/connect-status` registered |

---

## Decisions made during execution that diverge from the plan

1. **Two market-app files were modified even though §10.8's Files section names only members files.** The plan's own flow diagram (§10.4) draws `POST market /sellers/{ref}/connect-status → seller_profiles updated`, and `SellerProfile.php`'s pre-existing header comment (written in an earlier step) calls this "the step-10 callback" — but no endpoint existed on market to receive it, and nothing on brain was built to send it (see gap #1 below). Since the alternative — giving brain its own signed channel into market — is materially larger than this step's stated scope (a new brain↔market shared secret, a new verifier on market's side) and §10.9's own checklist requires `seller_profiles.charges_enabled` to actually become `true`, the pragmatic, narrowly-scoped fix was routing the write through members' *existing* signed members→market channel (step 04's `MarketClient`, already trusted) instead. `ConnectController::sync()` re-verifies against brain first — the same trust requirement the diagram implies, just reached via the channel that already exists rather than one this step would have had to invent.

2. **`DIRECT_PAYMENTS_REF`/`VITE_DIRECT_PAYMENTS_REF` added to members' `.env`, not listed in §10.8's own env list.** §10.3's own code sample requires `cfg.directPaymentRef`, and `dp.connect` is reached through a Direct Payment (step 08 §08.5.1) — nothing else in this app supplied one for Connect specifically. Reused the exact value (`eq4W10iF8`) `apps/market/.env`'s `DIRECT_PAYMENTS_REF` already uses — one processor, one Connect profile, so any Direct Payment on that processor resolves the same `dp.connect` surface; there was no reason to provision a second one.

3. **The SDK script tag is injected programmatically, not added as a static `<script>` tag** (§10.8 lists "the SDK script tag on the members pages that mount components" as a file change). `payouts.html` is an HTML-string fragment inserted by the client-side router — a `<script src>` inside `innerHTML`-assigned markup does not execute. `ConnectService.loadPortalSdk()` injects it lazily on first use instead, mirroring the exact pattern brain's own `EmbeddedHost.ts`/`StripeClient.ts` already use for *their* third-party scripts. Documented here rather than silently deviating.

4. **`onboarding_status: 'restricted'` is inferred, not read directly.** `ConnectController::sync()` has no record of the account's *previous* status to compare against without an extra market round trip, so a restricted account is approximated as `details_submitted && a disabled_reason is present`. Verified in isolation with a small standalone test (see "How this was verified") — correct for the four cases exercised, but it is an approximation, not an authoritative read of a state-transition Stripe reports directly.

5. **`EarningsView.ts` renders the ledger's actual columns, not the plan's literal (date, item, gross, fee, net, status) list.** See §10.7's write-up above — `SellerLedgerEntry` does not carry gross/fee/status at the row level yet; inventing that breakdown here would mean joining `OrderItem` inside a members-facing endpoint, which is step 16's "authoritative LedgerService" territory, not this step's.

---

## Known gaps / deferred

1. **The async, brain-initiated half of "account events keep the mirror fresh afterwards" (§10.4) is not built.** `SyncConnectAccountJob` (step 08) fires on account webhooks and updates brain's own `ProcessorConnectedAccount` row, but step 08's own completion report already flagged that it never pushes to market — this step does not close that gap either, for the same reason decision #1 above explains (it requires a new brain→market channel, materially larger than this step's file scope). **Concretely**: if a seller's account is restricted by Stripe *while they are not actively re-visiting the payouts page*, market's `seller_profiles.charges_enabled` mirror will not update until the seller happens to trigger a `sync()` call again (visiting `payouts`, exiting an onboarding component, or landing via a hosted redirect). This step's own §10.9 checklist item ("Restricting the test account flips `charges_enabled`… via the account event") is the one item this report cannot claim as done — it needs either live Stripe + a wired brain→market push to verify, and the push half does not exist yet. Recorded as a follow-up.
2. **No live Stripe test-mode account this session** (same standing gap as every step since 02). Everything checkable via static analysis, type-checking, and isolated logic tests was checked (see below); every §10.9 item that requires a real embedded component actually mounting, a real `onExit` firing, or a real account restriction is inference from code review, not observed execution.
3. **`future_requirements` on brain's `ConnectAccountStatus` is always `[]` today** (a gap step 09's own completion report already documented) — state 3's "restricted in 30 days should say so now" panel is wired correctly but has nothing to show until that field is actually persisted server-side.
4. **`FreelanceController.ts`'s "Payouts required" badge cannot narrow to price>0** — see Decision/§10.5 above; it shows for any non-published, non-free-looking gig while payouts are unset, which may over-warn on a gig whose only packages happen to be free.
5. **`api` onboarding mode's requirements list is read-only in this UI** — no submission form exists (matching step 08's own documented gap: `uploadDocument()`/requirement-submission UI was never built, `api` mode is not the platform default).

---

## How this was verified

- **`php -l`** on every created/modified PHP file (both members and market) — clean.
- **`tsc --noEmit --skipLibCheck`**, isolated (the project's real `tsconfig.json` is blocked by a pre-existing, unrelated `ignoreDeprecations: "6.0"` vs. installed `tsc` 5.9.3 version mismatch — not something this step touched or can fix) — every file this step created or modified: zero errors. A real bug this caught: `mountInlineConnectPrompt()`'s `accountRef` was originally declared inside a `try` block but read from a nested function declared outside it — `tsc` correctly flagged the scoping error before this ever reached a browser; fixed by hoisting the declaration.
- **JSON validity** (`en.json`) confirmed via `python3 -c "import json; json.load(...)"` after both the deletion and the addition passes.
- **A standalone PHP snippet** (no framework, pure logic) exercised the four `onboarding_status` classification branches `ConnectController::sync()` computes (`active` / `in_progress` / `restricted` / the default) — all four passed against their expected output.
- **Grep checks from §10.9**:
  - `grep -rn "bankAccount\|bankRouting\|addPaymentMethod" members/resources/js members/app` → clean except `resources/js/checkpointHelper/static/portal.d.ts`'s own `addPaymentMethod()` declarations, which are a *different, legitimate* feature (the Direct Payments SDK's vaulted-card-for-checkout method, not the deleted seller payout-method CRUD) — the same name used for two unrelated concepts. Excluded deliberately, not missed.
  - `grep -rn 'session("assetstore\|session("freelance'` → zero (unchanged from step 04).
  - `MARKET_MODIFIER_ADDITION` present in all 19 created/modified non-JSON files; reconciled against this report's own Files tables — no file in the list is untagged, no tagged file is missing from the list.
- **Structural review, not live execution**, for everything requiring a browser + a real Stripe test account: the three-state branch logic, the `onExit`/hosted-redirect re-verification paths, the wizard's inline-mount-without-losing-state design, and the mode-agnostic `startOnboarding()` call were all read through line by line against §10.3/§10.4's own worked examples rather than exercised — consistent with every prior step's "no live Stripe access" treatment.

---

## Follow-ups spawned

- Give brain a way to push connected-account status changes into market (§10.4's async half) — likely belongs to step 16, which already owns the ledger/payout reconciliation this would feed.
- `future_requirements_json` column + sync-method wiring (step 09's own follow-up, still open).
- A proactive notification when `future_requirements` first appears on an account (§10.6's "requirement-change tracking" obligation currently has no automation, only a passive UI surface).
- `EarningsView.ts`'s gross/fee/status columns, once step 16's `LedgerService` exists to compute them.

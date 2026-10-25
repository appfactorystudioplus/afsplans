# Step 09 — Brain: the Connect playground examples — completion report

**Plan:** [09_direct_payments_connect_example.md](../09_direct_payments_connect_example.md)
**Depends on:** [08](../08_stripe_connect_brain_api.md) (complete — see
[08_stripe_connect_brain_api_complete.md](08_stripe_connect_brain_api_complete.md)).
**Blocks:** 10 (members mounts the same components), 13 (market's checkout is written against these).

---

## Sequencing note

Per the plan's own header, this step runs interleaved with step 08 rather than strictly after it.
In practice both landed in the same session, step 08 first. `connect_setup/` needed §08.5/§08.6/§08.7.5
(profile, account creation, onboarding, components, status); `marketplace_split/` needed §08.7 (the
split charge path) plus connected accounts to charge against. Building `marketplace_split/` is what
surfaced the gap described in "Decisions" below — the split path step 08 shipped could not actually be
driven from a browser as written, which this step fixes rather than works around.

---

## What shipped

### The two playground pages (§09.1–§09.4)

`apps/www/public/playground/direct-payments/connect_setup/` and `.../marketplace_split/`, each an
`.html` + `-example.js` pair copying `single_charge/`'s CSS custom properties, Inter/Outfit font
pairing, `.item-card`/`.status-ok`/`.status-error` classes, IIFE-with-`'use strict'` structure, and
`window.Portal.httpUrl` literal (the one documented exception to step 01 D5, noted in both files'
header comments).

**`connect_setup/`** — all nine numbered items from §09.2:
1. Read-only profile panel from `dp.connect.getProfile()`, with the "configured in the dashboard,
   this page cannot change it" line and a link to `/portal/admin`.
2. A derived-values panel computed client-side from `integration_mode`
   (`self_hosted`/`express`/`full_dashboard` → `dashboard` → `requirements_collector` → KYC/negative
   balances/seller-sees), with the `self_hosted` row exact and the other two labelled as a
   best-effort approximation (see "Decisions" — `requirements_collector` for those two is not
   derivable from the *public* profile alone).
3. Account creation whose request body is displayed alongside the button and contains no mode
   fields — verified by reading the code (the `opts` object literal is `{email, display_name,
   country, entity_type}` only) and by grep (see "How this was verified").
4. `startOnboarding()` through the one three-line code path from the plan's own worked example, with
   the resolved mode shown as a badge, and an on-page instruction to change `onboarding_mode` in the
   dashboard and reload to watch it change.
5. A component gallery mounting `account_management`, `notification_banner`, `payouts`, `balances`,
   `documents` via `mountComponent()`.
6. A status panel from `getStatus()` — `charges_enabled`, `payouts_enabled`, `details_submitted`,
   `applied_configurations`, `requirements.entries[]`, `disabled_reason`, `future_requirements`.
   `applied_configurations`/`future_requirements` did not exist on `ConnectAccountStatus` before this
   step — added, see "Decisions".
7. An `onExit`-is-not-completion demonstration: listens for Stripe Connect.js's own `exit` event on
   the mounted `<account-onboarding>` element (see "Decisions" — `ConnectMount` does not expose this,
   so the page reads it directly off the DOM) and re-renders the status panel to show it still
   incomplete.
8. Both deliberate absences: a button that calls the non-existent `dp.connect.saveProfile(...)` and
   renders the resulting `TypeError`; an unsupported-provider panel from `isSupported()`/`support()`
   that renders `{supported:false, reason:'connected_accounts_unsupported'}` instead of throwing when
   pointed at a non-Stripe-backed Direct Payment.
9. A commented "what the server validates" block listing `ConnectProfileService::validate()`'s five
   rules and their error codes.

**`marketplace_split/`** — all nine numbered items from §09.3:
1. A fixture two-seller cart (`SELLERS` array — "Nova Forge Studio" / "Ambient Loop Collective"),
   items assigned round-robin so toggling checkboxes alone changes which sellers are present.
2. A live split preview grouping selected items by `seller_ref`, computing `fee =
   floor(line_net * platform_fee_percent / 100)` client-side (mirroring
   `SplitCalculator::computeLineFee()`) for preview purposes only — the server recomputes
   independently and is authoritative.
3. A mode badge (`destination`/`separate_transfers`) driven by `activeInvoice.getSellerRefs().length`,
   flipping live as items are checked/unchecked.
4. The full invoice lifecycle mirroring `single_charge`: `create(ref)` → `initializeDirectPayment()`
   → `createInvoice()` → `addItem({item_ref, quantity, seller_ref})` → `lock()` →
   `setupSinglePayPayment()` → mount → `confirmPayment()`.
5. Cart mutation after lock calls `destroyActivePaymentFlow()` / unlocks, reproducing
   `single_charge`'s own `destroyPaymentSession()`/`modifyCartBtn` behaviour.
6. Per-seller readiness via `getStatus()` before allowing lock — checks `charges_enabled` and
   `applied_configurations.includes('recipient')` per seller, naming which seller and which specific
   reason blocks the lock (no configured accountRef vs. charges not enabled vs. missing recipient
   config). One fixture seller (`seller-c-not-ready`, used only in the standalone verification
   harness, not the page's default two) demonstrates the missing-recipient case end to end — see "How
   this was verified".
7. A result panel showing PaymentIntent id, `transfer_group`, and application fee, with an explicit
   note that per-transfer status for `separate_transfers` is not available synchronously (transfers
   are created later, from the webhook) and points at `ProcessorTransferIntent` rows / Stripe's
   dashboard instead.
8. Every failure path renders in the status/result panel rather than throwing — see "Known gaps" for
   which of these (insufficient balance, declined card) need live Stripe to actually trigger.
9. A commented "what the server checks" block listing all five §08.7 invariants inline, plus a note
   that this page never builds a raw `split` itself except in the tamper test.

Both pages carry `MARKET_MODIFIER_ADDITION` in their opening comment (§09.1).

### The wiring gap this step found and fixed

Building `marketplace_split/` surfaced a real, load-bearing gap in what step 08 shipped:
`ProcessorIntentController::createIntent()` accepted a `split` block but expected the caller to have
**already resolved every destination to a raw Stripe account id** — something the public `dp.connect`
surface deliberately never exposes (`ConnectedAccount.reference_num`, never `stripe_account_id`,
§08.5.3's naming rule). No code path existed to get from a browser-known `seller_ref` to a
server-validated `split`. `SplitCalculator::buildSplit()` existed and did exactly the needed
resolution, but nothing called it. Fixed — see "Decisions" for the full account.

### Documentation (§09.5)

- `resources/js/external/connect/CONNECT_DOCUMENTATION.md` — extended (not recreated) with: the
  profile shape, the `integration_mode → dashboard → requirements_collector` derivation table, the
  three onboarding modes with their trade-offs, the `OnboardingHandle` union, the component list, the
  v1-endpoint/v2-account-id note for `AccountSession`, and an Examples section linking both pages.
- `resources/js/external/processors/PROCESSOR_DOCUMENTATION.md` — new §15 "Marketplace Split Payments
  (Stripe Connect Accounts v2)": the two split modes and when each is chosen, the `split`/`split_items`
  payload shapes, the five invariants, the transfer lifecycle, and links to both examples. The
  existing §10 ("Stripe Connect Accounts", the pre-step-08 v1 sketch) got a "superseded by §15" note
  rather than a rewrite — out of this step's scope. Fixed a pre-existing Table-of-Contents bug found
  while editing: the ToC never had an entry for §10 at all, so everything after it was off-by-one
  against the body headings; corrected while renumbering for the new §15.
- `resources/js/external/direct_payments/README.md` — new (none existed). Covers the `Invoice`
  lifecycle, the `seller_ref`/`hasSplit()`/`getSplitItems()` split-attribution addition, the main
  `ExternalDirectPaymentsAPI` methods, `dp.connect`, and links to all three playground folders.

---

## Files created

| File | Purpose |
|---|---|
| `apps/www/public/playground/direct-payments/connect_setup/direct-payments-connect-setup.html` | Connect setup playground markup |
| `apps/www/public/playground/direct-payments/connect_setup/direct-payments-connect-setup-example.js` | Connect setup playground logic |
| `apps/www/public/playground/direct-payments/marketplace_split/direct-payments-marketplace-split.html` | Marketplace split playground markup |
| `apps/www/public/playground/direct-payments/marketplace_split/direct-payments-marketplace-split-example.js` | Marketplace split playground logic |
| `apps/www/resources/js/external/direct_payments/README.md` | New — the directory had none |

## Files modified

| File | Change |
|---|---|
| `apps/www/app/Http/Controllers/Externals/Processor/ProcessorIntentController.php` | `createIntent()` accepts `split_items`/`transfer_group`, calls `SplitCalculator::buildSplit()` server-side when present, before `validateInvariants()` |
| `apps/www/resources/js/external/connect/ConnectTypes.ts` | `ConnectAccountStatus` gains `applied_configurations`, `future_requirements` |
| `apps/www/resources/js/external/connect/providers/StripeConnectProvider.ts` | `getStatus()`/`submitRequirements()` map the two new fields |
| `apps/www/resources/js/external/processors/ProcessorTypes.ts` | `CheckoutOptions` gains `split_items`, `transfer_group` |
| `apps/www/resources/js/external/processors/StripeClient.ts` | `create-intent` request includes `split_items`/`transfer_group`; `confirmPayment()` returns the raw `paymentIntent` as `raw` |
| `apps/www/resources/js/external/direct_payments/api.ts` | `Invoice._items` type gains `seller_ref`; new `Invoice.hasSplit()`/`getSellerRefs()`/`getSplitItems()`; `setupSinglePayPayment()` attaches split fields automatically; `confirmPayment()`'s return type/body carry `processor_result` |
| `apps/www/resources/js/external/portal.d.ts` | Mirrors all of the above on the public declaration surface |
| `apps/market/resources/js/portal.d.ts` | Same — kept byte-identical to the `apps/www` copy except the deliberate cross-reference comment (pre-existing convention from step 08) |
| `apps/www/resources/js/external/connect/CONNECT_DOCUMENTATION.md` | Extended per §09.5 (see above) |
| `apps/www/resources/js/external/processors/PROCESSOR_DOCUMENTATION.md` | New §15, superseded-note on §10, ToC fix |

No migrations, no new env/config keys.

---

## Decisions made during execution that diverge from the plan

1. **`createIntent()` gained a `split_items` input and server-side `buildSplit()` call — not
   explicitly specified by step 08's own text, but necessary for `marketplace_split/` to work at
   all.** Step 08 shipped `ProcessorIntentController::createIntent()` accepting a pre-built `split`
   block and validating it, but nothing in step 08 built that block from a browser-known `seller_ref`
   — `SplitCalculator::buildSplit()` existed and did the exact resolution needed, but was never
   called from the controller. Since `ConnectedAccount` deliberately never exposes a raw
   `stripe_account_id` to the browser (§08.5.3), no browser-only caller could have built a valid
   `split` itself. This step's own §09.6 ("Expected rework") named this exact possibility — "seller_ref
   may be better derived server-side" — so the fix is squarely inside what step 09 anticipated needing
   to touch. Added `split_items: [{seller_reference_num, order_item_reference_num, line_net}]` +
   `transfer_group` as an alternate input; when present and `split` is absent, the controller calls
   `buildSplit()` to resolve real destinations before running the same `validateInvariants()` check
   that already existed. The pre-built `split` path is untouched and still works for a
   server-to-server caller (e.g. a future market checkout) that already resolved its own accounts.

2. **`Invoice._items`'s TypeScript type gained `seller_ref`, which `addItem()` was already writing
   without declaring.** A pre-existing type gap (`addItem()`'s push included `seller_ref:
   options.seller_ref` into an array typed without that field) — found while adding
   `getSplitItems()`, which needed to read it. Declared, not invented.

3. **`ConnectAccountStatus` gained `applied_configurations`/`future_requirements`, and
   `StripeConnectProvider` now maps them.** §09.2.6 requires the status panel to show both.
   `applied_configurations` maps to the real, already-persisted `configurations` column — a pure
   wiring fix. `future_requirements` is genuinely not persisted anywhere server-side today (Stripe
   returns it — `ConnectAccountService::INCLUDE_FIELDS` already requests it — but
   `syncFromV2Response()`/`syncFromV1Response()` never save it to a column). Rather than add a
   migration+column to close this inside step 09 (a step-08-shaped change), the type/mapping exist so
   the field is visible once a local column does, and `getStatus()` returns `[]` for it today. Listed
   below as a known gap, not silently fixed.

4. **`connect_setup/`'s derived-values table for `express`/`full_dashboard` is a labelled
   approximation, not an exact derivation.** §09.2.2's worked example is written for `self_hosted`,
   which is exact and provable: `ConnectApi.validateProfile()` already enforces `self_hosted ⇒
   losses_collector: application`, and application `losses_collector` always yields an application
   `requirements_collector`. The public profile (§08.5.5) deliberately never exposes
   `losses_collector` for `express`/`full_dashboard`, so those two rows cannot be derived exactly from
   what a caller can see — the page says so explicitly and states that `getStatus()`'s real value is
   authoritative. This directly affects one §09.7 checklist item (see "How this was verified").

5. **The `onExit` demonstration reads Stripe's native `exit` DOM event directly off the mounted
   custom element, not through `ConnectMount`.** `ConnectMount` (`{unmount(): void}`) does not expose
   it, and adding an `onExit` option to `mountOnboarding()`/`mountComponent()`'s signature would be a
   step-08-shaped API change for a step-09 example page. Since `EmbeddedHost.ts` appends the real
   Stripe custom element into the target container and never removes it except on `unmount()`, the
   page queries for it after mounting and attaches the listener itself — functionally identical, no
   API surface change.

6. **`PROCESSOR_DOCUMENTATION.md`'s Table of Contents got a numbering fix alongside the new §15.**
   Pre-existing bug, unrelated to this step's feature work: the ToC listed 13 items ending at
   "13. Security Notes", but the body has 14 numbered sections because "10. Stripe Connect Accounts"
   was added to the body at some point and never added to the list — everything from that point was
   off by one in the ToC only (body headings were correct). Fixed while adding §15 rather than left
   sitting next to a new, correctly-numbered entry.

---

## Known gaps / deferred

Consistent with steps 02–08: **no live Stripe test-mode account was available this session.**
Everything checkable via static analysis, type-checking, and a standalone PHP harness (no Laravel
boot — `php artisan` remains broken in this repo, see step 08's report) was checked; everything
requiring a real browser session against a real Stripe test account is listed here rather than
claimed.

1. **The four dashboard-configuration-change checklist items** (§09.7): "changing `integration_mode`
   … updates the derived panel for all three values", "changing `onboarding_mode` … flips the badge",
   "embedded onboarding completes with no `stripe.com` navigation", "switching to `hosted` changes
   only the badge". All four require live editing of a real Processor's `extra_config.connect` plus a
   real page reload against a booted app — the code path is a straight read of `getProfile()`/
   `startOnboarding()`'s response with no caching beyond the page load, so there's no reason it
   wouldn't work, but that's an inference, not a verification.
2. **The unsupported-provider panel** needs a real Direct Payment backed by a PayPal or Square
   processor to exercise end to end. `PayPalConnectProvider`/`SquareConnectProvider` (step 08)
   already report `{supported:false, reason:'connected_accounts_unsupported'}` by construction — read,
   not run.
3. **`onExit` firing** needs a live embedded Connect.js session — the listener is attached
   correctly (verified by reading `EmbeddedHost.ts`'s mount mechanics) but never actually observed
   firing.
4. **The account-creation live-Stripe checklist items**: `dashboard:"none"`/`requirements_collector:
   "application"` round-trip, the admin dashboard's `self_hosted` + `losses_collector: stripe`
   rejection (pre-existing from step 08, not built by this step), a request without the preview
   version string failing visibly. All server/live-Stripe behavior, none of it touched by this step's
   changes.
5. **`marketplace_split/`'s full test-mode purchase** (one charge, two transfers, one
   `transfer_group`, visible in Stripe's dashboard) — needs live Stripe. The pieces that don't need
   it — split arithmetic, `buildSplit()`'s account resolution, `validateInvariants()`'s rejections —
   were verified directly (see below).
6. **Declined-card and insufficient-balance rendering** — the `try`/`catch` structure around
   `confirmPayment()` renders any thrown error in the status/result panel rather than letting it
   propagate uncaught (verified by reading the code), but actually triggering Stripe's specific
   decline/insufficient-funds test cards needs live test-mode credentials.
7. **`future_requirements` is always `[]`** — see Decision 3. Needs a migration + sync-method change
   that belongs to step 08's shape of work, not invented here.
8. **The two example pages were never loaded in a real browser against the compiled `sdk.js`.** This
   repo's browser tooling renders files outside the current project directory (JoystickEngine_v2) as
   static snapshots only — `fetch()`/`sdk.js` do not execute. Verified instead: HTML parses cleanly
   (Python's `html.parser`, no errors), zero duplicate element ids, every `getElementById()` call in
   both `-example.js` files resolves to a real id in its own `.html` (checked by grep, see below), and
   both JS files pass `node --check`.

---

## How this was verified

- **`node --check`** on both new `-example.js` files — no syntax errors.
- **`php -l`** on every modified/created PHP file — no syntax errors.
- **HTML structure**: Python's `html.parser` fed both new `.html` files — no parse errors reported.
  Zero duplicate `id` attributes in either file. Every `document.getElementById('...')` call in each
  `-example.js` file resolves to an `id="..."` present in its own `.html` (cross-checked by grep, both
  directions clean).
- **`tsc --noEmit --skipLibCheck`** (isolated, since the project-wide build is still blocked by the
  pre-existing missing `bootstrap` type-definition issue noted in step 08's report) against every
  modified `.ts` file plus `ConnectApi.ts`/`ConnectTypes.ts` for context: exit 0, zero errors. Also run
  standalone against both `portal.d.ts` copies: exit 0 each.
- **`diff` between `apps/www/resources/js/external/portal.d.ts` and `apps/market/resources/js/portal.d.ts`**:
  only the one deliberate cross-reference-comment line differs, matching the convention step 08
  established.
- **Grep checks from §09.7**:
  - `MARKET_MODIFIER_ADDITION` present in all 15 created/modified files (see Files tables above);
    reconciled against this report's own list — no file in the list is untagged, no tagged file is
    missing from the list.
  - `connect_setup/`'s two files: the only occurrence of `processor_reference_num`/`processorRef`
    anywhere is inside an explanatory *comment string* on the account-creation panel ("processor_
    reference_num is attached internally by StripeConnectProvider") — no actual method call on the
    page passes one. `marketplace_split/`'s tamper-test helper does use `processor_reference_num`,
    but that function deliberately bypasses `dp.connect` to hit the raw
    `/stripe/create-intent` endpoint directly (the endpoint's own pre-existing, unrelated
    requirement) — the checklist item is scoped to `connect_setup/`, not this page.
- **A standalone PHP harness** (no Laravel boot — `Illuminate\Database\Capsule\Manager` +
  `Illuminate\Container\Container`, the same technique step 08's report describes, extended with an
  in-memory SQLite `laravel_processor_connected_accounts` table and three seeded rows: two ready
  sellers, one missing the `recipient` configuration), run directly against `SplitCalculator` — the
  exact class both `createIntent()`'s new `split_items` path and the tamper test exercise:
  - `computeLineFee(4099, 10.0)` → `{fee: 409, net: 3690}`, `fee + net == line_net` — the §09.7 "to
    the cent on a `.99` price" checklist item, passing.
  - The plan's own §09.3.2 worked example ($40.00 + $20.00 @ 10%) reproduced exactly: fees $4.00 /
    $2.00, platform receives $6.00.
  - `buildSplit()` against the two seeded ready sellers: `mode: separate_transfers`, both
    destinations resolved to their real seeded `stripe_account_id`, correct per-seller net amounts,
    and — the round trip that matters — the *built* split passes back through
    `validateInvariants()` with **zero errors**, proving the new `createIntent()` code path (build,
    then validate) is internally consistent.
  - The single-seller case: `buildSplit()` returns `mode: destination` with the correct
    `application_fee_amount`.
  - The third seeded seller (missing `recipient` in `configurations`): `validateInvariants()` returns
    `CONNECT_E_DESTINATION_NOT_READY` — the exact case §09.3.6 asks the readiness panel to catch,
    proven at the layer the readiness check ultimately depends on.
  - The tamper test's own scenario (wrong `transfer_group`, unresolvable destination): rejected with
    `CONNECT_E_TRANSFER_GROUP_MISMATCH` and `CONNECT_E_UNKNOWN_DESTINATION` — proving the tamper-test
    button's expected outcome without needing live Stripe, since `validateInvariants()` runs entirely
    before any Stripe API call.
  - All 8 checks passed. This is real execution of the actual shipped `SplitCalculator` code (not a
    reimplementation), just without Laravel's HTTP layer or a real database.

---

## Follow-ups spawned

- `future_requirements` needs a `future_requirements_json` column and a one-line addition to both
  `syncFromV2Response()`/`syncFromV1Response()` to stop always being `[]` — small, but belongs to
  step 08's file set, not manufactured here.
- The four live-dashboard-configuration checklist items, the unsupported-provider panel, `onExit`
  firing, and the full test-mode purchase all need a real browser session against a booted app with
  live Stripe test-mode credentials — flag when that environment exists; nothing about the current
  implementation is expected to need code changes to pass them, based on the code-level verification
  above.
- `PROCESSOR_DOCUMENTATION.md` §10 (the pre-step-08 v1 Connect sketch) is now explicitly marked
  superseded but not removed or rewritten — worth deleting once nothing links to it, but that's
  unrelated churn for this step.

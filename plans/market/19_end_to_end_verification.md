# Step 19 — End-to-end verification

**On completion:** write `complete/19_end_to_end_verification_complete.md`.
**Depends on:** every prior step.

The individual steps each verify their own surface. This step verifies the **seams** — which is
where four independently-correct projects still produce a broken product.

---

## 19.1 Preconditions

- All four projects deployed to the sandbox environment.
- `MARKET_INTERNAL_API_KEY` byte-identical in members and market.
- `/mnt/node2/market/members` mounted and writable.
- Stripe in **test mode**; the seller's connected account fully onboarded.
- Seeded per step 17 with `--empty` — the buyer must walk the real purchase path.
- Both member refs present in `brain_memberships`: seller `m1xTHpRmdtH2Y`, buyer `m1xJppRmdtH2Y`.

---

## 19.2 The walkthrough

Perform in order, in one session. Record the result of every numbered step in the completion report,
including the ones that pass.

### A — Seller publishes an asset (members → market)

1. Sign into members as `m1xTHpRmdtH2Y`.
2. Confirm Connect shows **Active** (`charges_enabled`).
3. Start the asset wizard. Stage 1 details → **verify a draft row exists in market before continuing**.
4. Stage 2: select a folder containing `bad name.png`, `-flag.txt`, and `CON.cs`. Confirm three
   blocking issues, correct rules, upload disabled.
5. Fix the names, re-analyze, upload. Watch chunked progress; confirm `package.zip` and a matching
   sha256 on disk under `/mnt/node2/market/members/m1xTHpRmdtH2Y/…`.
6. Stage 3: read the default EULA, then author a custom license. Confirm the `market_licenses` row.
7. Stage 4: `joystick_engine`, package name `com.testseller.verifypack`. Confirm uniqueness
   validation by trying a name that already exists.
8. Stage 5: price $29.99, exclusive tax 8.25%. **Confirm the breakdown reads: buyer $32.46, fee
   $2.99, seller $27.00**, and that the 10% disclosure is visible without scrolling.
9. Stage 6: publish. Confirm `status = published` and `published_at` set.

### B — Seller publishes a gig

10. Create a three-tier gig with extras, gallery, FAQ, and four requirements.
11. Confirm the 10% disclosure on every tier.
12. Attempt Standard priced below Basic → rejected client- and server-side.
13. Publish. Confirm one `services` row and three `service_packages`.

### C — Buyer browses (market storefront)

14. Sign out. Load `/assets` as an anonymous visitor. The new asset is listed with its tag chip.
15. Open the detail page. **The license is readable in full without signing in.**
16. Confirm version history, file list, and the engine technical panel.
17. Load the gig page; confirm the package comparison table.
18. **View source on `/`, `/assets`, `/services`, `/checkout`, `/portal`** — every page has a
    fully-formed `sdk.js` src with no empty subdomain, and `window.Portal.httpUrl` is set before it.

### D — Buyer purchases

19. Sign in as `m1xJppRmdtH2Y` — confirm the redirect to `{AUTH_URL}/login` with `app_name=MARKET`
    and the return to `/portal`.
20. Add the asset and the gig's Standard package + one extra to the cart. Confirm the multi-seller
    indicator behaves per the step-08 decision.
21. Checkout. Confirm the license block renders and the checkbox blocks submission.
22. Pay with a Stripe test card. Confirm: order, order items with fee/net, entitlement for the asset,
    `service_order` in `awaiting_requirements` with **`due_at` null**, ledger entries, and the
    Connect transfer in Stripe's dashboard.
23. Reload the return page and re-POST `/checkout/confirm` with the same invoice ref — **still
    exactly one order and one entitlement.**

### E — Buyer portal

24. `/portal` shows the purchase.
25. Download the asset; confirm the file, and an `asset_downloads` row with `source = 'portal'`.
26. View the license — it is the **snapshot**, not the live row. Edit the seller's live license in
    members, reload: the buyer's text is unchanged.
27. Unpublish the asset in members. Reload `/portal`: entry, download and license **all unchanged**.
    Republish.

### F — Seller sees the sale

28. In members: sale notification, updated dashboard figures, ledger showing gross $29.99, fee
    $2.99, net $27.00, and pending (not available) during the clearing period.

### G — Freelance lifecycle

29. Buyer answers the requirements. Confirm the transition to `in_progress` and `due_at` now set.
30. Seller delivers files from members.
31. Buyer requests a revision; confirm `revisions_used` increments.
32. Seller redelivers; buyer accepts. Confirm `completed` and the ledger release.
33. Both leave reviews. Confirm neither publishes until both are in.

### H — The engine

34. Launch the Joystick Editor; sign in via the Launcher as `m1xJppRmdtH2Y`.
35. Open `Windows ▸ Service Assets Market`.
36. Confirm the purchased `joystick_engine` asset is listed and that **no `other`-tagged asset
    appears**. Cross-check against the seed list.
37. Call the engine endpoint directly with `distribution_tag=other` — no `other` assets returned.
38. Install into `MyFirstProject`. Confirm: files under `Packages/`, `.jmeta` generated, Content
    Browser shows them read-only, lockfile written.
39. Publish 1.0.1 from members; refresh the panel; confirm the update badge and a clean update.
40. Uninstall; confirm complete removal and a correct lockfile.
41. Delete `Packages/`, reopen the project, use Restore.
42. Confirm the purchased service is listed read-only and links out to the portal.

### I — Money

43. Refund the asset order. Confirm: charge refunded, transfer reversed, fee returned, entitlement
    revoked, download `403`, the order still visible as refunded in `/portal`, and negative ledger
    entries on the seller.
44. Run reconciliation. Confirm zero discrepancies (excluding rows marked seeded).

---

## 19.2.5 Shared-payments-layer regression — the other two consumers

Step 08 edits code that **every tenant** and all three first-party consumers depend on
(step 01 §01.2.5, D6). Market working proves nothing about the rest.

- [ ] **www membership subscription** — complete a real tier signup through `resources/js/afs`: `setupSubscription()` loads tiers, payment completes, `create.ts` registers the membership with `customerId` / `allocId` / `subscriptionId` / `memberRef`, and the success page renders. **Highest-value regression target in the plan** — it is the one first-party flow that already works end to end, so a break here means step 08 damaged the shared layer.
- [ ] Existing subscription renewals still process — check `invoice.payment_succeeded` handling after the webhook changes.
- [ ] **A non-Connect tenant checkout** — exercise a Direct Payments single charge on a processor with **no** Connect profile. It must be byte-identical to pre-change behaviour: no `application_fee_amount`, no `transfer_data`, no `on_behalf_of` on the PaymentIntent. Inspect the outbound request, not just the outcome.
- [ ] A tenant processor with no Connect profile is never offered Connect behaviour anywhere in the SDK surface.
- [ ] `git diff` on `external/direct_payments/api.ts` contains **no** change to lock/confirm semantics — only additive optional properties.
- [ ] The subscription path in `direct_payments/api.ts`, `ProcessorSubscriptionController` and `ProcessorPlanAdminController` is unchanged. Diff it.
- [ ] Webhook fail-closed rollout: `marketplace:check-webhook-config` run against **all** tenants, results recorded, and every affected tenant's secret configured before the change deploys.
- [ ] PayPal and Square tenant flows still work — the fail-closed fix touched all three providers, not just Stripe.

---

## 19.3 Cross-cutting checks

| Check | How |
|---|---|
| No hardcoded URLs | `grep -rn "appfactory\.studio"` across all four projects' application code (excluding `.env`, `_env/`, `EngineConfig.json`, and the step-09 playground file). Zero hits. |
| No `env()` outside `config/` | Grep market, members, brain app code. |
| Config cache safe | `php artisan config:cache` in all three Laravel projects, then repeat sections C–E. |
| Change index complete | `grep -rn MARKET_MODIFIER_ADDITION` returns every file listed across the 18 completion reports. Any file in a report but missing a tag is a gap. |
| Documentation | Spot-check ten created files for a `/** */` header explaining purpose and reasoning. |
| Identity discipline | No marketplace FK to `users`; no endpoint accepts `member_reference_num` from a client. |
| i18n parity | `en.json` / `es.json` key counts match in both members and the engine. |
| No orphaned files | After `market:clean-dummy-data`, `/mnt/node2/market/members` has no leftover seeded archives. |

---

## 19.4 Security pass

- [ ] Portal endpoints with another member's entitlement ref → `403`/`404`, never data.
- [ ] `?member_reference_num=` appended anywhere changes nothing.
- [ ] Internal API with an invalid, stale, or absent signature → `401`.
- [ ] Empty internal key → fails closed.
- [ ] Signed download URL: expired → `403`; other member → `403`.
- [ ] Zip-slip archive posted directly to finalize → `422`, nothing written outside the root.
- [ ] A tampered split payload (inflated transfer, foreign destination, zeroed fee) → rejected before any Stripe call. All three variants.
- [ ] Card data never reaches market's PHP process — inspect the checkout request bodies.
- [ ] Message bodies render escaped.
- [ ] `/mnt/node2` is not reachable over HTTP by any direct path.

---

## 19.5 Defect log

Every defect found gets a row, and the report is not complete until every row is resolved or
explicitly accepted:

| # | Step | Severity | Description | Repro | Owner | Status |
|---|---|---|---|---|---|---|

Severity: **blocker** (money, data loss, or auth) · **major** (a documented flow does not work) ·
**minor** (cosmetic or an edge case).

**No blocker may be carried.** A major may be deferred only with an explicit written decision naming
who accepted it and why.

---

## 19.6 Sign-off

- [ ] All 44 walkthrough steps pass.
- [ ] All cross-cutting and security checks pass.
- [ ] Zero open blockers.
- [ ] All 18 prior completion reports exist in `complete/`.
- [ ] The default EULA's legal-review flag has been actioned or explicitly deferred with an owner.
- [ ] Deferred scope is listed in one place: multi-seller checkout, hourly contracts, dual-root asset
      scanning, and anything else deferred along the way.
- [ ] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4). Verify by `grep -rn`, and reconcile the result against this step's Files section — a file in the list without a tag, or a tagged file not in the list, are both defects.
- [ ] `complete/19_end_to_end_verification_complete.md` written, containing the completed defect log.

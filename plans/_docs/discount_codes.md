The discount field is now on `/create`, and I've traced how discounts work and fixed what stopped them working. Nothing is committed. I haven't built the front end, run `tsc` or run any tests, and I haven't tried a discount in a browser or on the server.

## How discounts work today

1. **Creating a code.** A code is a canonical record in `laravel_direct_payment_discounts`, owned by the tenant, with a type, duration and redemption limit. An admin creates it with `POST /portal/api/externals/v1/directpayments/discounts` (dashboard auth) and can name processors to provision it on. Stripe gets one reusable coupon; Square and PayPal carry the discount in the price. Any other processor gets its own copy the first time the code is redeemed there.
2. **Which processors can sell which codes.**
   - Stripe supports all durations (once, repeating, forever).
   - Square and PayPal only support `forever`, because they can't end a discount after N cycles.
3. **Applying a code.** The browser sends `discount_code` on the `create-subscription` call. `ProcessorSubscriptionController` then finds the code, creates or reuses the Stripe coupon, puts it on the subscription and counts the redemption. The code is stored on the `ProcessorSubscription` row.
4. **Dev server.** There are no discount codes at all on the dev server (both discount tables are empty), so you'll need to create one to test. I didn't find a dashboard screen for it; I only found the API above.

## What was broken
- **Code never sent.** The SDK's `setupSubscriptionPayment` never sent the code, so a discount could not reach the server.
- **Lookup never matched.** `resolveDiscount()` sent the Direct Payment id as `processor_reference_num`, so the lookup never matched. The endpoint also only read per-processor copies, so a canonical code that hadn't been provisioned yet showed as "not found". It also returned the whole database row.
- **Bad codes silently ignored.** A mistyped, switched-off or used-up code was silently ignored, and the customer was charged full price.
- **Subscription created before any code.** The Stripe subscription is created when the payment form mounts, before the customer can type a code. Applying one means mounting again.

## What I changed (all in `www`)
- **Page:** [create.blade.php](resources/views/afs/create.blade.php) has a discount field with an Apply button and a hint line, shown for paid plans only. The order summary has a discount line.
- **Page logic:** [create.ts](resources/js/afs/create.ts) checks the code through the SDK when the customer clicks Apply or presses Enter. It then mounts the payment form again with a **new invoice** plus the code. The new invoice matters because the subscription is keyed by the invoice, so the old one would just return the undiscounted subscription. An empty field removes the code. Submitting with a typed-but-unapplied code is blocked with a message instead of charging full price.
- **SDK:** [api.ts](resources/js/external/api/direct_payments/api.ts) takes a `discount_code` option and passes it on, and `resolveDiscount()` now sends `direct_payment_reference_num`. The matching types are in [portal.d.ts](resources/js/external/api/portal.d.ts).
- **Public lookup:** [ProcessorTaxDiscountController.php](app/Http/Controllers/Externals/Processor/ProcessorTaxDiscountController.php) accepts a Direct Payment, picks its active processor and answers using the same rules the subscription is created under. It returns no provider ids.
- **Preview helper:** `DiscountProvisioner::preview()` is read-only, so a public lookup can't create Stripe coupons.
- **Invalid codes:** `ProcessorSubscriptionController` now answers 422 `DISCOUNT_E_INVALID` for a code it won't honour, instead of ignoring it.
- **Tests:** three new tests in `CanonicalDiscountTest`. They are written but not run, and I haven't checked that the lookup route has no origin or policy guard.

## Things to know
- **Superseded subscriptions.** Each Apply leaves the previous unpaid subscription at Stripe as `incomplete`. It expires on its own, and a local row stays behind.
- **Redemption count.** The redemption count goes up when the subscription is created, not when it's paid, so abandoned attempts count against `max_redemptions`.
- **Brain's stored code.** The Brain's `json_data.discount_code` comes from the browser field, not from the real subscription, so don't rely on it for reporting.
- **Tax estimate.** The tax estimate under the address still uses the undiscounted price. I didn't check how the invoice total and webhook reconciliation treat a discounted charge, so test that with a real code.
- **Other `portal.d.ts` copies.** `market` and the other apps carry their own copies of `portal.d.ts`, and I only updated `www`'s.

To try it: create a code on the dev server through the API above, with `processors: ["2uDJGCr"]`. After you commit, push and run `bash build_sites2.sh --update-repos --only www`, apply it on `/create` with a paid plan.
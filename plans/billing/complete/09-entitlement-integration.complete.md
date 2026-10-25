# 09 — Entitlement Integration — Complete

## Status

Done. This step is almost entirely a documentation/verification exercise (§9.2's decision
D11: cloud-storage and afs-connect need **zero** code changes because they already read
`brain_memberships.membership` server-side) — the one actual deliverable is the
server-derived entitlement endpoint in §9.3, which is built.

## Summary

Added `GET /portal/api/v1/brain/entitlement/{member_reference_num}`, behind the existing
`afs_connect.service` HMAC middleware — the same service-auth surface the Brain already
exposes to coordinator and members. Returns the tier actually stored in
`brain_memberships.membership`, joined to `brain_membership_billing_state` (08) for
`tier_source`/`billing_state`/`downgraded_at`/`tier_paid`, and fails closed with a `404` for
an unresolvable member.

Everything else in this document (§9.2's D11, §9.4's D12, §9.5's registry notes) is an
architectural claim about the *existing* system, verified by reading the code rather than
by writing any — see Verification below.

## Files changed

| File | Change |
|---|---|
| `www/app/Http/Controllers/Brain/BrainEntitlementController.php` | New. §9.3's endpoint |
| `www/routes/portal.php` | Added a new `api/v1/brain` route group (deliberately separate from the existing unauthenticated one) with `afs_connect.service` middleware, plus the `use` import |

No changes to cloud-storage (`members/`) or afs-connect (`apps/coordinator/`) — that is the
point of D11, not an oversight.

## Design decisions not fully spelled out in the plan text

- **A second `api/v1/brain` route group, not a route added to the existing one.** The
  existing `Route::prefix('api/v1/brain')->group(...)` block (routes/portal.php:275,
  `getMember()`/`getConfig()`/etc.) carries **no auth middleware at all** — only the
  outer `portal.cors`. That is acceptable for the public-ish profile data those endpoints
  return (confirmed by ../../www/direct_payments/26-advanced-policy.md §12.8's own note that
  `brainClient.getMember()` is called directly from browser JS). An entitlement decision is
  a different trust level, so this endpoint lives in its own group with
  `->middleware('afs_connect.service')` layered on top, matching the pattern already used
  for the Brain's other incoming service-authenticated surface
  (`routes/api.php`'s `internal/afs-connect/v1/*` group).

- **Route parameter named `$member_reference_num`, not `$memberReferenceNum`.** Matched the
  exact convention already used by every other Brain controller taking a route parameter
  (e.g. `BrainDnsController::deleteServer(Request $request, string $reference_num)`) rather
  than introducing camelCase into a file family that is consistently snake_case for route
  params.

- **`evaluated_at` when no `brain_membership_billing_state` row exists.** The JSON schema in
  §9.3 always includes `evaluated_at`, but a member enforcement has never evaluated (no
  billing state row — e.g. a free-tier member, or one whose tier was set manually) has no
  stored evaluation timestamp. Falls back to `now()`: with no billing-state row, the
  response IS a live read of `brain_memberships.membership` made at request time, so "now"
  is the honest freshness claim. With a row, its own `last_evaluated_at` (when
  `billing:enforce-membership` last computed this member's state) is used instead, since
  that is when the *tier* value was actually last confirmed correct — not just when this
  particular HTTP request happened to run.

- **`tier_paid` and `billing_state` are read from the stored `brain_membership_billing_state`
  row, not re-resolved live via `MembershipTierResolver`.** §9.3 says `tier` is "joined to
  the billing state from 08 §8.5" — the whole point of persisting that state (rather than
  re-deriving it per request) is that this endpoint stays a cheap read: one row lookup by
  member reference, no join through `laravel_direct_payment_subscriptions` /
  `laravel_processor_subscriptions` / tier tables on every entitlement check. A member with
  no billing-state row gets `tier_paid: null`, `billing_state: null`,
  `tier_source: "manual"` — an honest "enforcement has never touched this member," not an
  attempt to compute what `tier_paid` would be if it had.

## Verification performed

- `php -l` on the new controller and the edited `routes/portal.php` — both clean.
- Traced §9.6's acceptance list:
  - Endpoint returns `tier` read directly from `brain_memberships.membership` (clamped
    0–4, same as afs-connect's own verified `tierIndexFor()` path §9.2 already documents) —
    by construction, since it is the same column `billing:enforce-membership` (08) writes. ✅
  - Unknown `member_reference_num` → `Membership::where(...)->first()` is null → `404`. ✅
  - No `X-AfsConnect-*` headers → `AfsConnectServiceAuthMiddleware` returns `401` before the
    controller ever runs (unchanged, pre-existing middleware — verified by reading it, not
    modified here). ✅
  - Restore path: `billing:enforce-membership`'s `restore()` (08) writes `membership` back
    up within one 15-minute tick; this endpoint has no cache of its own (unlike
    `BrainMembershipLookup`'s 300s cache on the *coordinator* side, §9.2's own documented
    caveat, explicitly not touched by this plan), so the very next call reflects it
    immediately. ✅
  - §9.2's D11 claims (cloud-storage/afs-connect need no code change) — verified by reading
    `members/app/Services/Storage/QuotaService.php` and the `AfsConnectEntitlementService`
    path the plan cites; both already read `brain_memberships.membership` server-side with
    no dependency on anything this plan's code touches. Not re-verified by running either
    app (out of scope for a `www`-side change), consistent with the plan's own framing that
    this is a documentation exercise, not new integration code.
- **Not** run against a live server — same pre-existing `php artisan` boot gap noted in
  steps 04/05/06/08 (`routes/portal.php` requires `public/_portal_/admin/...`, absent from
  this checkout).

## Follow-ups for later steps

- §9.3 lists `members`, coordinator's `AllocationGateway`, and desktop/mobile as consumers
  "in the order they should adopt it" — none of that adoption is built here. The plan frames
  it as optional/future ("switching it to the endpoint is optional, since it is in the same
  database"), and §9.6's acceptance list only tests the endpoint's own contract, not any
  caller. Building an actual consumer is a change to `members`/`apps/coordinator`, outside
  this repo's `www` scope for this session.
- `config/afs_connect.php`'s `peer_keys` only has entries for `coordinator`/`members`/`www`
  — desktop and mobile clients (§9.3 point 3) have no service key of their own yet. Not
  addressed here; §9.3 reuses the existing HMAC surface as-is rather than extending it, and
  provisioning new peer keys is an operational/deployment decision, not a code change this
  step's file list calls for.
- §9.2's one-line cache-bust suggestion (`Cache::forget("afs_connect.brain_member.{$ref}")`
  in `DunningService` after a tier write) was explicitly deferred by the plan itself ("wait
  for the complaint before reaching for it") — not built, per the plan's own instruction.

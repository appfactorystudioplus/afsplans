# Feature 23 — `voip_numbers_count` (VoIP Phone Numbers)

> New 2026-09-23. Numbered by its `TODO-member-resources.md` entry (#23), following the
> `features/` convention — see `../README.md`.

> **PLANNING ONLY. No code written for this feature yet.** Unlike every other `features/`
> doc so far, that's true in a stronger sense here: the underlying member-facing feature —
> provisioning a real VoIP phone number — does not exist in the estate at all yet, not just
> its retention/limitation layer. See §F23.4. Per the working convention for this plan tree,
> features get built first; this document exists so the retention questions are on record
> for whoever builds this feature, to work through **then**, not to pre-decide now.

## F23.1 The feature in one paragraph

The registry already prices this: a member's plan sets how many VoIP telephone numbers they
may hold. But "provision a number" is not a feature that exists yet anywhere in this
codebase — see §F23.4. This document records the registry's numbers and the questions that
building this feature will need to answer; it does not answer them.

## F23.2 The numbers

Registry: `voip_numbers_count` = `["x", 1, 5, 25, 100]`, scope `member`, unit `numbers`,
period `lifetime`, `overage: block`, `tier_mode: absolute`, `retention: banded`,
`grace_days: 60` (the only resource above the 30-day default — already set in
`checkpoint_registry_v4.json`, not something this document has to decide), `inventory: null`.

`tiers[0]` is `enabled: false` — the free plan does not include VoIP numbers at all.

## F23.3 The template's five questions, run before there's a feature to run them against

(`04-feature-template.md`)

1. **Is retiring this irreversible from outside?** Yes — releasing a number back to the
   carrier cannot be undone; a different customer can be assigned that number the moment it's
   released. This is already why `grace_days` is 60 instead of 30. The registry's own
   `retention_note` additionally states *"purge must be an explicit member action, never an
   automatic sweep"* — but that's a note recorded when the registry was authored, not a
   decision this document is treating as settled. Whether purge should require an explicit
   member action, and if so what that looks like (the shared retention engine today only
   knows "grace expires → auto-purge," per storage/email — an "awaiting confirmation" state
   that never auto-purges would be new engine surface, not just an adapter, since every other
   resource's purge fires on the clock alone) is **open** — see §F23.5.
2. **Can a single item fail to fit?** No — `unit_kind` would be `discrete`; a phone number is
   a phone number, never a partial one.
3. **Does anything the platform depends on live here?** Nothing found. This resource is
   entirely member-provisioned numbers; no platform-critical address or system function was
   found depending on VoIP in the current codebase (see §F23.4 — there's barely anything
   depending on VoIP at all yet).
4. **Can the member actually rescue anything?** Unclear, and worth flagging rather than
   assuming: D10's "choose what survives" model reads naturally for a resource with many
   interchangeable items moving between bands (email accounts, forms). A phone number is one
   indivisible thing tied to a real external identity — there's no partial evacuation of *one*
   number the way there is for "250 email accounts, pick 50 to keep." What "evacuate" even
   means here (keep this number vs. that one, when a member holds several) is a real design
   question for whoever builds this, not obviously answered by the shared engine's default
   behaviour.
5. **What does the sweep cost on a tenant that never uses this resource?** Not yet
   answerable — there is no adapter and no inventory table, so there are no rows for a sweep
   to read either way.

## F23.4 Current-state findings — there is no VoIP provisioning feature today

Searched `www`, `members`, `auth`, `market`, `coordinator` for existing VoIP code
(2026-09-23), rather than assuming the registry entry implies something is already built:

- **`FunctionsController::sendmessagetome()`** (`www/app/Http/Controllers/FunctionsController.php:168-178`)
  is the only application code referencing VoIP. It decodes a posted `did`/`phone_number`/
  `text_message` and calls `(new \VoIPms())->sendSMS($did, $phone_number, $text_message)`.
- **`\VoIPms` is an empty stub.** `www/core/scripts/remote/builder/php/libs/voipms/voipms.php`
  is a single class with only an empty constructor — no `sendSMS()` method, no other method,
  nothing. Calling `sendSMS()` on it fatals with an undefined-method error. This code path is
  dead; it cannot have run successfully as written.
- **The only UI** is `resources/js/portal/admin/html/views/html/modules/communications/communications_voip_content.html`
  — a "Phone Number" field, a message box, and a Send button. It posts to the dead function
  above. There is no provision-a-number, list-my-numbers, or cancel-a-number UI anywhere.
- **No database table.** No migration in `www` (the schema owner — §5 of `../../../AGENTS.md`)
  creates anything named for VoIP numbers, DIDs, or phone numbers. `TODO-member-resources.md`
  #23 already marks the inventory owner **unknown**, and this search confirms why: there is
  nothing to be an inventory of yet.
- **VoIP.ms** (the carrier the dead stub and the `libs/voipms` folder name point at — a real
  VoIP wholesale/API provider) is the only hint of an intended vendor. Nothing live confirms
  that's still the plan; whoever builds this should verify rather than inherit the assumption
  from a decade-old empty stub.

**Conclusion:** this resource is a "the product claims a limit it does not have" case (the
same shape the registry's own `domain_discussions` note calls out for a different resource) —
except here even the *feature*, not just the retention layer, is entirely unbuilt.

## F23.5 What's genuinely open

Recorded here per this session's discussion, **not decided** — to be worked through when this
feature is actually built, the same way `domain_email_accounts`' OQ-B was resolved as part of
building that feature rather than in the abstract beforehand:

- **Does purge require an explicit member action, or can it fire automatically from a sweep
  once the 60-day grace expires?** The registry's `retention_note` already leans toward
  "explicit action, never automatic," but implementing that is new shared-engine surface (a
  "grace expired, awaiting confirmation" state with no auto-purge), not something the existing
  retire/grace/purge clock does today for any other resource. Worth deciding early **relative
  to this feature's own build**, since it changes what the adapter/engine contract needs to
  support — but not before there's a real provisioning feature and inventory to attach it to.
- **Which carrier/vendor actually backs number provisioning?** VoIP.ms is the only trace in
  the code, and it's a dead stub, not a live integration decision.
- **Where do provisioned numbers get created and stored?** No adapter, no inventory, no table
  — a real integration has to be chosen and built before "retire" or "purge" mean anything
  concrete to act on.
- **What does "evacuate" mean for one indivisible number** — §F23.3 item 4. `secondary_users`
  (also `member`-scoped, also discrete, also "people/things not files") is the closer sibling
  to check against once that feature is worked, rather than assuming storage/email's
  band-migration model transfers directly.

## F23.6 What "done" means (`04-feature-template.md`) — every item still open

- [ ] The provisioning feature itself: create/list/cancel a member's VoIP number — **does not
      exist**; everything below depends on this existing first
- [ ] Forward: `canUse` (fails closed), `consume` on create, `release` on delete — TODO
- [ ] Inventory identified and adapter built — TODO, blocked on the feature existing
- [ ] Band assignment column + backfill — TODO
- [ ] Retire/restore/purge implemented, per whatever §F23.5's purge-automation question
      resolves to — TODO
- [ ] Cascade — n/a, `member`-scoped with no children
- [ ] Member-facing preview + "choose what survives" UI — TODO, and §F23.5's "what does
      evacuate mean here" needs an answer first
- [ ] Notification before purge — TODO

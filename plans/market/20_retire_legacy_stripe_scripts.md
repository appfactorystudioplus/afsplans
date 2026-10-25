# Step 20 — Finish retiring the legacy Stripe integration

**On completion:** write `complete/20_retire_legacy_stripe_scripts_complete.md`.
**Depends on:** [08](08_stripe_connect_brain_api.md) §08.2 (the audit that found this).
**Blocks:** nothing. §20.3 should land before P1 reaches production.

> **On the number.** This step runs early, alongside 08 — not after 19. It is numbered 20 because it
> was added after the plan was written, and renumbering would break cross-links across twenty files.
> Read the dependency line, not the number.

> ## ⛔ NO DELETIONS. Standing rule for this step.
>
> **Project owner, 2026-08-18: nothing is to be deleted.** Not a file, not a directory, not a row,
> not a vendor tree, and nothing on the deployed host.
>
> "Retire" in this step means **remove from the execution path while leaving the artifact in place**:
> unregister a route, annotate a file, move it into a quarantine directory, or simply leave it
> untouched and document that it is inert. Every disposition below has been rewritten to that
> standard.
>
> This is the safer default regardless of preference. A quarantined file that turns out to be live
> is restored by moving it back; a deleted one is restored only if someone still has it.

---

## 20.0 Scope, after two rounds of measurement

Two project-owner decisions cut this step down from what the first audit found:

1. **`public/_portal_` is out of scope for everything.** That removes four `stripe_payments.php`
   copies, two `StripeModule.php` copies, thirty routed legacy endpoints, and the vendored
   `stripe-php` **17.6.0** tree at
   `public/_portal_/admin/core/php/includes/libs/payments/stripe/vendor/`. None of it is touched by
   this plan. It is noted here only so a future reader does not mistake its absence from this step
   for its absence from the repository.
2. **`core/scripts/includes/` has been deleted in full** by the project owner (2026-08-18),
   including the Stripe files, `AppfactoryStudio/Plugins/StripeModule.php`, `functions.php`,
   `paypal.php`, and every `libs/` subtree.

So the retirement this step was written to plan **has already been performed**. What remains is
verification, three orphaned files, and the deployed host.

---

## 20.1 Verified: the deletion was clean

The first draft of this step claimed the deletion broke member-website provisioning. **That was
wrong**, and the correction is recorded here rather than quietly dropped, because the reasoning is
what makes the conclusion trustworthy.

Three call sites reference `background_runner.php`, which required the deleted directory:

| Call site | Verdict |
|---|---|
| `app/Http/Controllers/Brain/BrainHttpServerController.php:534`, in `moveTenants()` | **Not live.** The line immediately after reads `// Stub — bulk tenant migration to be implemented`. |
| `app/Http/Controllers/FunctionsController.php:6198`, in `member_provision_new_domain_website()` | **Already broken before the deletion.** Resolves to `app/Http/includes/background_runner.php` — `__DIR__` is `app/Http/Controllers`, so `dirname(__DIR__)` is `app/Http`, and `app/Http/includes/` has never existed. |
| `app/Http/Controllers/FunctionsController.php:7842`, in `admin_subscriber_publish_article()` | Same stale path, same conclusion. |

Two further references, in `core/scripts/commands.sh:767` and
`core/scripts/remote/builder/remote_commands.sh:72`, are **obsolete** (project owner, 2026-08-18).

**Conclusion: nothing live depended on `core/scripts/includes/`.** No restore is needed.

---

## 20.2 The deployed path, resolved

The legacy requires used `dirname(__DIR__, 9) . "/portal/admin/core/api/php/includes/..."`, which
resolved to nothing in a working copy. The obsolete shell scripts recorded the real root:

```
/home/appfactorystudio/websites/{subdomain}/portal/admin/core/api/php/includes/
```

`{subdomain}` is `www` in production and is parameterized per member website. The nine-level walk is
correct **for that layout** — from
`…/includes/services/payments/stripe/`, level 3 is `includes`, 6 is `core`, 8 is `portal`, and 9 is
`{subdomain}`, so appending `/portal/admin/core/api/php/includes/init.php` lands exactly on itself.

The stale comment at line 7 of the deleted file (`/home/appfactorystudio/websites/www/admin/core/api/php/includes/…`)
simply omits the `portal/` segment. It is a typo, not evidence of a second layout — worth recording
because the first audit read it as a contradiction.

**This root is not a working copy.** Nothing in this repository controls it, and §20.4 is the only
part of this step that touches it.

---

## 20.3 Orphaned files in `core/scripts/`

> **Tagging (step 01 §01.4).** Every file this step creates or modifies carries
> `MARKET_MODIFIER_ADDITION` — created files in the top doc block, modified files at each changed
> line or region. `grep -rn MARKET_MODIFIER_ADDITION` is the change index for this whole effort.


Three files survived and now `require` a directory that no longer exists. Each would fatal if
executed. None is reachable from live code (§20.1).

**No file here is deleted.** The work is annotation, so that a future reader who opens one of these
does not conclude something is broken and go hunting for the wrong cause.

| File | Dangling requires | Disposition |
|---|---|---|
| `core/scripts/background_runner.php` | ~18 lines (6–23) into `scripts/includes/` — `init.php`, `functions/brain.php`, and every `libs/*` autoloader including `libs/payments/stripe/vendor/autoload.php` | **Annotate, leave in place.** Header block: inert since 2026-08-18, its `includes/` tree removed, its only callers a stub and two already-broken paths (§20.1). Tag `MARKET_MODIFIER_ADDITION`. |
| `core/scripts/watcher.php` | `dirname(__DIR__, 2) . "/core/api/php/includes/init.php"` — a deployed-only path, absent here | **Leave untouched pending §20.4.** May be live on the host. |
| `core/scripts/afs-watcher.php` | Same | **Leave untouched pending §20.4.** The `afs-watcher*.service` systemd units alongside it are a claim that something runs it somewhere. |

`core/scripts/docker/setup.php` includes `/opt/afsserver/docker/includes/init.php` — an absolute path
on a different machine, unaffected by any of this. Leave it.

Optionally, and only if the project owner asks: move the annotated files to
`core/scripts/_retired/` rather than annotating in place. That is a move, not a removal, and it is
reversible with a second move. It is **not** the default — annotation is.

The three call sites in §20.1 are annotated too, not removed: a `MARKET_MODIFIER_ADDITION` comment
at each recording that the target is inert and why. The stub in `moveTenants()` stays a stub.

---

## 20.3.5 Pre-production shortcut

Step 01 §01.2.4: nothing is serving production traffic. So the traffic-observation logic that would
normally gate a decommission — *"zero hits over a full billing cycle"* — has nothing to observe. An
empty access log in an environment with no users is not evidence.

Substitute **static** evidence for traffic evidence:

- A grep showing no live caller (already done for the Stripe files — §20.1).
- A confirmed replacement in the Processor controllers.
- The deployed-host inventory in §20.4.

That is stronger than a traffic window here, and it is available immediately.

---

## 20.4 The deployed host

The only remaining work, and it is operational rather than code.

**Read-only. Nothing on the host is deleted, moved, or disabled by this step.**

1. **Inventory** `/home/appfactorystudio/websites/{subdomain}/portal/admin/core/api/php/includes/`
   on the live host. Record what is present, what is Stripe-related, and what has traffic. A
   directory listing and an access-log summary — no writes.
2. **Read the Stripe webhook endpoints** registered in the dashboard, live *and* test mode. Record
   which point at a legacy `stripe_payments.php` path. **Repointing them at
   `POST /portal/webhooks?type=stripe` is a separate, owner-approved action**, not part of this
   step — but it must happen before the new SDK path ships, because a receiver that stops being
   maintained while Stripe still posts to it is silent event loss: deliveries fail as 404s in
   Stripe's log and nobody notices until a renewal fails.
3. **Confirm whether the watcher systemd units are installed and running.** That closes §20.3's two
   open questions. `systemctl status`, not `systemctl disable`.
4. **Stop there.** Any subsequent cleanup on the host is a separate decision with its own approval,
   taken with the inventory in hand.

The deployed host may also carry its own vendored `stripe-php`. Prerequisite P1 installs **21.2** in
the Laravel `vendor/` tree; that is a separate autoloader in a separate process and does not
conflict. **Do not consolidate them** — pointing legacy scripts at 21.2 is a silent four-major
upgrade in code with no tests. The duplication is the safe state until the legacy tree is gone.

---

## 20.5 Still in scope elsewhere, not here

`\Stripe\` references remain inside Laravel and are **not** this step's work:

| File | Owner |
|---|---|
| `ProcessorConnectController.php` | [Step 08](08_stripe_connect_brain_api.md) — rewritten against Accounts v2 |
| `ProcessorSyncController.php` | Step 08 §08.2.4's dead-code audit — contains account/coupon deletion calls that become executable the moment P1 lands |
| `FunctionsController.php` (~5246–5602) | Step 08 §08.2.4, same audit |

---

## 20.6 Verification

- [ ] **Nothing was deleted.** `git status` in `apps/www` shows no deletions attributable to this step. This is the first check for a reason.
- [ ] `find apps/www -name "stripe_payments.php" -not -path "*/public/_portal_/*"` → zero, **as a record of the owner's prior removal**, not as something this step performed.
- [ ] `grep -rn "scripts/includes" apps/www/app apps/www/core --include='*.php'` → every remaining hit is annotated per §20.3, none removed.
- [ ] `core/scripts/background_runner.php` still present, carrying its inert-since header.
- [ ] The three call sites in §20.1 annotated in place; `moveTenants()` still a stub.
- [ ] Deployed-host inventory complete and recorded — obtained read-only.
- [ ] Stripe dashboard webhook endpoints **recorded** for live and test mode. Any repointing done separately, with its own approval, and noted as such.
- [ ] Watcher systemd units checked with `systemctl status`; §20.3's two questions closed with a decision, not an action.
- [ ] `public/external/api/sdk.js` still contains zero `stripe_payments` references — re-grepped, not assumed. (It is the artifact steps 09/10/13 depend on; the legacy `afssdk.js` is a different file and out of scope with `public/_portal_`.)
- [ ] A full Direct Payments purchase completes end to end after the host cleanup.
- [ ] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4). Verify by `grep -rn`, and reconcile the result against this step's Files section — a file in the list without a tag, or a tagged file not in the list, are both defects.
- [ ] `complete/20_retire_legacy_stripe_scripts_complete.md` written, including the deployed-host inventory and the webhook-repoint confirmation.

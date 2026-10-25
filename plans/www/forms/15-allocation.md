# 15 — Allocation (alloc_v2)

Per-tier ceilings on how many forms a site may have, and how many submissions it may accept in
a month.

Read [../../allocation.md](../../allocation.md) first — it explains the system. This document
applies it to forms, and **half of it does not follow the standard pattern**, for a reason worth
reading before writing any code: one of the two resources is consumed by anonymous strangers on
a public endpoint, and the client-side model the rest of the estate uses cannot reach there.

## 15.1 Four registry entries, two of them ours

`checkpoint_registry_v3.json` already declares four forms-shaped resources. Nothing needs adding
to the registry; the work is deciding which two this plan owns and wiring them.

| Resource | Scope | Period | Overage | T0 | T1 | T2 | T3 | T4 | This plan |
|---|---|---|---|---|---|---|---|---|---|
| `domain_forms_count` | `per_domain` | lifetime | block | 2 | 10 | 50 | 250 | −1 | ✅ §15.3 |
| `domain_forms_submissions_monthly` | `per_domain` | **monthly** | block | 100 | 2 000 | 20 000 | 150 000 | −1 | ✅ §15.5 |
| `forms_items_count` | `member` | lifetime | block | 3 | 20 | 100 | 500 | −1 | ❌ §15.1.1 |
| `forms_items_submissions_monthly` | `member` | monthly | block | 200 | 5 000 | 50 000 | 500 000 | −1 | ❌ §15.1.1 |

Two things to read out of that table before anything else:

**Every tier has `enabled: true`, including T0.** Unlike processors and Direct Payments
([../../billing/16](../../billing/16-allocation.md) §16.1), forms exist on the free plan. There
is therefore **no "not included on your plan" message for forms** — only "you have reached your
limit". One refusal case, not two, and §15.11 has one fewer row than its billing counterpart.

**A free site gets 2 forms and 100 submissions a month.** Those are small enough that T0 members
will meet them in ordinary use, which makes the quality of the refusal a product surface rather
than an edge case.

### 15.1.1 `forms_items_*` describes a product this plan does not build

Its registry description reads *"forms created in the global form builder that are not attached
to a specific domain (embeddable / standalone forms)"* — a **member**-scoped, site-less form.

This plan has no such object. [02](02-security-and-tenancy.md) §2.3 makes
`website_reference_num` the authorisation boundary for every form, and forms that cannot be
attributed to a site are surfaced in an "unassigned" list to be *fixed*, not treated as a
supported kind. Embedding a form on a third-party page ([09](09-publishing-and-versioning.md)
§9.6) does not make it site-less — it still belongs to the site that owns it.

**So `forms_items_count` and `forms_items_submissions_monthly` stay unwired**, and they are two
of the twenty-six entries in [../../allocation.md](../../allocation.md) §12.1 that declare a
limit nothing enforces. They belong in that document's §13 audit as *"delete, or build the
product"* — not here. Wiring them to domain-scoped forms would be worse than leaving them: it
would enforce a member-wide ceiling that the pricing page describes as something else.

## 15.2 What is wired today: nothing, and five reasons it cannot simply be added

No allocation call exists anywhere in the forms module — admin, renderer, or controller.

| # | Finding | Consequence |
|---|---|---|
| **F62** | `createForm()` (`FormsViewsController.ts:461`) is `await fetch(…)` with **no status check and no body read**. The response is discarded and `loadFormsList()` runs regardless | A create that 422s or 500s looks identical to one that succeeded. **A `consume` placed here would count forms that do not exist** — and a lifetime allowance does not come back on its own |
| **F63** | `deleteForm()` (`:530`) does not check its response either — already noted under **F7**, where the route points at a method that does not exist | A `release` placed here fires on every delete, including the ones that 500. Releasing something never consumed drives the count negative ([../../allocation.md](../../allocation.md) §5.2) |
| **F64** | `laravel_form_submissions` has **no site column**. Its indexes are per-form: `(form_reference_num, created_at)` ([04](04-database-schema.md) §4.4) | A per-domain monthly count needs a join to `laravel_forms` on every submit. Wrong shape for the hot path — §15.6 |
| **F65** | The submit endpoint is **anonymous by design** ([02](02-security-and-tenancy.md) §2.8) | The respondent's browser has no member session, no `parse_alloc` and no tier. `createAllocV2()` cannot be constructed there at all — §15.5 |
| **F66** | The whole forms API is **unauthenticated** (**F1**, **F2**) | A client-side `canUse` in the admin, in front of an open `POST /forms`, is decoration. Allocation depends on **P0** and is worthless before it |

**F62 and F63 are why this document does not simply say "add three call sites".** The standard
pattern assumes the create path knows whether the create succeeded. Here it does not, and fixing
that is a prerequisite rather than a detail.

## 15.3 `domain_forms_count` — count the *active* set

The registry description says *"published web forms"*. **Do not implement that.** Counting only
`published` lets a member hold unlimited drafts, and makes the count jump every time a form is
published or closed — a limit that moves under the member is a limit they cannot plan against.

The status ladder already exists ([09](09-publishing-and-versioning.md) §9.5):

| Status | Counts against `domain_forms_count` |
|---|---|
| `draft` | **yes** |
| `published` | **yes** |
| `closed` | **yes** — a closed form still holds its slug, its submissions and its slot |
| `archived` | **no** |
| soft-deleted (`deleted_at`, 30-day window, **F21**) | **no** |

The rule is one sentence: **a form counts while it is in the active set, and `archived` is the
release.**

That resolves the T0 problem directly. A free member with 2 forms who abandons one is not stuck
— they archive it, the allowance returns, and the definition and its submissions are still
there. Without an untaken exit like that, "2 forms, lifetime, block" becomes a support ticket in
the first week.

**The registry `description` should be corrected** to say *forms*, not *published forms*.
Descriptions are cosmetic to the client — `tiers` is the contract
([../../allocation.md](../../allocation.md) §2.1) — but this one is member-facing copy that
would describe a different limit from the one enforced.

### 15.3.1 Five transitions, not three

Because the count follows a set rather than a row's existence, membership changes in both
directions:

| Transition | Call |
|---|---|
| create | `canUse` → create → `consume` |
| archive | `release` |
| **unarchive** | `canUse` → **refuse if full** → `consume` |
| soft delete | `release` |
| **restore from trash** | `canUse` → **refuse if full** → `consume` |
| hard delete (30-day purge) | nothing — already released at soft delete |

The two bold rows are what a three-call-site checklist misses. **A member at their limit cannot
unarchive or restore**, and both need the create refusal reworded for the action: *"Restoring
this form would put you over your limit of 2. Archive another form first."*

The alternative — letting restore always succeed — means the ceiling can be exceeded by anyone
who archives, creates, and restores. That is a limit with a documented bypass.

## 15.4 The call sites

All in `FormsViewsController.ts`, all client-side, all after **P0** (**F66**).

### Preflight

```ts
/**
 * AFS_ALLOC_V2_TAG
 * AFS_FORMS_MODIFIER_ADDITION — plans/www/forms/15-allocation.md §15.4
 *
 * Preflight against domain_forms_count before opening the "New Form" modal.
 *
 * per_domain scope: a member with three sites has a separate form allowance on
 * each, and omitting the scope reads a bucket nothing writes to.
 *
 * Gates the UI only. `consume` records the fact, after the server confirms.
 */
const domain = getSubdomainProperty(app, 'Domain');
if (!domain) {
    // getSubdomainProperty returns string | null (helpers.ts:505). A null scope
    // is not "no scope" — canUse would read the MEMBER bucket, which nothing
    // writes to, and cheerfully report the full allowance every time.
    console.error('[AllocV2] no Domain property; refusing to check an unscoped bucket');
    showFormsLimit(null, 'create');
    return;
}

try {
    const check = await allocV2.canUse(AllocV2Resources.DOMAIN_FORMS_COUNT, 1, { domain });
    if (!check.available) {
        showFormsLimit(check, 'create');     // §15.11
        return;
    }
} catch (err) {
    console.error('[AllocV2] forms canUse failed:', err);
    showFormsLimit(null, 'create');          // fail CLOSED — the resource is `block`
    return;
}

showCreateFormModal();
```

**The `catch` returns.** `email.ts:288` and `:384` are the estate's existing example of this
check and both fall through to the create path on error — the inverted-posture gap **G12** in
[../../allocation.md](../../allocation.md) §12.3. Do not copy them.

### Consume — and F62 first

`createForm()` has to learn what happened before anything can be consumed:

```ts
/**
 * AFS_ALLOC_V2_TAG
 * AFS_FORMS_MODIFIER_ADDITION — plans/www/forms/15-allocation.md §15.4
 *
 * Closes F62. The old body was `await fetch(...)` with the response discarded,
 * so a 422 or 500 was indistinguishable from success.
 *
 * That is a display bug on its own and a counting bug the moment allocation is
 * wired: consuming after an unverified create burns a lifetime allowance for a
 * form that does not exist, and lifetime allowances do not return on their own.
 *
 * Consume runs only on a confirmed 2xx, and never blocks the return — the form
 * exists; failing the member's create because a counter did not move would lose
 * real work over bookkeeping.
 */
async function createForm(name: string): Promise<CreatedForm | null> {
    const response = await fetch(FORMS_API_BASE, { /* … */ });
    if (!response.ok) {
        showToast(`Could not create the form: ${response.status}`, 'danger');
        return null;
    }
    const body = await response.json();
    if (!body?.success) { /* … surface body.message … */ return null; }

    allocV2.consume(AllocV2Resources.DOMAIN_FORMS_COUNT, 1, { domain })
        .catch((err: unknown) => {
            const code = err instanceof AllocV2Error ? err.code : 'UNKNOWN';
            console.error(`AllocV2 consume error [${code}]:`, err);
        });

    return body.form;
}
```

### Release

On archive, and on soft delete — each **after** a confirmed response, for the reason **F63**
gives:

```ts
/**
 * AFS_ALLOC_V2_TAG
 * AFS_FORMS_MODIFIER_ADDITION — plans/www/forms/15-allocation.md §15.4
 *
 * Return the allowance when a form leaves the active set (§15.3).
 *
 * Guarded on a confirmed response: deleteForm() historically ignored its result
 * while the route pointed at a method that does not exist (F7, F63), so an
 * unguarded release here would have fired on every failed delete and driven the
 * count negative.
 */
if (response.ok) {
    allocV2.release(AllocV2Resources.DOMAIN_FORMS_COUNT, 1, { domain })
        .catch((err: unknown) => console.error('[AllocV2] forms release failed:', err));
}
```

`AllocV2Resources.DOMAIN_FORMS_COUNT` **already exists** (`checkpoint/resources.ts:11`), as does
`DOMAIN_FORMS_SUBMISSIONS_MONTHLY` (`:12`). Use the constants; never a string literal
([../../allocation.md](../../allocation.md) §4.2).

## 15.5 `domain_forms_submissions_monthly` cannot be a client-side check

This is the part of the document that matters.

Every other alloc_v2 call in the estate runs in a **portal** page, where a member is signed in
and `app.window.member` carries `parse_alloc` and `membership`. A form submission runs in a
**respondent's** browser, on a public page, for someone who has no account (**F65**). There is
no member object, so `createAllocV2()` has nothing to construct from.

Even if there were, it would be the wrong place. This limit is:

- **cost-bearing** — every submission is storage, an answer decomposition, a queued job, and
  usually an email;
- **abuse-facing** — the endpoint that consumes it is the one endpoint accepting anonymous
  writes;
- **`overage: block`** — it is supposed to actually stop something.

[../../billing/09](../../billing/09-entitlement-integration.md) §9.4 already wrote the rule this
falls under, in as many words:

> *"Do not build product features that depend on `alloc_v2` alone for a security-relevant limit."*

**So the submission quota is enforced on the server, in `submitForm()`, against a local
authoritative counter, with a server-derived tier.** alloc_v2 receives a mirror of the number
for display (§15.9) and is never asked whether the submission may proceed.

This matches [../../cloud-storage/00-overview.md](../../cloud-storage/00-overview.md) **D4** —
local table authoritative, Parse mirrored for reporting — reached from the same two facts: the
counter is written on a hot path, and Parse's `MemberRegistry` is publicly writable
([../../allocation.md](../../allocation.md) §11.2, **G2**).

## 15.6 The counter, and the atomic claim

`laravel_form_submissions` has no site column and its indexes are per-form (**F64**), so a
per-domain monthly `count(*)` on every submit means a join and a scan on the hot path. It also
answers the wrong question: the quota measures **submissions accepted this month**, and rows get
soft-deleted, purged and hard-deleted for reasons that have nothing to do with the meter.

```php
// database/migrations/…_create_form_submission_quota_table.php
// AFS_ALLOC_V2_TAG
// AFS_FORMS_MODIFIER_ADDITION — plans/www/forms/15-allocation.md §15.6
laravel_form_submission_quota
    id
    website_reference_num  string(64)
    period                 char(7)              // 'YYYY-MM', UTC — §15.6.1
    accepted               unsignedInteger  default 0
    tier_snapshot          unsignedTinyInteger  nullable   // which tier was in force
    limit_snapshot         integer              nullable   // and the ceiling it gave (-1 unlimited)
    first_over_at          timestamp            nullable   // §15.8 grace band
    warned_at              json                 nullable   // { "85": "…", "95": "…", "100": "…" }
    mirrored_at            timestamp            nullable   // §15.9
    timestamps

    unique (website_reference_num, period)
```

The claim is one statement, and the row count it returns is the answer:

```php
/**
 * AFS_ALLOC_V2_TAG
 * AFS_FORMS_MODIFIER_ADDITION — plans/www/forms/15-allocation.md §15.6
 *
 * Claim one submission against the site's monthly allowance.
 *
 * A single conditional UPDATE, so the check and the increment cannot be
 * separated by a concurrent request. Read-then-write would let two submissions
 * arriving in the same millisecond both see `accepted = limit - 1` — the exact
 * race this form exists to remove, and the one the PHP allocation controller
 * still has (../../allocation.md §11.3).
 *
 * Returns false when the ceiling is reached. It never throws for a full quota:
 * "full" is an expected outcome, not an error, and the caller decides what a
 * respondent sees (§15.8).
 */
public function claim(string $siteRef, int $limit): bool
{
    if ($limit < 0) {                       // -1 = unlimited: count, never gate
        DB::table('laravel_form_submission_quota')
            ->where(['website_reference_num' => $siteRef, 'period' => $this->period()])
            ->increment('accepted');
        return true;
    }

    return DB::table('laravel_form_submission_quota')
        ->where('website_reference_num', $siteRef)
        ->where('period', $this->period())
        ->where('accepted', '<', $limit)
        ->increment('accepted') === 1;
}
```

The row is created by a `firstOrCreate` immediately before, guarded by the unique key so two
concurrent first-submissions of the month cannot both insert.

**This is the same mechanism [09](09-publishing-and-versioning.md) §9.5.1 needs for
`close_after_responses`** — *"enforced with the submission write so a race cannot overshoot"*.
Build one conditional-increment helper and use it for both, rather than two implementations of
the same careful thing.

### 15.6.1 The month is a UTC month

`alloc_v2.ts:588` computes a monthly period start as `Date.UTC(year, month, 1)`. That is the
client's rollover boundary, and the mirrored number has to agree with it or the portal gauge
resets on a different day from the counter that governs it.

So `period` is `YYYY-MM` in **UTC**, not in the site's timezone. Tenant-facing copy still renders
the reset moment in the site's own timezone — *"resets 1 September, 1:00 AM"* — because a member
should not have to know what UTC is to read their own dashboard.

### 15.6.2 What counts

| Submission `status` | Counts |
|---|---|
| `complete` | **yes** |
| `flagged` | **yes** — honeypot and timing heuristics flag rather than reject ([02](02-security-and-tenancy.md) §2.8). It arrived, it is stored, it is work: it counts |
| `spam` | **no** |
| `preview` | **no** — already excluded from counts and exports ([04](04-database-schema.md) §4.4) |

A quota that spam can exhaust is a denial-of-service against the tenant's own form, so `spam`
does not count. The claim therefore happens **after** the spam determination and **before** the
write, which is the only ordering in which both statements are true.

**There is no release.** A monthly quota measures traffic accepted in a window, not rows held.
Deleting a submission does not un-receive it, and purging last month's data does not return this
month's allowance. Say so in the code, because
[../../allocation.md](../../allocation.md) §5.2 spends a section on release being the call people
forget — and someone will helpfully "fix" its absence here.

> **The rule, generally:** `period: lifetime` resources have a release. `period: monthly` and
> `daily` resources do not; their reset *is* the release.

## 15.7 Where the server gets the tier

Not from the request, and not from Parse.

```
submitForm()
  └─ FormSubmissionQuota::limitFor($websiteReferenceNum)
       ├─ resolve the site's owning member          02 §2.3 (server-side, from the served domain)
       ├─ GET /portal/api/v1/entitlement/{member}   billing/09 §9.3  → { tier, level, … }
       ├─ checkpoint_registry_v3.json               domain_forms_submissions_monthly.tiers[tier]
       └─ → limit (int, -1 unlimited)
```

Three properties this inherits, each load-bearing:

- **The tier is server-derived.**
  [../../billing/09](../../billing/09-entitlement-integration.md) §9.3 reads
  `brain_memberships.membership` server-side. A respondent cannot influence it, because a
  respondent never touches it.
- **The registry JSON is read from disk**, not from Parse. It is static, versioned with the
  deployment, and safe — the same reason afs-connect's entitlement layer reads it directly.
- **Fail closed on an unresolvable member; fail *open* on an unreachable Brain.** §9.3 specifies
  deny for `block` resources when Brain is down. **Forms invert that one case deliberately**: a
  Brain outage must not silently start discarding a tenant's registrations. Cache the last
  resolved tier per site (300s, matching `BrainMembershipLookup`); with no cached value and Brain
  unreachable, **accept the submission, flag it, and log**, then reconcile (§15.12). An
  unresolvable *member* — a site with no owner — is still a hard refuse; that is a data error,
  not an outage.

That divergence is a decision, and it is the one [03](03-architecture.md) §3.5 already made for
this module: *"a submission must never be blocked by a limitation of ours."* Our Brain being down
is a limitation of ours. The tenant's plan being full is not.

## 15.8 The ceiling, from both sides

Two audiences who need opposite things.

### The respondent

A stranger filling in a registration form has no relationship with our billing. They must never
see a quota error, a plan name, or a 500.

**They see the form's closed message** — the mechanism
[09](09-publishing-and-versioning.md) §9.5.1 already defines for a form the tenant closed
deliberately, returned as **409** with the same body shape. From the respondent's side, a site
over quota is indistinguishable from a form whose registration period ended. That is honest
enough: it is closed, and why is not their business.

The response carries a machine-readable `reason: "quota"` for our logs and the operator's view.
**It is not rendered.**

### The tenant

Three notifications, and the first is the one that actually prevents the problem:

| Trigger | Who | What |
|---|---|---|
| **85%** (`warn_at_pct`) | site owner | Email + a persistent admin banner: *"1,700 of 2,000 submissions used this month."* |
| **95%** | site owner | Second email, urgent wording |
| **100%** | site owner | *"Your forms have stopped accepting submissions."* + upgrade link |

`warned_at` on the counter row makes each fire once per period. A quota warning that repeats on
every submission is a quota warning that gets filtered.

### The grace band

At exactly 100%, submissions stop. That is what `block` means, and unbounded free acceptance is
the cost the limit exists to control.

But the moment the ceiling is hit is the worst moment to be strict: the tenant has just been
notified and cannot act instantly, and the submissions being refused are somebody's job
application.

**`config('forms.quota_grace_hours')`, default 48.** For that long after `first_over_at`,
submissions are still accepted, stored with `status = flagged` and a `validation_state` note, and
`accepted` keeps climbing. After it, the form hard-closes for the rest of the period.

A tenant who upgrades within two days loses nothing; a tenant who ignores three emails for two
days has made a choice. **Set the default to `0` to disable it** — a deployment that would rather
be strict should not have to patch code to be.

## 15.9 The mirror

The local counter is authoritative. alloc_v2 gets a copy so the member's portal shows one number
per resource rather than forms being the odd one out.

`ProcessFormSubmission` ([11](11-integrations-and-notifications.md) §11.2) already runs queued,
per submission, and is the right place — **after** the response has gone back to the respondent.

- Mirror **the absolute value**, not an increment. A missed job would otherwise desynchronise
  permanently; an absolute write self-heals on the next submission.
- Coalesce: at most one mirror per site per minute (`mirrored_at`), so a burst of 400 submissions
  is one Parse write, not 400.
- **A failed mirror never affects the submission.** Log and move on; the number the tenant is
  governed by is the one in MySQL.
- Mirroring writes **usage only**. It must never write a tier or a limit into `MemberRegistry`
  ([../../billing/09](../../billing/09-entitlement-integration.md) §9.4's second warning).

`domain_forms_count` is not mirrored — it is *held* in alloc_v2, because §15.4's call sites are
its only writers.

## 15.10 Level 99

`app.window.member.level === 99` bypasses every allocation
([../../allocation.md](../../allocation.md) §6). Forms need it in two places, and it behaves
differently in each.

**Admin (`domain_forms_count`)** — nothing to do. The bypass is configured once in
`createAllocV2()` and §15.4's call sites inherit it: staff create forms past the ceiling, and
`consume`/`release` are no-ops so nothing lands in a member's registry.

**Server (`domain_forms_submissions_monthly`)** — the bypass **must be re-implemented**, because
this path never constructs the client. The entitlement response
([../../billing/09](../../billing/09-entitlement-integration.md) §9.3) carries `level`; the limit
resolver in §15.7 checks it exactly as the factory does:

```php
// AFS_ALLOC_V2_TAG — level 99 is the platform superuser marker (main.ts:596).
// EXACTLY 99, never >= 99: `level` defaults to 0 and has no ladder above it.
if ((int) $entitlement['level'] === 99) {
    return -1;                              // unlimited — but the claim still counts
}
```

**Note what that does not do.** [../../allocation.md](../../allocation.md) §6.2 makes `consume` a
**no-op** under bypass so staff activity never pollutes a member's counters. Here the counter
still increments, deliberately:

- The local counter is an **operational metric first** — it is what the operator's "submissions
  this month" figure is computed from. Zeroing it would misreport the tenant's own traffic to the
  tenant.
- Every submission it counts is a **real row**, with real storage and a real queued job. The
  client-side case skips counting because a staff-created processor is an artefact of testing; a
  submission is not — it is data the tenant now owns.

So: **bypass lifts the ceiling, never the meter.** State it in the code, because it reads as an
inconsistency with §6 until the reason is on the page.

## 15.11 What the member sees

Every tier has `enabled: true` (§15.1), so there is **no "not on your plan" case** — one refusal,
plus the error case:

| Case | Message |
|---|---|
| Limit reached, create | *"You're using all 10 forms included in your plan."* + **Upgrade** · **Archive a form** |
| Limit reached, unarchive / restore | *"Restoring this form would put you over your limit of 10. Archive another form first."* |
| Check failed | *"We couldn't verify your plan limits. Please try again."* — **no upgrade prompt** |
| Submissions at 85% / 95% / 100% | §15.8 |

Two deliberate details:

- **"Archive a form" is an action, not advice.** It is the one thing that resolves the block
  without paying, and hiding it to push an upgrade would make the limit feel like a trap. It is
  also the only reason 2-on-free is workable at all (§15.3).
- **The error case carries no upgrade prompt.** Selling to someone whose request failed for an
  unrelated reason is worse than saying nothing
  ([../../billing/16](../../billing/16-allocation.md) §16.5).

## 15.12 Drift

```
php artisan alloc:reconcile-forms [--dry-run] [--site=]
```

| Metric | Compared against |
|---|---|
| `domain_forms_count` in Parse | `count(*)` on `laravel_forms`, status ∈ (draft, published, closed), `deleted_at is null`, per site |
| `laravel_form_submission_quota.accepted` | `count(*)` on `laravel_form_submissions` for the period, status ∈ (complete, flagged) |

The second comparison has a known and acceptable divergence: submissions accepted during a Brain
outage (§15.7) were never claimed, and rows dropped by retention ([02](02-security-and-tenancy.md)
§2.6) are gone while the meter correctly remembers them. **The reconciler reports both as
annotated, not as drift** — a reconciler that cries wolf about its own documented behaviour is one
nobody reads.

**Reports by default; corrects only with `--fix`**, for the reason
[../../billing/16](../../billing/16-allocation.md) §16.8 gives: persistent non-zero drift means a
call site is missing, and the fix is the call site.

## 15.13 Rollout

| Phase | Contents | Depends on |
|---|---|---|
| **P8a — forms count** | §15.3, §15.4: the active-set rule, `canUse`/`consume`/`release`, the two restore transitions, **F62** and **F63** | **P0** (**F66**), and the status ladder from **P1** |
| **P8b — the meter** | §15.6: the quota table and the conditional-increment helper, shared with `close_after_responses` | P1 |
| **P8c — enforcement** | §15.7, §15.8: tier resolution, the claim in `submitForm()`, the closed-message refusal, the grace band, the three warnings | P8b, P0 |
| **P8d — mirror and messaging** | §15.9, §15.11: the coalesced mirror, `showFormsLimit()` | P8a, P8c |
| **P8e — reconciler** | §15.12 | P8a–P8d |

Slots after **P7** in [14](14-rollout-and-testing.md) §14.1. **P8a is the small one** — five call
sites and two response checks. **P8c is the one to review carefully**, because it is the first
thing in this module that can refuse a respondent.

**Nothing here ships before P0.** A client-side ceiling in front of an unauthenticated
`POST /forms` is not a limit; it is a suggestion made to the only party with no reason to take it
(**F66**).

## 15.14 Acceptance

**Forms count**

- A **T0** member creates **2 forms**; the 3rd shows the limit message with an **Archive** action
  (§15.11).
- **Archiving** frees the allowance immediately — verified by creating at the limit, archiving,
  creating again.
- **Unarchiving** at the limit is **refused**, with the restore-specific message (§15.3.1).
- **Soft-deleting** releases; **restoring from trash** at the limit is refused; the 30-day hard
  delete releases **nothing further** (§15.3.1).
- A **closed** form still counts. A **draft** still counts (§15.3).
- A member with **two sites** has a separate allowance on each — the `per_domain` scope is
  actually passed.
- A create that returns **422 or 500 does not consume** — verified by forcing the failure and
  reading the counter (**F62**).
- A delete that returns **500 does not release** (**F63**).
- A failed `canUse` **blocks** and shows the error message with **no upgrade prompt** — it does
  not fall through to the create path (**G12**).
- A **T4** member is unlimited and never sees a dialog.

**Submissions**

- A **T0** site accepts **100 submissions** in a UTC month; the 101st is accepted under the grace
  band, and after `quota_grace_hours` the form returns **409 with the closed message** — with
  **no plan or quota wording reaching the respondent** (§15.8).
- With `forms.quota_grace_hours = 0`, the 101st is refused immediately.
- The counter **resets at 00:00 UTC on the 1st**, and tenant-facing copy shows that moment in the
  site's own timezone (§15.6.1).
- **Concurrency:** 50 simultaneous submissions against a site with 10 remaining accept exactly 10
  (§15.6).
- A submission marked **`spam` does not count**; **`flagged` does**; **`preview` does not**
  (§15.6.2).
- **Deleting a submission does not restore quota** (§15.6.2).
- Brain unreachable with no cached tier → the submission is **accepted and flagged**, never
  discarded (§15.7).
- A site with **no resolvable owner** → refused.
- The Parse mirror matches `laravel_form_submission_quota`, and **a Parse outage does not affect
  a single submission** (§15.9).
- Warnings fire **once each** at 85%, 95% and 100% per period (§15.8).

**Level 99**

- A level-99 account creates forms **past every tier limit** with no dialog, and the member's
  Parse counters **do not move** (§15.10).
- A level-99 site accepts submissions **past its monthly ceiling**, and
  `laravel_form_submission_quota.accepted` **still increments** — the meter is not bypassed
  (§15.10).
- `level` of `98` or `100` is **not** a bypass.

**Not wired, deliberately**

- `grep -rn "forms_items_count"` returns the registry and `resources.ts` only — **no call sites**
  (§15.1.1).

# 11 — Secondary user dashboard (members)

## Completion document

When this plan step is completed, write `complete/11-secondary-user-dashboard.complete.md`
with status, summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly using JSDoc (TypeScript) and PHPDoc (PHP).

**One thing must be documented wherever it appears:** the shell a user gets is chosen by
their *identity type*, not by a feature flag or a role string. Every branch that selects a
shell says so and links here, because a future reader will otherwise try to unify the two
layouts and quietly hand a sub-account the owner's dashboard.

---

## 1. Requirement

> Secondary users sign in to the members website and get a **completely separate,
> minimal dashboard** listing only the apps they are authorised to use.

## 2. This reverses part of step 10 — read this first

[10-primary-only-enforcement.md](10-primary-only-enforcement.md) as originally written said
secondary users may **not** sign in to members at all. That is now wrong, and §7 of this
document specifies exactly what changes there.

**What is unchanged:** secondary users still cannot sign in to the **desktop** or **mobile**
apps, and still cannot reach any owner-level surface in members — billing, domains,
storage, VoIP, sub-user management, afs-connect settings.

**What changes:** "cannot sign in to members" becomes "signs in to members and reaches a
different, much smaller surface". The rule was a blunt instrument for the right instinct;
this is the precise version.

That distinction matters more than it looks. A flat ban is enforced with one middleware
flag. A restricted surface needs **two route groups with different rules**, and getting
that split wrong is how a sub-account ends up looking at the account owner's billing page.

## 3. Why this is better than the ban

Three problems the ban created, which the dashboard solves:

1. **Sub-account users had nowhere to go.** Nothing told them which apps they had, or
   where those apps were. Onboarding a staff member meant the account owner sending links
   by hand.
2. **[09](09-two-factor-authentication.md) was built around the absence of a dashboard.**
   Its §3.2 opens *"secondary users have no dashboard of their own, so the primary user
   controls whether 2FA is required"*. That premise is now partly false — see §6.
3. **A sub-account hitting members got a dead end.** [10](10-primary-only-enforcement.md)
   §4.3 had to invent an explanatory page whose entire job was to apologise and send them
   elsewhere. A launcher is that page, doing something useful.

## 4. What the secondary user sees

Deliberately minimal. This is a launcher and an account strip, not a small version of the
owner dashboard.

```
┌───────────────────────────────────────────────────────────┐
│  Appfactory Studio                         Jane R.  [ ⌄ ] │
│                                    working under Acme Ltd │
├───────────────────────────────────────────────────────────┤
│                                                           │
│   Your apps                                               │
│                                                           │
│   ┌─────────────┐  ┌─────────────┐  ┌─────────────┐       │
│   │      ▤      │  │      ✎      │  │      ◈      │       │
│   │ Coordinator │  │    Notes    │  │ Dream Graph │       │
│   │    Admin    │  │   Editor    │  │   Viewer    │       │
│   └─────────────┘  └─────────────┘  └─────────────┘       │
│                                                           │
│   Security                                                │
│   Two-factor authentication      Enrolled   [ Manage ]    │
│                                                           │
└───────────────────────────────────────────────────────────┘
```

### 4.1 What is on it

| Element | Source | Notes |
|---|---|---|
| App tiles | `user.apps` from the verified token | **Only** apps with a grant. No greyed-out upsells — a sub-account cannot buy anything |
| Role per app | `apps[name].role` | Shown because a user with `Viewer` on one app and `Admin` on another needs to know which is which |
| Account context | The super user's member record | *"working under Acme Ltd"* — a sub-account must know whose data they are about to edit |
| Own identity | The secondary user's own name/email | **Not** the primary's — see §5.3 |
| 2FA status + manage | [09](09-two-factor-authentication.md), §6 below | |
| Sign out | | |

### 4.2 What is deliberately absent

No billing, plan, or usage. No domains, storage, VoIP, market seller tools, afs-connect
settings, sub-user management, notifications feed, or app-store upsells.

**Absent from the layout, and refused by the API** (§5.2). A hidden nav item is a UI
preference; a route group that rejects the token is a control.

### 4.3 The app→URL gap

The launcher needs to link somewhere, and **no app→URL map exists today**. What exists:

| App | URL source | Client-visible? |
|---|---|---|
| COORDINATOR | `VITE_COORDINATOR_URL` | ✅ |
| NOTES_EDITOR | `VITE_NOTES_URL` | ✅ |
| GRAPH_EDITOR | `VITE_DREAM_GRAPH_URL` | ✅ |
| FLOW_EDITOR | `VITE_DESIGN_FLOW_URL` | ✅ |
| PDF_EDITOR | `VITE_PDF_EDITOR_URL` | ✅ |
| MARKET | `MARKET_URL` | ❌ **server-side only** — needs a `VITE_MARKET_URL` |
| GAME_EDITOR | — | ❌ **none exists** |

`GAME_EDITOR` is in `UI_APP_ID_TO_CHECKPOINT`, so a primary **can grant it today**, and the
launcher would then have a tile with nowhere to point.

Decide before building (§9 Q13): either give it a URL, or render granted-but-unlaunchable
apps as a tile with an explanation ("Joystick Engine runs in the desktop app") rather than
a dead link. Silently omitting a granted app is the one thing not to do — the user was told
they have access.

Build one registry, `resources/js/checkpoint/app-urls.ts`, mapping `CheckpointAppName` →
URL, beside the existing `app-names.ts` and carrying the same "keep in sync" comment.

## 5. Architecture

### 5.1 Two shells, chosen by identity type

`registerViews(app)` (`resources/js/views.ts:110`) registers a shell sub-view — `id: "main"`,
`init: true`, `body: dashboardShellTemplate` — that every content view renders into via
`{ container: 'dashboard-content' }`.

The branch goes there:

```ts
/**
 * The shell is chosen by IDENTITY TYPE, not by role, permission, or feature flag.
 *
 * A secondary user gets a separate minimal shell (plan 11 §4) with its own
 * template and its own small set of sub-views. This is not the owner shell
 * with items hidden: hiding is a CSS-level decision that a devtools user
 * undoes in seconds, and the owner sub-views would still be registered and
 * routable. Registering a different set of views is what makes the
 * restriction structural on the client — and the API refuses them anyway
 * (§5.2), because client-side structure is convenience, not security.
 */
export function registerViews(app: ApplicationContextManager) {
    return app.window.user?.type === 'secondary'
        ? registerSecondaryViews(app)
        : registerPrimaryViews(app);   // today's registerViews body, unchanged
}
```

`registerSecondaryViews()` registers:

| Sub-view | Route | Purpose |
|---|---|---|
| `secondary-main` | — (`init: true`) | The minimal shell |
| `secondary-apps` | `/` | The launcher grid |
| `secondary-security` | `/security` | 2FA management (§6) |

**No other sub-views are registered**, so no owner route is reachable — not by hash, not by
deep link, not by typing it.

### 5.2 Server: two route groups, not one flag

[10](10-primary-only-enforcement.md) §3.4 proposed `primary_only` across members' whole
authenticated group. That must now split.

```php
// ── OWNER SURFACE — primary users only. ────────────────────────────────
// Billing, domains, storage, VoIP, sub-user management, afs-connect.
// A secondary token is rejected here with `secondary_not_permitted`.
Route::middleware('checkpoint.member:primary_only')->group(function () {
    // …everything from plan 03 §2…
});

// ── LAUNCHER SURFACE — any authenticated member identity. ──────────────
// The three endpoints the secondary dashboard needs, and nothing else.
// Adding a route here is a security decision: it is the ONLY members
// surface a sub-account can reach (plan 11 §5.2).
Route::middleware('checkpoint.member')->prefix('api/v1/me')->group(function () {
    Route::get ('/',        [MeController::class, 'show']);      // identity + account context
    Route::get ('/apps',    [MeController::class, 'apps']);      // granted apps + URLs
    Route::get ('/2fa',     [MeController::class, 'twoFactor']); // status
    Route::post('/2fa/…',   …);                                   // enrol / regenerate (§6)
});
```

Three rules for the launcher group, all load-bearing:

- **It is an allow-list of three or four endpoints**, never a prefix that grows by accident.
- **It returns only the caller's own data** — no member-wide reads, even read-only. "Show
  the account's domains, read-only" sounds harmless and is how the surface starts leaking.
- **`/api/v1/me/apps` derives its list from the verified token's `apps` claim**, never from
  a request parameter.

### 5.3 The identity trap

`views.ts:154` currently ends the shell listener with:

```ts
setUser(app.window.member.username, 'Member');
```

For a secondary user, `member` is the **super user's** record ([01](01-platform-audit.md)
§1.1 P3). So the header would greet a sub-account by **the account owner's name**.

That is confusing at best and misleading at worst — someone could reasonably believe they
are signed in as the owner.

The secondary shell shows:

- **Name/email:** the secondary user's own, from `user`.
- **Account context:** the member's organisation name, clearly labelled as *whose account
  this is* — "working under Acme Ltd", not a name in the avatar slot.

`main.ts:141–142` already assigns both `app.window.user` and `app.window.member`, so both
values are present. The bug would be reading the wrong one.

### 5.4 What is reused

The existing `ApplicationContextManager`, `view.newSubView`, i18n, theme, and toast
infrastructure all work unchanged. This is a new shell template and three small views —
not a second frontend.

## 6. 2FA, revisited

[09](09-two-factor-authentication.md) §3.2 reasoned from *"secondary users have no dashboard
of their own"*. They now have one, so that premise needs restating rather than quietly
contradicting.

**Decided 2026-08-22.** The split below is settled; it amends
[09](09-two-factor-authentication.md) §3.1–3.2.

| Action | Who | Changed? |
|---|---|---|
| Whether 2FA is **required** | **Primary** | Unchanged — it is the account owner's policy |
| **Enrolling** a device | **Secondary**, now from `/security` as well as the forced login step | Improved |
| Viewing enrolment status | Secondary (own) + primary (all) | New for the secondary |
| **Regenerating** recovery codes | **Secondary**, from `/security` | New — removes a support path |
| **Reset** after a lost device | **Primary** | Unchanged — the secondary cannot authenticate to ask |
| Seeing/setting the TOTP secret | **Nobody but the enrolling user** | Unchanged, and non-negotiable |

The principle, stated once so the boundary survives future edits: **the primary owns
policy, the secondary owns their own device.**

What the dashboard changes is that a secondary user can re-enrol or regenerate codes
without an account owner doing it for them. What it does not change is the lost-device
case, which still needs the primary — a locked-out user cannot authenticate to fix their
own lockout, so the recovery path must sit with someone who can.

And the invariant underneath both: **nobody but the enrolling user ever sees or sets the
TOTP secret.** A primary who could read it could authenticate as that user, which would
make the second factor worthless against the one party it most needs to be independent of.

[09](09-two-factor-authentication.md) §3.1's table and §3.2's opening sentence are amended
by this section.

## 7. Changes to step 10

| [10](10-primary-only-enforcement.md) §| Was | Becomes |
|---|---|---|
| §1 rule | Secondary users may not sign in to members | Secondary users **may** sign in to members and reach the launcher surface only. Desktop and mobile remain barred |
| §2 | Why members joins the primary-only list | Why members is a **restricted** surface, not a barred one |
| §3.1 layer 1 | `MEMBERS` absent from `UI_APP_ID_TO_CHECKPOINT` | **Unchanged.** MEMBERS is still not a grantable app — see §7.1 |
| §3.2 layer 2 | `PRIMARY_ONLY_APPS` = MEMBERS, STUDIO, MOBILE | **MEMBERS removed.** STUDIO and MOBILE remain |
| §3.4 layer 4 | `primary_only` on members' whole API | Split into owner + launcher groups (§5.2) |
| §4.1 | Assert primary in members' web client | **Removed.** Replaced by the shell branch (§5.1) |
| §4.2 | Remove `login`/`selectAccount` from members' SDK copy | **Reversed.** members now needs the secondary login path |
| §4.3 | "This account can't sign in here" page | **Deleted.** The launcher replaces it |
| §6 P6, P7 | Secondary refused by client and API | Replaced by P6′/P7′ in §8 |

### 7.1 How a secondary user signs in without a MEMBERS grant

Worth being explicit, because it looks like a contradiction: `MEMBERS` is not grantable, so
no secondary user holds a MEMBERS-scoped grant — yet they must sign in to members.

**members does not require a MEMBERS-scoped token.** Its launcher group accepts any valid
secondary token, whatever app it was minted for. A user with only a `NOTES_EDITOR` grant
signs in and sees a launcher with one tile.

This is deliberate, and it is the one place the app-scoping rule
([02](02-two-patterns.md) §4) is relaxed on purpose:

- Requiring a MEMBERS grant would mean every primary must tick an extra box before their
  staff can find their apps — a setup step whose only failure mode is a confusing dead end.
- The launcher exposes **nothing app-specific**. It reads the token's own `apps` claim and
  shows the user what they already have. There is no data behind it that a
  `NOTES_EDITOR`-scoped user should not see.

The owner group keeps full `primary_only` enforcement, so the relaxation is scoped to three
endpoints that return only the caller's own identity.

> **This is exactly the shape of finding F3** — accepting a token minted for another app —
> which the plan elsewhere treats as a defect. The difference is that F3 is *unintentional
> and undocumented on a surface with member data behind it*, whereas this is a deliberate,
> documented relaxation on a surface with nothing behind it but the caller's own claims.
> Both facts belong in the code comment, or someone will later "fix" this to match F3.

## 8. Acceptance

| # | Criterion |
|---|---|
| SD1 | A secondary user signs in to members and lands on the minimal shell |
| SD2 | The launcher shows exactly the apps in the token's `apps` claim — no more, no fewer |
| SD3 | Each tile shows the user's role for that app and opens the correct URL |
| SD4 | A granted app with no URL is rendered with an explanation, never omitted and never a dead link |
| SD5 | No owner sub-view is registered for a secondary session — owner routes are unreachable by hash or deep link |
| SD6 | **P7′** — a secondary token is rejected by every owner API route with `secondary_not_permitted` |
| SD7 | **P6′** — a secondary token is accepted by the three launcher endpoints and nothing else |
| SD8 | The header shows the **secondary user's own** name, with the account owner shown separately as context (§5.3) |
| SD9 | A primary user's dashboard is **byte-identical** to today — the branch changes nothing for them |
| SD10 | A secondary user can view 2FA status, enrol, and regenerate recovery codes; they cannot change the `require_2fa` policy |
| SD11 | `/api/v1/me/apps` derives from the verified token, and a forged `apps` parameter changes nothing |
| SD12 | A secondary user with zero app grants sees an empty state explaining who to contact — not a blank page or an error |

**SD9 is the regression that matters.** Every primary user's dashboard flows through the
same `registerViews()` branch; the failure mode of getting it wrong is not a security hole
but every account owner losing their dashboard.

## 9. Open questions

| # | Question | Impact | Default |
|---|---|---|---|
| ~~Q12~~ | ~~Confirm the §6 2FA split~~ | — | **ANSWERED 2026-08-22: confirmed as specified in §6.** |
| **Q13** | `GAME_EDITOR` has no URL and is grantable. Give it one, or render it as launch-in-desktop? | SD4 | Explanatory tile |
| **Q14** | Should the launcher show anything else — notifications, a support link? | Scope | No. Start minimal; adding later is cheap, removing is not |
| **Q15** | Can a secondary user change their own password or email from `/security`? | Scope, and a real support burden if the answer is no | Password yes, email no (email is the login identifier the primary created) |
| **Q16** | `MARKET_URL` has no `VITE_` equivalent | The Market tile cannot link | Add `VITE_MARKET_URL` |

## 10. Files

| Path | Action |
|---|---|
| `resources/js/views.ts` | Branch on identity type; extract today's body to `registerPrimaryViews()` |
| `resources/js/views/secondary.ts` | New — `registerSecondaryViews()` |
| `resources/js/templates/views/secondary_shell.html` | New — the minimal shell |
| `resources/js/templates/views/secondary_apps.html` | New — launcher grid |
| `resources/js/templates/views/secondary_security.html` | New — 2FA management |
| `resources/js/checkpoint/app-urls.ts` | New — `CheckpointAppName` → URL |
| `resources/js/main.ts` | Nothing beyond what it already does — it assigns `user` and `member` at `:141–142` |
| `app/Http/Controllers/MeController.php` | New — the three launcher endpoints |
| `routes/web.php` | Owner group + launcher group (§5.2) |
| `resources/js/locales/*` | `secondary.*` strings |
| `_env/.env` | `VITE_MARKET_URL`; `GAME_EDITOR` URL if Q13 says so |

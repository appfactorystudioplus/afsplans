# 10 — Primary-only surfaces, enforced

## Completion document

When this plan step is completed, write `complete/10-primary-only-enforcement.complete.md`
with status, summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly — PHPDoc in `apps/auth` and `apps/members`,
JSDoc/`///` in the clients. Every rejection path documents which rule it enforces and where
that rule is written down.

**The omissions are the control here**, so they need comments more than the code does.
`APPFACTORY_STUDIO` and `MEMBERS` are absent from `UI_APP_ID_TO_CHECKPOINT`, and that
absence is load-bearing — without a comment saying so, someone will eventually "complete"
the list and silently remove the guarantee.

---

> ## ⚠ Amended by step 11
>
> This step originally barred secondary users from the members website entirely.
> [11-secondary-user-dashboard.md](11-secondary-user-dashboard.md) replaces that with a
> **restricted surface**: secondary users sign in to members and reach a minimal app
> launcher, while every owner-level surface stays primary-only.
>
> Step 11 §7 lists every section of this document that changes. **The desktop and mobile
> rules below are unaffected** and remain the substance of this step.

## 1. The rule

> **Secondary users may sign in to: coordinator, notes, dream_graph, pdf_editor, flow,
> market, and — restricted to a launcher surface — the members website
> ([11](11-secondary-user-dashboard.md)).**
>
> **Secondary users may NOT sign in to: the desktop app or the mobile app.**
>
> **Secondary users may never reach a members owner surface** — billing, domains, storage,
> VoIP, sub-user management, afs-connect settings.

Three decisions from the review, recorded here as settled:

| Question | Answer |
|---|---|
| Q8 — should auth refuse to mint secondary tokens for primary-only apps? | **Yes** |
| Q9 — mobile app name? | **Register `APPFACTORY_MOBILE`** (option B) |
| May secondary users use members? | ~~No~~ → **Yes, on a restricted launcher surface** ([11](11-secondary-user-dashboard.md)) |

## 2. Why the members *owner surface* is primary-only

members is where a primary user manages billing, domains, telephony, storage, and their own
secondary users. A sub-account reaching those pages could create further sub-accounts,
change the account's plan, or delete its domains.

That is an argument about the **owner surface**, not about the login itself — which is why
[11](11-secondary-user-dashboard.md) can let secondary users sign in while keeping every
sentence above true. The owner surface stays primary-only; the launcher surface is new and
has nothing behind it but the caller's own claims.

A structural argument makes the desktop and mobile halves cheap: `UI_APP_ID_TO_CHECKPOINT` in `members/resources/js/checkpoint/app-names.ts`
lists the apps assignable to a secondary user:

```
coordinator · notes · dream · design · pdf · engine · market
```

`MEMBERS` and `APPFACTORY_STUDIO` are **not** there. So no secondary user holds a
Studio-scoped grant, and none can be minted a Studio-scoped token.

The control exists — as the absence of entries in a UI constant. This step converts that
into an enforced, tested rule for Studio and mobile.

`MEMBERS` stays absent too, but for a different reason: members does not *require* a
MEMBERS-scoped token, so there is nothing to grant. See
[11](11-secondary-user-dashboard.md) §7.1.

## 3. Four layers

| Layer | Where | Stops |
|---|---|---|
| **1. Not grantable** | members UI + auth | A secondary user being given access to a primary-only app at all |
| **2. Not mintable** | auth | A token scoped to a primary-only app being issued, however requested |
| **3. Client refuses** | members web, desktop, mobile | A secondary identity arriving by any other route |
| **4. Server refuses** | members API | A tampered client presenting a secondary token |

Layers 1–2 are the real controls: if the token cannot exist, nothing downstream has to
catch it. Layers 3–4 are defence in depth.

### 3.1 Layer 1 — not grantable

`UI_APP_ID_TO_CHECKPOINT` stays as it is. Add:

```ts
/**
 * Apps assignable to a SECONDARY user in the members create-user UI.
 *
 * APPFACTORY_STUDIO and APPFACTORY_MOBILE are deliberately ABSENT and must stay
 * absent: secondary users are barred from the desktop and mobile apps — see
 * afsserver/plans/checkpoint-server-auth/10-primary-only-enforcement.md §1.
 *
 * MEMBERS is absent for a different reason. Secondary users DO sign in to the
 * members website, but only to a launcher surface that requires no MEMBERS
 * grant (plan 11 §7.1). There is nothing here to grant, and adding it would
 * imply owner access that the route groups refuse anyway.
 *
 * This omission is a security control, not an oversight. It is also enforced
 * server-side (§3.2) so editing this list alone cannot grant access — but a
 * change here would still put a broken option in front of a user, so do not
 * "complete" this map.
 */
```

Plus a test asserting all three names are absent. A comment is guidance; a test is a
control.

### 3.2 Layer 2 — not mintable

In `apps/auth`, a single guarded list:

```php
/**
 * App names a SECONDARY user can never be scoped to.
 *
 * These are first-party surfaces where the platform rule is primary-only
 * (plan 10 §1). Enforced HERE, at mint time, rather than only at the client:
 * a refusal to issue is the one control that does not depend on client code
 * a user can modify.
 *
 * Applies to secondary registration, secondary login, app-grant assignment,
 * and account selection — every path that can attach an app scope.
 */
public const PRIMARY_ONLY_APPS = [
    // MEMBERS deliberately NOT listed — a secondary user signs in to members
    // for the launcher surface (plan 11 §7.1). The owner surface is protected
    // by route-group middleware there, not by refusing to mint a token here.
    CheckpointAppName::APPFACTORY_STUDIO,
    CheckpointAppName::APPFACTORY_MOBILE,
];
```

Enforcement points in `SecondaryUserService` / `SecondaryUserController`:

| Path | Behaviour |
|---|---|
| `POST /checkpoint/secondary/register` | Reject if `app_name` is primary-only |
| `POST /checkpoint/secondary/login` | Reject with `SECONDARY_APP_NOT_PERMITTED` |
| `POST /checkpoint/secondary/select-account` | Same |
| App-grant assignment (`update`, grants API) | Refuse to attach a primary-only app |
| `GET /checkpoint/secondary/users` and any app list | Never present a primary-only app as available |

The error code is distinct — not folded into "invalid credentials" — so the UI can say
something true, and so the logs distinguish a misconfiguration from an attack.

### 3.3 Layer 3 — clients refuse

Identical `assertPrimary()` treatment in all three:

| Client | Spec |
|---|---|
| Desktop | [07](07-desktop-client-auth.md) §3, §4.1 |
| Mobile | [08](08-mobile-client-auth.md) §3, §4.1 |
| **members web** | ~~§4~~ — **superseded**: members now branches to a separate shell ([11](11-secondary-user-dashboard.md) §5.1) rather than refusing the session |

### 3.4 Layer 4 — members' API refuses

This is where members differs usefully from desktop and mobile.

[07](07-desktop-client-auth.md) §5.1 notes that server-side primary-only enforcement mostly
cannot work for Studio, because the desktop app calls the *same* APIs as the web client and
the server cannot tell them apart.

**members has no such problem.** `members/routes/web.php` serves members' own frontend and
nothing else, so `primary_only` there is a genuine server-side guarantee rather than a
client-side preference.

**Applied to the owner group only.** [11](11-secondary-user-dashboard.md) §5.2 splits
members' API into an owner group (`primary_only`) and a three-endpoint launcher group that
accepts any member identity. A secondary token reaching any owner route is rejected
`secondary_not_permitted`; the launcher endpoints return only the caller's own claims.

## 4. members web client — SUPERSEDED

> **This entire section is replaced by [11](11-secondary-user-dashboard.md) §5.**
>
> It specified refusing a secondary session in members' browser client, removing the
> secondary login path from members' SDK copy, and an explanatory "you can't sign in here"
> page. members now *branches to a different shell* instead, and needs that login path.
>
> Retained rather than deleted so the reversal is visible: a reader who finds the client
> asserting primary in a future diff should know it was specified here and withdrawn, not
> that it was forgotten.

~~members' browser client needs the same treatment the two native clients get.~~

### 4.1 Assert after login

`resources/js/main.ts:131–132` runs `ClientHandler.handlePageLoad()`. Add the assertion
immediately after, before any view mounts:

```ts
/**
 * Reject a secondary identity on the members website.
 *
 * Secondary users are barred from members (plan 10 §1, §2). This is layer 3;
 * layers 1–2 mean such a token should not exist, and layer 4 means the API
 * would reject it anyway. This exists so the user sees an explanation instead
 * of a dashboard that 401s on every request.
 *
 * Tokens are cleared, not ignored — a rejected session left in storage makes
 * every subsequent page load fail identically with no way out.
 */
if (result.authState.userType === 'secondary') {
    await handler.clearAuth();
    showSecondaryNotPermitted();   // explains, offers the apps they CAN use
    return;
}
```

### 4.2 Remove the capability

members' `resources/js/checkpoint/` is the same SDK the desktop ships, including
`secondary-users.ts` and the secondary paths in `client-handler.ts`.

**members cannot simply delete `secondary-users.ts`** — unlike desktop and mobile, members
is where a primary user *manages* their secondary users, and that management UI
(`resources/js/applications/applications.ts`) legitimately uses the register/list/update/
delete surface.

So the split is:

| Keep | Remove |
|---|---|
| `register`, `list`, `get`, `update`, `delete`, identifier helpers — **management**, performed by an authenticated primary | `login`, `selectAccount`, and the secondary branches of `client-handler.ts` — **authentication as a secondary user** |

Document the distinction at the top of the file, because it is not obvious that half a
module is permitted and half is not.

### 4.3 The message matters

A sub-account user who lands on members must be told something true and actionable:

> **This account can't sign in here.**
> Sub-accounts use the AFS apps directly — Coordinator, Notes, Dream Graph, PDF Editor,
> Design Flow, and Market. The members dashboard is for account owners.
> [ Go to Coordinator ] [ Sign in as a different user ]

Not "access denied", and not a silent redirect to a login loop. The account exists and the
password was right; only the destination is wrong.

## 5. Registering `APPFACTORY_MOBILE`

Q9, answered: add a distinct app name rather than reusing `APPFACTORY_STUDIO`.

| File | Change |
|---|---|
| `auth/app/Services/Checkpoint/CheckpointAppName.php` | Add `APPFACTORY_MOBILE` |
| `members/resources/js/checkpoint/app-names.ts` | Add to `CHECKPOINT_APPS` + a label; **not** to `UI_APP_ID_TO_CHECKPOINT` |
| `afsdesktop5/src/renderer/checkpoint/app-names.ts` | Keep in sync (it mirrors the same registry) |
| `afsmobile_flutter/lib/config.dart` | `checkpointAppName = 'APPFACTORY_MOBILE'` |
| `auth` — `PRIMARY_ONLY_APPS` | Include it (§3.2) |

Each registry file already carries a "must stay in sync" comment; extend those to name the
new entry and the primary-only rule.

### 5.1 What it buys

- Mobile sessions become distinguishable from desktop in logs and in any future per-app
  policy or revocation.
- Mobile gains the same layer-2 protection Studio has.
- A future "sign out all mobile devices" becomes possible; today there is nothing to scope
  it to.

### 5.2 What it does not buy

Primary tokens carry **no `app_name` claim** ([01](01-platform-audit.md) §1.1 P4), and
mobile users are all primary. So the app name does **not** scope the mobile app's own
sessions — it exists so that a *secondary* token can never be minted for mobile, and so the
client has a stable identity to declare.

Stating this prevents the natural misreading that registering a name makes mobile sessions
app-scoped. It does not.

## 6. Acceptance

| # | Criterion |
|---|---|
| P1 | `MEMBERS`, `APPFACTORY_STUDIO`, `APPFACTORY_MOBILE` are absent from `UI_APP_ID_TO_CHECKPOINT`, asserted by a test |
| P2 | Secondary registration naming a primary-only app is rejected with `SECONDARY_APP_NOT_PERMITTED` |
| P3 | Secondary login naming a primary-only app is rejected with the same distinct code |
| P4 | An app-grant update cannot attach a primary-only app to a secondary user |
| P5 | No API lists a primary-only app as available to a secondary user |
| ~~P6~~ | ~~Secondary identity refused by the members web client~~ → **P6′**: a secondary identity reaching members lands on the launcher shell ([11](11-secondary-user-dashboard.md) SD1) |
| ~~P7~~ | ~~Rejected by any members API route~~ → **P7′**: rejected by every **owner** API route; accepted by the three launcher endpoints ([11](11-secondary-user-dashboard.md) SD6, SD7) |
| P8 | members retains full secondary-user **management**: create, list, update, delete, grant apps |
| P9 | Desktop and mobile refuse secondary identities ([07](07-desktop-client-auth.md) DA3, [08](08-mobile-client-auth.md) MA4) |
| P10 | `APPFACTORY_MOBILE` exists in auth, both TS registries, and mobile config, and is in `PRIMARY_ONLY_APPS` |
| P11 | A secondary user can still sign in to coordinator, notes, dream_graph, pdf_editor, flow, and market |

**P11 is the regression that matters.** Everything else in this step restricts; P11 is the
check that it restricted only what was intended. A change that locks sub-accounts out of the
six apps they are sold with is a worse outcome than the exposure being closed.

## 7. Testing

| Test | Asserts |
|---|---|
| Create a secondary user in members | Only the six permitted apps are offered (P1, P5) |
| Craft a registration request naming `MEMBERS` | Rejected (P2) |
| Craft one naming `APPFACTORY_STUDIO` / `APPFACTORY_MOBILE` | Rejected (P2) |
| Secondary login with `app_name=MEMBERS` | Rejected with the distinct code (P3) |
| Grant-update attaching a primary-only app | Rejected (P4) |
| Hand-inject a secondary session into members' browser storage | Cleared; §4.3 message shown (P6) |
| Present a valid secondary token to a members API route | `secondary_not_permitted` (P7) |
| Primary creates, edits, grants apps to, and deletes a secondary user | All work (P8) |
| Secondary user signs in to each of the six permitted apps | All work (P11) |
| Secondary user with 2FA required | Enrolment forced at the hosted login page ([09](09-two-factor-authentication.md) T5) |

## 8. Sequencing

| Step | Content | Notes |
|---|---|---|
| **10a** | Register `APPFACTORY_MOBILE` across the registries | Additive; nothing consumes it yet |
| **10b** | `PRIMARY_ONLY_APPS` + auth mint refusal (layer 2) | Should be a no-op if layer 1 held. **Log rather than reject for one release** — if anything is refused, that is a finding about existing data, not a reason to lock users out on deploy day |
| **10c** | ~~members client assertion~~ → superseded by [11](11-secondary-user-dashboard.md) | — |
| **10d** | `primary_only` on members' **owner** API group + launcher group (layer 4) | Ships with step 11, not before — gating the whole API first would lock out the dashboard being built |
| **10e** | Desktop and mobile assertions | [07](07-desktop-client-auth.md), [08](08-mobile-client-auth.md) — independent release trains |

**10b's observe-first rule is the important one.** If any real secondary user currently
holds a MEMBERS or Studio grant — which layer 1 says is impossible, but layer 1 is a UI
constant — flipping straight to reject signs them out with no warning. Observe, read the
logs, then enforce.

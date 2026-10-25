# 00 — Overview

## Completion document

When this plan step is completed, write `complete/00-overview.complete.md` with status,
summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly using block comments (PHPDoc for PHP, JSDoc
for TypeScript). Every middleware, validator, and identity accessor documents what it
trusts, what it rejects, and which direction it fails.

---

## 1. The problem

All seven AFS web apps use Checkpoint (`apps/auth`) as their identity provider. The login
itself happens in the browser — an SSO exchange that hands the client a JWT. The server's
job is to take the bearer token off each request, ask Checkpoint whether it is real, and
derive the caller's identity from the answer rather than from anything the caller typed.

Five apps do exactly that. Two do not, and the two are `members` and `market` — the apps
that sit closest to billing, domains, telephony, and file storage.

### 1.1 How this was found

While planning [cloud-storage](../cloud-storage/), the members storage controller turned
out to authenticate every request as a hardcoded admin. Pulling that thread showed the
storage routes were not an isolated leftover: they were one of six ungated route groups in
`members/routes/web.php`, several of which resolve the caller from a query parameter.

Checking whether the sibling apps had the same shape produced the opposite surprise —
they had already been fixed, five months earlier, and rather well.

### 1.2 What is actually wrong

| # | Finding | Severity |
|---|---|---|
| **F1** | members: 32 of 99 routes ungated, including storage, VoIP, domains, `/api/v1/member`, and afs-connect | **P0** |
| **F2** | members: `DELETE /api/v1/domains/{reference_num}` has no auth **and** no ownership check — it deactivates a DNS zone by id | **P0** |
| **F3** | members/market: `checkpoint.verify` does not check the token's `app_name`, so a token minted for another app is accepted | **P1** |
| **F4** | Two divergent auth patterns platform-wide, with different request-attribute names for the same concept | **P1** |
| **F5** | `CheckpointTokenValidator`/`Verifier` copy-pasted across seven repos | **P1** (drift risk, not yet realised) |
| **F6** | dream_graph asset serving reachable by UUID alone — documented in-code as a known, unfixed exposure | **P2** |
| **F7** | The five-app validator caches *negative* results for 60 s; members caches them for 5 s, deliberately | **P2** |
| **F8** | members VoIP endpoints are an unauthenticated relay to the Twilio API and cannot enforce `voip_numbers_count` | **P2** |
| **F12** | Desktop and mobile ship complete, unguarded secondary-login paths; the "Studio is primary-only" rule is enforced nowhere | **P1** |
| **F13** | Both clients write tokens to plaintext storage on the secondary path (`localStorage` / `SharedPreferences`) | **P1** |
| **F14** | A member with 2FA enabled cannot sign in to the desktop or mobile app at all | **P1** |
| **F15** | The mobile client has no registered Checkpoint app name, so it can never be app-scoped | **P2** |

Evidence for F1–F11 is in [01-platform-audit.md](01-platform-audit.md); for F12–F15, in
[07-desktop-client-auth.md](07-desktop-client-auth.md) §2 and
[08-mobile-client-auth.md](08-mobile-client-auth.md) §2.

**F12 and F13 are one fix.** In both clients the plaintext token storage exists *only* to
serve the secondary path — every key is named `checkpoint_secondary_*`. Removing the
capability removes the storage.

## 2. Goals

| # | Goal | Measured by |
|---|---|---|
| G1 | **Every route in every app is either auth-gated or explicitly, visibly public.** No route is ungated by omission. | [01](01-platform-audit.md) §8, [03](03-members-remediation.md); acceptance A1 |
| G2 | **No controller anywhere resolves identity from `query()`, `input()`, `session()`, or a request header.** | [03](03-members-remediation.md) §4; acceptance A2 |
| G3 | **One auth pattern platform-wide**, with one attribute vocabulary. | [02](02-two-patterns.md) |
| G4 | **App-scoped tokens are enforced everywhere**, not just in five apps. | [02](02-two-patterns.md) §4; acceptance A5 |
| G5 | **One validator implementation**, consumed by all seven apps. | [04](04-shared-validator.md) |
| G6 | **Every remaining known exposure is either closed or has an owner and a date.** | [05](05-open-exposures.md) |
| G7 | **The anti-patterns cannot silently return**, because tests fail when they do. | [06](06-rollout-and-testing.md) §4 |
| G8 | **Only primary users can sign in to the desktop and mobile apps**, enforced in code rather than asserted in a comment. | [07](07-desktop-client-auth.md), [08](08-mobile-client-auth.md); acceptance A13 |
| G9 | **No client stores a credential in plaintext.** | [07](07-desktop-client-auth.md) §2.4, [08](08-mobile-client-auth.md) §2.3; acceptance A14 |
| G10 | **Two-factor authentication works on every client and for every user type**, including secondary users, so enabling it never costs anyone access. | [09](09-two-factor-authentication.md); acceptance A15, A17 |
| G11 | **Secondary users cannot sign in to desktop or mobile**, enforced where the token is minted rather than only in a UI list. | [10](10-primary-only-enforcement.md); acceptance A18 |
| G12 | **Secondary users get their own minimal members dashboard** — an app launcher — while every owner surface stays primary-only. | [11](11-secondary-user-dashboard.md); acceptance A19 |
| G13 | **The app-name registry cannot drift silently** across its four language copies, and the game engine gains a capability seam for future secondary-user restrictions. | [12](12-game-engine-client.md); acceptance A20 |

## 3. Non-goals

- **Changing how login works.** The browser-side Checkpoint SSO flow is fine and is not
  touched. This is entirely about what the *server* does with the resulting token.
- **Introducing Laravel sessions or `Auth::login()`.** Bearer verification is the correct
  mechanism for a platform whose clients include a desktop app and a phone. Several apps
  contain dead scaffolding assuming session auth (see [01](01-platform-audit.md) §6);
  deleting it is in scope, reviving it is not.
- **Redoing Phases S0.5 / S0.5g.** That work is complete and correct. This plan builds on
  it.
- **Re-architecting `apps/auth`.** Three *targeted* IdP changes are proposed and nothing
  more: 2FA support for secondary users ([09](09-two-factor-authentication.md) §5),
  refusing to mint secondary tokens for primary-only apps
  ([10](10-primary-only-enforcement.md) §3.2), and registering `APPFACTORY_MOBILE`
  ([10](10-primary-only-enforcement.md) §5). Its architecture, session model, and JWT
  design are untouched. A configuration question is raised in
  [05](05-open-exposures.md) §5 and remains a question, not a redesign.
- **Per-secondary-user authorisation models.** Whether a secondary user should have
  narrower rights *within* a member's data is a product question per app. This plan makes
  the identity available and correct; it does not decide policy. The one exception is the
  platform rule that secondary users may not sign in to desktop or mobile at all, and reach
  only a launcher surface in members ([10](10-primary-only-enforcement.md),
  [11](11-secondary-user-dashboard.md)) — that is a stated requirement, not a per-app
  choice.
- **Rate limiting and abuse controls** beyond what auth already does on `/checkpoint/*`.

## 4. Glossary

| Term | Meaning |
|---|---|
| **Checkpoint** | The IdP at `apps/auth`, served from `auth15.appfactory.studio/checkpoint`. Owns identity; mints and validates JWTs. |
| **member_reference_num** | `brain_memberships.reference_num`. The tenant key every app scopes data by. Derived from a verified token, never from the request. |
| **primary / "normal" user** | A member's own Checkpoint account. Tokens carry **no** `app_name` claim — Checkpoint does not scope primary sessions to one app. |
| **secondary user** | A sub-account created under a primary. Tokens **are** minted per app context and carry `app_name` plus a per-app role. Resolve to the **super user's** membership. |
| **app_name** | An identifier in `CHECKPOINT_APPS` — `MEMBERS`, `COORDINATOR`, `NOTES_EDITOR`, `PDF_EDITOR`, `FLOW_EDITOR`, `GRAPH_EDITOR`, `GAME_EDITOR`, `MARKET`, `APPFACTORY_STUDIO`, plus `APPFACTORY_MOBILE` added by [10](10-primary-only-enforcement.md) §5. |
| **primary-only app** | A surface secondary users may never sign in to: desktop (`APPFACTORY_STUDIO`), mobile (`APPFACTORY_MOBILE`). See [10](10-primary-only-enforcement.md). |
| **owner surface** | The members routes and views only a primary user may reach — billing, domains, storage, VoIP, sub-user management, afs-connect. Distinct from the **launcher surface** ([11](11-secondary-user-dashboard.md) §5.2). |
| **`checkpoint.verify`** | members/market middleware. Verifies a token, sets `member_reference_num`. No app scoping. |
| **`checkpoint.member`** | The five-app middleware. Verifies a token against a **required app name**, sets four attributes including user type and role. |
| **capability URL** | A URL whose knowledge *is* the authorisation — e.g. dream_graph's `/api/assets/{uuid}`. Secure only while the URL stays secret. |

## 5. The six decisions

| # | Decision | Choice | Rationale |
|---|---|---|---|
| **D1** | Which pattern wins | **`checkpoint.member`** — the five-app one | It is a strict superset: app scoping, user type, per-app role, and a typed `CheckpointIdentity` result. `checkpoint.verify` has nothing the other lacks. Details in [02](02-two-patterns.md) §3. |
| **D2** | Verify locally or call auth | **Call auth.** Both existing patterns already do | Local JWT verification needs auth's HS256 signing secret in every app, which lets each app *mint* tokens — turning any single app compromise into a platform-wide identity compromise. It also cannot honour revocation. |
| **D3** | Attribute naming | **`checkpoint_*` prefix**, the five-app convention | Five apps already use it and two do not. Migrating two is cheaper than migrating five, and the prefix makes the provenance obvious at the call site. |
| **D4** | Fixing the duplication | **Shared Composer package** (`afs/checkpoint-auth`), adopted app by app | Seven copies is the finding. A package with a version constraint is the only mechanism that survives the next person copy-pasting. Sequencing and risk in [04](04-shared-validator.md). |
| **D5** | members' public routes | **Explicit allow-list**, not "whatever is left over" | The current failure mode is routes ungated *by omission*. Inverting the default is what stops it recurring. |
| **D6** | Breaking-change handling | **Log-and-ignore for one release**, then remove | Several endpoints today accept `?user_id=` / `?member_reference_num=`. Removing them outright breaks unknown callers; logging when a supplied value disagrees with the token surfaces them first. |

## 6. Acceptance criteria

| # | Criterion |
|---|---|
| A1 | Every route in all seven apps is either inside an auth-gated group or listed in that app's documented public allow-list. A test enumerates routes and fails on anything uncategorised. |
| A2 | No controller in any app reads `user_id`, `member_reference_num`, or a role from `query()`, `input()`, `session()`, or a request header. Enforced by a test. |
| A3 | An unauthenticated request to any non-public route returns 401 in all seven apps. |
| A4 | A valid token for member A, sent with member B's identifiers in the query string, operates on A's data or 404s — never B's. |
| A5 | A secondary token minted for app X is rejected by app Y with a distinguishable `wrong_app` reason, in all seven apps. |
| A6 | A valid token whose `member` is null is rejected (403), never treated as an empty tenant key. |
| A7 | Auth unreachable ⇒ requests fail closed (401/403) in all seven apps. |
| A8 | One validator implementation is consumed by all seven apps; `grep -r "class CheckpointTokenValidator"` across the repos returns one definition. |
| A9 | Negative verification results are cached for ≤ 5 s; positive results for ≤ 60 s. |
| A10 | `DELETE /api/v1/domains/{reference_num}` requires a token and verifies the zone belongs to the caller. |
| A11 | dream_graph asset URLs are either signed or explicitly accepted as capability URLs, with a dated decision recorded. |
| A12 | Every app's auth middleware documents what it trusts, rejects, and how it fails. |
| A13 | Neither the desktop nor the mobile client can initiate or complete a secondary sign-in; a secondary identity arriving by any route is refused with a distinct, actionable message and its credentials cleared. |
| A14 | No client writes an access or refresh token to plaintext storage. Legacy plaintext keys from older builds are removed by migration, not merely ignored. |
| A15 | A 2FA-enabled member can sign in to the desktop and mobile apps, including with a recovery code, and the `two_factor_token` never reaches persistent storage. |
| A16 | `APPFACTORY_MOBILE` is registered in auth, both TypeScript registries, and mobile config — and is absent from the secondary-assignable list. |
| A17 | A secondary user under an account with `require_2fa` is forced through enrolment before any session is issued, and the primary can require, inspect, and reset that 2FA but never see or set the secret. |
| A18 | A secondary token scoped to `APPFACTORY_STUDIO` or `APPFACTORY_MOBILE` cannot be minted; secondary users still sign in normally to the six apps they are entitled to. |
| A19 | A secondary user signing in to members reaches a minimal launcher shell showing only their granted apps; every owner API route rejects their token, and a primary user's dashboard is unchanged. |
| A20 | The app-name set is identical across PHP, TypeScript, Dart, and C++, asserted in CI; the Joystick Engine has a capability seam that allows everything and changes no behaviour. |

## 7. Repository map

| Repo | Role in this plan |
|---|---|
| `apps/members` | The bulk of the work — [03](03-members-remediation.md) |
| `apps/market` | Pattern migration only; route coverage already correct |
| `apps/coordinator` | Pattern is already correct; source of the validator to be packaged |
| `apps/notes`, `apps/flow`, `apps/pdf_editor` | Adopt the shared package; no behavioural change |
| `apps/dream_graph` | Adopt the package; plus the asset-serving decision ([05](05-open-exposures.md) §2) |
| `apps/auth` | Near read-only. One configuration question ([05](05-open-exposures.md) §5), and one small proposed change: refuse to mint Studio/mobile-scoped secondary tokens ([07](07-desktop-client-auth.md) §5.2) |
| `afsdesktop5` | Primary-only enforcement, plaintext storage, 2FA — [07](07-desktop-client-auth.md) |
| `afsmobile_flutter` | Same, plus the app-name decision — [08](08-mobile-client-auth.md) |
| `JoystickEngine_v2` | Registry sync, capability seam, loopback timeout — [12](12-game-engine-client.md). **Secondary users are permitted here**, unlike desktop and mobile |
| `afsserver` | This plan |

## 8. Relationship to other plans

- **[cloud-storage/01-server-authentication.md](../cloud-storage/01-server-authentication.md)**
  specified the members-only fix as a prerequisite for storage. This plan **supersedes and
  widens** it: same diagnosis, same mechanism, seven apps instead of one. That document
  should be reduced to a pointer here once this plan is accepted, so there is one
  description of the members work rather than two that can drift.
- **[afs-connect-desktop/account-and-cloud-sync](../afs-connect-desktop/account-and-cloud-sync/)**
  Phases S0.5 and S0.5g delivered the five-app fixes. **Complete; not revisited.** Its
  completion notes are the best available description of what those apps do today and are
  cited throughout [01](01-platform-audit.md).
- **[afs-connect/11-billing-and-allocation.md](../afs-connect/11-billing-and-allocation.md)**
  §11.3 owns the tier-index rule (`brain_memberships.membership`, clamp 0–4, never resolve
  by name). Any app surfacing the plan tier from a verified token follows it rather than
  re-deriving it.

# checkpoint-server-auth — Planning Index

Every AFS web application authenticates its users through the **Checkpoint IdP**
(`apps/auth`). The browser does the login; the server is supposed to verify the resulting
bearer token before trusting anything about who is calling.

**Five of seven apps do this correctly today.** This plan is about the two that do not,
the two incompatible implementations that grew up in parallel, and the handful of
documented-but-unfixed gaps left behind.

## The platform at a glance

Verified by reading the working trees on 2026-08-22 — not from documentation.

### Server apps

| App | Middleware | Validator | Route coverage | State |
|---|---|---|---|---|
| **members** | `checkpoint.verify` | `CheckpointTokenVerifier` | **32 of 99 routes ungated**, incl. storage, VoIP, domains, member, afs-connect | ❌ **Laggard** |
| **market** | `checkpoint.verify` + `checkpoint.identify` | `CheckpointTokenVerifier` (copy) | Full; public shell deliberate and reasoned | ✅ |
| **coordinator** | `checkpoint.member` | `CheckpointTokenValidator` (201 L) | Full; 2 deliberate carve-outs | ✅ |
| **notes** | `checkpoint.member` | `CheckpointTokenValidator` (184 L) | Full — 0 ungated | ✅ |
| **dream_graph** | `checkpoint.member` | `CheckpointTokenValidator` (184 L) | Full except asset serving | ⚠️ known gap |
| **pdf_editor** | `checkpoint.member` + `.optional` | `CheckpointTokenValidator` (184 L) | Full; 2 deliberate carve-outs | ✅ |
| **flow** | `checkpoint.member` | `CheckpointTokenValidator` (184 L) | Full — 0 ungated | ✅ |

### Client apps

| App | Login path | Primary-only? | Token at rest | State |
|---|---|---|---|---|
| **afsdesktop5** | `auth.login()` — primary endpoints | ❌ secondary paths present and unguarded | `safeStorage`-encrypted (primary) / **plaintext `localStorage`** (secondary path) | ⚠️ [07](07-desktop-client-auth.md) |
| **afsmobile_flutter** | `loginNormal()` — primary endpoints | ❌ `loginSecondary()` present and advertised in the SDK docstring | `flutter_secure_storage` (primary) / **plaintext SharedPreferences** (secondary path) | ⚠️ [08](08-mobile-client-auth.md) |
| **JoystickEngine_v2** (`checkpoint_cpp`) | Browser/loopback SSO — credentials never touch the engine | ✅ secondary **allowed here by design** | File permissions (`0600` / Windows ACL), not encrypted | ✅ best of the three — [12](12-game-engine-client.md) |

Neither client's *production* login can sign a secondary user in today — both call primary
endpoints, and secondary accounts authenticate through a different endpoint entirely. The
finding is that **nothing enforces it**, the capability is fully present in both SDKs, and
the rule is asserted only in a comment in a third repository.

> **Credit where it is due.** The five `checkpoint.member` apps were fixed on 2026-08-01
> under [account-and-cloud-sync](../afs-connect-desktop/account-and-cloud-sync/) Phases
> S0.5 and S0.5g. That work was thorough — it caught a cache-key bug, deleted dead
> `GraphProjectPolicy` scaffolding, and fixed a members↔coordinator integration it broke
> in passing. **This plan does not redo any of it.** It closes what was left.

## Read in this order

| # | Document | Contents |
|---|---|---|
| — | [README.md](README.md) | This index |
| 00 | [00-overview.md](00-overview.md) | Problem, goals, non-goals, glossary, the six decisions |
| 01 | [01-platform-audit.md](01-platform-audit.md) | **The evidence.** Per-app verified state, every ungated route, every finding |
| 02 | [02-two-patterns.md](02-two-patterns.md) | `checkpoint.verify` vs `checkpoint.member` — how they differ, which is right, how to converge |
| 03 | [03-members-remediation.md](03-members-remediation.md) | The laggard, route by route — the bulk of the work |
| 04 | [04-shared-validator.md](04-shared-validator.md) | Seven copies of one class across seven repos, and what to do about it |
| 05 | [05-open-exposures.md](05-open-exposures.md) | dream_graph assets, negative caching, missing app scoping, VoIP relay |
| 06 | [06-rollout-and-testing.md](06-rollout-and-testing.md) | Phases, breaking-change management, the cross-app test matrix |
| 07 | [07-desktop-client-auth.md](07-desktop-client-auth.md) | **afsdesktop5** — primary-only enforcement, plaintext token storage, 2FA lockout |
| 08 | [08-mobile-client-auth.md](08-mobile-client-auth.md) | **afsmobile_flutter** — same, plus the missing app-name decision |
| 09 | [09-two-factor-authentication.md](09-two-factor-authentication.md) | **2FA for every user type**, including secondary users, whose 2FA the primary user controls |
| 10 | [10-primary-only-enforcement.md](10-primary-only-enforcement.md) | **Secondary users barred from desktop and mobile** — enforced at mint time, not just in a UI list. *Its members section is amended by step 11* |
| 11 | [11-secondary-user-dashboard.md](11-secondary-user-dashboard.md) | **A separate minimal members dashboard for secondary users** — an app launcher, with the owner surface still primary-only |
| 12 | [12-game-engine-client.md](12-game-engine-client.md) | **Joystick Engine (`checkpoint_cpp`)** — registry drift, the secondary capability seam, and why 2FA needs no native UI here |

## Completion documents

Each numbered document gets a completion document in [`complete/`](complete/) when its
work lands — source basename plus `.complete.md`. Include status, summary, files changed,
verification steps, and follow-up work.

## Code documentation standard

Document all new and changed code thoroughly using block comments (PHPDoc for PHP, JSDoc
for TypeScript). Auth code specifically must document **what it trusts, what it rejects,
and which way it fails** — a security check whose reasoning is not written down gets
"simplified" away in a later refactor. The five `checkpoint.member` middlewares are the
standard to match: each explains the bug it closed and why secondary tokens are handled
the way they are.

## The five findings that justify this plan

1. **members has 32 ungated routes**, including four that were already known
   (`/api/storage/*`, from the cloud-storage plan) and eight that were not — the VoIP
   number-provisioning surface and the whole domains group, where
   `DELETE /api/v1/domains/{reference_num}` takes a DNS zone offline with **no
   authentication and no ownership check whatsoever**.

2. **Two incompatible auth patterns exist**, and the weaker one guards the app with the
   most sensitive surface. `checkpoint.member` (5 apps) validates the token *and* checks
   it was minted for that app, and exposes user type and per-app role.
   `checkpoint.verify` (members, market) checks neither — so a secondary user granted
   access only to Notes presents a token that members accepts.

3. **The validator is copy-pasted seven times across seven repositories.** Right now the
   copies are functionally identical and differ only in comments — which is the best
   possible moment to fix it, because there is no drift to reconcile yet. There will be.

4. **"Studio desktop must never obtain a secondary session" is enforced nowhere.** The rule
   is stated as settled in coordinator's `MemberCheckpointAuthMiddleware` docblock, but
   both the desktop and mobile clients ship complete, unguarded secondary-login
   implementations — and in both, the plaintext token storage exists *only* to serve that
   path. Removing the capability removes the plaintext storage with it.

5. **A member with two-factor authentication enabled cannot use the desktop or mobile app.**
   Both throw on `requires_2fa` because neither has a code-entry screen, while Checkpoint
   supports the flow and the desktop's own test harness implements it. The practical advice
   today is "turn off 2FA" — a security feature penalising the users who adopted it.

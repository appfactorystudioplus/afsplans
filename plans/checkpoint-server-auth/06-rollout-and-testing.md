# 06 — Rollout and testing

## Completion document

When this plan step is completed, write `complete/06-rollout-and-testing.complete.md` with
status, summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly, including test files — each test names the
finding (F1–F15) or acceptance criterion it covers.

---

## 1. Phase 0 — answer these first

**Status as of 2026-08-23** — updated in place rather than left to drift from what
actually happened; see `complete/*.complete.md` for the full account behind each row.

| # | Question | Blocks | If unanswered | Status |
|---|---|---|---|---|
| ~~Q1~~ | ~~Is production's `JWT_SECRET` the dev value in `apps/auth/.env`?~~ ([05](05-open-exposures.md) §5) | Everything | Stop and answer | **ANSWERED: yes, confirmed live in `auth/.env`. Rotated.** User must still apply to whatever is actually deployed — [05](complete/05-open-exposures.complete.md) |
| ~~Q2~~ | ~~Is `CHECKPOINT_JWT_SECRET` live anywhere, or dead config?~~ | Whether it can be deleted | Assume dead; verify before removing | **ANSWERED: dead. Deleted.** |
| **Q3** | Which callers pass `?user_id=` / `?member_reference_num=` to members? | Phase 2b's breaking change | Log-and-ignore for one release ([03](03-members-remediation.md) §9) | **Still open** — log-and-ignore was not implemented; the old params are silently ignored with no logging, so this is unanswered by data |
| **Q4** | Does every frontend caller of each members route group send a bearer? | Whether gating that group 401s the page | Verify per group before gating ([03](03-members-remediation.md) §7) | **Answered for every group step 03 actually gated** (domains, member, afs-connect, VoIP) — one required a real frontend fix first (`afsConnectSettings.ts` was sending no bearer at all). Not re-checked for anything gated after this plan's work, since nothing else has been gated since |
| ~~Q5~~ | ~~dream_graph assets: sign, cookie, or accept?~~ ([05](05-open-exposures.md) §2) | F6 | Recommendation is B + C's headers | **ANSWERED and implemented 2026-08-23: option B + C.** [05](complete/05-open-exposures.complete.md) |
| **Q6** | Should VoIP numbers be recorded per member so `voip_numbers_count` is enforceable? | F8's second half | Gate the routes now; decide enforcement separately | Routes gated ([03](03-members-remediation.md) §5). Enforcement question **still open**, deliberately |
| ~~Q7~~ | ~~Package distribution: private registry or VCS + tag?~~ | [04](04-shared-validator.md) §6 | VCS + tag | **ANSWERED, and taken further than either option**: a real private GitHub repo (`appfactorystudioplus/checkpoint-auth`), Composer-installed via VCS entries, not just tag references with no registry at all |
| ~~Q8~~ | ~~Will auth refuse to mint secondary tokens for primary-only apps?~~ | — | — | **ANSWERED: yes.** [10](10-primary-only-enforcement.md) §3.2 — not yet **implemented**; step 10 hasn't started |
| ~~Q9~~ | ~~Mobile app name?~~ | — | — | **ANSWERED: register `APPFACTORY_MOBILE`.** [10](10-primary-only-enforcement.md) §5 — not yet **implemented**; step 10 hasn't started |
| **Q10** | Does any existing secondary user hold a `MEMBERS` or Studio grant? | Whether 10b can enforce immediately or must observe first | Observe first — [10](10-primary-only-enforcement.md) §8 | **Still open** — blocked on step 10, not started |
| **Q11** | Should `require_2fa` default on for new accounts, or off? ([09](09-two-factor-authentication.md) §4.2) | Product/onboarding decision | Off; primary opts in | **Still open** — blocked on step 09, not started (default already stated in the plan text: off) |
| ~~Q12~~ | ~~2FA responsibility split~~ | — | — | **ANSWERED 2026-08-22:** primary owns policy + reset; secondary owns enrolment + recovery-code regeneration ([11](11-secondary-user-dashboard.md) §6) — not yet **implemented**; steps 09/11 haven't started |
| **Q13–Q16** | Remaining secondary-dashboard questions | [11](11-secondary-user-dashboard.md) §9 | Defaults stated there | **Still open** — blocked on step 11, not started (defaults already stated in the plan text) |

Q1 is not a formality. If the answer is "yes, production shares it", that becomes the only
thing on this plan's critical path. **It was yes.**

Full punch list of every open item across every step, not just this table:
[`plans/TODOS.md`](../TODOS.md).

## 2. Phases

**Status column added 2026-08-23** — reflects what's actually landed, not the plan.

| Phase | Content | Apps | Ships independently? | Status |
|---|---|---|---|---|
| **1 — Stop the bleeding** | Domains: middleware, identity from token, ownership checks on `destroy`/`update`. Frontend drops the query param | members | ✅ Yes, and should | **Done** — [03](complete/03-members-remediation.complete.md) |
| **2 — members coverage** | 2a: `/api/v1/member`, afs-connect, progress. 2b: VoIP. 2c: route-file restructure + cleanups (F9, F10) | members | ✅ | **Done**, with one deliberate exception (`/api/v1/progress/*` left ungated — real non-browser caller found) — [03](complete/03-members-remediation.complete.md) |
| **3 — The package** | Build `afs/checkpoint-auth` from coordinator's validator + members' negative-cache TTL. Behaviour suite. No app consumes it yet | — | ✅ | **Done, exceeded** — real private GitHub package, not just built-and-unconsumed — [04](complete/04-shared-validator.complete.md) |
| **4 — Low-risk adoption** | flow → notes → pdf_editor → dream_graph. Attribute names unchanged, so controllers are untouched | 4 apps | ✅ per app | **Done**, all 4 — [04](complete/04-shared-validator.complete.md) |
| **5 — coordinator** | Adopt package; leave the engine and afs-connect schemes alone | coordinator | ✅ | **Done** — [04](complete/04-shared-validator.complete.md) |
| **6 — market** | Adopt package; rename attributes across portal API controllers; `app_name: MARKET` in **observe** mode | market | ✅ | **Not started** |
| **7 — members onto the package** | Adopt package; rename attributes; `app_name: MEMBERS` in observe mode | members | ✅ | **Not started** — members' own route/IDOR work (phases 1–2) is done, but it's still on its local validator copy, not the package |
| **8 — Enforce app scoping** | Flip observe → enforce in members and market, once logs are clean | members, market | ✅ | **Not started** — blocked on 6/7 |
| **9 — Desktop client** | Remove secondary paths + plaintext storage (one change), `assertPrimary()`, 2FA modal | afsdesktop5 | ✅ | **Not started** — plan step [07](07-desktop-client-auth.md) |
| **10 — Mobile client** | Same, plus secure-storage migration and the app-name decision | afsmobile_flutter | ✅ | **Not started** — plan step [08](08-mobile-client-auth.md) |
| **11 — Primary-only enforcement** | [10](10-primary-only-enforcement.md): register `APPFACTORY_MOBILE`; `PRIMARY_ONLY_APPS` mint refusal in auth; members client assertion + capability split; `primary_only` on members API | auth, members, + clients | ✅ per sub-step (10a–10e) | **Not started** |
| **12 — 2FA everywhere** | [09](09-two-factor-authentication.md): `(user_type, user_id)` schema; secondary 2FA service + endpoints; hosted-login enrolment UI; members admin controls; members primary self-service; desktop/mobile code entry | auth, members, + clients | ✅ per surface | **Not started** |
| **13 — Secondary dashboard** | [11](11-secondary-user-dashboard.md): shell branch, launcher views, app-URL registry, `/api/v1/me` group, owner/launcher route split | members | ✅ | **Not started** |
| **14 — Game engine** | [12](12-game-engine-client.md): `AppNames.h` sync + CI parity check, `SecondaryUsers::Login` 2FA guard, capability seam, loopback timeout | JoystickEngine_v2, afsserver | ✅ — but **§4.2 must ship with or before phase 12** | **Not started.** Two facts confirmed in passing while fixing F6: the sync-script claim in `AppNames.h` really is false (no C++ target exists), and the engine genuinely never calls `/api/assets/*` — see [05](complete/05-open-exposures.complete.md) |
| **15 — Open exposures** | F6 decision implemented; F8 enforcement decision; F11 follow-up | dream_graph, members, auth | ✅ | **F6 and F11 done; F8 enforcement still an open decision** — [05](complete/05-open-exposures.complete.md) |

Full detail on every open item, including everything not yet started: [`plans/TODOS.md`](../TODOS.md).

### 2.1 Ordering rationale

**Phase 1 is one endpoint and ships alone.** `DELETE /api/v1/domains/{ref}` is the only
destructive unauthenticated endpoint on the platform — no token, no ownership check, takes
a DNS zone offline. It should not wait for a package, a migration, or a planning cycle.

**members' coverage (2) precedes the package (3)** because coverage is the actual security
finding and the package is an engineering-quality improvement. Doing them in the other
order would delay the fix behind a refactor.

**Adoption runs smallest-first** (flow, 11 routes) so the package is proven on a low-stakes
app before it reaches members. For apps 1–5 the attribute names do not change, so adoption
is deleting three files and adding a dependency.

**App scoping enforcement (8) is gated on observation.** It is the only *server* change
here that starts rejecting requests that succeed today.

**The client phases (9, 10) are independent of everything before them.** They touch
different repositories and different release trains, and nothing in phases 1–8 blocks
them — so they can run in parallel with the server work rather than queueing behind it.
**Phase 11 (primary-only) precedes Phase 12 (2FA)** for one reason: 11 decides *where*
secondary users can sign in, and 12 has to build a 2FA UI for every such place. Doing 12
first means building secondary 2FA screens for members, desktop, and mobile — three
surfaces that Phase 11 then bars secondary users from entirely.

## 3. The breaking changes, and how each is handled

| Change | Who breaks | Handling |
|---|---|---|
| Identity from token, not `?user_id=` | Anything calling members' API with that parameter | Log-and-ignore one release ([03](03-members-remediation.md) §9). The parameter is inert from day one; the log enumerates callers |
| Gating a previously open group | Any caller not sending a bearer | Verify per group **before** gating (Q4). Switch plain `fetch()` to `fetchWithSessionRefresh` first |
| Attribute rename in members/market | Their own controllers | Mechanical, compile-time-ish; the trait accessor means most controllers never touch the attribute |
| App-scoping enforcement | Secondary tokens minted for another app | Observe mode, then enforce (Phase 8) |
| Package version bumps | Consuming apps | SemVer, with "any change to what is rejected is MAJOR" ([04](04-shared-validator.md) §6.1) |
| Secondary users barred from members | Any secondary user who currently signs in there | Layer 1 says none can exist. **Observe first anyway** (Q10) — 10b logs before it rejects |
| `require_2fa` turned on by a primary | Their secondary users, at next login | Not a regression — it is the feature. But the admin UI must show `Required — not enrolled` so the primary can see who is blocked ([09](09-two-factor-authentication.md) §6.2) |

### 3.1 Server-to-server callers need a different answer

Some routes are called by other AFS apps, not browsers — coordinator calls members'
afs-connect endpoints, members calls coordinator's. Those hops have **no per-member bearer
to present**.

S0.5 already solved this once, correctly: it added a **signed internal endpoint** behind
`afs_connect.service` HMAC rather than leaving the route open or breaking the integration.
Apply the same pattern to any route in this plan that turns out to have a service caller.
Do not grant an exemption from the member gate.

## 4. Testing

> **2026-08-23 — the user is running this section themselves.** Nothing below was
> executed as part of finishing this step; it's recorded here exactly as specified so
> the work is trackable, and itemized in [`plans/TODOS.md`](../TODOS.md) under Step 06
> for pickup. The exceptions: individual tests written and actually run alongside the
> code they verify in steps 03/04/05 (e.g. the F6 asset-access-cookie suite, the shared
> package's own behaviour suite) — those already exist and pass; see each step's
> completion doc. What follows is the *cross-cutting* suite this section specifies,
> which spans apps/repos this session didn't build test infrastructure for.

### 4.1 The cross-app suite

Every app runs the same seven tests. They encode the four `apps/auth` properties
([01](01-platform-audit.md) §1.1) so no app can regress them independently.

| Test | Asserts | Finding |
|---|---|---|
| No bearer → every non-public route | 401 | F1 |
| Malformed / expired / wrong-signature token | 401 | — |
| auth returns `{valid:false}` with **HTTP 200** | Rejected | P1 |
| auth returns `{valid:true, member:null}` | Rejected 403 | P2 |
| auth times out | Rejected, fails closed | A7 |
| **Primary token, any required app** | **Accepted** | P4 |
| Secondary token for a different app | Rejected `wrong_app` | F3 |

### 4.2 Static checks (the anti-pattern guards)

These are what make the fixes durable. Without them, the next controller reintroduces the
bug and review is the only defence.

| Check | Fails when |
|---|---|
| Route enumeration | Any route is neither auth-gated nor in the app's documented public list ([05](05-open-exposures.md) §7) |
| Identity-source grep | A controller reads `user_id`, `member_reference_num`, or a role from `query()`, `input()`, `session()`, or a header |
| `env()` outside config | Any `env(` call in `app/` |
| Validator uniqueness | More than one `class CheckpointTokenValidator` definition across the repos |
| Legacy header grep | `X-User-Id` / `X-App-Role` / `X-Coordinator-Role` / `X-Checkpoint-User-Id` appears in live code (comments allowed) |

### 4.3 members-specific

| Test | Asserts |
|---|---|
| `DELETE /api/v1/domains/{ref}` without a token | 401 |
| `DELETE` with a valid token, another member's zone | **404** — not 403, which would confirm existence |
| `PATCH /api/v1/domains/{ref}` same two | As above |
| `GET /api/v1/member?user_id=<other>` | Returns the **caller's** record |
| `PUT /api/v1/afs-connect/settings` with another member's id | Writes the caller's own settings |
| `GET /api/v1/afs-connect/sources` unauthenticated | Still 200 — the deliberate carve-out |
| `/api/voip/*` without a token | 401 |
| Domains page end to end after gating | Loads, lists, creates, deletes |
| `config:cache` then a domains request | Correct Brain URL |

### 4.3a Client apps

Full matrices in [07](07-desktop-client-auth.md) §8 and [08](08-mobile-client-auth.md) §8.
The four that matter most:

| Test | Asserts |
|---|---|
| Secondary callback / identity presented to either client | Refused with a distinct message; credentials cleared (A13) |
| Pre-seeded secondary session from an older build | Cleared on launch, not silently retried |
| Fresh install, inspect on-device storage | No token in `localStorage` / `SharedPreferences` (A14) |
| 2FA-enabled member, both clients | Signs in, including with a recovery code (A15) |

### 4.3b Primary-only and 2FA

Full matrices in [10](10-primary-only-enforcement.md) §7 and
[09](09-two-factor-authentication.md) §9. The five that matter most:

| Test | Asserts |
|---|---|
| Secondary login naming `MEMBERS` / `APPFACTORY_STUDIO` / `APPFACTORY_MOBILE` | Rejected at mint time (A18) |
| **Secondary user signs in to each of the six permitted apps** | **All still work** — the regression that matters |
| Secondary under `require_2fa`, not yet enrolled | Forced enrolment; no session issued first (A17) |
| Primary attempts to read a secondary's TOTP secret | No endpoint exposes it |
| Primary id N and secondary id N both enrolled | Independent secrets (09 T14) |

### 4.4 Cross-app integration

Regressions here are the likeliest way this plan breaks something that works today.

| Test | Asserts |
|---|---|
| members → coordinator `GET /api/afs-connect/sources` | Still works unauthenticated |
| members → coordinator `GET /internal/afs-connect/v1/status` | Signed HMAC path still works |
| coordinator → members afs-connect endpoints | Still works after members gates them (may need §3.1) |
| Desktop app → each app's API | Bearer flow unaffected by the package swap |
| Mobile app → members API | Same |

### 4.5 Manual verification per app after package adoption

Because the package swap is invisible in the diff of any controller, each adopting app
needs one end-to-end pass: sign in, load the main view, perform one write, sign out, and
confirm a request with a stale token 401s.

## 5. Rollback

| Phase | Rollback |
|---|---|
| 1 | Revert one controller + one route group. Low risk; but reverting restores an unauthenticated destructive endpoint — fix forward instead |
| 2 | Per sub-phase. The log-and-ignore design means no caller depends on the new behaviour yet |
| 3 | Package unreleased/unconsumed — nothing to roll back |
| 4–7 | Pin the previous package version; the local classes are deleted, so keep them in git history and be prepared to restore one file if needed |
| 8 | **Config flag.** Observe/enforce is a setting, not a deploy — this is why it is a flag |
| 9, 10 | Client releases. Ship a build with the previous auth module. Note that rolling back **restores** the unguarded secondary paths and the plaintext storage — fix forward |
| 11 | Sub-steps roll back independently. 10b ships in observe mode first, so enforcement is a config flip, not a deploy. Reverting restores the ability to mint primary-only-scoped secondary tokens |
| 12 | The schema change (`user_type` on the 2FA tables) is additive and safe to leave. Reverting the policy layer means `require_2fa` stops being enforced — users stay enrolled, so re-enabling needs no re-enrolment |
| 13 | Frontend + route split. Reverting returns secondary users to a dead end; the owner group stays protected either way |
| 14 | Additive. The capability seam is a no-op by construction, so reverting it changes nothing either |
| 15 | Per decision |

## 6. Definition of done

**Status as of 2026-08-23** — not all done; recorded here so it's a real checklist, not
aspirational text.

- All acceptance criteria in [00-overview.md](00-overview.md) §6 pass (A1–A16). —
  **Partial.** A1 (route coverage) true for members/coordinator/notes/dream_graph/
  pdf_editor/flow, not market/members-on-package. A5 (app-scoping rejection) not true
  yet — members/market aren't on the shared package (F3, open). A13–A20 (client/2FA/
  primary-only/secondary-dashboard/game-engine criteria) not applicable — those steps
  haven't started.
- [03](03-members-remediation.md) §10 M1–M12 pass. — **Mostly**, per that step's own
  completion doc; M1/M9/M12 not test-verified (manual/reasoned only).
- [04](04-shared-validator.md) §9 S1–S8 pass. — **True for the 5 migrated apps**, not
  market/members (S1/S2/S4/S6 depend on all seven).
- [07](07-desktop-client-auth.md) §7 DA1–DA11 pass. — **N/A, step not started.**
- [08](08-mobile-client-auth.md) §7 MA1–MA12 pass. — **N/A, step not started.**
- [09](09-two-factor-authentication.md) §8 T1–T14 pass. — **N/A, step not started.**
- [10](10-primary-only-enforcement.md) §6 P1–P11 pass. — **N/A, step not started.**
- [11](11-secondary-user-dashboard.md) §8 SD1–SD12 pass. — **N/A, step not started.**
- [12](12-game-engine-client.md) §9 GE1–GE13 pass. — **N/A, step not started.**
- Every row in the public-route register ([05](05-open-exposures.md) §7) has a stated
  reason, and no route outside it is public. — **True and current** as of the F6 fix and
  the §7 additions made in step 05.
- Q1 answered and acted on. — **True.**
- Q5, Q6, Q10 and Q11 have recorded decisions with dates — including where the decision
  is "accept" or "leave as is". (Q8 and Q9 are answered.) — **Q5 done and implemented.
  Q6, Q10, Q11 answered by the plan text but not yet acted on** (Q10/Q11 block on
  unstarted steps 10/09).
- The static checks in §4.2 run in CI for all seven apps. — **Not started.** No CI
  wiring exists for anything in this plan yet, including the shared package's own test
  suite (step 04's own follow-up).

This plan is **not done**. Steps 06–12's remaining scope (market/members package
migration, both native clients, 2FA, primary-only enforcement, the secondary dashboard,
and the game engine) is the majority of the acceptance criteria above and none of it has
started. Full punch list: [`plans/TODOS.md`](../TODOS.md).

## 7. What this plan deliberately leaves open

Stated so they are not mistaken for oversights:

- **Revocation within a token's lifetime** ([05](05-open-exposures.md) §6). Bounded at
  ~1 h; closing it is an `apps/auth` change.
- **Per-secondary-user authorisation** *within* a member's data. This plan makes
  `checkpoint_user_type` and `checkpoint_app_role` available on every request; what each
  app does with them is a product decision per app.
- **Client-side auth consolidation.** The TypeScript `resources/js/checkpoint/` clients and
  the Dart `checkpoint_client` are separate implementations of the client half. Same class
  of duplication, different problem, not in scope.
- **`apps/auth` architecture.** Untouched, apart from the configuration question in Q1/Q2.

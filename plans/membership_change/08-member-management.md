# 08 — Member management: the Brain operator console

> Renumbered 2026-09-22 (was `10-member-management.md`) — section numbers below were
> renumbered from §10.x to §8.x to match. See `README.md`.

> **Done — see [complete/08-member-management.complete.md](complete/08-member-management.complete.md).**
> MM1's remainder, MM2/MM3/MM5/MM7, and MM8/MM9 are fixed, tested, and wired into the UI.
> MM4 and everything downstream of the retention engine (§8.5 P2/P3) are deliberately not
> built — the engine has no caller anywhere in this estate yet, and this document's own D6
> rules out a second, console-only implementation of it. §8.7 OQ-A/OQ-C/OQ-D/OQ-F remain
> genuinely open — see the completion doc's own "What's left, explicitly."
>
> **§8.3.3's Allocations-tab leftover closed 2026-09-23 — see
> [complete/08-member-management-allocations.complete.md](complete/08-member-management-allocations.complete.md).**
> `members_brain`'s own completion docs claimed both requirements done; checking the current
> code (not the docs) found the override endpoints had no real authorization beyond "not
> your own record" (part of **OQ-A**, fixed for these 5 routes specifically — the other 34
> Brain-admin routes are still open), a plain per-member override 500'd on every attempt, and
> overrides were never actually visible in the console's normal list→detail flow. All three
> fixed and tested — this route group's first tests, ever.

**Scope:** the operator-facing side of a member — changing their plan, seeing and managing what
they hold, and the actions support needs when a member writes in. Client code is
`brain_membership.ts`; the API is `/portal/api/v1/memberships/*`.

> ### ⚠ This overlaps `members_brain/`, deliberately and only at one tab
>
> [../members_brain/](../members_brain/README.md) is nine documents about **one screen** —
> `brain:memberships:allocations`, turning its raw JSON textarea into a generated allocation
> console with per-member ceiling overrides and an audit trail. That work is not repeated
> here.
>
> **The split:** `members_brain` owns *editing a member's allocation record*. This document
> owns *everything else an operator does to a member*, and the shell those tabs live in. Where
> they touch — the Allocations tab — this document defers, and §8.3 marks it so.
>
> Two things `members_brain` establishes are load-bearing here and are not re-argued:
> its **P0** (the Brain admin API is unauthenticated; the two Parse app ids disagree), and
> **BR25** (the flat `ac_*` counter is the authority and every hand-editing surface writes the
> nested projection instead).

---

> ## 🔴 MM1 — read before anything else
>
> **`PUT /portal/api/v1/memberships/{ref}` is unauthenticated and accepts `membership` and
> `level`.**
>
> ```php
> $allowed = $request->only([
>     'account_status', 'account_close_date', 'membership', 'level',
>     'customer_id', 'firstname', 'lastname',
> ]);
> $membership->fill($allowed);
> $membership->save();
> ```
>
> The route sits in the `portal.cors` group, and that middleware is
> `return $next($request);` ([07](07-signup-flow.md) **S2**). No authentication, no
> authorisation, no check that the caller is an operator, no audit row.
>
> Two of those columns are not ordinary fields:
>
> - **`membership`** is the tier the entire entitlement system reads — `alloc_v2`,
>   `QuotaService::planFor()`, afs-connect's five layers, and this folder's retention engine.
>   Anyone can set any member to 4.
> - **`level`** is the platform superuser marker. `main.ts:80` is `if (level === 99) return
>   true;` and `main.ts:596` gates the Brain sidebar on it;
>   [../allocation.md](../allocation.md) §6 documents it as bypassing *"every restriction and
>   every allocation."* **Anyone can make any member — including a member they just created —
>   a platform superuser.**
>
> This is the same class as [07](07-signup-flow.md) **S1** and **S19**: an unauthenticated
> endpoint writing a field that decides access. It is one route in the group [07](07-signup-flow.md)
> **S2** already says must be closed, and it is the strongest single argument for closing it.

---

## 8.1 What exists today

`brain_membership.ts` already implements a member detail shell with tabs. This is a real
foundation, not a blank page — the plan below extends it rather than replacing it.

| View | Function | State |
|---|---|---|
| List | `loadMembershipList()` | Loads every membership, client-side render |
| Create | `initMembershipCreateView` / `submitMembershipForm` | Operator-side member creation |
| **Detail / Edit** | `initMembershipDetailView` | Name, **tier**, **account status** — the one that writes MM1's fields |
| Subscription | `initMembershipSubscriptionView` | Discount, billing amount, billing date |
| Payments | `initMembershipPaymentsView` | Charges, payment methods, refunds |
| **Allocations** | `initMembershipAllocationsView` | Raw JSON editor — **`members_brain`'s subject** |
| Domains | `initMembershipDomainsView` | Per-member domain list |
| afs-connect | `initMembershipAfsConnectView` | Per-source toggles |
| Checkpoint errors | `initMembershipCheckpointErrorsView` | Failed registrations from signup ([07](07-signup-flow.md) **S13**) |

Tab navigation is a `CustomEvent('navigate')` carrying the whole `member` object as a param
(`setupMemberDetailTabs`), so every tab works from a snapshot taken when the list was loaded.

## 8.2 Findings

| # | Finding | Consequence |
|---|---|---|
| **MM1** | **`PUT /{ref}` is unauthenticated and writes `membership` and `level`** | Unauthenticated tier escalation **and** platform-superuser escalation. See above |
| **MM2** | **Changing the tier here bypasses everything signup enforces.** `store()` derives the tier from the subscription link ([07](07-signup-flow.md) §7.1); `update()` writes whatever it is given | The two paths that set the same column disagree about whether it may be asserted. An operator raising a tier creates an entitlement with no billing behind it |
| **MM3** | **Nothing records who changed a tier, when, or why** | A member's plan can change with no attributable cause. This is the column money is supposed to be the only input to ([../handoff.md](../handoff.md) §7 flow 1) |
| **MM4** | **The tier change does nothing to what the member holds** | The premise of this whole folder. A downgrade here moves an integer; 28 of 29 resources are untouched, so the member keeps everything while paying less |
| **MM5** | **`membership` and `account_status` are edited from one form as if they were the same kind of field** | [../handoff.md](../handoff.md) §9 D5: billing writes `membership`, account lifecycle writes `account_status`, features read both. One save button writing both erases that split |
| **MM6** | **No preview.** An operator cannot see what a downgrade will do before doing it | [03](03-api-contracts.md) §3.4's `preview(toPlan)` exists precisely for this, and has two consumers — this screen and `dunning_final`'s "what you lose" email |
| **MM7** | **The whole member object is passed between tabs as a `CustomEvent` param** | Every tab operates on a snapshot from list-load. Two operators, or one operator with a stale tab, silently overwrite each other — the same shape as `members_brain`'s **BR3** blind-overwrite finding, at the member level rather than the registry level |
| **MM8** | **`loadMembershipList()` fetches every membership and renders client-side** | No pagination, no server-side search. Fine at hundreds, not at thousands, and it is the screen an operator opens first |
| **MM9** | **The list is the only way to reach a member** | No lookup by email, reference, domain or subscription id — which is how a support request actually arrives |
| **MM10** | **`level` is editable but undocumented.** It has one meaningful value (99) and no ladder | [../allocation.md](../allocation.md) §6.1 calls it *"a sentinel, not a rank"*, and warns `>= 99` is wrong. A free-text-ish integer on an admin form is how a `98` gets typed |
| **MM11** | **`account_close_date` is writable with no modelled meaning.** `account_status = 3` ("marked for deletion") is unimplemented estate-wide | [../handoff.md](../handoff.md) **OQ-E**. The form offers a lifecycle the system does not have |

## 8.3 What the console becomes

The shell and its tabs stay. What changes is what an operator can *do* from them, and what the
system records when they do.

### 8.3.1 Tier changes become an operation, not a field edit

Today: a `<select>` and a Save button that writes an integer.

```
  ┌─ Change plan ─────────────────────────────────────────────┐
  │  Business (2)  →  [ Pro (1) ▾ ]                           │
  │                                                            │
  │  PREVIEW — what changes                    preview(toPlan) │
  │    domains          10 held, 3 allowed  →  7 retire        │
  │      └ acme.com     … 4 mailboxes, 1 store cascade         │
  │    forms            12 held, 10 allowed →  2 retire        │
  │    storage          8.1 GB / 12.5 GB    →  within limit    │
  │                                                            │
  │  Grace: 30 days · first purge 2026-09-26                   │
  │  Reason  [ required ▾ ] [ notes……………………… ]                │
  │                                    [ Cancel ] [ Apply ]    │
  └────────────────────────────────────────────────────────────┘
```

Three things that make it an operation:

- **`preview(toPlan)` before the write** (**MM6**). [03](03-api-contracts.md) §3.4 already
  specifies it, and this is the second consumer that justifies building it once.
- **A reason is mandatory** and lands in the audit log (**MM3**).
- **The write is the same server-side path a billing-driven change takes**, so the retention
  engine runs identically whether the trigger was a failed card or an operator (**MM4**).

### 8.3.2 What the operator can see, per member

One page answering the questions support is actually asked:

| Panel | Answers |
|---|---|
| **Plan & status** | What are they on, since when, changed by whom, and is the tier backed by a subscription (`tier_source` — [07](07-signup-flow.md) **S20**) |
| **Holdings vs allowance** | Per resource: held, allowed, retired, purge date. Read from the same registry-driven computation the engine uses, never a second implementation |
| **Retirement state** | What is in grace, what the deadline is, what a restore would return ([02](02-architecture.md) §2.3) |
| **Identity** | Checkpoint user, `parse_alloc`, secondary users, and whether any of the three is missing — the three ways [07](07-signup-flow.md) leaves a member half-created |
| **Billing** | Subscription, next charge, dunning state, recent charges — the existing Subscription and Payments tabs, unchanged |
| **Timeline** | Every operator action and every automated tier write, one list |

### 8.3.3 Allocations tab — deferred

Owned by [../members_brain/](../members_brain/README.md). This document requires two things
from it and adds nothing else:

1. The generated form replaces the JSON textarea (`members_brain` **04**).
2. Per-member **ceiling overrides** (`members_brain` **05**) are visible here, because an
   override is the difference between "over their limit" and "over their limit on purpose."

> **Overrides are invisible to the retention engine as currently designed** — [02](02-architecture.md)
> computes bands from `tiers[]` alone, and `members_brain/05` puts overrides in a Brain MySQL
> table. A member given +5 domains by support is retired back down on the next sweep. That is
> a real conflict between two plans in this repository and it belongs to whoever builds the
> engine, but this console is where it would be noticed.

### 8.3.4 Operator actions

Beyond the tier: suspend/restore (`account_status`, and **only** `account_status` — **MM5**),
resend a welcome or verification mail, re-run a failed Checkpoint registration or Parse
provisioning ([07](07-signup-flow.md) **S13**, **S32**, **S37**), grant a ceiling override,
extend a grace window, and force a reconcile of a member's counters.

Every one of them writes a timeline entry. None of them is a raw column edit.

## 8.4 Decisions

| # | Decision | Why |
|---|---|---|
| **D1** | **The admin API is authenticated and operator-scoped before anything else is built** | **MM1**. A console on an open API is a nicer interface to a hole. This is [07](07-signup-flow.md) **S2** and `members_brain`'s P0 — one fix, three plans waiting on it |
| **D2** | **`level` is removed from the editable set** | It is a superuser sentinel, not a member attribute. If staff accounts need marking, that is its own screen with its own authorisation — not a number on a member form (**MM10**) |
| **D3** | **A tier change is a server-side operation with a reason, not a column write** | It has to run the same path a billing change runs, or the retention engine sees two different kinds of downgrade (**MM2**, **MM4**) |
| **D4** | **`membership` and `account_status` are separate controls with separate confirmations** | They come from different systems and mean different things ([../handoff.md](../handoff.md) §9 D5). One form writing both is how suspension gets used as a billing action (**MM5**) |
| **D5** | **Every write is audited: who, when, what changed, why** | **MM3**. The audit row is the deliverable, not a side effect — a tier with no attributable cause cannot be reconciled against billing |
| **D6** | **The console never computes entitlement itself** | It renders what the registry-driven engine returns. A second implementation in the admin UI is a second answer, and the operator's answer would be the one people trust |
| **D7** | **Nothing here writes counters directly** | `members_brain` **BR25**: the flat `ac_*` field is the authority and hand-editing surfaces write the projection. Corrections go through the same path a consume goes through, or they do nothing visible |
| **D8** | **The list is server-side: paged, searchable, filterable** | **MM8**, **MM9**. Search by email, reference, domain and subscription id, because that is how a support request identifies a member |

## 8.5 Phases

| Phase | Contents | Depends on |
|---|---|---|
| **P0** | **MM1** — authenticate the route group; drop `level` from `$allowed` (**D2**). Both are small and neither waits on the console | nothing |
| **P1** | Audit log + timeline (**D5**); tier changes routed through a server-side operation with a mandatory reason (**D3**); `account_status` split out (**D4**) | P0 |
| **P2** | `preview(toPlan)` wired into the tier dialog (**MM6**) — needs [03](03-api-contracts.md) and the engine | P1, [02](02-architecture.md) |
| **P3** | Holdings / retirement / identity panels (§8.3.2); operator actions (§8.3.4) | P2 |
| **P4** | Server-side list: paging, search, filters (**D8**) | P0 |

**P0 and P4 are independent of the retention engine** and can ship while it is still being
designed. P2 and P3 cannot — they render things the engine has to compute first.

## 8.6 Acceptance

| # | Check | Expect |
|---|---|---|
| A1 | `PUT /memberships/{ref}` with no credentials | 401 |
| A2 | `PUT` with `level: 99` as an authenticated operator | Rejected — `level` is not writable here (D2) |
| A3 | `PUT` with `membership: 4` directly | Rejected. Tier changes go through the operation, not the column (D3) |
| A4 | Change a member's tier through the console | Preview shown first; reason required; audit row written; retention engine runs (D3, D5, MM4) |
| A5 | The same change triggered by billing instead | **Identical** engine behaviour and an audit row naming the automated cause |
| A6 | Downgrade a member holding more than the new tier allows | Preview lists exactly what the engine then retires — no disagreement between the two |
| A7 | Suspend a member | `account_status` changes, `membership` untouched, grace clocks frozen ([02](02-architecture.md) §2.4) |
| A8 | Two operators open the same member and both save | The second is told the record changed; no silent overwrite (MM7) |
| A9 | A member with a ceiling override, viewed then swept | The console shows the override, and the engine honours it — or the conflict in §8.3.3 is closed some other way, deliberately |
| A10 | Search by email / reference / domain / subscription id | Each finds the member (D8) |
| A11 | The member list with 10,000 members | Pages; the browser does not receive them all (MM8) |
| A12 | Any counter shown in the console | Matches what `alloc_v2` reports for that member — one computation, not two (D6) |

## 8.7 Open questions

| # | Question | Notes |
|---|---|---|
| **OQ-A** | **Who is an operator?** There is no role model for Brain admin — today it is `level === 99` and a hidden sidebar link | D1 needs an answer. `level` is a sentinel, not a role, and D2 removes it from this form; what replaces it as the authorisation check is unresolved |
| **OQ-B** | **May an operator set a tier that no subscription backs?** | Support grants are real and legitimate. But an unbacked tier is invisible to billing and will be corrected by the next enforcement sweep unless `tier_source: manual` protects it ([../billing/09](../billing/09-entitlement-integration.md) §9.3 already returns that field) |
| **OQ-C** | **Does an operator downgrade start the grace clock, or retire immediately?** | Billing downgrades earn grace because the member was warned by the dunning ladder. An operator downgrade has no such warning, so it arguably needs *more* grace, not less |
| **OQ-D** | **Can an operator reverse a retirement after purge has begun?** | [02](02-architecture.md) says `purging → active` is a valid edge before hard deletion. Whether the console exposes it, and to whom, is a policy question |
| **OQ-E** | **What happens to the Create-member tab?** | It posts to the same `store()` as public signup ([07](07-signup-flow.md) **OQ-B**), which is why `store()` carries an `isAdminCreation()` branch keyed on a client-supplied field. [07](07-signup-flow.md) **D3** splits the endpoint; this tab is the other consumer |
| **OQ-F** | **Does this console belong in the Brain at all?** | `members` is the member-facing dashboard; the Brain is the operator's. They share a database. Splitting or merging them is a bigger question than this document, but it is the reason two admin surfaces exist |

## 8.8 Related

| Document | Relationship |
|---|---|
| [../members_brain/](../members_brain/README.md) | **Owns the Allocations tab.** Nine documents; not repeated here (§8.3.3) |
| [07-signup-flow.md](07-signup-flow.md) | The same route group (**S2**), the same `store()`, and the half-created members this console has to repair |
| [02-architecture.md](02-architecture.md) | The engine whose output every panel in §8.3.2 renders |
| [03-api-contracts.md](03-api-contracts.md) | `preview(toPlan)` — §3.4, and this is its second consumer |
| [../billing/08-brain-enforcement.md](../billing/08-brain-enforcement.md) | The automated writer of the same column. **A5** requires both paths behave identically |
| [../allocation.md](../allocation.md) §6 | What `level === 99` means, and why **D2** removes it from this form |

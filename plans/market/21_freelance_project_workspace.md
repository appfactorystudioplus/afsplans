# Step 21 — Freelance project workspaces: market orders managed in coordinator

**On completion:** write `complete/21_freelance_project_workspace_complete.md`.
**Depends on:** [15](15_freelance_order_lifecycle.md) (the order lifecycle and its state machine),
[16](16_payouts_fees_and_ledger.md) (the ledger — every money fact this step reports comes from it),
[11](11_market_portal_auth.md)/[12](12_market_portal_dashboard.md) (the portal shell this step adds
pages to). Outside this plan: afs-connect **step 60** (the source registry and the five-layer
entitlement gate) and **step 61** (the `hosted` source kind and coordinator's fourth auth surface) —
both landed, see `afsserver/plans/afs-connect/13-step-60-findings-and-execution-plan.md`.

**Blocks:** nothing in this plan. This is additive.

**Projects touched:** market (`apps/market`), coordinator (`apps/coordinator`), members
(`apps/members`). Brain is not involved — no money moves in this step that step 16 does not already
move.

---

## 21.1 The problem

Step 15 built a freelance order lifecycle: requirements → in progress → delivered → revisions →
completed, with milestones, disputes and a message thread. For a $60 logo that is exactly right, and
anything more would be in the way.

It is inadequate the moment an order gets *large*. A buyer commissioning a $28,000, four-month build
across three freelancers has questions step 15's UI structurally cannot answer:

- Which of the eleven deliverables are late, and what is blocking them?
- We are 60% through the budget — are we 60% through the work?
- Freelancer B is waiting on Freelancer A's export. When does that unblock?
- We approved milestone 3 last week. What did it actually cost per hour of work delivered?
- I have four active engagements. Is anyone double-booked?

Those are project-management questions, and the platform already has a project-management
application: **coordinator**. It has projects, a WBS task tree with indent and sort order, Gantt
dates, typed dependencies with lag, assignees, per-day time entries, kanban columns, a per-project
per-year budget with hourly/salaried assignee rates and quarterly allocation, one-time and recurring
cost lines, expenses, an audit trail and immutable snapshots. Every one of those tables exists and
is populated by a working SPA today (`pm_*`, migrations `2026_07_13_2000*`).

Step 21 connects the two: **a market freelance order can be promoted into a real coordinator
project, which then stays in sync with the order for its whole life, and reports cost and schedule
against numbers only market knows.**

The user-facing name is **Project Workspace**. Internally the joining record is an **engagement** —
one coordinator project fed by one *or several* market orders (§21.12).

### What makes this worth building rather than bolting a Gantt chart onto market

Two things, and both come from coordinator being a *separate, member-owned* application rather than
a widget inside the store:

1. **Cross-engagement visibility.** A buyer's four concurrent engagements, their internal staff, and
   their notes/graphs/PDFs are all already in coordinator. Freelance work becomes one more input to
   a picture the buyer already keeps — not a fifth silo. Capacity conflicts and portfolio-level
   budget burn are only visible from there.
2. **The buyer keeps the record.** When the order closes, the marketplace relationship ends but the
   project history — what was scheduled, what it cost, what shipped — stays in the buyer's own
   workspace. Data the buyer would otherwise lose when a gig archives.

---

## 21.2 Who owns which fact

This is the first thing to settle, because the failure mode of a two-system integration is two
systems each believing they own the same number.

| Fact | Owner | The other side's copy |
|---|---|---|
| Order/contract status, milestone status | **market** (`ServiceOrderStateMachine`, step 15) | coordinator mirrors it read-only |
| Money: contract value, fee, escrow, released, refunded | **market** (`seller_ledger_entries`, step 16) | coordinator reads it; never computes it |
| Who the freelancer is, what they were hired for | **market** | coordinator holds a shadow `pm_user` row |
| Approved timesheet hours (hourly contracts) | **market** (`contract_timesheets`) | coordinator reads it |
| Deliverable files | **market** (step 05 storage) | coordinator links, never copies |
| Schedule: task tree, dates, dependencies, % progress | **coordinator** | market shows a summary only |
| Buyer-side effort: internal staff hours, internal tasks | **coordinator** | market never sees it |
| Budget plan, expenses, non-market costs | **coordinator** | market never sees it |
| The *variance* between plan and actual | **coordinator** (computed from both) | — |

**The load-bearing rule: coordinator never moves money.** Every money-affecting action a buyer can
take from inside a Project Workspace — approve a milestone, accept a delivery, request a refund —
is a *command sent to market*, executed by market's own state machine and ledger with market's own
guards. Coordinator renders the button; market decides. §21.9 specifies the channel.

The reason is not tidiness. Step 15's state machine and step 16's ledger contain every rule about
what may legally happen to an order and what it does to a seller's balance. A second code path that
writes `service_orders.status` or a ledger row — in a different application, on a different
deployment cycle — is exactly the drift both of those steps exist to prevent.

---

## 21.3 Findings from the coordinator audit

Read before designing anything. Six facts about coordinator as it stands today constrain this step
heavily, and two of them are hazards that predate it.

### 21.3.1 `saveWorkspace()` is a destructive full-replace — the central constraint

`apps/coordinator/app/Services/Pm/PmPersistenceService.php`. The SPA loads the entire workspace and
saves the entire workspace. Every persist method ends the same way:

```php
// persistProjects(), :448
if ($incomingIds !== []) {
    PmProject::forMember($memberReferenceNum)
        ->whereNotIn('client_id', $incomingIds)
        ->whereNull('deleted_at')
        ->update(['deleted_at' => now()]);
}
```

Identically in `persistTeams()` (:495) and `persistSourceTasks()` (:586). **Any project or task not
present in the browser's payload is soft-deleted.**

So market cannot simply `INSERT` into `pm_projects`. A workspace-created project would survive
exactly until the buyer's next browser save from a tab that loaded before the insert — at which
point it silently disappears, with no error anywhere.

This is not a bug in coordinator; it is a coherent design for a single-writer offline-first SPA. It
stops being coherent the moment there is a second writer, which is what this step introduces.

**Consequence for the design:** an `origin` column on `pm_projects`/`pm_tasks` plus an explicit
exclusion in all three delete sweeps (§21.5.1, slice 21-B). Nothing else in this step is safe until
that lands.

### 21.3.2 `persistTaskChildren()` hard-deletes child rows — worse, and it decides the whole shape

Same file, `:604`–`:690`. For every task in the incoming payload, assignees, dependencies, comments,
**time entries**, checklist items and tags are each `->delete()`d unconditionally and re-inserted
from the payload. No `whereNotIn` guard, no soft delete:

```php
PmTaskTimeEntry::forMember($memberReferenceNum)
    ->where('project_client_id', $projectClientId)
    ->where('task_client_id', $taskClientId)
    ->delete();
foreach ($task['timeEntries'] ?? [] as $entry) { /* re-insert */ }
```

A fresh load→save round trip is harmless, because `loadWorkspace()` reads those rows back out of the
database and the SPA sends them straight back. But a **stale tab** — one loaded before market wrote
a timesheet's hours — permanently destroys them on save. Hard delete. No tombstone, no recovery.

This single fact determines the storage design of the entire step:

> **Market-sourced facts do not live in `pm_task_*` child tables.** They live in coordinator-owned
> `afsconnect_engagement_*` tables that the SPA payload cannot reach, and coordinator's *read* layer
> merges them when producing budget actuals, reports and charts.

Mirroring approved hours into `pm_task_time_entries` — so they appear on the buyer's own timesheet
views — is genuinely desirable, and is specified as **optional slice 21-N, gated on 21-A landing
first** (§21.20). It must not ship before the concurrency guard.

### 21.3.3 `client_id` is `Date.now()` from the browser

`resources/js/src/tasks.ts:316`, `users.ts:241`, `budget2/store.ts:236`, `kanban.ts:545` — all
`id: Date.now()`. Roughly `1.79e12` today.

Two consequences. First, market needs an allocation strategy that cannot collide: a **reserved band
at or above `9_000_000_000_000_000`** (9e15 ms ≈ year 287,000), allocated by *coordinator* inside the
inserting transaction, so there is exactly one allocator and no cross-application coordination.

Second, an observation offered rather than fixed: `Date.now()` collides with itself when two entities
are created in the same millisecond. Pre-existing, out of scope, worth a line in the completion
report.

### 21.3.4 afs-connect is read-only by charter, and this step writes

`afs-connect/00-overview.md`, non-goals: *"**Not** giving the other four apps write access into
coordinator's data (this is coordinator *reading about* the others, not a two-way sync engine)."*

Step 21 deliberately departs from that. It must be recorded as a considered decision, not an
oversight. Two things make it defensible:

- The precedent already exists. Step 61 added `POST /api/engine/v1/connect/tasks/{taskId}` —
  described in `04-api-contracts.md` as *"the first write endpoints in the internal API, which
  therefore run coordinator's real PM authorization against the forwarded acting user."*
- The charter's concern was **loops**: app A writing into coordinator while coordinator writes back
  into app A. This design has no loop. Market writes schedule/shadow rows into coordinator;
  coordinator writes nothing into market — it *asks* market to act, and market's own state machine
  decides (§21.2, §21.9).

The write surface is narrow and enumerated (§21.7, §21.8). It is not a sync engine.

### 21.3.5 A fourth copy of the same HMAC

The payload `{timestamp}.{METHOD}.{path}.{sha256(body)}`, HMAC-SHA256, ±300s skew, `hash_equals`,
now exists in four places:

| Class | App | Channel | Header prefix |
|---|---|---|---|
| `MarketSignature` | market/members | members ↔ market (step 04) | `X-Market-*` |
| `BrainInternalSignature` | market/brain | brain ↔ market (step 16) | `X-Brain-*` |
| `AfsConnectSignature` | coordinator | the afs-connect mesh | `X-AfsConnect-*` |
| *(this step)* | market | market ↔ coordinator | `X-AfsConnect-*` |

Market gets a fifth signer class. **This is correct and should not be "fixed" by sharing code** —
there is no shared PHP package across these repos, and afs-connect's own step-60 report reached the
identical conclusion for `auth`'s copy of `AllocationGateway` (*"necessarily a separate copy — no
shared PHP package across these repos, same reason the JS client is file-synced"*). Market's new
class must be byte-compatible with coordinator's `AfsConnectSignature` and use **its** header names,
because coordinator's middleware is what verifies it.

### 21.3.6 `EngineTaskController` is a stub

`apps/coordinator/app/Http/Controllers/Api/Engine/EngineTaskController.php` — both methods return
placeholder JSON with `TODO(step 61-J)` comments. The task read/write path this step might otherwise
have reused **does not exist yet**.

Step 21 therefore builds its own read path and does not depend on 61-J. Where the two overlap
(fetching PM tasks for an external caller, optimistic-concurrency conflict handling), 21 should
build it in a shape 61-J can adopt, and say so.

### 21.3.7 Coordinator is scoped to exactly one member — the freelancer cannot log in

Every `pm_*` and `afsconnect_*` table is keyed by a single `member_reference_num`. A freelancer is a
*different* member account. There is no model in coordinator for "a person from outside this member
account who can see one project."

This is the reason §21.13 exists and looks the way it does: freelancer visibility is delivered
**through market's own portal**, with market calling coordinator on the freelancer's behalf using a
narrowly-scoped token. Nobody gets a coordinator login they did not already have, and coordinator's
single-member scoping is never bent.

---

## 21.4 Architecture

```
   market (system of record for the ORDER)        coordinator (system of record for the PROJECT)
   ────────────────────────────────────────       ────────────────────────────────────────────────

   ServiceOrder / Contract
        │
        │  ① provision      POST /api/internal/afs-connect/v1/engagements
        ├─────────────────────────────────────▶   create pm_project (origin=market)
        │      (signed, X-AfsConnect-*)            + kanban columns, pm_users, pm_team
        │                                          + pm_tasks from milestones/deliverables
        │   ◀─────────────────────────────────     + pm_budget + assignees + cost lines
        │      { pm_project_client_id, url }       returns the ids market must remember
        │
        │  ② sync (every state-machine transition, every ledger write)
        ├─────────────────────────────────────▶   POST .../engagements/{ref}/events
        │                                          → afsconnect_engagement_* (facts)
        │                                          → pm_tasks status/progress (shell only)
        │                                          → afsconnect_activity_log
        │
        │  ③ read  (market is an afs-connect `provider` source)
        │   ◀─────────────────────────────────     GET /api/internal/afs-connect/v1/summary
        │                                                 .../changes  .../items/{id}  .../search
        │                                          → afsconnect_index rows, linkable to any task
        │
        │  ④ command (the ONLY path that touches money)
        │   ◀─────────────────────────────────     POST {market}/api/internal/v1/engagements/
        │      market's own state machine runs             {ref}/commands   (X-Market-* signed)
        └──▶  ServiceOrderStateMachine / LedgerService decide; ② reports the result back
```

Four mechanisms, each with a different trust posture:

| # | Direction | Auth | Writes what |
|---|---|---|---|
| ① provision | market → coordinator | `afs_connect.service`, new `market` peer key | `pm_*` shells + `afsconnect_engagement_*` |
| ② sync | market → coordinator | same | `afsconnect_engagement_*`, narrow `pm_tasks` fields |
| ③ read | coordinator → market | `market.internal` (step 04 HMAC, `X-Market-*`) | nothing — market serves |
| ④ command | coordinator → market | `market.internal`, plus an acting-user assertion | market's own tables, via market's own services |

Market is registered as an afs-connect **`provider`** source (§21.15) — it is a Laravel app with a
public URL and can serve the four provider endpoints, so `hosted` (the engine's kind, for
unaddressable clients) does not apply.

---

## 21.5 Coordinator schema

All new tables use the `afsconnect_` prefix and no FK constraints, matching every existing
`afsconnect_*` table (`2026_07_22_000001_create_afsconnect_tables.php`,
`2026_07_28_190000_create_afsconnect_engine_tables.php`).

### 21.5.1 The provenance columns — the fix for §21.3.1

```php
// coordinator: add_origin_to_pm_tables.php
Schema::table('pm_projects', function (Blueprint $table) {
    // 'pm' (the SPA, default — every existing row) | 'market'
    $table->string('origin', 16)->default('pm')->index();
    // Opaque back-reference, e.g. 'engagement:eng_9fk3a'. Never parsed by PM code.
    $table->string('origin_ref', 64)->nullable();
});
Schema::table('pm_tasks', function (Blueprint $table) {
    $table->string('origin', 16)->default('pm')->index();
    $table->string('origin_ref', 64)->nullable();   // e.g. 'milestone:412', 'deliverable:88'
});
Schema::table('pm_users', function (Blueprint $table) {
    $table->string('origin', 16)->default('pm')->index();
    $table->string('origin_ref', 64)->nullable();   // 'seller:seller_ref_0001'
});
```

And the three delete sweeps become, identically:

```php
PmProject::forMember($memberReferenceNum)
    ->where('origin', 'pm')            // ← MARKET_MODIFIER_ADDITION: market-owned rows
    ->whereNotIn('client_id', $incomingIds)   //   are not the SPA's to delete
    ->whereNull('deleted_at')
    ->update(['deleted_at' => now()]);
```

Note `updateOrCreate`'s explicit field list (`persistSourceTasks`, `:557`) does **not** include
`origin`, so a market-owned row round-trips through an SPA save with its provenance intact. That is
luck rather than design, and the completion report should say so — a future field added to that list
without thought would break it.

**What the buyer may still change on a market-owned row, and what market re-asserts.** The SPA can
freely edit an imported task's dates, assignees, priority, tags, checklist and description; those are
the buyer's plan and market never touches them after provisioning. Market re-asserts exactly three
fields on every sync — `title`, `status`, `progress` — and only for tasks whose `origin_ref` names a
milestone or deliverable, because those three *are* the order's own state. A buyer dragging a
"Milestone 2" card to Done in kanban does not approve milestone 2; the next sync moves it back, and
the UI says why (§21.11.6). Approving is §21.9's job.

### 21.5.2 `afsconnect_engagements`

The join record. One row per (coordinator project, market order).

```php
Schema::create('afsconnect_engagements', function (Blueprint $table) {
    $table->id();
    $table->string('reference_num', 32)->unique();        // 'eng_...' — coordinator-issued
    $table->string('member_reference_num', 64)->index();  // the BUYER
    $table->unsignedBigInteger('pm_project_client_id')->index();

    // Market's side of the join. Exactly one of these two is set.
    $table->string('market_kind', 16);                    // 'service_order' | 'contract'
    $table->string('market_reference_num', 32);           // so_… | ct_…
    $table->string('seller_reference_num', 64)->index();

    $table->string('title', 500);
    $table->string('status', 32)->default('active');      // active | completed | cancelled | disputed | archived
    $table->string('currency', 3)->default('usd');

    // Contracted value, tax-excluded, in MINOR UNITS — integer cents, the same
    // shape FeeCalculator uses (step 16 §16.2). Never a float, never a decimal
    // column that a JS client will parse into one.
    $table->bigInteger('contract_value_cents')->default(0);
    $table->string('billing_type', 16);                   // 'fixed' | 'milestone' | 'hourly'
    $table->unsignedInteger('weekly_limit_hours')->nullable();  // hourly contracts only

    $table->timestamp('provisioned_at');
    $table->timestamp('last_synced_at')->nullable();
    $table->timestamp('closed_at')->nullable();
    // Set when the BUYER deletes the PM project. Market must not resurrect it
    // on the next sync — see §21.14.4.
    $table->timestamp('detached_at')->nullable();
    $table->timestamps();

    $table->unique(['market_kind', 'market_reference_num'], 'afsconnect_engagements_market_unique');
    $table->index(['member_reference_num', 'status']);
});
```

`unique(market_kind, market_reference_num)` is what makes provisioning idempotent: a double-clicked
"Create Workspace" button converges on one engagement, the same way step 13's
`orders.dp_invoice_reference_num` unique index makes checkout idempotent.

### 21.5.3 `afsconnect_engagement_facts` — the append-only money/effort feed

Everything market reports lands here first. Append-only for the same reason
`seller_ledger_entries` is (step 16 §16.3): a financial history you can `UPDATE` cannot be audited.

```php
Schema::create('afsconnect_engagement_facts', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('engagement_id')->index();
    $table->string('kind', 32);
    //  milestone_funded | milestone_approved | order_completed | timesheet_approved
    //  | payment_released | refund | fee | expense | delivery | revision_requested
    //  | dispute_opened | dispute_resolved
    $table->string('market_ref', 64)->nullable();     // milestone id, timesheet id, ledger entry id
    $table->bigInteger('amount_cents')->default(0);   // signed; 0 for non-money facts
    $table->decimal('hours', 10, 2)->nullable();      // timesheet_approved only
    $table->unsignedBigInteger('pm_task_client_id')->nullable()->index();
    $table->string('actor_reference_num', 64)->nullable();
    $table->string('memo', 500)->nullable();
    $table->json('context')->nullable();
    $table->timestamp('occurred_at')->index();
    $table->timestamp('created_at');

    // Idempotency. Market sends a stable event_id; a redelivered webhook is a no-op.
    $table->string('event_id', 96)->unique();
});
```

Two properties worth stating explicitly, because both are load-bearing:

- **This table is unreachable from the SPA payload.** That is the whole point (§21.3.2). A stale tab
  cannot destroy a payment record.
- **It is a mirror, not a source.** If it disagrees with market's ledger, market is right and the
  reconciler (§21.14.5) repairs coordinator. Coordinator never repairs market.

### 21.5.4 `afsconnect_engagement_participants`

```php
Schema::create('afsconnect_engagement_participants', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('engagement_id')->index();
    $table->string('seller_reference_num', 64)->index();
    $table->unsignedBigInteger('pm_user_client_id');   // the shadow pm_users row
    $table->string('display_name', 255);
    $table->string('role', 64)->nullable();            // from the gig/proposal title
    $table->bigInteger('rate_cents')->nullable();      // hourly rate, minor units
    $table->string('rate_type', 16)->nullable();       // 'hourly' | 'fixed'
    $table->timestamp('joined_at');
    $table->timestamp('left_at')->nullable();
    $table->timestamps();

    $table->unique(['engagement_id', 'seller_reference_num'], 'afsconnect_eng_participants_unique');
});
```

The shadow `pm_users` row exists so the freelancer can be a first-class assignee, budget assignee and
Gantt resource. `pm_users.checkpoint_user_id` is nullable (`2026_07_13_200004`), so this needs no
login and creates no account.

### 21.5.5 `afsconnect_engagement_commands` — the outbox for ④

```php
Schema::create('afsconnect_engagement_commands', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('engagement_id')->index();
    $table->string('command', 48);          // approve_milestone | accept_delivery | request_revision
                                            // | fund_milestone | request_cancellation | post_message
    $table->json('payload');
    $table->string('idempotency_key', 64)->unique();
    $table->string('acting_checkpoint_user_id', 64);   // WHO clicked — never taken from the request body
    $table->string('status', 16)->default('pending');  // pending | sent | accepted | rejected | failed
    $table->unsignedTinyInteger('attempts')->default(0);
    $table->text('response')->nullable();
    $table->timestamp('sent_at')->nullable();
    $table->timestamps();
});
```

An outbox rather than a direct call, because the alternative is a buyer clicking "Approve milestone"
and getting a spinner that fails on a transient network error with no record that they tried. The
row is written first, a queued job sends it, and the UI reflects `status`.

---

## 21.6 Market schema

One new table. Market already owns everything else this step needs.

```php
// market: create_engagement_links_table.php
Schema::create('engagement_links', function (Blueprint $table) {
    $table->id();
    $table->string('buyer_reference_num', 64)->index();
    $table->string('engagement_reference_num', 32)->index();   // coordinator's eng_…
    $table->unsignedBigInteger('pm_project_client_id');
    $table->string('workspace_url', 500);                      // deep link into coordinator

    // Exactly one of these is set — the same disjunction afsconnect_engagements has.
    $table->foreignId('service_order_id')->nullable()->constrained('service_orders')->cascadeOnDelete();
    $table->foreignId('contract_id')->nullable()->constrained('contracts')->cascadeOnDelete();

    $table->string('status', 16)->default('active');   // active | detached | failed
    $table->timestamp('provisioned_at')->nullable();
    $table->timestamp('last_event_at')->nullable();
    $table->text('last_error')->nullable();
    $table->timestamps();

    $table->unique('service_order_id');
    $table->unique('contract_id');
});
```

`unique` on each FK is the market-side half of the idempotency guarantee: one order maps to at most
one engagement. (Several orders may map to the *same* engagement — §21.12 — which is why the unique
constraint is on the order side, not on `engagement_reference_num`.)

---

## 21.7 Provisioning: order → workspace

### 21.7.1 When the button appears

On `/portal/services/{ref}` and `/portal/contracts/{ref}` (step 15's pages), a **"Manage as a
project"** panel, shown when all of:

- the order is in a state where a workspace is useful — `in_progress`, `delivered`,
  `revision_requested`, or a contract with `status = active`. Not `awaiting_requirements` (nothing
  to schedule yet), not `completed`/`cancelled` (see §21.14.1 for the deliberate exception);
- market's cached copy of coordinator's `/api/afs-connect/status` says this member is entitled
  (§21.15) — otherwise the panel becomes an upsell naming the tier that unlocks it, following the
  three-state rendering step 60 already built for `afsConnectSettings.ts`;
- no `engagement_links` row exists for this order (otherwise the panel is a link to the workspace).

### 21.7.2 The blueprint

Market POSTs one document. Coordinator does all the creating, so there is one transaction and one
allocator.

```
POST {coordinator}/api/internal/afs-connect/v1/engagements
X-AfsConnect-App: market
X-AfsConnect-Timestamp / X-AfsConnect-Signature
```

```json
{
  "idempotency_key": "eng-provision-so_in4ysaug",
  "member_reference_num": "buyer_ref_0001",
  "acting_checkpoint_user_id": "10432",
  "market_kind": "service_order",
  "market_reference_num": "so_in4ysaug",
  "title": "Isometric city pack — 40 buildings",
  "currency": "usd",
  "billing_type": "milestone",
  "contract_value_cents": 2800000,
  "weekly_limit_hours": null,
  "starts_on": "2026-08-21",
  "due_on": "2026-12-15",

  "participants": [
    { "seller_reference_num": "seller_ref_0001", "display_name": "Ana P.",
      "role": "3D artist", "rate_type": "fixed", "rate_cents": null }
  ],

  "tasks": [
    { "origin_ref": "milestone:412", "title": "M1 — Blockout + style frame",
      "kind": "milestone", "amount_cents": 600000,
      "starts_on": "2026-08-21", "due_on": "2026-09-12",
      "estimate_hours": 60, "status": "In Progress",
      "assignee_seller_reference_num": "seller_ref_0001" },
    { "origin_ref": "milestone:413", "title": "M2 — 20 buildings, textured",
      "kind": "milestone", "amount_cents": 1000000,
      "starts_on": "2026-09-15", "due_on": "2026-10-24",
      "estimate_hours": 120, "depends_on_origin_ref": "milestone:412" },
    { "origin_ref": "requirement:1", "title": "Provide brand palette + reference",
      "kind": "buyer_action", "due_on": "2026-08-25" }
  ]
}
```

Three things about the shape:

- **`origin_ref` is the stable join key**, not array position. Market re-sends the same
  `origin_ref` forever; coordinator upserts on it. A milestone renamed in market renames its task;
  a milestone added later appears as a new task without disturbing the rest.
- **`kind: buyer_action`** exists because the most common cause of a late freelance project is the
  *buyer* not supplying something. Requirements (step 15 §15.3) become tasks assigned to the buyer,
  on the same Gantt as the freelancer's work, and count in the same lateness metrics. This is a
  small idea that does a lot of the feature's real work.
- **`amount_cents` on a task** is what lets the budget be generated (§21.7.4) and what makes
  earned-value computable per task rather than only per project.

### 21.7.3 What coordinator creates, in one transaction

1. `afsconnect_engagements` row, `reference_num = 'eng_' . Str::random(9)`.
2. `pm_projects` row — `origin = 'market'`, `origin_ref = 'engagement:eng_…'`, `client_id` allocated
   from the reserved band:

   ```php
   // One allocator, inside the inserting transaction. The band starts above any
   // plausible Date.now() (§21.3.3) so it cannot collide with the SPA's ids.
   private const CLIENT_ID_BAND_START = 9_000_000_000_000_000;

   $clientId = max(
       self::CLIENT_ID_BAND_START,
       ((int) PmProject::withTrashed()
           ->where('member_reference_num', $ref)
           ->where('client_id', '>=', self::CLIENT_ID_BAND_START)
           ->lockForUpdate()
           ->max('client_id')) + 1
   );
   ```

3. `pm_kanban_columns` — the four the SPA defaults to, plus **"Awaiting you"** for `buyer_action`
   tasks and **"In review"** for `delivered` work sitting between done and paid. Those two states
   exist in market's lifecycle and have nowhere to go on a stock board.
4. A `pm_users` row per participant (`origin = 'market'`, no `checkpoint_user_id`), plus a `pm_teams`
   row named for the engagement with all participants in it.
5. `pm_tasks` from `tasks[]`, in order, `indent` derived from `depends_on_origin_ref` chains;
   `pm_task_dependencies` rows (`dependency_type = 'FS'`) from the same; `pm_task_assignees` from
   `assignee_seller_reference_num` → the shadow `pm_users.client_id`.
6. A `pm_budgets` row for `year(starts_on)`, `workflow = 'draft'`, `desired_total =
   contract_value_cents / 100`; a `pm_budget_assignees` row per hourly participant carrying
   `hourly_rate`; a `pm_budget_one_time_items` row per milestone (`category = 'Freelance —
   milestone'`) so the plan side of the budget is populated the moment the workspace exists.
7. `afsconnect_index` rows for the order and each deliverable, so market items are searchable and
   linkable like any other source (③).
8. `afsconnect_activity_log` — `event_type = 'linked'`.

Response: `{ engagement_reference_num, pm_project_client_id, workspace_url, tasks: [{origin_ref,
pm_task_client_id}] }`. Market stores the mapping in `engagement_links` and mirrors the per-task ids
on its own milestone rows, so later syncs address tasks directly.

### 21.7.4 Import modes

Not every buyer wants the same thing, and forcing one shape is how an integration gets ignored.
Three modes on the provisioning panel:

| Mode | Creates | For |
|---|---|---|
| **Full workspace** (default) | New project, tasks, team, budget | The common case — one big order |
| **Add to existing project** | Tasks + budget lines into a project the buyer picks | A market order that is one workstream inside a larger internal project |
| **Track only** | Engagement + facts + index rows; **no** `pm_*` writes | A buyer who wants the cost dashboard and search but not another Gantt to maintain |

"Track only" is worth having precisely because it costs almost nothing here — the money/effort
reporting (§21.10) reads `afsconnect_engagement_facts`, which exists in all three modes — and it
removes the objection that this feature clutters a workspace.

---

## 21.8 The sync: market → coordinator

### 21.8.1 Where it hooks

Step 15 put every `service_orders.status` write behind `ServiceOrderStateMachine::transition()`, and
step 16 put every ledger write behind `LedgerService`. Both are single choke points, which is exactly
what a sync needs. Market adds one listener per choke point; neither service learns anything about
coordinator.

```php
// market: app/Services/Engagement/EngagementSyncDispatcher.php
// Called AFTER commit, dispatching a queued job — the same rule step 04's
// MemberEventDispatcher established: "a failed callback must never fail the
// transaction that caused it."
```

Reuse `DeliverMemberEvent`'s retry shape (6 tries, backoff `[1,5,30,300,1800]`s) rather than
inventing a second one.

### 21.8.2 The event contract

```
POST {coordinator}/api/internal/afs-connect/v1/engagements/{engagementRef}/events
```

```json
{
  "event_id": "so_in4ysaug-transition-7",
  "occurred_at": "2026-09-12T14:02:11Z",
  "facts": [
    { "kind": "milestone_approved", "market_ref": "milestone:412",
      "amount_cents": 600000, "actor_reference_num": "buyer_ref_0001",
      "memo": "M1 — Blockout + style frame" }
  ],
  "task_updates": [
    { "origin_ref": "milestone:412", "status": "Done", "progress": 100 }
  ],
  "index_items": [
    { "source_type": "deliverable", "source_id": "88", "op": "upsert",
      "title": "M1 blockout.zip", "url": "https://market…/portal/services/so_in4ysaug" }
  ]
}
```

One envelope carrying three different kinds of write, because they must land together or not at
all: a milestone that is paid but whose task still says "In Progress" is worse than either alone.

`event_id` is the idempotency key, unique on `afsconnect_engagement_facts` — a redelivery is a
`200` no-op, matching `afsconnect_webhook_events`' existing contract (`04-api-contracts.md` §4.2).

### 21.8.3 The full event map

| Market event (step 15/16) | Facts written | Task effect | Money |
|---|---|---|---|
| Requirements submitted | — | `buyer_action` tasks → Done; work tasks → In Progress | — |
| Milestone funded | `milestone_funded` | — (escrow is not progress) | escrow +|
| Milestone approved | `milestone_approved` + `payment_released` | milestone task → Done, 100% | released + |
| Delivery submitted | `delivery` | task → "In review" column | — |
| Delivery accepted / auto-accepted | `order_completed` + `payment_released` | all order tasks → Done | released + |
| Revision requested | `revision_requested` | task → In Progress, progress −25 (floor 0) | — |
| Timesheet approved (hourly) | `timesheet_approved` (`hours`) | — (see §21.3.2) | billed + |
| Ledger `refund` / `fee_refund` | `refund` | — | released − |
| Ledger `platform_fee` | `fee` | — | fee + |
| Dispute opened | `dispute_opened` | project banner; **financial sync pauses** | frozen |
| Dispute resolved | `dispute_resolved` | banner clears | per resolution |
| Order cancelled | `order_completed` (0) | engagement → `cancelled`; tasks left as-is | — |

"Financial sync pauses" during a dispute means facts are still recorded (the history must be
complete) but the cost dashboard shows a disputed banner and stops projecting a forecast from
numbers that are actively contested.

---

## 21.9 The command channel: coordinator → market

The only path by which anything a buyer does in coordinator affects an order.

```
POST {market}/api/internal/v1/engagements/{engagementRef}/commands
X-Market-App: coordinator     (step 04's HMAC — market's own channel, not afs-connect's)
X-Market-Idempotency-Key: …
```

```json
{
  "command": "approve_milestone",
  "acting_checkpoint_user_id": "10432",
  "payload": { "milestone_ref": "milestone:412" }
}
```

Six commands, and no more:

| Command | Executes | Guard |
|---|---|---|
| `fund_milestone` | `MilestoneService::transition(…, 'funded')` | step 15's milestone table |
| `approve_milestone` | `MilestoneService::approve()` → ledger | must be `submitted`/`disputed` |
| `accept_delivery` | `DeliveryService::accept()` | must be `delivered` |
| `request_revision` | `RevisionService::request()` | revision cap enforced |
| `request_cancellation` | posts the flagged system message | — |
| `post_message` | `ServiceOrderMessage` + `MemberEventDispatcher::message()` | — |

**Market re-derives the buyer from its own records.** `acting_checkpoint_user_id` identifies *who*
for the audit trail; it never establishes *which order* or *which member* — market resolves the
engagement to its own `service_orders.buyer_reference_num` and checks the caller is entitled to act
on it. This is the same rule step 11 established for `member_reference_num` ("the only source a
portal data controller may read the caller's identity from") applied to a service caller.

An illegal transition returns step 15's `409 MARKET_E_ILLEGAL_TRANSITION` unchanged. Coordinator
records it on the command row and shows the buyer market's own message — not a coordinator-invented
one, so there is one vocabulary for what went wrong.

---

## 21.10 Cost and time: every number, and where it comes from

The heart of the feature. Each figure is attributed, because an unattributed number in a financial
dashboard is a number nobody trusts.

### 21.10.1 Money

| Figure | Formula | Source |
|---|---|---|
| **Contracted** | `contract_value_cents` | market, at provisioning + on change |
| **Escrowed** | Σ `milestone_funded` − Σ `milestone_approved` | facts |
| **Released** | Σ `payment_released` − Σ `refund` | facts (mirrors step 16's ledger) |
| **Platform fee** | Σ `fee` | facts |
| **Remaining** | `Contracted − Released − Escrowed` | derived |
| **Other project cost** | `pm_budget_expenses` + recurring/one-time lines *not* `origin = market` | coordinator |
| **True project cost** | `Released + Other project cost` | derived — the number the buyer actually wants |

The last row is why this belongs in coordinator rather than market. Market can tell a buyer what
they paid a freelancer. Only coordinator knows about the $400/mo software licence and the internal
designer's 30 hours that also went into the same project.

### 21.10.2 Time

| Figure | Source |
|---|---|
| **Estimated hours** | Σ `pm_tasks.estimate_hours` (from the gig's own estimates at import, buyer-editable after) |
| **Freelancer hours** | Σ `afsconnect_engagement_facts.hours` where `kind = timesheet_approved` |
| **Internal hours** | Σ `pm_task_time_entries.hours` for tasks with `origin = 'pm'` |
| **Elapsed / remaining** | `pm_tasks` dates vs. today |
| **Weekly burn** | freelancer hours grouped by ISO week |
| **Cap headroom** | `weekly_limit_hours − burn(this week)` — hourly contracts only |

### 21.10.3 Derived — the part that is genuinely new

Standard earned-value, computed from data that until now lived in two applications:

```
BAC  = contracted                                     (budget at completion)
PV   = BAC × (planned % complete as of today)         from pm_tasks dates
EV   = Σ (task.amount_cents × task.progress/100)      from pm_tasks progress
AC   = released + escrowed-consumed + other cost
CPI  = EV / AC        < 1.0 → paying more than delivered
SPI  = EV / PV        < 1.0 → behind schedule
EAC  = BAC / CPI      forecast final cost at current efficiency
VAC  = BAC − EAC      forecast overrun (negative) or saving
```

Plus two the marketplace makes possible and a generic PM tool cannot:

- **Effective hourly rate** = `released ÷ (freelancer hours + estimated hours on fixed milestones)`.
  On a fixed-price milestone the buyer never sees an hourly rate — this reconstructs one, and makes
  a $6,000 milestone that took 20 hours comparable with a $6,000 milestone that took 200.
- **Revision cost** = Σ `amount_cents` of milestones with ≥1 `revision_requested` fact, as a share of
  released. The price of an unclear brief, in currency. Buyers who see this number once tend to
  write better requirements the next time, which is the rare metric that improves the thing it
  measures.

### 21.10.4 Rounding

Every money figure is integer minor units end to end, matching step 16 §16.2. Percentages and
ratios are computed from cents and formatted at the edge. No `decimal` column is ever summed into a
PHP float — `BalanceCalculator`'s own `SUM(ROUND(amount * 100))` in coordinator's equivalent
queries, for the same reason.

---

## 21.11 What the buyer sees

Six surfaces in coordinator, one new tab on market's order page.

### 21.11.1 Project Workspace overview

Header: engagement status, freelancer avatars, contracted/released/remaining as three tiles with a
single stacked bar. Below: **next 5 things** — a merged list of upcoming milestone due dates,
`buyer_action` tasks that are late, and deliveries awaiting review. One list, sorted by urgency,
because the buyer's actual question is "what needs me today."

### 21.11.2 Schedule

The existing Gantt, unmodified, now populated. Milestone tasks render with the milestone diamond
(`pm_tasks.milestone`), `buyer_action` tasks in a distinct colour, dependencies as arrows. A money
row under the timeline shows escrow and release events on the same axis as the schedule — so
"we paid for M2 three weeks before M2 was scheduled to finish" is visible rather than inferable.

### 21.11.3 Cost

The §21.10 figures. Three views:

- **Waterfall** — contracted → escrowed → released → remaining.
- **Burn-up** — planned vs. earned vs. actual over time, with the EAC forecast line projected to the
  scheduled end date. The moment EAC crosses BAC is a date, and it is on the chart.
- **Per-milestone table** — amount, status, estimated vs. actual hours, effective rate, revision
  count. Sortable. This is the table a buyer screenshots for their own finance team.

### 21.11.4 People

Per participant: assigned tasks, hours this week vs. cap, released to date, average turnaround
(delivery submitted → accepted), revision rate. And, across **all** the member's engagements, the
capacity view: one freelancer's committed hours summed over every active engagement, flagged when
concurrent commitments exceed a plausible week. Only coordinator can compute this, and it is the
single strongest argument for the feature existing at all.

### 21.11.5 Risk

Auto-detected, each with the evidence that triggered it and the action that clears it:

| Flag | Rule |
|---|---|
| **Milestone at risk** | due ≤ 5 days, progress < 50% |
| **Silent freelancer** | no message, delivery or timesheet in ≥ N days (default 7) |
| **Budget ahead of work** | released ÷ BAC exceeds EV ÷ BAC by > 20 points |
| **Blocked on you** | a `buyer_action` task is overdue |
| **Revision spiral** | ≥ 3 revisions on one milestone, or revision cost > 25% of released |
| **Cap breach imminent** | hourly burn projects past `weekly_limit_hours` before week end |
| **Stale sync** | `last_synced_at` older than 2× the reconcile interval (§21.14.5) |

"Blocked on you" appearing in a risk list the buyer opened to check on a freelancer is deliberate.

### 21.11.6 Order actions

The six §21.9 commands, each with the money consequence stated *before* the click — "Approve M1 —
releases $6,000.00 to Ana P. This cannot be undone." Approve/accept require a typed confirmation,
consistent with market's own portal.

Where coordinator cannot act, it says so plainly rather than hiding the control: a kanban card
dragged to Done that market still considers `delivered` snaps back with "Milestone 2 moves to Done
when you approve it — approving releases $10,000.00. Approve now?" Correcting the buyer's mental
model at the moment it is wrong, rather than silently disagreeing with them.

### 21.11.7 On market's side

A **Project** tab on `/portal/services/{ref}`: schedule summary, next milestone, % complete, and a
deep link. Read-only. Market does not grow a second project-management UI — the same rule step 61
applied to the engine editor.

---

## 21.12 Multi-order programs

A real buyer hires three freelancers for one build: two gigs and an hourly contract. Three market
orders, one project.

`afsconnect_engagements` is keyed on `(market_kind, market_reference_num)` with
`pm_project_client_id` as an ordinary indexed column — several engagements may share a project. The
"Add to existing project" import mode (§21.7.4) is how the second and third orders join.

Aggregation then works at two levels, and the distinction must be explicit in the UI or the numbers
become nonsense:

- **Engagement level** — one order. CPI/SPI computed against that order's own contracted value.
- **Program level** — the project. Contracted = Σ of engagements; EV summed across all tasks
  including `origin = 'pm'` ones with no money attached (which contribute schedule but not cost).

The trap is EV: a project-level CPI that includes internal unpaid work understates cost efficiency.
The program view therefore reports **two** CPIs — "vs. freelance spend" and "vs. total project cost"
— rather than one averaged number that is true of nothing.

---

## 21.13 The freelancer's view

§21.3.7: the freelancer is a different member and cannot be given a coordinator login.

**v1: they get the schedule, through market.** The buyer toggles "Share schedule with freelancers"
per engagement (default **on**, because a freelancer who cannot see the plan cannot follow it).
When on:

- market's seller-side order page (members, step 07) gains a **Schedule** panel;
- members calls **market**, market calls **coordinator** using its `X-AfsConnect-*` service key plus
  an explicit `for_seller_reference_num`, and coordinator returns *only* tasks assigned to that
  participant, with dates, dependencies and status — no budget, no cost, no other participants'
  work, no internal `origin = 'pm'` tasks;
- the freelancer can update `progress` and post a comment on their own tasks. Both arrive as
  ordinary market events (§21.8) — the freelancer never writes to coordinator directly, and market
  is still the only thing coordinator trusts.

**Explicitly not v1:** a freelancer editing the schedule, seeing the budget, or appearing in the
buyer's coordinator user list as anything but a shadow row. Making a freelancer a Checkpoint
secondary user under the buyer's account was considered and rejected: it consumes the buyer's
`secondary_users` allocation (step 60 §60.4.3 now enforces that quota), grants far more access than
one project, and survives the engagement ending.

---

## 21.14 Lifecycle edges

The cases that decide whether this feature is trustworthy.

### 21.14.1 The order completes

The engagement goes `completed`, sync stops, **nothing is deleted**. The project stays in the
buyer's workspace with its full history — that is one of the two reasons the feature exists (§21.1).
The workspace becomes read-only *with respect to market* (no more commands) but stays fully editable
as an ordinary PM project. A completed order can still be promoted to a workspace after the fact
(§21.7.1's exception) — buyers frequently want the record only once it is over.

### 21.14.2 The order is refunded or cancelled

Engagement → `cancelled`. Facts record the refund. The project is **archived, never deleted**: a
cancelled engagement is a thing that happened and cost time. The cost view shows released minus
refunded, which may be zero, and says so rather than showing an empty state.

### 21.14.3 A freelancer leaves mid-engagement

`afsconnect_engagement_participants.left_at` set. Their `pm_users` row stays — time entries, budget
assignee rows and task assignments reference it, and deleting it would corrupt history. They stop
appearing in assignee pickers, their tasks are flagged unassigned, and the capacity view excludes
them.

### 21.14.4 The buyer deletes the project in PM

`afsconnect_engagements.detached_at` is set (detected on the next sync when the target project is
soft-deleted). Market's `engagement_links.status` → `detached`. **Sync stops permanently and the
project is not recreated** — without a tombstone, the next sync helpfully resurrects a project the
buyer deliberately deleted, forever. Market's order page offers "Create a new workspace" as an
explicit new action, not an automatic repair.

### 21.14.5 Reconciliation

A missed webhook is inevitable (step 16 §16.7's reasoning applies unchanged). A daily
`afs-connect:reconcile-engagements` command walks active engagements, asks market for facts since
`last_synced_at`, and repairs coordinator.

Two rules carried directly from step 16 §16.7:

- **It never auto-corrects market.** Coordinator is the mirror; a disagreement is repaired in
  coordinator and *reported* for market.
- **It reports rather than fixes silently.** Findings go to a report and the log, and surface as the
  "Stale sync" risk flag.

### 21.14.6 The member disables afs-connect

Sync stops (coordinator's entitlement gate refuses market's calls, as it does every other source's).
Existing projects remain — they are the member's own PM data and were never afs-connect's to remove.
Re-enabling resumes sync and backfills from `last_synced_at`. Pruning is a separate, explicitly
scheduled retention job, never a side effect of a toggle — `11-billing-and-allocation.md` §11's own
rule.

---

## 21.15 Entitlement, billing, metering

### 21.15.1 The registry entry

One block in `apps/coordinator/config/afs_connect_sources.php` — §12.6's promise that adding a
source is one entry plus an env var:

```php
'market_orders' => [
    'key' => 'market_orders',
    'label' => 'Marketplace Orders',
    'kind' => 'provider',                       // market is a Laravel app with a public URL
    'base_url_env' => 'AFS_CONNECT_MARKET_BASE_URL',
    'group' => 'marketplace',                   // a new group — see below
    'default_on' => true,
    'min_tier_index' => 2,                      // proposed; a pricing decision, not an engineering one
    'meter' => 'afs_connect_index_rows',
],
```

`group` is a new value (`creative` | `www` | `engine` | **`marketplace`**). Every consumer derives
group labels from the registry (§12.2), so this is additive — but the settings UI's group headings
are a hardcoded label map in `apps/members/resources/js/settings/afsConnectSettings.ts` and must gain
one entry. That file was rewritten in step 60 Phase 4 to render from `GET /api/afs-connect/sources`;
verify against the current file rather than against step 60's description of it.

Also required, per §12.5's consistency test: `AFS_CONNECT_SERVICE_KEY_MARKET` added to
`config/afs_connect.php`'s `peer_keys` (a `provider` source without a peer key fails the test), and
`AFS_CONNECT_MARKET_BASE_URL` set.

### 21.15.2 Access

Project Workspace is not the same capability as indexing market items. Two gates:

- **`market_orders` source** (`min_tier_index` 2 proposed) — indexing and search of orders and
  deliverables. Read-only, cheap.
- **`afs_connect_engagements`** — a new `checkpoint_registry_v3.json` resource for the workspace
  feature itself, with a per-tier *count* limit (how many active engagements). Proposed
  `[0, 1, 5, 25, unlimited]` by tier index, index 0 `enabled: false`.

Adding a registry resource is a pricing decision requiring sign-off, exactly as step 60 §60.4.2
established. **Build the seam; do not adopt the number unilaterally.** If the resource is not
present at build time, provisioning gates on the `market_orders` source alone and the completion
report records that the count limit is unenforced — the same honest treatment step 60 gave its
deferred capacity meters.

### 21.15.3 Metering

`afs_connect_engagements` consumed on provisioning, released on `completed`/`cancelled`/`detached`.
Use coordinator's existing `AllocationGateway` (step 60 Phase 3) — `check()` before the transaction,
`consume()` after it commits, `release()` on close. Never `consume()` inside the transaction: an
allocation consumed against a rolled-back provisioning is a counter that only goes up.

---

## 21.16 Security and privacy

1. **Market authenticates as a peer app, not as a member.** `X-AfsConnect-App: market` +
   `AFS_CONNECT_SERVICE_KEY_MARKET`. `member_reference_num` in the body is a *claim*; coordinator's
   entitlement gate independently confirms that member exists and is entitled. §21.3.4's "defense in
   depth" rule applies here as much as to any source.
2. **The acting user is asserted, never inferred.** Every write carries
   `acting_checkpoint_user_id`; it is recorded on the row and never used for authorization
   (§21.9) — the same split step 61 made for the engine's `afsconnect_engine_seen_users`.
3. **No PII crosses that isn't already crossing.** Participants carry a display name and seller
   reference — both already visible to the buyer on the order page. No email, no address, no
   payout detail, ever. `seller_profiles`' three Connect columns are never read by this step.
4. **Messages are opt-in and truncated.** Mirroring message threads into `pm_task_comments` is off
   by default; when on, only a 140-character preview and a deep link, never the full thread. A
   freelance conversation can contain anything.
5. **The command channel is buyer-scoped.** Market re-derives the order's buyer from its own
   database; a command naming an engagement the caller's member does not own is a 403 regardless of
   signature validity.
6. **Deliverable files never leave market.** Coordinator indexes titles and links. The link goes to
   market's own gated download (step 15's `DeliveryController::apiDownload()`), which re-checks the
   order's status on every request.
7. **Disputes freeze projection, not history.** Facts keep recording; forecasts stop, because a
   forecast built on a contested number is worse than no forecast.

---

## 21.17 UI

**Coordinator** (`resources/js/src/`): a `workspace/` module — overview, schedule overlay, cost
views, people, risk, order actions. Charts follow the app's existing library; no new dependency.

**Market** (`resources/js/portal/`): `engagement.ts` — the provisioning panel, the read-only Project
tab, and the entitlement/upsell states. Wired through `portalApiFetch()` (step 12), rendered into
Blade shells, everything escaped — step 15's own conventions unchanged.

**Members**: the `market_orders` source toggle appears automatically from the registry endpoint; the
only change is the `marketplace` group label (§21.15.1) and the freelancer Schedule panel (§21.13).

---

## 21.18 Config and env

| Key | App | Purpose |
|---|---|---|
| `AFS_CONNECT_MARKET_BASE_URL` | coordinator | market's internal API base — required for a `provider` source |
| `AFS_CONNECT_SERVICE_KEY_MARKET` | coordinator + market | the shared peer key (both sides, one value) |
| `COORDINATOR_URL` | market | coordinator's base URL — via `config('market.urls.coordinator')`, never a literal |
| `MARKET_ENGAGEMENT_ENABLED` | market | kill switch for the whole feature |
| `MARKET_ENGAGEMENT_SYNC_TIMEOUT` | market | HTTP timeout for ② (default 15s) |
| `AFS_CONNECT_ENGAGEMENT_RECONCILE_HOURS` | coordinator | §21.14.5 cadence (default 24) |
| `AFS_CONNECT_ENGAGEMENT_SILENT_DAYS` | coordinator | "Silent freelancer" threshold (default 7) |

Market reads every one through `config/market.php`, never `env()` directly — step 02 §02.1's rule,
restated because a `config:cache` that nulls a coordinator URL fails far from the symptom.

---

## 21.19 Files

> **Tagging (step 01 §01.4) — every file below.** Created files carry `MARKET_MODIFIER_ADDITION` in
> their top doc block; modified files carry it at each changed line or region.
> `grep -rn MARKET_MODIFIER_ADDITION` must return this list in full, or the change index is already
> wrong.

**Created (market):**
`app/Models/EngagementLink.php` + migration ·
`app/Services/Engagement/{EngagementProvisioner,EngagementBlueprintBuilder,EngagementSyncDispatcher,CoordinatorClient,CoordinatorSignature}.php` ·
`app/Jobs/DeliverEngagementEvent.php` ·
`app/Http/Controllers/Portal/EngagementController.php` ·
`app/Http/Controllers/Internal/{EngagementCommandController,AfsConnectProviderController}.php` ·
`app/Services/AfsConnect/MarketAfsConnectProvider.php` ·
`resources/js/portal/engagement.ts` · `resources/views/portal/engagement/*.blade.php` ·
`app/Console/Commands/ReconcileEngagements.php`

**Modified (market):**
`app/Services/ServiceOrders/ServiceOrderStateMachine.php` (dispatch hook) ·
`app/Services/ServiceOrders/{MilestoneService,DeliveryService,RevisionService,DisputeService}.php` (hooks) ·
`app/Services/Ledger/LedgerService.php` (hook) ·
`routes/{portal,internal,console}.php` · `config/market.php` · `.env` ·
`resources/views/portal/services/show.blade.php` · `resources/js/portal/serviceOrder.ts` ·
`lang/en/portal.php`

**Created (coordinator):**
migrations for `afsconnect_engagements`, `_facts`, `_participants`, `_commands`, and the `origin`
columns · `app/Models/AfsConnect/{AfsConnectEngagement,AfsConnectEngagementFact,AfsConnectEngagementParticipant,AfsConnectEngagementCommand}.php` ·
`app/Services/AfsConnect/Engagement/{EngagementProvisioningService,EngagementEventIngestService,EngagementMetricsService,EngagementRiskService,EngagementCommandDispatcher,PmClientIdAllocator}.php` ·
`app/Http/Controllers/Api/Internal/AfsConnectEngagementController.php` ·
`app/Http/Controllers/Api/AfsConnect/{AfsConnectEngagementController,AfsConnectEngagementMetricsController}.php` ·
`app/Jobs/AfsConnect/{IngestEngagementEvent,SendEngagementCommand}.php` ·
`app/Console/Commands/ReconcileEngagements.php` · `resources/js/src/workspace/*`

**Modified (coordinator):**
**`app/Services/Pm/PmPersistenceService.php`** (the three delete sweeps — §21.3.1; the highest-risk
change in this step) · `config/afs_connect_sources.php` · `config/afs_connect.php` (peer key) ·
`routes/api.php` · `.env`

**Modified (members):**
`resources/js/settings/afsConnectSettings.ts` (the `marketplace` group label) ·
the seller order view + its controller (§21.13's Schedule panel)

---

## 21.20 Build order

Fourteen slices. Each is independently verifiable, and the ordering is deliberate: the two hazards
(§21.3.1, §21.3.2) are neutralised before anything writes.

| # | Slice | Notes |
|---|---|---|
| **21-A** | `saveWorkspace()` optimistic-concurrency guard | **Recommended prerequisite.** The SPA sends the `revision` it loaded; a stale save is rejected `409`. Fixes a real pre-existing hazard for *all* PM data, not just market's. If descoped, say so explicitly and do not build 21-N. |
| **21-B** | `origin`/`origin_ref` columns + the three delete-sweep exclusions | Nothing else is safe first |
| **21-C** | `PmClientIdAllocator` (reserved band) + its collision test | |
| **21-D** | Coordinator's four engagement tables + models | |
| **21-E** | `market` peer key, market's `CoordinatorSignature`, round-trip signature test | |
| **21-F** | Provisioning: blueprint builder (market) → `EngagementProvisioningService` (coordinator) | Full workspace mode only |
| **21-G** | Import modes: add-to-existing, track-only | |
| **21-H** | The sync: dispatcher hooks + ingest + idempotency | The §21.8.3 table, all rows |
| **21-I** | `MarketAfsConnectProvider` — the four provider endpoints + registry entry | Market becomes a searchable source |
| **21-J** | `EngagementMetricsService` — §21.10, with a fixture-based test per formula | |
| **21-K** | Coordinator UI: overview, schedule, cost, people | |
| **21-L** | The command channel + `EngagementRiskService` + the risk surface | |
| **21-M** | Market's Project tab; freelancer Schedule panel (§21.13) | |
| **21-N** | *(optional, requires 21-A)* mirror approved hours into `pm_task_time_entries` | Do not ship without 21-A |

---

## 21.21 Verification

- [ ] A `$28,000` milestone gig provisions a project with the right tasks, dependencies, kanban columns, shadow users, team and budget lines — in one transaction, with one `afsconnect_engagements` row.
- [ ] Clicking "Manage as a project" twice creates **one** engagement (the unique constraint holds, not just a UI guard).
- [ ] **A market-created project survives a full SPA load → save cycle** — and survives a save from a tab loaded *before* provisioning. This is §21.3.1; if it fails, nothing else in the step matters.
- [ ] A market-created task survives the same, with `origin`/`origin_ref` intact after the round trip.
- [ ] Allocated `client_id`s never collide with `Date.now()`-derived ids, verified with the SPA's own generator at both ends of the band.
- [ ] Approving a milestone in market moves exactly one task to Done, writes exactly two facts (`milestone_approved`, `payment_released`), and changes released by exactly the milestone amount.
- [ ] The same event delivered three times produces one set of facts (`event_id` unique).
- [ ] Approving a milestone **from coordinator** goes through market's state machine: an illegal approve returns step 15's own `409 MARKET_E_ILLEGAL_TRANSITION`, recorded on the command row, shown to the buyer in market's own words.
- [ ] Coordinator cannot write `service_orders.status` or `seller_ledger_entries` by any path — verified by grep across coordinator for both table names, expecting zero write sites.
- [ ] Every §21.10 figure matches a hand-computed fixture: contracted, escrowed, released, fee, remaining, CPI, SPI, EAC, effective hourly rate, revision cost.
- [ ] Money is integer minor units end to end — no `decimal` column summed into a PHP float anywhere in the metrics service.
- [ ] A dispute freezes forecasting and shows the banner; facts keep recording.
- [ ] A refund reduces released and the cost view states it rather than showing an empty state.
- [ ] Deleting the PM project sets `detached_at`, stops sync, and **the next sync does not recreate it**.
- [ ] Disabling afs-connect stops sync and leaves the project intact; re-enabling backfills from `last_synced_at`.
- [ ] The reconciler detects a deliberately dropped event and reports it without auto-correcting market.
- [ ] A free-tier member sees an upsell naming the tier, not a bare refusal (step 60's three-state rendering).
- [ ] The registry consistency test (§12.5) passes with `market_orders` added — including the peer-key and base-URL assertions a `provider` source must satisfy.
- [ ] A freelancer sees only their own tasks: no budget, no cost, no other participants' work, no `origin = 'pm'` tasks. Verified by response-body inspection, not by UI.
- [ ] A hostile message body, task title and freelancer display name all render escaped.
- [ ] Every file created or modified carries `MARKET_MODIFIER_ADDITION` (§01.4). Verify by `grep -rn` and reconcile against §21.19 in both directions — a listed file without a tag, and a tagged file not in the list, are both defects.
- [ ] `complete/21_freelance_project_workspace_complete.md` written, stating which slices shipped, whether **21-A** shipped, and whether the `afs_connect_engagements` allocation resource was adopted or the count limit left unenforced.

# Feature 2 — `domain_email_accounts` (Email Accounts)

> Renumbered 2026-09-22: was `membership_change/05-feature-email.md` (step 05 in the main
> sequence). Moved to `features/`, numbered by its `TODO-member-resources.md` entry (#2)
> instead of a step number — see `../README.md`.

> **STUB, FOR AUDIT.** Registry v4 settled the numbers: `tier_mode: absolute`,
> `grace_days: 30`, `inventory: www/users_email`. §F2.3 (**OQ-B** — what "disabled" does to
> arriving mail) is **resolved as of 2026-09-20** — inbound delivery is never touched by this
> plan, at any lifecycle stage. §F2.9's `M7`/`M8` were prerequisites under the old reading and
> are no longer needed for this feature under the resolved one.

## F2.1 The feature in one paragraph

A domain's plan selects **how many email-account slots it owns**. Slot band `n` is unlocked
by plan `n`, and its size is
`checkpoint_registry_v4.json → resources.domain_email_accounts.tiers[n].value`. New accounts
land in the lowest band with a free slot and cascade upward as each fills. The domain's
administrator can **move accounts between the bands they own**, so they decide which
addresses matter. If the plan drops, the bands above it are **retired** — the accounts in
them are **disabled**, readable but not usable, for a grace window — and only then deleted.
The administrator chooses what survives, in advance, instead of finding out which 200
addresses stopped working by receiving a phone call about it.

## F2.2 The numbers

Registry: `domain_email_accounts` = `[2, 10, 50, 250, -1]`, scope `per_domain`,
overage `block`.

**Settled by registry v4: `tier_mode: absolute`.** For the record, the two readings were:

| Plan | Label | Bands owned | Cumulative | Absolute |
|---|---|---|---|---|
| 0 | free | 0 | 2 | 2 |
| 1 | starter | 0,1 | 12 | 10 |
| 2 | professional | 0,1,2 | **62** | **50** |
| 3 | business | 0,1,2,3 | **312** | **250** |
| 4 | enterprise | 0–4 | Unlimited | Unlimited |

Absolute won because `[2, 10, 50, 250]` is plainly a table of totals — nobody reading a
pricing page thinks tier 2 means 62. Storage stays `cumulative` because its bands are five
real directories that coexist and genuinely sum ([storage](25-storage_files_size_kb.md) §F25.8).

Values are read from the registry at runtime (**D5**). The table above is illustrative.

## F2.3 What "disabled" means — **RESOLVED 2026-09-20**

Retiring a band has to answer three separate questions:

| Question | Options | Decision |
|---|---|---|
| **Inbound mail?** | bounce (remove the route) · queue · accept and file | **Accept and file, permanently, at every lifecycle stage — active, retired, or purged.** Once an account is created, whatever delivers mail to it keeps delivering to it. This plan never suppresses or removes inbound routing, so it never touches the mail-transport layer at all |
| **Can the member log in and read?** | yes · no | **Gated in the application's own database** — an `active`/status check on the `users_email` row, enforced wherever the account authenticates. No mail-transport change |
| **Can the member send?** | yes · no | **Gated in the application's own database, at send time** — a check against the account's band/quota state, enforced at the point outbound mail is handed to whatever processes it for sending, before it leaves the server. No mail-transport change |

This settles the analogue to **D3** ("a retired band stays *readable and evacuable*") without
ever needing to reach outside this system: reading, sending, and login are all
application-side checks against the same `users_email` row this plan already adds
`band`/`status` columns to.
Nothing here is irreversible — restoring is the same check flipping back.

> Superseded reasoning (kept for the record): earlier drafts of this section argued email was
> "live infrastructure" requiring a mail-transport-level change to actually suppress inbound or
> outbound delivery, on the theory that a database flag with no corresponding external change
> would be cosmetic. That reasoning doesn't apply here: inbound is never suppressed in the first
> place (so there is nothing to make cosmetic), and outbound already passes through an
> application-controlled processing step this plan can gate directly — see §F2.3.1.

### F2.3.1 What it costs at the shell level — nothing

Because inbound is never touched, there is no `--delete-email-account` shell mechanism to
build for that purpose, and **M7**/**M8** (§F2.9) are not prerequisites for this feature — they
may be worth fixing as standalone defects, but nothing in this plan depends on them anymore.

What this plan actually builds is entirely inside the application:

- disable / restore — flip `status` on the `users_email` row (already the adapter's `retire()`
  / `restore()`, minus the Postfix TODOs those methods no longer need)
- purge — remove (or mark removed) the `users_email` row once grace expires; the underlying
  mailbox and its inbound routing are **not** touched by this step
- a send-time check — wherever outbound mail is handed off for processing, look up the
  account's `status`/band before allowing it through

All three are ordinary application code against tables this plan already owns.

### F2.3.2 Where the checks actually go — located 2026-09-21

Per direct instruction: login and send are both checked server-side, because sending will be
possible from more than one application — a client-side check (the existing `allocV2.canUse()`
pattern in `email.ts`) cannot be the enforcement point for either.

> **RESOLVED 2026-10-05, by the owner: there is NO login gate.** A disabled (retired or turned-off) mailbox can
> still log in and read; it cannot send. This follows D4 ("a retired band stays readable and evacuable") and the
> §F2.5 mock-up, and supersedes the "preventing login" reading in the paragraph below. `login()` stays unchanged; the
> send gate (below) is the enforcement. The 120-minute cookie caveat therefore no longer matters for this feature.

**Login — `w/www/app/Http/Controllers/Portal/Mail/MailMuaController.php::login()` (lines
55-89).** Queries `UsersEmail::where('userid', ...)->where('domain', ...)->where('verified', 1)`
and compares the plaintext password. Add `->where('status', 'active')` (or an equivalent
explicit check with its own rejection message) here.

- **Caveat to record, not solve silently:** `PortalMailAuth` middleware (`app/Http/Middleware/PortalMailAuth.php`)
  only decrypts the `portal_mail_auth` cookie — it never re-queries `users_email`. The cookie
  is issued with a 120-minute TTL (`MailMuaController::login()` line 88). So a login-time-only
  check means a session that started *before* a disable keeps working for up to 120 minutes
  after it. This matches what was actually asked for ("preventing login"), but it is a real
  gap between "disabled" and "locked out" that should be a recorded decision, not a surprise —
  closing it would mean the middleware itself re-checking `status` per request, which is a
  separate, larger change (a DB query on every mail-UI request instead of only at login).

**Send — same file, `MailMuaController::sendEmail()` (lines 188-235).** This is the **one
confirmed** outbound-send call site in the `www` repo — it sends through a SendGrid-backed
integration (`FunctionsController::sendEmailWithIntegration()`), not raw SMTP; there's also a
private, unrouted `_sendOwn()` PHPMailer method in the same file that nothing calls (dead code,
out of scope here). Add the `status`/band check right after `$auth = $this->authPayload();`
(line 196), before the integration call.

- **Open item, not yet resolved:** "it will be possible to send email from multiple
  applications" means other repos among the ten under `w/` (`members`, `coordinator`, `auth`,
  etc.) may independently send mail using `users_email` credentials. Only `www`'s
  `MailMuaController::sendEmail()` is confirmed so far. Each repo is an independent Laravel
  app with its own codebase — a check added in `www` does not protect a send call site living
  in another repo. **Before this is actually done, the other nine repos need to be searched
  for direct `users_email` sends**, and each one found needs its own copy of the same check.
- Recommend the check itself live as one method on `app/Models/UsersEmail.php` (e.g.
  `isActive(): bool` / `canSend(): bool`) so every call site *within* `www` shares one
  implementation instead of repeating a raw column comparison — the model currently has no
  such method, and `band`/`status` aren't even in its `$fillable` yet (§F2.4).

**Account management UI — `w/www/resources/js/portal/admin/views/email.ts` is the only place
this is managed**, confirmed by direct instruction. Concretely:
- `renderEmailAccounts()` (line 621) needs a Band and Status column — the data is already
  there for free, since `EmailAccountController::index()` (line 67) returns
  `$account->toArray()` unmodified (minus password), and the `band`/`status` columns already
  exist on the table.
- **Planned fix — `app/Models/UsersEmail.php`:** add `band` and `status` to `$fillable`
  (currently excludes both, so mass assignment silently drops them today) and to `$casts`
  (`band` as `integer`; `status` needs no cast, it's already a plain enum string).
- **Planned fix — `EmailAccountController::update()` (lines 91-115):** add `band` and
  `status` to the endpoint's fillable-field list so it can actually accept them (it can't
  today, independent of the model fix above — the controller has its own hardcoded field
  list). This resolves the earlier open question in favor of extending the existing
  endpoint rather than adding a separate one.
- **Not optional, part of the same fix, not a later add-on:** a `band` change through this
  endpoint must be validated against the target band's free-slot count before being applied.
  Accepting any `band` value with no capacity check would silently break §F2.5's premise
  ("choose what survives") — an admin could move an account into a band that's already full.
  Validation logic belongs wherever the retention engine's `move()` semantics live
  ([02-architecture.md](../02-architecture.md) §2), called from `update()`, not reimplemented
  inline in the controller.
- This replaces the earlier (incorrect) plan of a separate `EmailBandManager.ts` file — there
  is only one file to extend, not a new one to create.

**`release()` wiring (M9/E3) — `EmailAccountController::destroy()` (lines 121-129).** Today
this hard-deletes the row with no allocation call at all. `consume()` is currently called
*client-side* from `email.ts` after a successful create (already a known weak spot — M13,
client-side checks can be bypassed). `release()` should not repeat that mistake: it belongs
**server-side, in `destroy()`**, not added to `email.ts`'s delete-button handler.

**FIXED 2026-09-22, per direct instruction, exactly as planned above** — see
[complete/2-domain_email_accounts.complete.md](complete/2-domain_email_accounts.complete.md)
for the full account: `destroy()` now calls a new `App\Services\Checkpoint\CheckpointAllocClient::release()`
server-side, verified with a live `allocConsume`→`allocRelease` round trip. Building this also
surfaced that `AllocationV2Controller`'s `/checkpoint/alloc/v2/consume`/`/release` HTTP routes
(the mechanism this section originally assumed `release()` would call) had already been removed
from `auth/routes/checkpoint.php` — the actual write path is the `allocConsume`/`allocRelease`
Parse Cloud Functions instead (see [07-signup-flow.md](../07-signup-flow.md)'s S34 correction).

## F2.3.3 `AFS_ALLOC_EMAIL` marker — every touched file, per direct instruction

Three files already carry a one-line `AFS_MODIFIER_ADDITION · plans/membership_change/2-domain_email_accounts.md`
docblock tag (`members/app/Checkpoint/{RetentionEngine,InventoryAdapter}.php`,
`members/app/Checkpoint/Email/EmailRetentionAdapter.php` — relocated from `www` 2026-09-21,
see [complete/25-storage_files_size_kb.complete.md](complete/25-storage_files_size_kb.complete.md)'s
Status section) — but that convention marks a **new file**,
once, in its class docblock. This feature also modifies several **existing** files line-by-line,
which that convention doesn't cover. Per instruction, every one of those gets its own
`AFS_ALLOC_EMAIL` marker (an inline `// AFS_ALLOC_EMAIL` comment at the changed block, in
whichever comment syntax the file uses) so every place touching email allocation can be found
with one grep, independent of the file-level tag. Expected to land on:

- `w/www/app/Http/Controllers/Portal/Mail/MailMuaController.php` — login check, send check
- `w/www/app/Http/Controllers/Portal/Admin/EmailAccountController.php` — `destroy()`'s
  `release()` call, and whatever `update()`/new-endpoint change resolves the band-move question
- `w/www/app/Models/UsersEmail.php` — `band`/`status` added to `$fillable`/`$casts`, plus the
  new `isActive()`/`canSend()` method
- `w/www/resources/js/portal/admin/views/email.ts` — band/status columns, move/disable controls
- `w/members/app/Checkpoint/Email/EmailRetentionAdapter.php` — relocated from `www` and E4
  fixed 2026-09-21; this marker should land here now
- any send call site found in another repo per the open item above

**Staleness note, 2026-09-22 full-plan audit — since fixed, same day:** a `grep -r
AFS_ALLOC_EMAIL` across `www` and `members` found **zero** matches at audit time — this marker
had never actually been applied to any file touched by this feature, despite the direct
instruction above. **Retrofitted 2026-09-22**, per direct instruction, to every existing file
with a real line-level change for this feature: `www/app/Http/Controllers/Portal/Admin/EmailAccountController.php`
(both the `index()` reconcile call and the `destroy()` release call), `www/config/services.php`
(the `alloc_service_key` addition), `members/app/Checkpoint/Email/EmailRetentionAdapter.php`
(which also had a stale path reference fixed in the same pass — its docblock still cited the
pre-renumbering `05-feature-email.md`), `members/bootstrap/app.php` (the `www.internal` route
group and middleware alias), and `members/routes/console.php` (the sweep schedule). New files
created for this feature (`EmailRetentionService.php`, `CheckpointAllocClient.php`, etc.) were
left alone — they already carry the one-time `AFS_MODIFIER_ADDITION` class-docblock tag, the
convention this section itself says new files should use instead.

## F2.4 Band assignment

**M11**: nothing today records which band an account belongs to. Without it, a downgrade's
only available rule is "disable the newest N", which takes the choice away from the member —
exactly what **D3**/**D10** forbid.

So: each `users_email` row gains a band assignment.

- **New accounts** are assigned by the shared cascade — lowest band with a free slot.
- **Existing accounts** need a backfill. Ordering by `id` ascending fills band 0 first,
  which is the least-surprising default, but it is a *default*, and the point of §F2.5 is
  that the administrator can change it before any downgrade matters.
- **Moving** an account between bands updates one column. Nothing physical moves —
  no mailbox is touched, no Postfix change, no downtime. Email's move is trivially atomic in
  a way storage's (which depends on all bands sharing a filesystem) is not.

## F2.5 Choosing what survives

The whole point. Before a downgrade takes effect, the administrator sees which accounts are
in which band and can rearrange them.

```
┌──────────────────────────────────────────────────────────────────────┐
│  EMAIL ACCOUNTS — example.com          Plan: Business (3)  [Manage ▸]│
│                                                                      │
│  Band 0   ██████████████████████████████  2 of 2                   │
│             info@ · support@                      [ Move accounts ]  │
│  Band 1   ████████████░░░░░░░░░░░░░░░░░░  6 of 10                  │
│             sales@ · billing@ · …                 [ Move accounts ]  │
│  Band 2   ██░░░░░░░░░░░░░░░░░░░░░░░░░░░░  4 of 50                  │
│  Band 3   ░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░  0 of 250                 │
│                                                                      │
│  ⚠ If you downgrade to Professional, band 3 is retired. You have   │
│    0 accounts there, so nothing would be affected.                   │
│    [ See what would be affected ]                                    │
└──────────────────────────────────────────────────────────────────────┘
```

And the case that actually matters — where the member cannot rescue anything:

```
┌──────────────────────────────────────────────────────────────────────┐
│ ⚠  12 accounts are disabled                                          │
│                                                                      │
│    Your plan changed from Business to Free on 22 Aug 2026. Bands   │
│    1, 2 and 3 are retired and hold 12 accounts.                      │
│                                                                      │
│    They can still receive and be read, but cannot send.              │
│    They will be deleted on 21 Sep 2026 (29 days from now).           │
│                                                                      │
│    Band 0 is full (2 of 2), so you cannot move any of these into   │
│    it without deleting an account first.                             │
│                                                                      │
│    [ Export mail ]  [ Choose what to keep ]  [ Upgrade to restore ]  │
└──────────────────────────────────────────────────────────────────────┘
```

The third paragraph is `can_evacuate: false` rendered honestly
([03](../03-api-contracts.md) §3.3). Offering "move your accounts" when band 0 is full is
advice that cannot be followed.

## F2.6 Upgrade

Symmetric and cheap. The band returns to `active`, its accounts are re-enabled (`status`
flips back, restoring login and send), the purge clock is cleared, `band_restored` is logged.
Inbound was never touched, so there is nothing to restore on that side. Nothing was moved, so
restoration is a state change, not a recovery.

This is why purge is two-phase — an upgrade during the hold window still gets everything
back.

## F2.7 Suspension

`account_status = 2` freezes **every** band regardless of plan (**D4**). For email that
means every account is disabled — and **no purge clock starts or advances**. A suspended
domain's mail is frozen, not scheduled for deletion.

## F2.8 Where the enforcement lives — **OQ-A**

Today `allocV2.canUse()` in `email.ts` is the entire limit: client-side, at the moment of
save, and failing **open** on one of its two paths (**M13**).

Registry v4 settles the *backward* edge — `inventory: www/users_email` is authoritative, and
retirement reads the tier server-side (**D7**). It does **not** settle the *forward* edge.

Two coherent options:

| | `canUse` asks… | Consequence |
|---|---|---|
| **A** | the inventory (`SELECT count(*) FROM users_email WHERE …`) | Both edges agree by construction. Drift becomes impossible. Adds a local query where there is now one Parse call |
| **B** | Parse, as today | No change to the forward edge, but two counts of the same thing — and nothing reconciles them (**M20**) |

Since `tier_mode: absolute` matches what alloc_v2 already computes, the two do **not**
disagree on the number today. The risk is drift in the *count*, not the *limit* — which is
exactly what a missing `release` (**M9**) already causes.

## F2.9 Prerequisites — defects that block this feature

From [01](../01-current-state-audit.md). None of these are caused by this plan; all of them are
in its way.

| # | Defect | Needed for |
|---|---|---|
| ~~**M7**~~ | ~~`--delete-email-account` is an empty stub~~ | **No longer a prerequisite (2026-09-20).** Disable/restore/purge are all DB-only under the resolved §F2.3; nothing in this plan calls a delete-email-account shell mechanism. May still be worth fixing as its own defect, independent of this plan |
| ~~**M8**~~ | ~~`destroy()` leaves the `vmailbox` route — mail is still delivered to "deleted" accounts~~ | **No longer a prerequisite (2026-09-20).** Under the resolved §F2.3, mail is *supposed* to keep being delivered forever — this is no longer a defect from this plan's perspective |
| ~~**M9**~~ | ~~`release()` is never called — the quota only ratchets up~~ | **FIXED 2026-09-22** — see §F2.3.2 above and complete/2-domain_email_accounts.complete.md |
| **M10** | `users_email` has no status column | Recording "disabled" |
| **M11** | No band assignment | §F2.4 |
| **M13** | `canUse()` fails open on the add path | **D6** says a `block` resource fails closed |
| **M14** | Quota panel renders only for `appfactory_studio` — every other provider shows nothing | A downgrade would be invisible to a SendGrid member |

## F2.10 Open questions specific to email

- ~~**OQ-B**~~ (§F2.3) — what disabled does to arriving mail. **Resolved 2026-09-20**: inbound
  is never touched; login and send are both DB-driven checks in application code.
- ~~OQ7 — multi-domain members.~~ **Settled**: `scope_graph` makes `per_domain` mean per
  *instance*. Three domains = three independent band sets; the preview aggregates across
  them. See §F2.12 for the cascade case this creates.
- **OQ-G** — do system/required addresses (`postmaster@`, `abuse@`, the account behind
  `laravel_mail_handlers` sends) get pinned to band 0 so they can never be retired?
  Disabling the address the platform itself sends from would be a self-inflicted outage.
- **OQ-H** — what happens to an account that is *disabled* when the member tries to delete
  it? (Presumably allowed and encouraged — it reduces what will be purged, matching
  cloud-storage §8's "member deletes files while retired".)
- ~~Grace window~~ — **settled** at `grace_days: 30`. Revisit only if lost correspondence
  proves costlier than lost files.

---

## F2.11 What registry v4 settled

| Question | Answer |
|---|---|
| Cumulative or absolute? | **`absolute`** — plan 2 is 50 accounts, not 62. `[2,10,50,250]` is a table of totals |
| Grace window | **30 days**, same as storage. Revisit if lost correspondence proves costlier than lost files |
| Where do the items live? | **`www` / `users_email`** — one of only 4 resolved inventories |
| Multi-domain members | `scope: per_domain` means **per instance**. Three domains = three independent band sets, and a member-level downgrade retires in all three |

## F2.12 Email is a cascade child

`domain_email_accounts` sits under `per_domain`, whose parent resource is
`member_domains_count`. So mailboxes retire for **two** independent reasons:

1. **The domain's own email allowance drops** — plan 3→1 means 250→10 accounts on that
   domain; 240 retire and the member chooses which.
2. **The domain itself retires** — the member's `member_domains_count` allowance dropped and
   this whole domain is above it. **Every mailbox on it retires regardless of the email
   allowance**, and per [02](../02-architecture.md) §2.6 rule 2 they inherit the *domain's*
   `purge_after`, not their own 30 days.

Case 2 is the one that makes per-feature retirement code impossible: nothing in the email
adapter can know it is happening.

The member-facing consequence differs too. In case 1 they choose which mailboxes survive on
a domain they keep. In case 2 the domain is going away, so the only rescue is upgrading or
moving the *domain* — and the UI must say that rather than offering a mailbox chooser that
cannot help.

## F2.13 Platform-critical addresses

> **DECIDED 2026-10-05, by the owner: pin them. Built (unrun)** — see the completion report. Pinned names are
> configured in `members/config/email_retention.php` (default `postmaster`, `abuse`, `no-reply`, `noreply`; override
> with `EMAIL_PINNED_USERNAMES`). They always sit in band 0 and stay active; they cannot be moved, disabled or deleted;
> the sweep and backfill put a misplaced one back.


`postmaster@`, `abuse@`, and whatever address `laravel_mail_handlers` sends the platform's
own mail from must be **pinned to band 0** so they can never retire.

Disabling the address billing sends dunning notices from, as a consequence of a billing
downgrade, is a self-inflicted outage with a particularly unfortunate feedback loop.

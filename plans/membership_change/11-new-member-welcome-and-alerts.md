# 11 — A new member is finished properly, welcomed, and announced (Brain only)

> **STATUS: BUILT 2026-10-20 — see [complete/11-new-member-welcome-and-alerts.complete.md](complete/11-new-member-welcome-and-alerts.complete.md) for what was built and where it differs from this text.** Written from the code in `w/www` as it is today. Paths are relative to `w/www` unless stated.
> The phone side is [12](12-phone-add-member-and-alerts.md) (alerts) and [14](14-phone-and-dashboard-member-lifecycle.md) (add, pay, cancel, change); the paid side of creating a member
> (free periods, a card now or later, Stripe) is [13](13-staff-member-lifecycle-server.md). **Owner answers of 2026-10-20 are applied below (§7).**

## 0. Scope: this is the Brain's module, not the tenants'

`memberships` (the Brain's own members, in `brain_memberships`) belongs to Appfactory Studio only. No tenant has it. The general notification system it plugs into
([`www/notifications_and_analytics`](../www/notifications_and_analytics/README.md), steps 02, 03, 06 and 11) is **shared code that every tenant clone carries**, so this
step keeps the two apart:

- The Memberships module lives under **`app/Services/Brain/Memberships/Notifications/`** (the folder where Brain-only code already lives) and is registered in
  `ActivityServiceProvider::modules()` **only when `config('brain.is_brain')` is true**. On a tenant the catalogue never contains `memberships`, the rules editor never
  lists it, and publishing it would be dropped by the publisher's "unknown module" check.
- Tenant-facing code gets no new line except that one conditional registration.
- The built-in e-mail file (`defaults/memberships/…mjml`) ships in the admin bundle that tenants also carry, because the bundle is one build. It is inert there (nothing reads
  it without the module). If that is not acceptable the file can live in a Brain-only template folder; see OQ-N6.

## 1. What is wrong today (read from the code, 2026-10-20)

| # | Finding | Where |
|---|---|---|
| **NA1** | **Two ways to create a member, and the one the phone uses does less.** Public signup `store()` and the admin create `adminStore()` (used by the Brain admin form **and the phone app**) are separate code. `adminStore()` skips three things `store()` does. | `app/Http/Controllers/Portal/Admin/MembershipController.php` `store()` ~244, `adminStore()` ~645 |
| **NA2** | `adminStore()` never resolves a **payer** (`PayerService::resolveForCheckout`), so `payer_reference_num` stays empty. Everything that finds a member through its payer (subscription control, billing, a later upgrade) cannot find an admin-created one. | `store()` ~323, absent in `adminStore()` |
| **NA3** | `adminStore()` never **provisions the member's storage** (`--create-afs-member-storage <ref>` through `RunCommandBG`). A member created from the phone or the admin form has no storage folder until something else makes one. | `store()` ~513, absent in `adminStore()` |
| **NA4** | `adminStore()` never propagates the e-mail to the payer's provider customers (`PayerService::updateEmail`). | `store()` ~496, absent |
| **NA5** | **No welcome e-mail ever goes out.** `store()` only tries one for tier 0 or a posted `testing` flag; it needs a `mail_handler_reference_num` that no client sends (so it throws, is caught, and is logged as "welcome e-mail not sent"); and its recipient is hard-coded to `james@appfactory.studio`. `adminStore()` has none. | `store()` ~519; `contructMailHandler()` ~1271, recipient ~1330 |
| **NA6** | `store2()` is an unrouted stub that returns a mail handler's JSON; dead code. | `store2()` ~701 (no route) |
| **NA7** | Nothing **announces** a new member: no dashboard notification, no analytics event. The only e-mail a paid signup produces is the Direct Payments receipt, which knows the invoice, not the member. | no `ActivityPublisher` call in `MembershipController` |
| **NA8** | The phone has no way to learn that a member signed up unless you open the member list. (See 12.) | `afsbrain_flutter` has no notification code and only the `INTERNET` permission |

## 2. Decisions

- **NM1 — One "finish creating a member" routine, used by both paths.** It is extracted from what `store()` already does, so the public signup is unchanged and the admin/phone path
  gains NA2–NA4. It does not duplicate code; `store()` and `adminStore()` both call it.
- **NM2 — The member is announced once, through `ActivityPublisher`, as `memberships.member_created`.** That one call gives: the welcome e-mail to the member, a bell for the
  Brain's administrators, and an analytics count. It replaces the dead welcome block (NA5) and `contructMailHandler()` (NA6 goes with it).
- **NM3 — The welcome e-mail is the event's built-in e-mail, switchable.** On by default (owner's OQ7, 2026-10-19: event defaults on). The owner can turn it off or point it at his own
  mail handler from the same per-event card every other module has ([notifications 07](../www/notifications_and_analytics/07-admin-ui.md)). It carries no password, ever
  ([notifications 11 §11.1.3](../www/notifications_and_analytics/11-module-adoption.md): secrets never travel through an Activity).
- **NM4 — The Activity carries facts only:** the member's reference and the plan number. The resolver and the variable builder look the member up; the e-mail address, name
  and username are never copied into the Activity, the queue or analytics.
- **NM5 — A quiet create.** The operator may create a member without e-mailing them (a test account, a member who asked for no e-mail). The create request takes
  `send_welcome` (default true). When false the Activity says `quiet: yes`; the recipient resolver then returns no address, the send is recorded as skipped, and the bell and
  analytics still fire.
- **NM7 — Two ways to set a password, chosen per member.** (a) *Set it now:* the operator types one or has one generated; it is given to Checkpoint at registration and shown once to the operator
  (as the phone does today); it is never e-mailed and never stored. (b) *Send a set-your-password link:* the account is registered with a random password nobody sees, and the member is e-mailed a
  link to choose their own. The operator may pick either, and may pick (a) **and** send the link as well. Which e-mail carries the link and who builds the link is §3.4, because Checkpoint (the `auth`
  repo) owns passwords, not the Brain.
- **NM8 — Every URL comes from `.env`, through config.** Nothing in this step writes a host or a path as a literal: the member's sign-in address, the set-password page, the Brain's own address and the
  card page address (step 13) are read as `config('memberships.…')` / `config('app.url')`, which read `.env` in their config files only (never `env()` elsewhere). The keys are in §3.5. On the phone the base
  address already comes from the app's `.env` (`PORTAL_API_URL`), and the same rule holds for every new address the app needs.
- **NM6 — Free and paid are the same event.** A member exists the moment the row exists; payment is the Direct Payments receipt's business (a separate e-mail, 30 seconds later,
  already working). The welcome says nothing about money.

## 3. Design

### 3.1 The shared routine

A new class `App\Services\Brain\Memberships\MemberProvisioning`, constructor-injected (`PayerService`, `ActivityPublisher`, `MembershipsActivities`), two methods:

```php
// before the row exists: the payer the member will hang off (free signup: by e-mail, one payer per e-mail)
public function payerFor(array $data, string $createdBy): ?string;

// after the row exists, in this order; each step best-effort except where noted
public function finish(Membership $member, array $data, ?string $payerReferenceNum): void
{
    // 1. propagate the e-mail to the payer's provider customers          (store() ~496 today)
    // 2. provision storage: FunctionsController::RunCommandBG("--create-afs-member-storage {$member->reference_num}", ...)   (store() ~513)
    // 3. announce: $this->publisher->publish($this->activities->created($member, quiet: ...))
}
```

`store()` keeps its own tier-from-subscription logic and Checkpoint step (they are specific to a paid signup) and calls `payerFor(..., 'afs_signup')` and `finish()`. `adminStore()` calls
`payerFor(..., 'admin_create')` and `finish()`. Both are constructor-injected into `MembershipController`; **no `app()`** anywhere in this step.

If a step after the row exists fails, the member still exists and the response still says so (as `store()` already does for the welcome e-mail): the failure is logged, and the storage step
keeps its current behaviour.

### 3.2 The module (Brain only)

Files in `app/Services/Brain/Memberships/Notifications/`: `MembershipsActivityModule`, `MembershipsActivities`, `MembershipsRecipientResolver`, `MembershipsVariableBuilder` — the same four classes
every other module has ([notifications 11](../www/notifications_and_analytics/11-module-adoption.md)), small.

- One event, **`member_created`**: label "Member created"; bell default **on** (title "New member", body "`${memberships_username}` joined on the `${memberships_plan}` plan.", link to the member);
  e-mail default **on**; default template `memberships/member_created`; variables `memberships_full_name`, `memberships_username`, `memberships_plan`, `memberships_login_url`, `memberships_free_period` (empty, or "Your 3 month free period runs until 20 Jan 2027." — [13](13-staff-member-lifecycle-server.md) §7).
- `MembershipsActivities::created(Membership $m, bool $quiet)` builds `Activity(module: 'memberships', event: 'member_created', subjectRef: $m->reference_num, data: ['member' => ref, 'plan' => n, 'quiet' => 'yes'|'no'], dedupeKey: ...)`.
  Publishing twice is one e-mail and one bell.
- `MembershipsRecipientResolver::customerEmail()` returns the member's e-mail by reference, or `null` when `quiet` is `yes`.
- `MembershipsVariableBuilder` fills the four variables; the plan label comes from `MembershipController::tierLabels()` (the server's authority, which the phone already reads).
- Registration, in `ActivityServiceProvider::modules()`:

```php
$modules = [ /* the existing shared modules */ ];
if (config('brain.is_brain')) {
    $modules[] = $app->make(\App\Services\Brain\Memberships\Notifications\MembershipsActivityModule::class);
}
```

- Built-in e-mail: `resources/js/portal/admin/afsemail/mjml-templates/mjml/defaults/memberships/member_created.mjml` and `defaults.json` (subject "Welcome to `${name}`"), the same shape as
  `defaults/sponsorships/`.

### 3.3a Every signup rings the bell

`inboxDefault: true` (already in §3.2) applies to every `member_created`, free or paid, public signup or created by an operator. No filter, no threshold. The owner can still switch it off in the settings card.

### 3.3 The Brain admin sees it too

The Brain's existing admin bell (`laravel_notifications`) shows the new row with no extra work. The Brain membership admin screen gets the same per-event settings card
(`appendNotificationsCard(..., { module: 'memberships' })`) so the welcome e-mail and the bell can be switched there.

### 3.4 The set-your-password link (NM7 b) — decided from the `auth` code

Read from `w/auth` (2026-10-20):
- Checkpoint has `POST /checkpoint/auth/password/forgot` and `/password/reset` (selector, token, new password; 8–72 characters, no spaces). `forgot` **does send an e-mail itself** (`EmailService::sendPasswordResetEmail`, SendGrid; it is
  skipped with a log line if no SendGrid key is configured).
- **That e-mail's link points at `{Checkpoint base URL}/checkpoint/auth/reset-password?selector=…&token=…`, and the `auth` repo has no page at that address** (the only mention is in an obsolete client). The link would 404 today.
- The Brain's `CheckpointServerClient` only knows register, login and member-init.

So the work lands in the `auth` repo, because Checkpoint owns passwords:
1. **A page** in `auth` at the link's address that shows a "choose your password" form and posts selector, token and password to `/password/reset`. Its host is Checkpoint's own `.env` (`url.production`/`url.development`), not a literal.
2. **A set-password e-mail** distinct from "forgot password": `EmailService::sendPasswordSetupEmail` ("Welcome — set your password"), 24-hour link.
3. **A signed-service route** `POST /checkpoint/member/password-setup` (the same system-key pattern as `member/init`) that creates the reset request for an e-mail and sends that e-mail.
4. **Brain side:** `CheckpointServerClient::requestPasswordSetup(email)` calls it. The secret pair never reaches the Brain, never enters an Activity, the queue or analytics (notifications 11 §11.1.3).

Either way the welcome e-mail and the set-password e-mail are **two separate e-mails** with two jobs (a welcome is announced as an Activity; a link carrying a secret is not). The member is registered with a random password
nobody sees when the mode is `send_link`.

### 3.5 Config keys (all read from `.env` in `config/memberships.php`)

| Key | `.env` name | Meaning |
|---|---|---|
| `memberships.login_url` | `MEMBERSHIPS_LOGIN_URL` | where a member signs in (used in the welcome e-mail) |
| *(step 13)* `memberships.card_page_url` | `MEMBERSHIPS_CARD_PAGE_URL` | added by [13](13-staff-member-lifecycle-server.md), not by this step |

(The processor is not a key: Direct Payments routes it, [13](13-staff-member-lifecycle-server.md) NM10.) `app.url` (existing) is the fallback for the first two when unset, with a warning in the log; the file `config/memberships.php` is the only place `env()` is called.

## 4. Phases (inside this step)

| Phase | Work | Proof |
|---|---|---|
| **P0** | Characterization tests first (the §3.4 `auth` work is its own phase P2b, below): what `store()` and `adminStore()` do today, field by field — rows written, payer, storage command issued (through the existing `RunCommandBG` seam), e-mails sent (none). | green on the current code |
| **P1** | Extract `MemberProvisioning`; `store()` calls it (P0's `store()` tests pass **unedited**); `adminStore()` calls it and now gets payer, e-mail propagation and storage. | the `adminStore()` tests change on purpose: payer set, storage command issued |
| **P2b** | The `auth` repo work of §3.4 (page, set-password e-mail, signed-service route) and `CheckpointServerClient::requestPasswordSetup`. Its own tests in `w/auth`. | the page posts to `/password/reset`; the route needs the system key |
| **P2** | The Memberships module, Brain-only registration, built-in template. | catalogue test: present when `is_brain`, absent when not |
| **P3** | `finish()` announces, and `adminStore()` sends the set-password link when asked (§3.4); delete the dead welcome block, `contructMailHandler()` and `store2()`. | one welcome to the member's own address from `store()` (free and paid) and from `adminStore()`; none to `james@appfactory.studio`; `send_welcome=false` sends none but the bell remains; publishing twice sends once; no password in the Activity, the queue or analytics |
| **P4** | The settings card on the Brain membership screen; i18n keys (5 languages). | `tsc` clean; `npm run test:admin`; card test like the other modules |
| **P5** | Documentation and the completion record `complete/11-new-member-welcome-and-alerts.complete.md`. | — |

## 5. Tests (written with the code, run once at the end)

PHPUnit against the real database (never SQLite). New: `tests/Feature/Memberships/MemberProvisioningTest`, `MembershipsAdoptionTest` (the welcome, quiet, dedupe, no secrets, free and paid),
`MembershipsBrainOnlyTest` (catalogue with and without `is_brain`). Gates: the existing `G10` (no secret in an Activity) covers the new module with no change.

## 6. Risks

- **Behaviour change for the phone/admin create:** it now provisions storage and a payer. That is the intent (NA2, NA3), but it is a visible change: P0's tests make it explicit.
- **Storage provisioning runs a shell command** (`RunCommandBG`). It already does for public signups; the same escaping rule applies (use the stored `reference_num`, never the request field).
- **The first real welcome e-mail** depends on the Brain's mail provider token being valid; a failure is recorded in the send ledger as failed, not thrown.

## 7. Questions and the owner's answers (2026-10-20)

| # | Question | Answer |
|---|---|---|
| **OQ-N1** | The sign-in address for `memberships_login_url` | **From `.env`** (`MEMBERSHIPS_LOGIN_URL`), §3.5. The value is yours to set; the code names none. |
| **OQ-N2** | Bell for every signup, including free | **Yes**, §3.3a |
| **OQ-N3** | Admin-created member: password link or a password handed over | **Both options, chosen per member** (NM7) |
| **OQ-N4** | One welcome text for free and paid, or two | open; one until you say otherwise |
| **OQ-N5** | Other member events later (plan changed, status changed, past due) | open; only `member_created` here |
| **OQ-N6** | Template in the shared admin bundle or a Brain-only folder | open; shared bundle |
| **OQ-N11** | Does Checkpoint itself send the reset e-mail, and with what link? | **Answered from the code:** yes, but the link target page does not exist; §3.4 adds it in `auth` |

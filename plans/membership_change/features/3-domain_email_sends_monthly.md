# Feature 3 — `domain_email_sends_monthly` (Outbound Email Sends per Domain)

> New 2026-09-22. Numbered by its `TODO-member-resources.md` entry (#3), following the
> `features/` convention — see `../README.md`. Sibling of
> [2-domain_email_accounts.md](2-domain_email_accounts.md) (same `per_domain`/`email`
> registry group, same `www/users_email` inventory, several shared open items — cross-
> referenced throughout rather than duplicated).

> **PLANNING ONLY. No code written for this feature yet.** This document is the design;
> implementation is a separate, later step.

> **Correction 2026-10-05.** Server-side quota code (a members-supplied ceiling plus a `www` reservation around
> `MailMuaController::sendEmail()`) was written for this feature outside the plan and has been **removed**. The forward edge
> is `CheckpointAllocV2`'s `canUse` / `consume` / `release` against Parse, called around the send at its call site
> (`03-api-contracts.md` §3.1, `00-overview.md` OQ-A). Nothing below has changed.

## F3.1 The feature in one paragraph

A domain's plan sets **how many outbound emails it may send per calendar month**. Unlike
`domain_email_accounts` (a permanent slot, held until explicitly changed), this is a rolling
counter that resets to zero every month — nothing is ever retired, disabled, or purged,
because there is nothing held: a send either happened or it didn't, and next month's
allowance doesn't care what last month's did. The entire feature is two checks around one
existing call site: **may this domain send one more message this month**, and **record that
it just did**.

## F3.2 The numbers

Registry: `domain_email_sends_monthly` = `[500, 5000, 25000, 150000, -1]`, scope
`per_domain`, unit `messages`, period `monthly`, overage `block`, `warn_at_pct: 85`
(`domain_email_accounts`'s is 90 — a real, registry-driven difference; the shared engine
reads it per-resource, so no code anywhere hardcodes either number).

**`retention: none`, confirmed against `00-overview.md` §6's D11: "the period reset is the
release."** There are no bands, no grace window, no purge — `TODO-member-resources.md` #3
already records `release`/Retirement as `n/a` for exactly this reason. This is not an
oversight to fix; it's the registry's own model for a resource that is *consumed and forgotten*
rather than *held*. Confirmed independently while researching this doc: the
`allocConsume`/`allocRelease` Cloud Functions (`auth/public/checkpoint/parse/cloud/
functions.ts`) already implement period rollover generically — `periodHasRolledOver()` reads
a row's `period_start` and treats it as `used: 0` once the calendar month has turned over,
regardless of resource. **The reset is not something this feature has to build; it already
exists in the shared forward-edge machinery, unexercised only because nothing calls
`allocConsume` for this resource yet.**

## F3.3 Why this is smaller than its siblings

Running the template's five questions (`04-feature-template.md`) against this resource, and
answering "n/a" is itself the finding, not a gap:

1. **Is retiring irreversible from outside?** N/a — nothing is ever retired. A blocked send
   in month N doesn't un-happen the sends from month N-1, and month N+1 starts fresh.
2. **Can a single item fail to fit?** No — a message is sent or it isn't; there's no partial
   send. `consume` is always `amount: 1`.
3. **Does anything the platform depends on live here?** Investigated, not assumed: this
   resource gates `MailMuaController::sendEmail()` (§F3.4 below), the **member-facing**
   webmail send action. Platform/system mail (verification codes, invite emails, admin
   notifications) goes through a different call path —
   `FunctionsController::SendEmailHandler()`/`sendEmailWithIntegration()` called directly by
   controllers like `EmailAccountController::sendInviteEmail()`, never through
   `MailMuaController`. **No platform-critical address needs pinning here** — this resource
   only ever throttles a member's own outbound webmail traffic, not anything the platform
   itself depends on to function. Flagged as a decision, not left implicit, because F2's
   template explicitly warns "disabling the address the platform sends from is a
   self-inflicted outage" — the answer here is that this feature structurally cannot cause
   that outage, not that nobody checked.
4. **Can the member rescue anything?** N/a — there is nothing to evacuate. Once blocked, the
   member's only recourse is to wait for next month or upgrade; there's no "choose what
   survives" UI because nothing is being taken away, only paused.
5. **What does the sweep cost on a tenant that never uses this resource?** **Zero — there is
   no sweep.** No backward edge means no `EmailRetentionSweep`-style hourly job, no ledger
   table, no adapter. This is the one resource in the `domains`/`email` group that needs
   *only* the forward edge.

## F3.4 Current-state findings — the send call site

**One confirmed outbound-send call site exists in `www`**, the same one
`2-domain_email_accounts.md` §F2.3.2 already located for its own status/band check:
`app/Http/Controllers/Portal/Mail/MailMuaController.php`, `sendEmail()` (lines 188-235).

Read directly (2026-09-22), not assumed from the sibling doc:

```php
public function sendEmail(Request $request)
{
    $request->validate([...]);
    $auth = $this->authPayload();        // line 196 — decrypts the portal_mail_auth cookie
    $from = $auth['username'] ?? '';     // used today
    ...
    // TODO_1: EMAIL
    FunctionsController::sendEmailWithIntegration($integration, [...], function ($response) use (&$sendStatusCode) {
        $sendStatusCode = $response['response_status_code'] ?? null;
        ...
    });

    if ($sendStatusCode === null || (int) $sendStatusCode >= 300) {
        return response()->json(['status' => 'error', 'message' => 'Failed to send email'], 500);
    }
    $this->imap()->appendToSent($from, $to, $subject, $body);
    return response()->json(['status' => 'success', 'message' => 'Email sent']);
}
```

**Finding: the domain is already sitting in `$auth`, unused.** `login()` (line 55-89) encrypts
`['username' => ..., 'password' => ..., 'user_id' => ..., 'domain' => $domain]` into the
`portal_mail_auth` cookie; `authPayload()` (line 286) decrypts and returns the whole array.
`sendEmail()` currently reads only `$auth['username']` — `$auth['domain']` is already
available with no extra resolution call, unlike `EmailAccountController`, which has to call
`getSubdomainProperties()` because it has no equivalent auth payload to read from.

**Open item, shared verbatim with `2-domain_email_accounts.md` §F2.3.2:** "it will be possible
to send email from multiple applications" — the other nine repos under `w/` may independently
send mail using `users_email` credentials, and each one found needs its own copy of whatever
check lands here. Not re-investigated separately for this doc; the same cross-repo search
closes both features' open items at once, since it's the same call-site question asked twice.

## F3.5 Design: server-side, not client-side

`email.ts` already reads this resource for **display** —
`allocV2.get(RegistryResourceIds.DOMAIN_EMAIL_SENDS_MONTHLY, { domain: quotaDomain })` renders
the quota panel's used/limit numbers. That stays; it's the emailaccounts pattern of
`canUse()`/`consume()` being called *from the frontend* that this feature should **not**
repeat.

**M13** (`01-current-state-audit.md`, shared with `domain_email_accounts`): a client-side
`canUse()` check is enforcement theatre — a member who can open devtools can call the send
endpoint directly and skip it. `domain_email_accounts`' own `canUse` is already tracked as
`BROKEN (fails open)` in `TODO-member-resources.md` #2 for exactly this reason. This feature
should not inherit that defect on day one: **`canUse` and `consume` both belong inside
`sendEmail()` itself**, server-side, gating the actual `FunctionsController::
sendEmailWithIntegration()` call — not added to `email.ts`'s send-button handler.

**The mechanism already exists and is proven live.** `App\Services\Checkpoint\
CheckpointAllocClient` (`www`, built for `domain_email_accounts`' `release()` — see
`2-domain_email_accounts.complete.md`) already calls the `allocConsume`/`allocRelease` Cloud
Functions server-side, authenticated via the service-key path (`resolveCaller()`'s "a service
caller... IS permitted to name the member" branch) — exactly the situation here:
`MailMuaController` has its own cookie-based mail auth, not a Checkpoint bearer token, so it
must name the member explicitly, the same way `EmailAccountController::destroy()` does. This
feature needs the sibling calls, not a new mechanism:

- **`check`** (read-only preflight, `CheckpointAllocClient::check()` — does not exist yet,
  `release()` is the only method built so far) called before
  `sendEmailWithIntegration()`; if not available, return the same
  `['status' => 'error', ...]` shape `sendEmail()` already returns for a send failure, so the
  mail UI's existing error handling needs no new branch.
- **`consume`** (`CheckpointAllocClient::consume()` — also not built yet) called only after
  `$sendStatusCode < 300` — mirroring `domain_email_accounts`' own rule of consuming only on
  a *successful* create, never a failed attempt.

Scope for both calls: `resourceId: 'domain_email_sends_monthly'`, `domain: $auth['domain']`,
`amount: 1`, `period: 'monthly'` (`CheckpointAllocClient::release()`'s existing `$period`
parameter already defaults to `'lifetime'` — this resource is the first caller that needs
`'monthly'` passed explicitly).

## F3.6 What's genuinely open

- **OQ-J — does a blocked send fail loud or silent?** `overage: block` says the send must not
  happen, but does the member see "you've hit your monthly send limit" (a real UX surface
  that doesn't exist yet in the mail MUA's send form) or a generic "Failed to send email"
  indistinguishable from a SendGrid outage? The current 500-on-failure response gives no room
  to tell these apart. Needs a decision before implementation, not during it.
- **The cross-repo send-site search** (§F3.4) is unresolved for both this feature and
  `domain_email_accounts` simultaneously — doing it once closes both.
- **`CheckpointAllocClient::check()`/`::consume()` don't exist yet** — only `::release()` was
  built (for #2). Adding the other two Cloud Function calls is straightforward (same class,
  same HTTP contract, different function name and response shape) but is implementation, not
  planning — noted here so it isn't mistaken for already-done infrastructure.

## F3.7 Files to create/modify (when this moves to implementation)

| File | Repo | Change |
|---|---|---|
| `app/Services/Checkpoint/CheckpointAllocClient.php` | www | Add `check()` and `consume()`, siblings of the existing `release()` |
| `app/Http/Controllers/Portal/Mail/MailMuaController.php` | www | `sendEmail()` (188-235): `check()` before the integration call, `consume()` after a confirmed successful send |
| `resources/js/portal/admin/views/email.ts` | www | No change expected — already reads this resource for display; OQ-J may add a specific "limit reached" message if resolved that way |

No `members` changes, no migration, no new table — the entire backward edge is `n/a` by
design (§F3.3), and the forward edge writes to the same `MemberResource` Parse row every
other `alloc_v2` resource already uses.

## F3.8 Success criteria

- [ ] Cross-repo send-site search done (shared with #2)
- [ ] OQ-J resolved
- [ ] `CheckpointAllocClient::check()`/`::consume()` built
- [ ] `sendEmail()` gates on `check()`, fails closed, consumes only after a confirmed send
- [ ] Verified live against the vagrant lab's Parse server (same pattern as #2's
      `allocConsume`→`allocRelease` round-trip test), including a monthly period-rollover
      check — confirm a stale `period_start` really does read back as `used: 0`, not assumed
      from reading `functions.ts`
- [ ] No backward-edge work attempted — retention is `n/a` by design, not by omission

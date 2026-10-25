# 02 — Domain identity and collisions: which claim is active

> **Moved 2026-10-23** from `domains_deploy/new_way/` to `domains_deploy/domain_name_management/` when the plan was split into domain names and website deployment. The text is unchanged; step, section and finding numbers are the same.

> **STATUS 2026-10-08: BUILT (P0 and P1); P2 not built.** What exists, where it differs from this text, and how it was verified:
> [complete/02-domain-identity-and-collisions.complete.md](complete/02-domain-identity-and-collisions.complete.md). The completion document supersedes this one for current behaviour.

> The problem as stated: member 1 adds `example.com`, but `example.com` is registered to member 2. They collide. The plan
> has to decide, mechanically and without a human, **which one is real**, and make the loser harmless.

## 2.1 The idea in one paragraph

Adding a name never gives anyone the name. It creates a **claim** — a private note that "member X says they control
`example.com`". Many claims can exist for one name. A claim is **inert** (no zone published, no website, no sites name
in DNS, no certificate) until it **proves control**; proof is either "the platform registered it for you" or "you
published a secret we can see in the real DNS". The database allows **exactly one active claim per name** (a unique
index, §3.2), so whichever claim proves control first becomes the only one that can ever publish anything. Everyone else
sits in a queue and is promoted if the incumbent stops proving control. A member can lose nothing but time to a false
claim, and a false claim can never serve a byte.

## 2.2 The two ways in, and what each proves

| | **A. Purchase** | **B. Connect ("just add it")** |
|---|---|---|
| What the member does | Searches, pays, buys through the platform | Types a name they already own elsewhere |
| Who is registrant of record | The platform (or the member, via the registrar's contact data — **D6**) | The member, at their own registrar |
| Nameservers | Set by us to the platform NS right after registration (`setNameservers`, already coded) | Member changes them, **or** keeps their DNS and points records at us |
| `dns_mode` | `managed` | `managed` (NS moved to us) or `external` (member keeps DNS) |
| Proof of control | **The registration itself.** The registrar refuses to sell a name that is registered, so a successful purchase *is* exclusive control | **A TXT challenge** (§2.4). NS delegation alone is **not** identity (§2.5) |
| `proof_level` | `registrar` | `txt` |
| Starts as | `purchasing` → `active` | `pending_verification` → `active` |
| Can it displace an incumbent? | Never needed: a name that is purchasable has no active claim. Unproven claims on it are superseded when the purchase completes | Only by the rules in §2.7 |

## 2.3 Claim states

```
                    ┌─────────────────────────────────────────────┐
   connect ───────▶ pending_verification ──proof ok──▶ active ────┤──periodic re-check fails N times──▶ lapsed
                    │        │                          ▲  │      │
                    │        │ incumbent exists         │  │      └──member releases / deletes──────────▶ released
                    │        ▼                          │  └─staff or abuse action──▶ suspended ──lifted──▶ active
                    │     contested ──incumbent lapses──┘
                    │        │
                    │        └─ a purchase completes on this name ─────────────────────────────────▶ superseded
                    └─ proof not given within 14 days ─────────────────────────────────────────────▶ expired_unverified
   purchase ──────▶ purchasing ──registered──▶ active          (registrar failure ──▶ failed, refunded)
```

| State | Meaning | Publishes zone / vhost / cert? | Counts for "active name"? |
|---|---|---|---|
| `pending_verification` | Claim created; no proof yet; no incumbent | no | no |
| `contested` | Valid claim but another claim is active; waiting | no | no |
| `purchasing` | Saga in progress (05 §5.2) | no | no |
| `active` | The one real claim | **yes** | **yes** |
| `suspended` | Staff/abuse hold or account suspended; stays owner of the name | zone stays, site offline (see §2.10) | **yes** (still reserves the name) |
| `lapsed` | Proof failed repeatedly; zone withdrawn after a grace period | no (after grace) | no |
| `released` | Member gave it up | no | no |
| `superseded` | A purchase or a stronger proof beat it | no | no |
| `expired_unverified` | Never proven | no | no |
| `failed` | Purchase failed | no | no |

`active` and `suspended` are the only two states that hold the name. That is what the unique index encodes (§3.2).

## 2.4 The TXT challenge (connected names)

1. On `POST` of a connected name the Brain creates the claim and a **challenge**: a random token of at least 128 bits,
   stored in the clear because the member must be able to see it again. It is **per claim, single-purpose, and worthless
   once the claim is active or dead**, so storing it readable costs nothing.
2. The member is shown exactly one record to create at their current DNS provider:

   ```
   _afs-verify.example.com.   TXT   "afs-verify=<token>"
   ```

3. The Brain checks it with a **verifier** that does **not** trust one resolver's cache:
   - find the domain's authoritative servers: ask the parent for the NS set (a recursive query for `NS example.com`);
   - query **each authoritative server directly** for the TXT name; the claim passes if **all reachable** authoritative
     servers return the token (a single stale secondary does not fail it, an authoritative server that disagrees with
     another does not pass it);
   - confirm once through a public recursive resolver (configurable set), so a hand-edited zone on a rogue server that is
     not actually delegated cannot pass.
4. Checks run on a schedule (every minute for 10 minutes after the member presses "Verify", then every 15 minutes for
   24 hours, then every 6 hours until the 14-day expiry) and on demand, with a per-claim rate limit.
5. A pass writes `verified_at`, `proof_level = txt`, stores the resolver evidence (servers asked, answers, time), and
   asks the claim to **activate** (§2.6).

**Why a TXT record and not a file or an email.** A TXT record at the zone apex-adjacent name can only be published by
whoever controls the domain's DNS right now. It does not depend on a website existing (we are about to create it), it
does not depend on a mailbox (we have none yet), and it is checkable from inside the lab (the lab resolver is
configurable, §9).

## 2.5 Why NS delegation is not identity (read this twice)

It is tempting to say "if the domain's NS records point at our nameservers, the member controls it". **That proves
somebody with registrar access pointed the domain at us. It does not say which of our members that is.**

Consider: member 2 legitimately owns `example.com`, points its NS at us, and activates it. Member 1 then adds
`example.com`, sees "point your nameservers at ns1…ns4.appfactory.studio" and *the check passes for member 1 too* — the
NS are indeed ours. If NS delegation were the proof, member 1 would hijack a zone that member 2 is paying to serve.

So the rules are:

- **NS delegation proves reachability, not ownership.** It sets `dns_mode = managed` and lets us serve the zone. It is
  required before a `managed` zone is *published*.
- **Only the per-claim TXT token (or the registrar purchase) proves identity.** When the domain is already delegated to
  us, the verifier queries *our* authoritative servers, which are serving the **incumbent's** zone; member 1's token is
  not in it and cannot be, because member 1 cannot write the incumbent's zone. Member 1 stays `contested`.
- For a *non-delegated* name, member 1's token goes into whatever DNS provider answers for it today. If that provider is
  controlled by member 2, member 1 cannot place the token; if it is controlled by member 1, then member 1 controls the
  name's DNS and (as far as the internet is concerned) *is* the domain's controller.

The same reasoning is why a claim for a name that is **not yet delegated** can be verified and activated *before* the NS
move: the member proves identity first, then moves NS at their leisure; the zone is published as soon as NS are ours.

## 2.6 Activation: the single point of truth

Activation is one database transaction in the Brain:

1. `SELECT … FOR UPDATE` the claim, and every other claim row for the same `fqdn`.
2. If another row has `status IN ('active','suspended')` → this claim becomes `contested`; stop.
3. Otherwise set this claim `status = 'active'`, `activated_at = now()`, assign `site_ref` if it has none (§3.3), mark every
   other `pending_verification`/`purchasing`-failed claim for the name `contested` (they stay in the queue) — **never
   deleted**, so a later promotion can use them.
4. Insert the publish job (04 §4.3) in the same transaction (outbox pattern), so "active" and "needs publishing" are
   one atomic fact.

The unique index (`active_fqdn`, §3.2) is the backstop: if two activations race past step 2, the second `UPDATE` fails
with a duplicate-key error and is retried as `contested`. No application bug can produce two active claims.

## 2.7 Precedence and displacement

| Situation | Outcome |
|---|---|
| Two connected claims, neither active | The first to **prove** wins. Nothing about who typed it first. |
| Incumbent active (any proof level), challenger proves TXT | **Challenger does not displace.** It stays `contested`. The incumbent is only replaced if it stops proving (§2.8) or releases. |
| Incumbent is `txt`, member buys the name later | Cannot happen: the registrar will not sell a registered name. If the registrar *does* complete a purchase (the incumbent's registration had lapsed), `registrar` proof **supersedes** the TXT claim and the incumbent is notified. |
| Incumbent released, several contested claims | Promote by **earliest successful proof**, only if that claim's TXT is still present *now* (re-verify at promotion). Others stay queued. |
| Incumbent lapses (no proof for the grace period) | Same promotion. |
| Two claims by the **same member** | The second is rejected as a duplicate of their own claim (§2.9). |
| A dispute the machine cannot settle (both appear to control the DNS) | A staff-visible `contested` list with the evidence stored on each claim. Resolution is a staff action that writes an audit row. **D3** |

Displacement of a healthy incumbent is deliberately impossible without human action. The cost of that choice is that a
real owner whose domain was claimed earlier by an impostor who *did* control its DNS (for example a former employee)
needs a support path; the audit trail and the staff transfer action are that path.

## 2.8 Staying active: continuous proof

A name is not "yours forever" because you proved it once; registrations lapse and DNS changes hands.

- **Connected, `txt`:** re-verify every 24 hours (the same verifier). Three consecutive misses (≥ 72 h apart in
  wall-clock, not three quick retries) ⇒ `lapsed`. A lapsed claim keeps its zone published for a **grace period** of 14
  days (configurable) so a DNS-provider hiccup does not take a customer's site down, then the zone is withdrawn.
- **Managed (NS ours):** also check NS delegation. If NS no longer point at us, `dns_mode` flips to `external` and the
  zone is no longer authoritative anywhere that matters (it is kept, but marked "not delegated" in the UI).
- **Purchased:** sync with the registrar daily (expiry, auto-renew, lock, NS). Expired and not renewed ⇒ `lapsed`; the
  grace period follows the registrar's own redemption window.
- Every transition writes a row to `brain_domain_events` (§3.5), which is also what the member sees as "why".

## 2.9 Normalisation and refusal rules (applied before a claim is created)

1. **Normalise:** trim, lowercase, strip a trailing dot, strip a leading `www.`, convert IDNs to punycode (ASCII form is
   the key; keep the Unicode form for display). Reject anything that is not a valid hostname (no underscores, labels
   ≤ 63, total ≤ 253).
2. **Registrable domain only** (**D4**). Resolve against the Public Suffix List; accept only the registrable apex
   (`example.com`, `example.co.uk`). A subdomain (`blog.example.com`) is refused in v1 with a clear message. Rationale: a
   subdomain claim needs a zone cut or a record-level delegation, and the dangling-subdomain-takeover problem is its own
   design. Lab names under `10-0-0-243.sslip.io` and `test2` are allowed through a configured extra-suffix list (09 §9.2–9.3).
3. **Reserved names:** the platform's own zones and every name under them — `appfactory.studio`, `*.sites.appfactory.studio`,
   `appfactory.net`, `ns*.…` — and the lab equivalents. Case-insensitive, enforced server-side.
4. **Same member, same name:** if the member already has a live claim (any non-terminal state) → return it, do not
   create another. Idempotent.
5. **Sanity cap** on live unproven claims per member (default 20) and a rate limit on claim creation. Domain names are
   exempt from *plan* quotas (feature 1, F1.0), so this cap is abuse control, not entitlement, and is not tied to plan.
6. **Uniform response.** Creating a claim returns the same shape whether or not someone else holds the name
   (`pending_verification`, the challenge, instructions). It never says "another member has this" and never reveals who.
   `contested` is shown to a member only after *they* have a valid proof, and says only "this name is currently
   connected to another account; we will promote your claim if it becomes available, or contact support".

## 2.10 What an unproven or contested claim may do

Nothing that touches shared infrastructure. It may:

- hold a **staged zone** (the member can enter records in the DNS tab; they are stored, validated and rendered to a
  preview, but never published);
- show instructions.

It may **not**: publish a BIND zone, receive a sites name in DNS, deploy a website, have a certificate requested, or
appear in any vhost. The `site_ref` is only assigned at activation (§2.6), so an impostor cannot even reserve one.

A `suspended` incumbent keeps the name (nobody can take it while it is suspended for abuse) and its **website goes
offline** (vhost returns the platform's "suspended" page); DNS stays up unless staff withdraw it. This matches feature
1's decision D1 (retire = site offline, DNS and mail stay).

## 2.11 Every race, and what stops it

| Race | Stopped by |
|---|---|
| Two members add the same name in the same second | Both claims exist (harmless). Only one proof can win; activation's unique index lets one through |
| Two proofs land together | `FOR UPDATE` on the name's claim rows; the unique index is the backstop (§2.6) |
| Purchase and connect at once | Purchase takes a **name lock** (§5.2) that blocks activation of any connected claim for that name until the purchase ends; after it ends, `registrar` proof supersedes |
| Verify while a delete is in flight | Claim row `FOR UPDATE`; state check inside the transaction |
| Publish job running while the claim lapses | The job re-reads the claim at start and at the "commit" step (rename); a non-active claim aborts and cleans up |
| Two Brain workers publishing one zone | Per-zone advisory lock (`GET_LOCK('dnspub:'||fqdn)`); the second waits, then finds nothing to do |
| Deploy starts for a claim that lapses mid-way | Deploy step 1 and step 14 both assert the claim is `active`; step 14 failing triggers compensation (07 §7.7) |

## 2.12 Test matrix for this step (goes into step 10)

1. Member A connects `example.com` and proves it; member B connects it afterwards, adds a TXT at *their own* DNS that
   cannot be the authoritative one → B stays `contested`. A is unaffected.
2. Reverse the timing: B proves first; A cannot displace.
3. A releases; B is promoted only if B's TXT still verifies.
4. NS delegated to us by A; B follows "point your NS to us" and presses Verify → fails (identity), stays `contested`.
5. Purchase of a name for which A has a pending (unproven) claim → A is `superseded`, the purchase proceeds.
6. Same member adds a name twice → one claim.
7. Uppercase, trailing dot, `www.` prefix, punycode IDN → one key.
8. `appfactory.studio`, `foo.sites.appfactory.studio`, `com`, `co.uk` → refused.
9. Concurrent activation of two claims (parallel test with two connections) → exactly one active, the other `contested`.
10. Re-verify misses ×3 → `lapsed`; grace → zone withdrawn; promotion of the next claim.

## 2.13 Phases

| Phase | Work |
|---|---|
| **P0** | Normalisation and refusal rules; claim + challenge tables; verifier (authoritative + one public resolver); activation transaction and unique index; `contested`; uniform responses; purchase proof (registrar) |
| **P1** | Re-verification and lapse/grace/promotion; events table and member-visible reasons; sanity cap and rate limits; staff `contested` list and transfer action |
| **P2** | Subdomain claims (**D4**); registrar-side ownership sync for connected names via registrar APIs; email notice to a displaced incumbent |

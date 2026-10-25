# 02 — The two patterns, and how to converge them

## Completion document

When this plan step is completed, write `complete/02-two-patterns.complete.md` with
status, summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly using block comments. Any middleware that
survives this convergence documents which pattern it implements and why.

---

## 1. Why there are two

Neither pattern is a mistake; they solved different problems at different times.

**`checkpoint.verify`** (members, market) came from the marketplace work. Its job was
narrow: the browser already holds a Checkpoint token, the server needs the caller's
`member_reference_num`, and `ResolvesMarketMember` was resolving it from `Auth::id()` —
which is always `null` in an app that never calls `Auth::login()`. `VerifyCheckpointToken`
supplied the missing piece and nothing more.

**`checkpoint.member`** (the five apps) came from the desktop-sync work
([account-and-cloud-sync](../afs-connect-desktop/account-and-cloud-sync/) Phase S0.5). Its
starting point was harsher — `/api/pm/*` trusted a client-supplied `X-User-Id` header —
and its clients included a desktop app and a game engine, where secondary users are minted
per app context. So it had to answer "is this token valid *for this app*", and it grew a
typed identity to carry the answer.

The result is one platform with two vocabularies for the same idea.

## 2. Side by side

| | `checkpoint.verify` | `checkpoint.member` |
|---|---|---|
| Apps | members, market | coordinator, notes, dream_graph, pdf_editor, flow |
| Middleware | `VerifyCheckpointToken` (1 copy ×2 repos) | `{App}CheckpointAuthMiddleware` (5 near-identical) |
| Service | `CheckpointTokenVerifier` | `CheckpointTokenValidator` + `CheckpointIdentity` |
| Return | `array{valid, member_reference_num}` | `CheckpointIdentity` value object |
| Auth endpoint | `/checkpoint/auth/token/verify` | `/checkpoint/auth/token/validate` or `/checkpoint/secondary/token/validate` |
| Secondary handling | Implicit — auth normalises it | Explicit — routes to the secondary endpoint, exposes `userType` |
| **App scoping** | **none** | `wrong_app` for secondary tokens |
| Per-app role | ✗ | `checkpoint_app_role` |
| Acting user id | ✗ | `checkpoint_user_id` |
| Positive cache | token lifetime (≤ 1 h) | 60 s |
| Negative cache | **5 s** | **60 s** |
| Failure detail | boolean | `reason` string (`missing_token`, `invalid_token`, `null_member`, `wrong_app`) |

### 2.1 Attribute names

The same value has two names, which is the part that makes cross-app code impossible to
share:

```php
// members, market
$request->attributes->get('member_reference_num');

// coordinator, notes, dream_graph, pdf_editor, flow
$request->attributes->get('checkpoint_member_reference_num');
```

## 3. Decision: `checkpoint.member` wins

**`checkpoint.member` is a strict superset.** There is nothing `checkpoint.verify` does
that it does not, and four things it adds: app scoping, acting-user id, per-app role, and
a typed failure reason.

The only argument for the other direction is that `CheckpointTokenVerifier` is simpler.
That is true and is not a benefit here — the simplicity comes entirely from omitting the
app check, which is finding **F3**.

### 3.1 What `checkpoint.verify` is missing, concretely

Because neither members nor market checks `app_name`:

> A secondary user created under a member and granted access **only to Notes** holds a
> token minted with `app_name: NOTES_EDITOR`. Presented to members, that token validates
> (auth confirms the signature and attaches the super user's membership), so members
> treats the caller as the primary member and serves the full members API — billing,
> domains, afs-connect, storage.

The five `checkpoint.member` apps reject this with `wrong_app`. members and market accept
it.

Two things bound the severity, and neither removes it:

- It requires an existing secondary account under the member — an insider case, not an
  anonymous one.
- It grants the *super user's* member scope, which is the same scope the secondary user
  already has inside their permitted app.

So this is a privilege-boundary failure between apps, not a cross-tenant leak. It matters
because the entire point of per-app secondary tokens is that the boundary exists.

### 3.2 The one thing to carry over from `checkpoint.verify`

Its **5-second negative cache**, with the reasoning in its comment. The
`checkpoint.member` validator caches failures for the full 60 s, which turns a transient
blip into a minute of lockout ([01](01-platform-audit.md) §5.1, [05](05-open-exposures.md)
§4). members is right; the merged implementation takes members' behaviour here and the
five-app behaviour everywhere else.

## 4. The target

One package (`afs/checkpoint-auth`, [04](04-shared-validator.md)) providing:

```php
// The middleware every app registers, configured per app.
final class VerifyCheckpointMember
{
    public function __construct(
        private readonly CheckpointTokenValidator $validator,
        private readonly string $requiredAppName,   // from config('checkpoint.app_name')
    ) {}

    public function handle(Request $request, Closure $next): Response;
}
```

Request attributes, one vocabulary, all seven apps:

| Attribute | Type | Meaning |
|---|---|---|
| `checkpoint_member_reference_num` | string | Tenant key. Never empty — an empty one is rejected at the middleware |
| `checkpoint_user_id` | string | The **acting** user. For a secondary token this is the secondary's own id, not the super user's — so audit trails name who actually acted |
| `checkpoint_user_type` | `'normal'\|'secondary'` | |
| `checkpoint_app_role` | ?string | Secondary users' role for this app; `null` for primary |
| `checkpoint_member_plan` | int 0–4 | **New** — see §4.2 |

### 4.1 Accessor, not raw attribute reads

Every app gets the same trait, so no controller touches `attributes->get()` directly:

```php
trait ResolvesCheckpointMember
{
    protected function currentMemberReferenceNum(): string;
    protected function currentCheckpointUserId(): string;
    protected function currentUserType(): string;
    protected function currentAppRole(): ?string;
    protected function currentMemberPlan(): int;
}
```

members' existing `ResolvesMarketMember` becomes a deprecated alias of this for one
release; the five apps' `Concerns\ResolvesMember` / `ResolvesAppAuth` are re-pointed at it.

### 4.2 Adding the plan tier

The verify response already carries `member.membership`, and several features need it
(storage quota in [cloud-storage/08](../cloud-storage/08-storage-retention.md), plan gating
elsewhere). Resolving it in the middleware means one source of truth per request rather
than a `brain_memberships` query that can disagree with the token.

Clamped and defaulted **once, here**, per
[afs-connect/11](../afs-connect/11-billing-and-allocation.md) §11.3:

```php
$plan = max(0, min(4, (int) ($identity->membership ?? 0)));  // missing ⇒ 0, fail closed
```

Never resolved by tier *name* — both ladders rank 0–4 and only the labels differ, so a
name lookup breaks the first time marketing edits one.

## 5. Migration per app

| App | Work | Risk |
|---|---|---|
| **coordinator** | Swap local classes for the package; keep `MemberCheckpointAuthMiddleware` as a thin subclass or delete it in favour of config. Attribute names unchanged | Low |
| **notes, flow, pdf_editor, dream_graph** | Same. pdf_editor additionally keeps its `.optional` variant, which the package must support | Low |
| **market** | Swap `CheckpointTokenVerifier` → package; **rename attributes** `member_reference_num` → `checkpoint_member_reference_num`; set `app_name: MARKET` | Medium — attribute rename touches every portal API controller |
| **members** | Same as market, plus the route coverage work in [03](03-members-remediation.md); `app_name: MEMBERS` | Highest |

### 5.1 Turning on app scoping is a behaviour change

For members and market, adding the `app_name` check **starts rejecting tokens that work
today** — specifically, secondary tokens minted for another app.

Sequence it in two steps, never one:

1. **Observe.** The middleware evaluates the app check and logs a mismatch, but does not
   reject. Run for one release.
2. **Enforce.** Turn rejection on once the logs show no legitimate traffic being flagged.

If step 1 produces mismatches from real users, that is a finding about how tokens are
minted for those clients — investigate before enforcing, rather than shipping a lockout.

### 5.2 What must not change

- **Primary tokens must never be rejected for `wrong_app`.** They carry no `app_name`
  claim at all (property P4). The five-app validator already handles this correctly; the
  package must preserve it, and it needs a regression test because it is the single
  easiest thing to break while "tightening" the check.
- **Deliberate public routes stay public.** market's portal shell, coordinator's
  `/afs-connect/sources`, pdf_editor's share-token endpoint, dream_graph's signed export
  download. Each has a recorded reason; convergence must not sweep them up.

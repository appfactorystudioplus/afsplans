# 04 — Form Generation (P2) — Complete

## Status

Done, with three defects found and fixed during audit. The form is generated from
`checkpoint_registry_v4.json` and renders all **34** resources across all **seven** scopes.
Project typecheck clean (0 errors). **Nothing committed.**

Two items in §4.10 are reasoned-correct from the code but **not observed running** — they need a
real member, a live Parse object and a browser. They are listed in *Not verified* below rather
than claimed.

## Summary

**This step was already implemented when the audit began.** The form generator, row rendering,
card grouping, filters and the draft/diff model live in `brain_membership.ts` and were built
against the plan as it read at the time.

The audit's value was in what the plan had got wrong, and what the implementation had inherited
from it. §4.7 described four resources as inert — *"no container in the storage shape, no scope
key in the client"* — which was true under registry v3 and stopped being true when the
provisioning template was regenerated from v4 (step 03's correction). The implementation
faithfully rendered them as *"Unscoped / Unsupported"* with `used: 0` hardcoded.

So the plan was corrected first, then the code brought up to it. Two further defects surfaced
while checking the corrected behaviour: a period-encoding bug that makes every rollover check
return "not rolled over", and the flat `ac_*` counters never being read at all.

## Plan corrections (made before touching code)

| § | Was | Now |
|---|---|---|
| §4.1 docblock | *"The registry's conventions block says tiers resolve by name via an `index` field — the text is wrong"* | **v4 states the positional rule correctly.** The warning applied to v3 and is retired |
| §4.1 | *"three copies of the **ten** `per_domain` rows"* | **fifteen** — v4 has 15 per-domain resources |
| §4.7 | *"The unscoped section"* — four resources, read-only, inert | **Rewritten as deep-scope cards.** v4 declares seven scopes and the template has a container for each; `per_store` nests inside its domain (the BR10 fix) |
| §4.2 mock | showed an *"Unscoped — not enforceable"* card | shows a Graph card and an empty Documents card |
| §4.10 | *"three domain cards, each with its own **ten** rows"* | fifteen rows, plus nested store cards and empty-container cards |

## Defects found and fixed

| # | Defect | Consequence | Fix |
|---|---|---|---|
| **1** | **Four of seven scopes never rendered.** `per_graph`, `per_document`, `per_design_project`, `per_store` fell through to one *"Unscoped / Unsupported"* row with `used: 0` hardcoded. `getResourceEntry()` returned `null` for them, so even reaching them read zero | Four resources reported as broken when they have homes, and no operator could see or correct a real count | `DEEP_SCOPE_CONTAINERS` map + a `per_store` walk through `domains[].stores`; `getResourceEntry()` extended to all seven |
| **2** | **BR26 — `periodHasRolledOver()` cannot read the encoding this screen sees.** `new Date(String(periodStart))` on Parse's `{ __type:'Date', iso }` yields `"[object Object]"` → Invalid Date → **`false` for every value** | *Never rolled over*, always. A row whose window has expired renders as live, and an operator sets a number the client ignores — §4.5 calls that "the worst thing this form could do" | Accepts both encodings. Recorded as **BR26** in §4.5 because anything else passing `toJSON()` output into it gets the same silent wrong answer |
| **3** | **BR25 — the `ac_*` counters were never read.** Zero references in the file | `03` §3.5 calls this *"the finding that decides what the form has to write"*. Where a flat counter exists it is what `readUsage()` prefers, so the form showed the nested projection and a correction to it would be the silent no-op BR25 describes | `getCounterValue()`, deriving the name through alloc_v2's own `counterField()`. Rows carry `counterValue` and `nestedValue` **separately** so §4.3's disagreement row can show both |

## Files changed

| File | Change |
|---|---|
| `www/resources/js/checkpoint/alloc_v2.ts` | **Exported** `counterField`, `scopeToken`, `periodHasRolledOver`. Additive; no behaviour change. §4.6 requires the form to derive field names through the same code — the scope token carries a hash suffix, and a hand-built name silently misses every real counter |
| `www/.../brain/brain_membership.ts` | `DEEP_SCOPE_CONTAINERS` / `DEEP_SCOPE_LABELS`; `getResourceEntry()` extended to seven scopes; `per_store` walk; `getCounterValue()`; `resolveUsed()` / `nestedUsed()`; `periodHasRolledOver()` accepts both encodings; `AllocationFormRow` carries `counterValue`/`nestedValue`; imports `counterField` |
| `plans/members_brain/04-form-generation.md` | The five plan corrections above, plus **BR26** recorded in §4.5 |

### Written and then removed

`allocationModel.ts` and `allocationForm.ts` were written before the existing implementation was
discovered, then deleted. Keeping them would have created a second generator for the same
registry — which is **BR11**, the finding this whole screen exists to avoid, reintroduced by the
work meant to close it. The one piece worth keeping was the `alloc_v2` export, which stayed.

## Verified

Against the real `checkpoint_registry_v4.json` and a member object with two domains (one holding
a store), one project and one graph:

- **34** resources placed, **0 homeless** — every scope the registry declares has a container
- member card **14** rows · each domain **15** rows · store nested **inside** its domain
- empty containers still render a card
- all seven §4.3 row states fire: `tier_disabled`, `unprovisioned`, `normal`, `over_ceiling`,
  `unlimited`, `counter_conflict`, `window_expired`
- override applies: ceiling `5000` → effective `500`
- counter field derived correctly (`ac_m__member_domains_count`)
- **BR19** — `Number.isInteger(value) || value < 0` rejects `abc`, `1.5`, `-1` before save
- **BR22** — zero `objectId` references; it is not rendered as a field
- project typecheck: **0 errors**

## Not verified

Needs a live stack and a browser; reasoned-correct from the code, not observed:

1. **A real member's Parse object end to end** — every check above used a constructed object.
2. **The `ac_*` path against a live Parse server.** Whether a flat counter exists at all is the
   open question in `03` §3.5: the column embeds a scope token and cannot be declared ahead of a
   locked schema, so the write may be rejected and the nested value may be all there is. **The
   form now handles both, so it is correct either way** — but which branch runs in production is
   unknown, and one save against staging settles it.
3. **`[reset now]`** writing `used = 0` and the current UTC period start in one edit (§4.5).
4. **Filters never hiding a row with an unsaved change** (§4.9).

## Follow-up

- **`sync-checkpoint-client.sh` must run.** `alloc_v2.ts` is a synced file; the three new exports
  do not reach the other eight apps until it does. `--check` currently passes, so it will report
  drift the moment this lands.
- **The local `periodHasRolledOver` copy should import instead.** Fixing it in place closed BR26
  here, but two copies of one rule is BR11 in miniature — and the copy is why the bug existed in
  two places at once.
- **§4.6's add/remove domain is not implemented** — no `addDomain`/`removeDomain` in the file.
  The `--Example_NAME--` collapse it describes is now moot for new members (step 03 emptied the
  containers) but still applies to any member provisioned before that.
- **§4.8's bulk actions are not implemented** — no "reset all monthly counters", no "zero this
  domain's counters".
- **Nothing is committed.**

## Related

| Document | Relationship |
|---|---|
| [03](../03-the-data-model.md) §3.5 | BR25 — the counter authority this step now reads, and the `MemberRegistryCounter` change that will replace it |
| [03 completion](03-the-data-model.complete.md) | Regenerated the v4 template, which is what made §4.7's four scopes real |
| [05](../05-limit-overrides.md) | The override control the ceiling column opens; rows already carry `override` |
| [07](../07-save-semantics.md) | Consumes the diff this form produces. §4.2's button says *Review & Save* because that step exists |
| [../../alloc-v2-parse-direct/03](../../alloc-v2-parse-direct/03-parse-cloud-functions.md) §3.2.2 | Moves counters to `MemberRegistryCounter`. **When it lands, `getCounterValue()` and the write-both rule both change** — see 03 §3.5's dated note |

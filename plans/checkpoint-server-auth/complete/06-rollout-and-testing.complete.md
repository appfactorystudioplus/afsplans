# 06-rollout-and-testing — Completion

**Status:** Reconciled, not "done" in the sense of finishing the rollout — this document
is a tracking/coordination artifact, not a feature to build. Its non-testing content
(§1 Phase 0 questions, §2 phases, §6 definition of done) has been brought up to date
against what's actually landed across steps 00/03/04/05. Its testing content (§4) was,
at the user's explicit direction, left for the user to run rather than built or executed
this session — see Follow-up work.

## Summary

`06-rollout-and-testing.md` is mostly a reference/tracking document — a phase
breakdown, an open-questions table, a breaking-changes-and-handling summary, a rollback
table, and a testing plan — rather than something with its own code to write. What this
pass actually did:

1. **Updated §1's Q1–Q16 table in place** with current status. Resolved since the doc
   was written: Q1 (JWT_SECRET — confirmed live, rotated), Q2 (`CHECKPOINT_JWT_SECRET` —
   confirmed dead, deleted), Q5 (dream_graph assets — decided and implemented, option B),
   Q7 (package distribution — went further than either option the question posed: a real
   private GitHub repo, not just a bare tagged VCS reference with no registry at all).
   Confirmed still open: Q3, Q4 (partially — verified for the groups actually gated),
   Q6, Q10, Q11, Q13–Q16 — all blocked on steps that haven't started (09, 10, 11) or on
   work explicitly not done (Q3's log-and-ignore).
2. **Updated §2's phases table** with a status column. Phases 1–5 done (domains fix,
   members coverage, the package, low-risk adoption, coordinator). Phases 6–15 not
   started, with phase 14's two relevant facts (the `AppNames.h` sync-script claim is
   genuinely false; the Joystick Engine doesn't consume `/api/assets/*`) noted since
   they were confirmed as a side effect of step 05's work even though phase 14 itself
   wasn't touched.
3. **Updated §6's definition-of-done checklist** with a status against every line —
   most of it is not done, because most of it depends on steps 07–12, none of which have
   started. Recorded plainly rather than left to imply more progress than exists.
4. **Left §4 (Testing) entirely unbuilt and unrun**, at the user's explicit instruction
   ("you do not have to worry about testing too much I'll do the testing"). Added a note
   at the top of that section recording the decision and pointing to `plans/TODOS.md`
   for the itemized list of what it specifies. The exception: tests that ship alongside
   a specific code change from steps 03–05 (the F6 asset-access-cookie suite, the shared
   package's own behaviour suite) already exist and already pass — those aren't part of
   what's being deferred, they were never in question.
5. **§3, §5, §7 left as-is** — reviewed for accuracy against what actually happened and
   found still correct (the breaking-changes handling table, the rollback table, and the
   "deliberately left open" list all describe decisions and designs that haven't been
   contradicted by anything built so far).
6. **All open items consolidated into `plans/TODOS.md`**, per the user's separate
   request in this session — that file is now the canonical punch list across the whole
   plan, not just this document's own Q-table.

## Files changed

- `afsserver/plans/checkpoint-server-auth/06-rollout-and-testing.md` — §1 (Q-table
  status column + note), §2 (phases status column), §4 (deferred-testing note), §6
  (per-line status). §3, §5, §7 unchanged.
- `afsserver/plans/TODOS.md` — Step 06 section expanded with the full §4 testing
  itemization; intro line updated to note 06 now has a completion doc.

## Verification steps

- Cross-checked every Q-table and phase-table status claim against the actual
  completion docs for 00/03/04/05 (not re-derived from memory) before writing it into
  06's own document, so the two don't silently disagree.
- Confirmed via direct reading that §3 (breaking changes), §5 (rollback), and §7
  (deliberately-open items) still accurately describe the current state — none of the
  work done in steps 03–05 contradicts anything in those three sections.
- No code was written or tests run as part of this step itself — it's a documentation
  reconciliation pass, and is verified as such (accuracy of the update), not by running
  anything.

## Follow-up work

Everything in `plans/TODOS.md`'s "§4 — Testing: deliberately left for the user to run"
section is the concrete list:

1. The 7-test cross-app suite (§4.1), per app.
2. The 5 static checks (§4.2) — route enumeration is the hardest of the five; it needs
   a machine-readable public-route manifest per app first (today the register is a
   markdown table, not something a script can check route output against).
3. The 9 members-specific tests (§4.3) — reasoned-correct from the step 03 work, never
   executed against a running app with a database.
4. §4.3a/§4.3b (client and 2FA/primary-only matrices) — N/A until steps 07–11 exist.
5. The 5 cross-app integration tests (§4.4) — 3 of 5 are live now and untested; the
   other 2 are N/A until steps 07/08.
6. The manual per-app verification pass (§4.5) for the 5 apps that adopted the shared
   package this session.

Beyond testing, everything else still open is steps 06–15's remaining scope: market and
members onto the shared package (unblocks Q3/F3/phase 8's enforcement flip), and steps
07–12 in full (both native clients, 2FA, primary-only enforcement, the secondary
dashboard, the game engine) — none of which have started. `plans/TODOS.md` has the full
per-step detail.

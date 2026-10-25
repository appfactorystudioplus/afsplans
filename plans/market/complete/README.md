# Completion Reports

One file per finished step, named `NN_step_name_complete.md` — the same `NN_step_name` as the step
file it reports on. A step is not done until this file exists.

Why a separate file rather than editing the step: the step file is the **intent** and stays
readable as intent. This folder is the **record** — what actually shipped, what diverged from the
plan, and what was deliberately left out. Six weeks later the difference between those two is the
only thing that matters when something breaks.

## Rules

- **Never overwrite.** If the file already exists (a step revisited, a follow-up landed), APPEND a
  new dated section under a `---` rule. This mirrors the JoystickEngine_v2 convention for
  `plan/_guides/steps_plan_execution/`.
- **Absolute dates**, never "yesterday" or "last week".
- **List every file touched**, with its `MARKET_MODIFIER_ADDITION` tag placement (whole-file header
  vs. line-level), because that tag is the debugging index for this entire effort.
- **Be honest about what did not ship.** A step that landed 80% with two known gaps is far more
  useful recorded that way than recorded as "done".
- Step 18 additionally appends its report to
  `JoystickEngine_v2/plan/_guides/steps_plan_execution/something24-step-90-service_assets_market.md`.

## Template

```markdown
# Step NN — <step title> — completion report

**Date:** YYYY-MM-DD
**Projects touched:** brain / members / market / joystick_engine
**Status:** ✅ complete | ⚠️ complete with gaps | ⛔ blocked

## What shipped

<Prose. What now works that did not work before, described from the outside — what a user or a
calling service can now do.>

## Files created

| File | Project | Tag placement | Purpose |
|---|---|---|---|
| `path/to/file.php` | market | header | … |

## Files modified

| File | Project | Lines / regions | Tag placement | Change |
|---|---|---|---|---|
| `path/to/file.ts` | members | 112–160, 344 | line-level | … |

## Database changes

<Migrations added, columns changed, data backfilled. Include the exact `php artisan migrate`
invocation used and whether it was run against a database with existing rows.>

## Environment / config keys added

| Key | Project | Example value | Notes |
|---|---|---|---|

## Decisions made during execution that diverge from the plan

<Each one: what the plan said, what was done instead, and why. This section is the reason the file
exists — if it is empty, say "none" explicitly rather than deleting the heading.>

## Known gaps / deferred

<What was consciously not done, and which step or follow-up picks it up.>

## How this was verified

<Concrete. Which URL was loaded, which artisan command was run, which member ref was used, what the
observed output was. "Tested manually" is not a verification record.>

## Follow-ups spawned

<Links to other step files, or new work items.>
```

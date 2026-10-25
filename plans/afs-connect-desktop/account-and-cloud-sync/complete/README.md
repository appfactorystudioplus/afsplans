# Account & Cloud Sync — Completion Notes

**Status:** 🟡 Partial — server-side auth done, desktop client and all sync phases not started
**Date:** 2026-08-01

## Completion documents

One completion document per plan document — source basename plus `.complete.md`, recording
status, summary, files changed, verification steps, and follow-up work.

| Doc | Completion | Status |
|-----|------------|--------|
| [01-cloud-sync.md](../01-cloud-sync.md) | — | Draft; not started |
| [02-engine-live-sync.md](../02-engine-live-sync.md) | — | Draft; not started |
| [03-coordinator-pm-sync.md](../03-coordinator-pm-sync.md) | — | Draft; not started |
| [04-desktop-apps-report-to-coordinator.md](../04-desktop-apps-report-to-coordinator.md) | — | Draft; not started |
| [05-flow-cloud-sync.md](../05-flow-cloud-sync.md) | — | Draft; not started |
| [06-studio-authentication.md](../06-studio-authentication.md) | [06-studio-authentication.complete.md](06-studio-authentication.complete.md) | 🟡 Partial — C1a, C1a′ only |
| [07-unified-sync-orchestration.md](../07-unified-sync-orchestration.md) | [07-unified-sync-orchestration.complete.md](07-unified-sync-orchestration.complete.md) | 🟡 Partial — S0.5, S0.5g only |

## Phase-to-document mapping

This plan names its phases with IDs (S0.5, C1a, …) defined in
[07-unified-sync-orchestration.md](../07-unified-sync-orchestration.md), and the completion
notes were originally filed under those IDs. They are now filed under the plan document
each one implements, with the phase IDs kept as section headings:

| Phase | Was | Now in |
|-------|-----|--------|
| **S0.5** — server-side bearer enforcement (Coordinator, Flow) | `S0.5-server-side-bearer-enforcement.md` | [07-unified-sync-orchestration.complete.md](07-unified-sync-orchestration.complete.md) |
| **S0.5g** — extended to Notes Pro, Dream Graph, PDF Editor | `S0.5g-notes-dream-graph-pdf-editor.md` | [07-unified-sync-orchestration.complete.md](07-unified-sync-orchestration.complete.md) |
| **C1a** — desktop `IS_PRODUCTION` flag | `C1a-desktop-is-production-flag.md` | [06-studio-authentication.complete.md](06-studio-authentication.complete.md) |
| **C1a′** — Checkpoint app registration | `C1a-prime-checkpoint-app-registration.md` | [06-studio-authentication.complete.md](06-studio-authentication.complete.md) |

Other plans cite these in prose as "the S0.5 completion notes"; that content is now under
the S0.5 heading in doc 07's completion file.

## What is done

The **server-side half of auth** (S0.5 / S0.5g) across all six member-scoped apps in this
plan's orbit — Coordinator, Flow, Notes Pro, Dream Graph, PDF Editor — which previously
resolved member scope from a client-supplied `X-User-Id` header, plus two small desktop
prerequisites (C1a, C1a′) that do not depend on the rest of C1.

One deliberate gap remains open: Dream Graph's asset image and texture serving stays
unauthenticated, because at least eight frontend call sites load it through `<img src>`
and cannot attach a header. Details and the intended signed-URL fix are in doc 07's
completion file.

## What is not started

The desktop client itself — `StudioAuthService` (C1b), the secondary-user login guard
(C1c), SSO wiring (C1d), unifying `settings.ts` (C1e), 2FA (C2) — and every sync phase
after S1: S2 Coordinator PM sync, S3 Flow, S4 Dream Graph, S5 hub reporters, S6 engine live
sync.

**No sync UI may ship until C1 lands.** S0.5 removes the server-side blocker but does not
substitute for it. See
[07-unified-sync-orchestration.md](../07-unified-sync-orchestration.md) for the
authoritative phase order.

## Related

The validator duplication that S0.5/S0.5g left behind — the same class copied across five
apps — is picked up as its own step by
[checkpoint-server-auth/04-shared-validator.md](../../../checkpoint-server-auth/04-shared-validator.md),
which builds on this work rather than revisiting it.

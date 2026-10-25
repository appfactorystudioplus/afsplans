# 09-replication-later — Completion

**Status:** Done, as the plan defines "done" for this step — it has no phases and explicitly says not to build
anything. What "starting" it means is: verify the hooks §1 claims actually exist, and check §4's rule is not being
violated. Both done. One small correction made (below), no replication feature of any kind. Nothing was committed.
Development mode: `vagrant`.

Changed: **members** only (`StorageNode::primaryDevice()` and five call sites that now use it, plus a two-test file
pinning the seam).

## Summary

Re-read the real code behind every row of §1's table (not re-derived from memory or from earlier completion docs):
all seven hold. D6 (device recorded per file), D9 (one hostname per device), D8 (tokens name one device), D5
(placement returns a device), D7 (members commits only after device confirmation), idempotent `/internal/ops`, and
`sha256` on every committed file are all exactly where the plan says they'd be, built incidentally by steps 03–06.

§4 bans two things: adding any copies table/column/job (not done — confirmed by grep, nothing new exists), and
scattering direct `device` reads instead of one helper. The second was **already being violated in a small way**:
`$node->device` was read directly at three sites in `StorageTreeController` (the download-refusal check, the
download-url endpoint) and two in `StorageSessionController`'s replace-commit logging. Added
`App\Models\StorageNode::primaryDevice(): ?int` — today literally `return $this->device;`, nothing more — and
routed those five sites through it. This is the "one helper" the plan asks for, done now while it costs nothing,
so that whenever `members_storage_node_copies` is eventually designed, this method is the one place to change instead
of a repeat of this same small audit.

## Verification

| Check | Result |
|---|---|
| Every §1 claim re-checked against source, not documentation | 7/7 confirmed |
| `grep` for any copies table/replica column/replication job anywhere in the three repos | none found |
| `StorageNodePrimaryDeviceTest` | 2 pass |
| members full suite (after the refactor) | 219 tests, 0 failures, 6 skipped (the opt-in e2e tests) |

## Not done (by design — this step has no phases)

Everything in §2 (`members_storage_node_copies`, replica-aware placement, async copy jobs, replica-aware download
fallback, repair/scrubbing) remains undesigned beyond the plan's own sketch. Scheduling it is, per the plan, "a
separate decision, made when a device loss becomes a risk the business will not carry" — not this session's call.

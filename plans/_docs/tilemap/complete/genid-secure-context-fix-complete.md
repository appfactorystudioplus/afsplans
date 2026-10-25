# genId() Secure-Context Crash — COMPLETE

**Real root cause of the drag-and-drop failure**, found via the browser console after the previous (text/plain MIME-type) hypothesis turned out not to be it. The actual error:

```
Uncaught TypeError: crypto.randomUUID is not a function
    at genId (projectStore.ts:23:28)
    at addNodeAt (projectStore.ts:303:17)
```

## Root cause

`crypto.randomUUID()` is a **secure-context-only** API per spec — it's only defined when the page is served over HTTPS or from literal `localhost`. The Herd dev site is served over plain `http://dream_graph.test`, which is neither, so the browser never defines `crypto.randomUUID` at all. Every call to `genId()` — used by every single node-creation path, including palette drag-and-drop, node duplication, and the quick-create-on-drag-release ("hub") flow — threw immediately inside a React event handler, with no visible UI feedback. This is why the previous MIME-type fix (a real, legitimate cross-browser issue, kept as a hardening) didn't resolve it: the drop event itself was firing and reading the correct type string fine, but `addNodeAt` blew up one line later, before the node was ever added to state.

## The fix

[`projectStore.ts`](../../../apps/dream_graph/resources/js/graph/store/projectStore.ts)'s `genId()` now checks for `crypto.randomUUID` and, when it's unavailable, builds an RFC 4122 v4 UUID from `crypto.getRandomValues()` instead — which has no secure-context restriction. `genId` is now exported (it was previously private to the module) and reused in [`GraphCanvas.tsx`](../../../apps/dream_graph/resources/js/graph/canvas/GraphCanvas.tsx)'s node-duplicate handler, which had its own separate `crypto.randomUUID()` call with the same latent bug.

## Verification

New [`genId.test.ts`](../../../apps/dream_graph/resources/js/graph/store/__tests__/genId.test.ts) (3 tests): uses the real `crypto.randomUUID` when present, falls back correctly when it's deleted from the global (simulating an insecure context), and confirms the fallback path still produces unique ids across repeated calls. Verified red→green via `git stash` on `projectStore.ts` — without the fix, the two fallback-path tests threw `TypeError: genId is not a function` (it wasn't exported yet), confirming the test suite actually exercises the change.

Full suite: 192/193 vitest tests pass (1 pre-existing unrelated floating-point failure in `scripting.test.ts`, unchanged). `npm run build` compiles cleanly (306 modules). Note: `npx tsc --noEmit` itself currently fails project-wide with `error TS5103: Invalid value for '--ignoreDeprecations'` — this is pre-existing tooling drift (no local `typescript` devDependency pinned; tsconfig's `ignoreDeprecations: "6.0"` isn't valid for the resolved 5.9.3 compiler) unrelated to this fix, and doesn't affect `vite build`, which is what actually ships.

Also fixed in the same pass (cosmetic, requested alongside the drag-and-drop report): the "Back to Projects" button is icon-only now (label moved to tooltip), and the Play/Save buttons share a matched `minWidth` so the toolbar doesn't jitter when "Save" becomes "Saved".

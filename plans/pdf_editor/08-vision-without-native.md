# Step 8 (web) — Vision acceleration without the native add-on

**Desktop step:** [08-native-addon-pdf-vision.md](../../../../afsdesktop5/plans/pdf_editor/08-native-addon-pdf-vision.md). Status: planning only. This is the file that answers "what can the web do instead of the C++ add-on?".

## Finding

The desktop `pdfvision` add-on is a Node-API module (C++ with FFmpeg 6.1.1). **A browser cannot load it.** Everything it does must have a web equivalent or be dropped. The desktop plan already requires a **pure TypeScript twin** for every add-on function with the same interface, so the web needs no new algorithms — only a place to run them.

## Function-by-function

| Add-on function | Web route |
|---|---|
| decode / scale / crop / rotate | `createImageBitmap`, `OffscreenCanvas` (`drawImage` with smoothing), no FFmpeg needed |
| JPEG2000 decode | pdf.js decodes JPX itself (its own wasm decoder); nothing else needed |
| contrast / levels / curves / normalise | TS twin on `ImageData` (the core math is plain loops) |
| binarise (Otsu/Sauvola), denoise, morphology | TS twin; hot loops structured for later WASM |
| skew estimate + deskew | TS twin (projection profile); rotate via canvas |
| layout segmentation, ruling lines, connected components | TS twin |
| inpainting (remove text from an image) | TS twin (diffusion fill) |
| fast thumbnails | canvas |
| image-diff metrics for tests | Node harness only (not shipped to browsers) |

## Three ways to go faster than TypeScript

1. **TypeScript twins in Workers (baseline, shipped).** Cost: slower than native on big scans; measured per function in the Step 11 benchmark and published.
2. **WebAssembly build of a dependency-free `pdfvision_core`.** The desktop plan keeps FFmpeg-dependent code out of the core so the core is portable C++. Building it for the web needs **Emscripten (`emcc`), which is not installed** on this machine, so this is a **spike that requires the owner's approval to install the toolchain**. If approved: build `pdfvision_core.wasm` (SIMD), load in the same Worker, same interface; threaded builds additionally require cross-origin isolation (COOP/COEP) on the app origin — a deployment decision, not assumed.
3. **Server-side job** (Laravel queue; the `jobs` table already exists): an optional, opt-in route for heavy documents using a native CLI built from the same core. Trade-offs: page images leave the browser (privacy), server CPU, queue latency. Not part of the baseline.

## Recommendation

Ship (1). Run the benchmark in Step 11; only if a function is unacceptably slow in TypeScript, approve (2) for that function; keep (3) as a documented escape hatch.

## Work

- **P0:** adopt the shared TS twins in a `VisionWorker`; capability probe (`OffscreenCanvas`, SIMD) with clear messages.
- **P1:** benchmark against the desktop native results on the shared corpus; write the numbers into the completion record.
- **P2 (gated on approval):** Emscripten spike for `pdfvision_core`; go/no-go from measured gain.

## Acceptance

All Step 7 flows work in the browser with TypeScript twins only; the benchmark records the speed gap; any WASM or server work has an explicit owner decision recorded in `_OPEN_ITEMS.md`.

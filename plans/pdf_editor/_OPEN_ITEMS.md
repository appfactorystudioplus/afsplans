# Web PDF editor plan — open items

Decisions shared with the desktop are in [the desktop `_OPEN_ITEMS.md`](../../../../afsdesktop5/plans/pdf_editor/_OPEN_ITEMS.md). Open for the web only (each has a default the plan uses):

1. **Emscripten for a WASM `pdfvision_core`** ([08](08-vision-without-native.md)). Default: do not install anything; ship TypeScript twins and benchmark. Say yes if you want the WASM spike (installs a toolchain).
2. **Server-side recognition/vision jobs.** Default: none (privacy: page images stay in the browser). Say yes only if the TypeScript speed proves too slow.
3. **Upload limit and quota.** Default: 500 MB per file, 5 GB per member.
4. **Cross-origin isolation (COOP/COEP)** for threaded WASM. Default: not enabled (it breaks embedding of third-party content).
5. **Desktop↔web exchange.** Default: formats shared (`.afspdf`, `.afstemplate`), no sync.
6. **Font hosting.** Default: our origin, with an on-demand server fetch from Fontsource for extra families.

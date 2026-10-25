# Step 7 (web) — Image text analysis and OCR

**Desktop step:** [07-image-text-analysis-and-ocr.md](../../../../afsdesktop5/plans/pdf_editor/07-image-text-analysis-and-ocr.md). Status: planning only.

Tesseract is the shared engine, so recognition, thresholds, bold/italic/size inference, cluster-to-line logic and the review markers are the same shared code. Deltas:

- **Self-hosted tesseract.js** (D-W7): worker, core (SIMD and non-SIMD builds) and `eng.traineddata` served from the app's own `/vendor/tesseract/`; other languages from `/vendor/tesseract/lang/<code>.traineddata.gz`, fetched on demand and cached with the Cache API. No CDN calls at run time (today tesseract.js defaults to one).
- **Preprocessing** (enlargement 3×, contrast, deskew, binarise) uses the TypeScript twins from [Step 8](08-vision-without-native.md) on `OffscreenCanvas`; accuracy will be measured against the desktop numbers and any gap recorded, not assumed.
- **Pool:** a Worker pool (≤ 4) with a page queue; cancel on navigation; progress is the standard job progress.
- **Results** are written into the model (`recognised` text elements with confidence and `reviewed` state) and the search index, exactly as on the desktop.
- **Privacy statement:** recognition happens in the member's browser; page images are not sent to the server.
- **Optional server-side recognition** (only if the speed gap proves unacceptable): a queued Laravel job calling a native Tesseract on the server, opt-in per member, documented in [08](08-vision-without-native.md).

Tests: the shared OCR corpus run in Chromium Workers; accuracy and time reported side by side with the desktop run; language pack download/caching; offline after first load.

# Step 14 (web) — Page and document tools

**Desktop step:** [14-page-and-document-tools.md](../../../../afsdesktop5/plans/pdf_editor/14-page-and-document-tools.md). Status: planning only.

The web editor already has most of this tool list (see the desktop table: blank/insert/delete/reorder pages, export selection, stamps, signature, pen/highlighter, shapes, redact-cover, image insert, forms, search, OCR, versions, shares). The work is to **move each onto the shared model operations** so both editors behave identically, and to add what is new for both:

- **New on web:** library-level merge, split (N pages, ranges, bookmarks, blank pages), images→PDF, rotate/crop/page size/duplicate/move-to-document, metadata editor, **true redaction** (content removed, verified by re-extraction), outline/page-label/link editors, compress, PDF→images, compare.
- **Library-level tools** create **new documents** server-side via the normal save path; sources are untouched.
- **Shares:** stay a web-only feature (the desktop exports instead).
- **Print** uses the browser print pipeline from rendered pages.
- **Search index** built in a Worker from the model (including recognised text).

Tests: shared page-op property tests; parity fixtures with the desktop (page lists, stamps, search hits); redaction proof on the tricky corpus; Playwright flows for page manager, merge/split dialogs.

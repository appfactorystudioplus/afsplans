# Step 13 (web) — Templates

**Desktop step:** [13-templates.md](../../../../afsdesktop5/plans/pdf_editor/13-templates.md). Status: planning only.

Template components and template pages (`afs-template/1`), the instantiation engine, ipsum/dummy content, shapes, formatting and the guided Fill mode are shared code and a shared format. Web deltas:

- **Storage:** `pefeditor_templates` (member, kind `component|page`, name, folder, `json_path`, timestamps) with the JSON on the member's disk; built-in starter templates ship as static JSON and are read-only. Schema per `w/AGENTS.md` §5.
- **Import/export:** `.afstemplate` files upload/download (zip), compatible with the desktop; this is the only desktop↔web exchange path planned (no sync).
- **Dummy images:** generated in the browser (canvas) so no network is needed.
- **Sharing templates** between members reuses the existing share model (member/link, view/edit) — a later addition.

Tests: shared template fixtures produce identical models in both editors; Fill mode keyboard flow; round-trip `.afstemplate`.

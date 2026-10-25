# plans/members

Plan home for the `w/members` website app — the member-facing portal (Laravel), distinct from
`w/www` (the public/marketing/builder app) and `w/communications` (the new real-time backend).
Following this repo's existing convention (`plans/afs-connect-desktop` hosts multiple
`afsdesktop5` feature plans in subfolders; this folder does the same for `w/members`), features
live in subfolders here as they're planned.

## Feature plans

| Folder | Feature | Code lives in |
|---|---|---|
| [`video_communications/`](video_communications/README.md) (pointer) | Zoom/Teams-style video conferencing — website host/join client. The plan itself lives in [`../video_communications/website/`](../video_communications/website/00-overview.md) | `w/members` (client), `w/communications` (backend, planned in [`../video_communications/`](../video_communications/00-overview.md)) |

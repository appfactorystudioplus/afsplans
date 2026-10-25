# 05 — Feature: Asset Upload & Management

> **Requirement:** "a way to upload assets like images and manage those assets."

Articy equivalent: the **Assets** section — imported media with folders, metadata,
and references from entities/nodes. Ours is browser-native: drag files in, organize,
reference anywhere, always know where an asset is used.

## 1. Scope

| In v1 (phase 4) | Later |
|---|---|
| Images (png/jpg/webp/gif/svg) | Video previews with scrubber |
| Audio (mp3/ogg/wav) with inline player | Voice-over pipeline (line ↔ take matching) |
| Docs (pdf/txt/md) stored + downloadable | External storage sync (Drive/Dropbox) |
| Folder tree, rename, move, tags | Asset versions history |
| Thumbnails + previews | Image annotations |
| "Where used" + safe delete | |
| Replace-in-place (same UUID) | |
| Dedupe by checksum | |

Limits (config): 25 MB/file default (chunked upload raises to 500 MB for audio/video),
per-project quota setting, MIME allow-list enforced server-side (never trust extension).

## 2. UX design

### Assets panel

A left-dock tab (next to the node palette) plus a full-screen **Asset Manager** view:

- **Folder tree** (create/rename/drag-move folders) + flat "All / Recent / Unused" smart views.
- **Grid or list** of asset cards: thumbnail, name, kind badge, size; hover = larger
  preview; audio cards get a mini play button.
- **Toolbar**: Upload button, search box (name + tags), kind filter, sort.
- **Multi-select** with shift/cmd; bulk move/tag/delete.
- **Details drawer** (select an asset): big preview, metadata (dimensions, duration,
  size, uploader, date), editable name + tags, folder, **Usages list** (each usage row
  = breadcrumbed link: "Portrait of *Bartender*", "Background in *Tavern Scene ▸ Intro*"),
  Replace and Download buttons.

### Getting assets in

1. **Drag files onto the Assets panel** → uploads into the current folder.
2. **Drag files onto the canvas** → uploads *and* creates an Image node at the drop
   point (or attaches as the selected node's image if dropped on a node — v2).
3. Upload button (multi-file picker). Paste-from-clipboard (screenshots) — cheap win.

Upload UI: per-file progress rows, cancel, parallel×3; failures retry-able. Duplicate
checksum in the same project → offer "Use existing asset" instead of storing a copy.

### Using assets

- **Asset picker** component (searchable modal, reused everywhere a `asset_ref`
  property appears): entity portraits, image node source, template properties of type
  `asset_ref`, location backgrounds (later).
- **Image node** type renders the image on canvas (with natural-size cap).
- **Dialogue nodes** show the speaker entity's portrait thumbnail automatically —
  this is the visible payoff that makes the asset system feel alive.

### Deleting safely

- Delete = soft delete. If the asset has usages → dialog lists them; require explicit
  "Delete anyway" which nulls references (nodes render a "missing asset" placeholder,
  never crash).
- "Unused" smart view + bulk delete = janitor workflow.

## 3. Backend design

- **Storage:** Laravel filesystem, disk `assets` (local `storage/app/assets/{project_uuid}/…`
  in dev; S3-compatible in prod — path layout identical). Never store under `resources/`
  (see the mess flagged in doc 01 §5.3).
- **Package:** `spatie/laravel-medialibrary` is the fast path (conversions, responsive
  images, S3, streaming). If we'd rather stay lean: `intervention/image` for thumbnails
  + ~200 lines of service code. Recommendation: **medialibrary** — thumbnailing,
  conversions queue, and disk abstraction are exactly its job.
- **Thumbnails:** queued job on upload → 256px + 1024px webp conversions; audio gets
  waveform PNG later (nice-to-have); pdf first-page thumb via imagick if available,
  else generic icon.
- **Serving:** signed URLs (`/assets/{uuid}/{conversion?}`) with long cache headers;
  auth check = project membership. SVGs served with `Content-Disposition` +
  sanitization (svg-sanitizer) because SVG = XSS vector.
- **Checksums:** sha256 computed on upload; unique-per-project lookup for dedupe prompt.
- **Chunked uploads** (phase 4 v2): resumable endpoint (pion/laravel-chunk-upload or
  tus) — only needed once audio/video lands.
- **Validation:** MIME sniff server-side, image dimension caps, quota check, filename
  normalization (keep original name as metadata only; storage names are `{uuid}.{ext}`).

Schema is in doc 03 §2 (`assets`, `asset_folders`); usage discovery convention:
every reference in node/entity JSON uses key `asset_uuid` (or `…_asset_uuid`), so
`GET /assets/{id}/usages` is one JSON-path scan per table, or a maintained
`asset_usages(asset_id, referrer_type, referrer_id, role)` pivot if profiling demands.

## 4. Frontend implementation notes (React)

- `panels/assets/` components: `AssetPanel` (folder tree + virtualized grid),
  `UploadQueue` (react-dropzone + XHR progress, parallel×3, cancel/retry),
  `AssetPicker` (promise-based modal: `pickAsset({kind:'image'}) → uuid` — reused by
  the Inspector for every `asset_ref` field, entity portraits, image nodes),
  `AssetDetails` drawer with usages list.
- ProjectStore caches the asset catalog (metadata only — a few KB per 100 assets);
  thumbnails lazy-load with `loading="lazy"`; grid virtualizes past ~200 items.
- `GraphCanvas` `onDrop` branches: palette drag (`application/dreamgraph-node`) →
  create node; `Files` → upload into current folder, then create an Image node at
  `screenToFlowPosition(event)`; drop *onto* a node with an image slot → attach (v2).
- Image node component caps rendering at natural size / 480px and uses the 256px
  conversion until zoomed in past 100% (cheap semantic-zoom win).

## 5. Acceptance criteria (phase 4 exit)

1. Drag 10 images onto the panel → thumbnails appear as each finishes; reload — all present.
2. Drop an image file on the canvas → Image node appears at drop point showing it.
3. Assign a portrait to an entity via picker; the portrait shows on that speaker's
   dialogue nodes; asset details lists both usages with working links.
4. Deleting a used asset warns and, if forced, nodes show placeholder not errors.
5. Replace-in-place swaps the file everywhere without touching any node data.
6. A non-member of the project cannot fetch the asset URL (403).

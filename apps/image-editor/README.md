# DAVVAG Image Editor

Version 0.2.0 starts Phase 2 while preserving the secure Phase 1 editor for
thumbnails, posters, social graphics, and reusable tenant templates.

Version 0.2.1 keeps the templates compatible with the dock's Vue 2.0.3 runtime.
It fixes the null multi-selection render error in the editor and restores the
My Designs loading/empty/results branch so returned projects render correctly.

## Phase 2 slice: pages and versions

- Designs can contain up to 30 same-size pages, with a maximum of 500 elements
  per page and 2,000 elements across the document.
- Users can add, duplicate, delete, reorder, and switch pages. Page deletion is
  recoverable through session undo until the editor is closed.
- Revision persistence now writes and verifies every page in an immutable
  revision, and asset/font authorization scans every page.
- The editor exposes named versions, lists immutable revision history, and
  restores a selected revision by creating a new completed restore revision.
- Raster export currently downloads the active page. All-page PDF export remains
  a later Phase 2 slice.

## Included in the first working slice

- DAVVAG app routes for projects, editor, template gallery, fonts, and admin tools.
- Fabric.js 7.4.0 runtime vendored locally with its MIT license.
- Single-page canvas editing: text, rectangles, ellipses, triangles, lines, images,
  selection, move/resize/rotate, alignment, layer ordering, locking, hiding,
  duplication, background color, zoom, undo, and redo.
- Versioned and normalized JSON documents persisted as immutable revisions.
- Owner-scoped projects and assets, tenant template publishing, soft lifecycle states,
  optimistic save conflict detection, and idempotent save keys.
- PNG/JPEG/WebP image validation and protected media access.
- Built-in DejaVu Sans plus private or tenant TTF/OTF uploads, server-side font
  inspection, licensing attestation, approval workflow, and dynamic FontFace loading.
- PNG/JPEG export at 1x, 2x, and 3x after document fonts are ready.

## Remaining work

- Generate stored project/template preview thumbnails after saves and publishes.
- Add visual crop-box handles and additional non-destructive image filters beyond
  the current crop offsets, brightness, and grayscale controls.
- Add WOFF/WOFF2 parsing; the first slice intentionally accepts TTF and OTF only.
- Add copy/paste across pages, grouping, distribution, gradients, shadows, masks,
  richer filters, PDF export, brand libraries, favorites, and richer search.
- Run datastore migrations and browser-level tenant integration tests in a configured
  DAVVAG environment.

## Validation

From the repository root:

```powershell
C:\xampp\php\php.exe davvag-core\localhost\apps\image-editor\tests\document_rules_test.php
node davvag-core\localhost\apps\image-editor\tests\workspace_render_test.js
```

The app's schema files are service-only. All reads and writes go through the app
service and are authorized against the current DAVVAG profile.

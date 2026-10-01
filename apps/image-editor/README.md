# DAVVAG Image Editor

Phase 1 establishes a secure single-page design editor for thumbnails, posters,
social graphics, and reusable tenant templates.

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

## Phase 1 follow-up work

- Generate stored project/template preview thumbnails after saves and publishes.
- Add visual crop-box handles and additional non-destructive image filters beyond
  the current crop offsets, brightness, and grayscale controls.
- Add WOFF/WOFF2 parsing; the first slice intentionally accepts TTF and OTF only.
- Add searchable template/font pagination and richer revision-history UI.
- Run datastore migrations and browser-level tenant integration tests in a configured
  DAVVAG environment.

## Validation

From the repository root:

```powershell
C:\xampp\php\php.exe davvag-core\localhost\apps\image-editor\tests\document_rules_test.php
```

The app's schema files are service-only. All reads and writes go through the app
service and are authorized against the current DAVVAG profile.

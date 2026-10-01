# Build the DAVVAG Image Editor

Create a new DAVVAG application in:

`C:\xampp\htdocs\davvag-core\davvag-core\localhost\apps\image-editor`

Follow the architecture authority in:

`C:\xampp\htdocs\davvag-core\DAVVAG-Framework-App-Development-AI-Context.md`

The `localhost` tenant path is a symbolic link to `C:\xampp\htdocs\apps.davvag.com`. Resolve the active tenant again before implementation and treat changes through the alias as changes to the tenant repository. At the time this instruction was written, the `image-editor` directory was empty and the app was not registered in the tenant or group files.

## Goal

Build a browser-based visual design application for creating thumbnails, posters, social-media graphics, and reusable tenant templates. The experience should be inspired by Canva's core editing workflow, but do not copy Canva's branding, proprietary templates, assets, source code, or entire product scope.

The first release must let an authenticated user:

1. Start from a size preset, blank custom canvas, or published template.
2. Add and edit text, images, shapes, lines, and backgrounds.
3. Select, move, resize, rotate, align, reorder, lock, hide, duplicate, and delete elements.
4. Undo and redo editing actions.
5. Save work safely and reopen it without visual or data loss.
6. Export a high-quality PNG, JPEG, or WebP file.
7. Duplicate an existing design.
8. Create a personal template from a design.
9. Upload a licensed font, apply it to text, and preserve that typography in saved designs and exports.

An authorized template publisher or administrator must also be able to review, publish, unpublish, categorize, and archive tenant templates. Published templates must be reusable without granting permission to modify their source.

## Scope and Delivery Phases

Implement a dependable single-page editor before advanced design features.

### Phase 1: required release

- Authenticated project dashboard.
- Blank-design presets and custom pixel dimensions.
- Single-page canvas.
- Text, image, rectangle, ellipse, triangle, and line elements.
- Solid background color.
- Selection, drag, resize, rotate, duplicate, delete, lock, hide, and layer ordering.
- Alignment controls and optional center/edge snapping guides.
- Text family, size, weight, style, alignment, color, line height, and letter spacing.
- Built-in fonts plus secure upload and use of private or tenant-approved custom fonts.
- Font loading status, fallback warnings, and export-time font verification.
- Shape fill, stroke, stroke width, corner radius where applicable, and opacity.
- Image upload, replace, crop, fit/fill, opacity, flip, and reliable basic filters.
- Zoom, pan, fit-to-screen, and actual-size controls.
- Undo and redo for the current browser session.
- Debounced autosave plus explicit Save.
- Project list, rename, duplicate, archive, restore, and soft-delete behavior.
- Tenant template gallery and create-from-template.
- Template draft, preview, publish, unpublish, and archive workflow for authorized publishers.
- PNG, JPEG, and WebP export with configurable bounded scale and quality.
- Desktop-first responsive layout that remains usable on tablets.

### Phase 2: only after Phase 1 passes

- Multiple pages.
- Groups and ungrouping.
- Copy/paste across pages, multi-selection, and distribution.
- Gradients, shadows, masks, and additional filters.
- Named revision history and revision restore.
- PDF export through an approved local PDF capability.
- Brand kits, shared palettes, and logo libraries.
- Template favorites and richer search.
- Safe server-mediated remote-image import.
- Comments, sharing, and collaboration after a separate permission/concurrency design.

Do not delay Phase 1 for real-time collaboration, animation, video editing, stock-media search, background removal, generative AI, print ordering, or a public marketplace.

## DAVVAG Framework Requirements

This must be a normal tenant app. Do not introduce a second runtime or standalone PHP entry point.

- Use `app.json` as the app contract and `app.php` as the standard entry file.
- Register browser code through `WEBDOCK.component().register(...)`.
- Initialize Vue-backed components through `exports.vue.onReady`, not `exports.onReady`.
- Put presentation and transient editor state in browser components.
- Put authentication, authorization, ownership, validation, persistence, state transitions, template publication, and export registration in a PHP service component.
- Use `SOSSData` and tenant schemas. Do not open a direct MySQL connection.
- Use existing auth/profile systems and resolve the active profile through the shared `profile` plugin pattern.
- Declare every app, schema, plugin, workflow, and PHP-extension dependency; use empty arrays when unused.
- Lock every service-backed button for the full request and release it on success and failure.
- Retry only transport failures, never real service failures or state-changing POSTs automatically.
- Scope CSS under an image-editor root class for Webdock/admin-dock compatibility.
- Bump app and affected component/service versions after resource or descriptor changes.
- Merge tenant/group registrations without removing existing entries.

## Reuse Existing DAVVAG Capabilities

Depend on `davvag-tools` and reuse:

- `davvag-file-uploader` for uploads and progress.
- `davvag-img-cropper` for fixed-aspect image cropping where suitable.
- Shell `soss-uploader` behind the shared wrapper.
- Shell `soss-routes` for navigation.

Use `exports.getAppComponent("davvag-tools", ...)` and declare the app dependency. Do not copy Cropper.js or uploader code into this app.

The layered editor is a separate capability. It may use a mature canvas/object library, but that library must be:

- License-compatible and pinned to an exact tested version.
- Loaded locally through declared resources, never solely from a CDN.
- Wrapped by an app-owned renderer adapter.
- Documented with version, license, source, and modifications.

Fabric.js is an acceptable candidate after compatibility testing. If another renderer is selected, document why. Do not build a large object engine from scratch without evidence that maintained libraries cannot meet the requirements.

The stored document format must be application-owned and versioned. Do not persist an unrestricted, opaque library `toJSON()` result. Convert through a safe adapter.

## Recommended Structure

```text
image-editor/
|-- app.json
|-- app.php
|-- appicon.png
|-- README.md
|-- instruction.md
|-- assets/
|   |-- vendor/
|   |-- fonts/
|   `-- presets/
|-- components/
|   |-- workspace/
|   |-- editor/
|   |-- template-gallery/
|   |-- template-manager/
|   |-- font-manager/
|   |-- editor-runtime/
|   `-- image-editor-style/
|-- services/api/
|-- lib/
|   |-- ImageEditorDocument.php
|   |-- ImageEditorAccess.php
|   `-- ImageEditorMediaAccess.php
`-- tests/
```

Keep extra internal classes behind the DAVVAG service contract. Do not add framework-like controller/repository layers without a concrete need.

## Descriptor and Routes

Use app code `image-editor`, title `Image Editor`, and an initial version such as `0.1.0`. `description.icon` must be `appicon.png`, not `assets/appicon.png`.

Register at least:

- `workspace`: dashboard and create-design entry.
- `editor`: visual editor.
- `template-gallery`: published templates.
- `template-manager`: authorized template administration.
- `font-manager`: personal font uploads and authorized tenant-font administration.
- `editor-runtime`: pinned renderer and safe adapter.
- `image-editor-style`: shared scoped styles.
- `api`: PHP service.

Recommended routes:

```text
/                    -> workspace
/editor              -> editor
/templates           -> template-gallery
/fonts               -> font-manager
/admin/templates     -> template-manager
/admin/fonts         -> font-manager
```

Use `workspace` as `startupComponent`. Load `api`, `editor-runtime`, and `image-editor-style` through `onLoad` after verifying lifecycle/order. Never hard-code service URLs to compensate for a broken descriptor.

Use full dock hashes: `#/app/image-editor`, `#/app/image-editor/templates`, `#/app/image-editor/fonts`, `#/app/image-editor/admin/templates`, and `#/app/image-editor/admin/fonts`. Open a project with `#/app/image-editor/editor?id={projectId}`. Treat the query ID as untrusted and check it in `GetProject`.

## Visibility and Roles

Default registration:

- Add `image-editor` to `tenant.json`.
- Add it to `web_user.json` for authenticated creators.
- Add it to `sysadmin.json`.
- Do not add it to `anonymous.json` in Phase 1.

Visibility is not authorization. Server roles are:

- Creator: manage only their projects/assets/exports/private fonts; use published tenant templates and active tenant fonts.
- Template publisher: manage template drafts and publication, and select only tenant-approved fonts for published templates.
- Administrator: publisher capabilities plus tenant-font approval, moderation, and recovery.

Derive capabilities from DAVVAG auth/group access. Never trust browser fields such as `isAdmin`, `ownerProfileId`, or `canPublish`.

## User Experience

### Workspace

Show Create design, presets, custom pixel dimensions, recent project previews, title/size/update/status, search/status filters, and open/rename/duplicate/archive/restore/create-template actions. Provide loading, empty, error, and no-results states.

Include common thumbnail, landscape post, square post, portrait post, story, presentation, and poster presets as validated app configuration. Confirm current platform recommendations before production rather than treating preset labels as permanent external standards.

### Editor

- Top: back, project title, save state, undo/redo, preview, export.
- Left: templates, uploads, text, shapes, assets.
- Center: zoomable/pannable artboard on a neutral pasteboard.
- Context toolbar: selected-element controls.
- Right: layers and detailed properties.
- Bottom: zoom controls and later page controls.

The artboard must work at common laptop widths; panels may collapse on tablets. Mobile Phase 1 may provide viewing and limited editing rather than pretending to offer the full desktop editor.

### Interaction rules

- Click selects; locked elements cannot transform/delete; hidden elements do not render/export.
- Arrow keys nudge; Shift+Arrow nudges farther.
- Delete/Backspace removes unlocked selection without browser navigation.
- Ctrl/Cmd+D duplicates; Ctrl/Cmd+Z undoes; Ctrl/Cmd+Shift+Z and Ctrl/Cmd+Y redo where appropriate.
- Escape exits text editing or clears selection.
- Ignore editor shortcuts while typing in inputs/editable text unless explicitly supported.
- Project/template destructive actions require confirmation and recoverable status changes.

## Font Support and Font Uploads

Font support is required in Phase 1. The editor must provide a searchable font picker containing:

- A small documented set of locally bundled, license-compatible built-in fonts.
- Private fonts uploaded by the active profile.
- Active tenant fonts approved for all authorized tenant users.
- Browser-safe fallback families when a selected font cannot load.

Do not depend on a third-party font CDN at runtime. Built-in fonts and uploaded fonts must be served from DAVVAG-controlled same-origin storage so the editor, previews, and exports render consistently.

### Font upload workflow

1. The user opens Font Manager or chooses **Upload font** from the text toolbar.
2. The UI explains accepted formats, size limits, visibility, and the requirement to possess a valid license.
3. The user selects a font file and chooses `private` visibility. Only an administrator/font publisher may request `tenant` visibility.
4. The user supplies a display name only when it cannot be read safely from the font metadata, selects or describes the license, optionally uploads a license document, and explicitly attests that they have permission to use and store the font.
5. Upload through `davvag-file-uploader` into the dedicated `image_editor_font` namespace. Upload an optional license document into the separate `image_editor_font_license` media namespace.
6. The backend verifies staged-upload ownership, file type, size, signature, parseability, metadata, and duplicate checksum before registering it.
7. A valid private font becomes available to its owner. A tenant font remains pending until an authorized administrator approves it.
8. The browser loads the approved media reference through the `FontFace` API, adds the face to `document.fonts`, waits for it to become ready, and only then applies it to a canvas text element.
9. Failure leaves the previous font applied and shows an actionable message; it must not silently save or export a visually different font.

### Supported font formats

- Prefer WOFF2 for efficient browser delivery.
- Permit WOFF, TTF, and OTF only when the server-side validator and target browsers support them.
- Reject legacy EOT, Type 1, executable files, archives, variable/collection formats that the selected renderer cannot handle reliably, and files whose detected type does not match their contents.
- TTC/OTC font collections are out of scope for Phase 1 unless individual faces can be safely extracted by an approved server-side library.
- Variable fonts are out of scope for Phase 1 unless axes, persistence, reload, preview, and export are all tested. A variable font must never be presented as supported merely because one browser can open it.

Set explicit documented limits for file size, number of fonts per user/tenant, faces per family, and total font storage. Suggested starting limits are 10 MB per file, 25 private faces per user, and 100 active tenant faces, subject to deployment testing.

### Font identity and loading

- Assign every registered face a stable `font_id`.
- Read and store safe metadata such as family, subfamily, PostScript name, weight, style, stretch, and format. Do not trust client-supplied metadata as authoritative.
- Create a collision-resistant internal CSS family name such as `DAVVAGFont-{fontId}-{checksumPrefix}`. Keep the human display family separate.
- Represent regular, italic, and weight variants as separate font-face records related by normalized family name.
- Generate `FontFace` descriptors for weight/style/stretch from validated server metadata.
- Load only fonts referenced by the current document or visible picker page. Do not preload the entire tenant library.
- Deduplicate concurrent loads and cache successful faces for the browser session.
- Track `loading`, `ready`, `failed`, and `unavailable` states in the UI.
- Remove app-created faces/listeners during teardown where safe, without disrupting another open image-editor component using the same face.
- Never create CSS or a `FontFace` source from an arbitrary browser-supplied URL.

Text elements must persist `fontId`, safe fallback family, weight, style, size, and other typography properties. Every bundled or uploaded face receives a database-backed stable `fontId`; a display family name alone must not grant access. Seed bundled font records through an idempotent install/admin path. On load, the service returns only fonts the active profile may use, and the document validator rejects inaccessible font IDs.

### Font licensing and lifecycle

- Users must explicitly attest that they own the font or have a license permitting the selected private/tenant use.
- Store license type/name, optional source URL, optional protected license document reference, attestation profile, and attestation date.
- Do not label a font as open-source merely from its filename or family name.
- Tenant publication requires an administrator to confirm that the license permits organization/tenant use.
- Published templates may reference only built-in or active tenant fonts. They must not depend on a creator's private font.
- If a private-font project is converted to a tenant template, require replacement, authorized promotion of the font to tenant scope, or an explicit flatten-to-image workflow added in a later reviewed phase.
- Archiving a font prevents new use but must not physically delete a face referenced by retained revisions. Existing authorized owners must still be able to open/export their projects according to the retention policy.
- Reject new use immediately after a font is rejected, deleted, or loses tenant approval. Show affected documents a clear missing-font warning and require replacement before template publication.
- Font files and license documents are never public merely because a template preview is visible.

### Typography and export correctness

- Recalculate text layout after the exact face loads; do not preserve measurements produced with a fallback font.
- Save normalized text box dimensions and typography, but allow the renderer adapter to recompute glyph metrics deterministically after font readiness.
- Before preview generation or export, collect every referenced `fontId`, authorize it, load it, await `document.fonts.ready`, and verify `document.fonts.check(...)` for the required style/weight.
- Block export with a list of missing/failed fonts instead of silently substituting another face.
- Include font identity/checksum in preview and revision checksums where it affects deterministic rendering.
- Raster PNG/JPEG/WebP exports render glyphs to pixels. Future SVG/PDF export must separately verify whether the font license allows embedding or subsetting; do not embed uploaded font bytes by default.
- Cross-browser tests must confirm text wrapping, alignment, weight, italic style, and line height remain acceptably consistent after save/reload and export.

## Safe Document Contract

Define a renderer-independent versioned payload, conceptually:

```json
{
  "documentVersion": 1,
  "projectId": 123,
  "baseRevisionId": 456,
  "clientSaveKey": "uuid",
  "pages": [{
    "pageUuid": "uuid",
    "width": 1280,
    "height": 720,
    "background": {"type": "solid", "color": "#ffffff"},
    "elements": [{
      "elementUuid": "uuid",
      "type": "text",
      "name": "Heading",
      "zIndex": 10,
      "locked": false,
      "hidden": false,
      "properties": {}
    }]
  }]
}
```

Allowlist properties per element type. Common transforms include finite numeric `x`, `y`, `width`, `height`, `scaleX`, `scaleY`, `rotation`, `opacity`, `flipX`, and `flipY`. Type-specific properties cover text/typography, shape style, image asset/crop, or line endpoints.

Authoritative backend validation must:

- Reject unknown document versions, types, and properties.
- Reject `NaN`, infinity, invalid numbers, unsafe colors/URLs, oversized strings, impossible dimensions, duplicate UUIDs, invalid groups, and unreasonable z-indexes.
- Reject `__proto__`, `prototype`, and `constructor` at every nested level.
- Enforce recursion, page, element, text, property, and request-size limits.
- Require every asset to exist and belong to the owner or approved tenant library.
- Require every `fontId` to resolve, be authorized for the profile/project, and match the requested weight/style. New selection and new template publication require `active`; retained revisions may use an archived face only through the explicitly authorized retention path.
- Store media references, never data URLs/base64 image bodies.
- Normalize values for deterministic save/load cycles.

Suggested initial limits: one page, 500 elements, 10 MB request body, 32 KB properties per element, 8192 pixels per side, plus a total export-pixel limit. Verify PHP, datastore, and browser limits and document final values.

## Schemas

Create schemas under the resolved tenant with prefix `image_editor_`. Mark business schemas `serviceOnly: true` and declare them in the API's `serviceNamespaces`. Do not define DAVVAG system columns.

### `image_editor_project`

- `id`: auto-increment integer primary key.
- `owner_profile_id`: required integer relation to `profile.id`.
- `title`: string, max 255.
- `description`: bounded plain string.
- `design_type`: `thumbnail`, `poster`, `social`, `presentation`, or `custom`.
- `width_px`, `height_px`: positive bounded integers.
- `status`: `active`, `archived`, or `deleted`.
- `source_template_id`: nullable integer.
- `current_revision_id`: nullable integer.
- `preview_media_reference`: bounded string.

### `image_editor_revision`

- `id`: auto-increment integer primary key.
- `project_id`: required integer relation.
- `revision_number`: monotonically increasing per project.
- `revision_type`: `autosave`, `manual`, `template_publish`, or `restore`.
- `state`: `writing`, `complete`, or `failed`.
- `client_save_key`: bounded idempotency key.
- `base_revision_id`: nullable optimistic-concurrency ID.
- `document_version`: integer.
- `renderer_version`, `label`, `checksum`: bounded strings.
- `saved_by_profile_id`: required profile ID.

### `image_editor_page`

- `id`: auto-increment integer primary key.
- `revision_id`: required relation.
- `page_uuid`: bounded UUID.
- `page_number`: positive integer.
- `width_px`, `height_px`: positive integers.
- `background_json`: allowlisted `object`.

### `image_editor_element`

- `id`: auto-increment integer primary key.
- `page_id`: required relation.
- `element_uuid`: bounded UUID.
- `parent_uuid`: optional future grouping UUID.
- `element_type`, `name`: bounded strings.
- `z_index`: integer.
- `is_locked`, `is_hidden`: booleans.
- `properties_json`: allowlisted, bounded `object`.

Use one row per element rather than one unrestricted whole-design field. This bounds objects and avoids embedded images/oversized inline JSON.

### `image_editor_asset`

- `id`: auto-increment integer primary key.
- `owner_profile_id`: required profile ID.
- `project_id`: nullable integer.
- `media_reference`: required bounded string.
- `original_name`: bounded basename.
- `mime_type`, `extension`: allowlisted bounded strings.
- `byte_size`, `width_px`, `height_px`: positive integers.
- `asset_type`: `image`, `template_preview`, or `export` as applicable.
- `checksum`, `alternative_text`: bounded strings.
- `status`: `staged`, `active`, `archived`, `deleted`, or `rejected`.

### `image_editor_template`

- `id`: auto-increment integer primary key.
- `source_project_id`: required integer.
- `published_revision_id`: nullable integer.
- `title`, `description`: bounded plain strings.
- `category`: allowlisted bounded string.
- `tags_json`: bounded `object` array of normalized tags.
- `width_px`, `height_px`: positive integers.
- `preview_media_reference`: bounded string.
- `status`: `draft`, `published`, `unpublished`, or `archived`.
- `visibility`: `tenant` in Phase 1.
- `created_by_profile_id`, `published_by_profile_id`: profile IDs.

### `image_editor_font`

- `id`: auto-increment integer primary key and authoritative `font_id`.
- `owner_profile_id`: profile ID for uploaded faces; nullable only for idempotently seeded built-in faces.
- `source`: `builtin` or `upload`.
- `display_family`, `normalized_family`, `subfamily`, `postscript_name`: bounded metadata strings.
- `internal_family`: server-generated collision-resistant CSS family.
- `font_weight`: validated integer from 100 through 900.
- `font_style`: `normal`, `italic`, or `oblique` when supported.
- `font_stretch`: bounded allowlisted value.
- `format`: `woff2`, `woff`, `ttf`, or `otf` from server detection.
- `mime_type`, `byte_size`, `checksum`: server-derived bounded values.
- `media_reference`: protected font media reference.
- `visibility`: `private` or `tenant` in Phase 1.
- `license_type`, `license_name`, `license_url`: bounded license metadata.
- `license_media_reference`: optional protected license document reference.
- `attested_by_profile_id`: profile that confirmed upload rights.
- `attested_at`: date/time.
- `approved_by_profile_id`, `approved_at`: nullable tenant-approval audit values.
- `status`: `staged`, `pending`, `active`, `rejected`, `archived`, or `deleted`.
- `rejection_reason`: bounded administrator-safe text.

Relate profile fields to `profile.id`. Never accept approval/audit identities or timestamps from the browser. If a separate license schema becomes necessary later, preserve the same service-level access rules.

### Optional `image_editor_export`

- `id`: auto-increment integer primary key.
- `project_id`, `revision_id`, `created_by_profile_id`: integers.
- `format`: `png`, `jpeg`, or `webp`.
- `width_px`, `height_px`: positive integers.
- `quality`: bounded numeric value.
- `media_reference`: bounded string.
- `status`: `staged`, `complete`, `failed`, or `deleted`.

If export history is not shipped, omit this namespace and dependency instead of leaving an unused schema.

## Revision and Save Semantics

Saving must never corrupt the last complete design:

1. Authenticate and verify ownership/edit permission.
2. Validate and normalize the entire document.
3. Return the existing completed result for a duplicate `clientSaveKey`.
4. Compare `baseRevisionId` to the current revision; return a conflict instead of overwriting newer work.
5. Create a revision in `writing` state.
6. Insert validated pages/elements.
7. Re-read counts/checksum.
8. Mark the revision `complete`.
9. Only then update the project's `current_revision_id` and preview.
10. On failure, leave the prior pointer intact and mark/clean the incomplete revision through controlled maintenance.

Do not update completed revision element rows in place. Published revisions are immutable.

Autosave must be debounced, start only when dirty, allow one save in flight, queue one newest save when changes occur mid-flight, preserve dirty state on error, warn on unsaved navigation, and accurately show `Unsaved`, `Saving`, `Saved`, `Conflict`, or `Save failed`. A browser recovery copy is optional convenience, scoped by tenant/profile/project, never authoritative storage. Retain bounded autosaves and never remove referenced revisions.

If the adapter lacks a suitable transaction, the `writing` state plus final pointer update is mandatory. Do not claim atomicity the adapter cannot provide.

## API Service

Create `services/api/component.json` with an exact matching class such as `image_editor\ApiService`. Recommended handlers:

```text
GET  Capabilities
GET  Presets
POST ListProjects
POST CreateProject
POST GetProject
POST RenameProject
POST DuplicateProject
POST ChangeProjectStatus
POST SaveRevision
POST ListProjectRevisions
POST RestoreRevision
POST RegisterAsset
POST ListAssets
POST ChangeAssetStatus
POST RegisterFont
POST ListFonts
POST GetFont
POST UpdateFontMetadata
POST RequestTenantFontApproval
POST ApproveFont
POST RejectFont
POST ArchiveFont
POST ListTemplates
POST GetTemplate
POST CreateProjectFromTemplate
POST CreateTemplateDraft
POST UpdateTemplateMetadata
POST PublishTemplate
POST UnpublishTemplate
POST ArchiveTemplate
POST RegisterExport
POST ListExports
```

Expose Phase 2 handlers only with their UI and authorization. `Capabilities` returns safe booleans/limits, never secrets/paths.

Use stable results and codes for validation, permission, not found, conflict, quota, and unsupported-version errors. Never expose raw SQL, stack traces, or filesystem paths. Every mutation derives the actor from session state, validates ownership/state, enforces idempotency where needed, and returns authoritative IDs/status.

## Template Rules

- A draft references a source project editable by its creator or authorized publisher.
- Publishing validates the complete revision and generates a preview from that exact revision.
- `published_revision_id` is immutable for that publication. Source edits never alter published templates or user projects.
- Republish by pointing to a newly completed revision while preserving history.
- Create-from-template deep-copies safe document rows into a project owned by the active profile.
- Record `source_template_id`, but never propagate later template changes.
- Creators see/use only published tenant templates.
- Draft/unpublished/archived templates require publisher/admin rights.
- The preview must match the published revision.
- Publication must fail when the revision references a private, pending, rejected, archived, deleted, missing, or otherwise unavailable font.
- Seed starter templates only through an idempotent authorized install/admin path.

## Upload and Media Security

Use upload namespaces `image_editor_asset`, `image_editor_font`, and `image_editor_font_license`. Integrate all three with existing `ProtectedMedia`: implement app-local media authorization and add only the narrow tenant media-policy registrations required for these namespaces.

- Accept PNG, JPEG, and WebP subject to server support.
- Reject SVG in Phase 1 unless a separately reviewed sanitizer converts it safely.
- Validate extension, detected MIME, decodability, dimensions, size, and pixel count server-side.
- Reject malformed images, polyglots, unsupported content, decompression bombs, and limit violations.
- Generate server-owned names; never trust original path segments.
- Strip/normalize EXIF where supported, especially location metadata.
- Verify staged-upload ownership before asset registration.
- Return media references, never physical paths.
- Serve private assets only to owners or authorized template consumers.
- Prevent attaching another user's reference.
- Use same-origin protected assets so export canvases remain untainted.

Font uploads require additional controls:

- Verify font magic/signatures and parse table directories with a maintained, approved server-side font parser; extension/MIME checks alone are insufficient.
- Reject malformed, truncated, encrypted, suspiciously complex, or parser-failing fonts and never pass unvalidated bytes to browser CSS.
- Extract only bounded metadata; ignore embedded names that contain control characters, markup, or excessive lengths.
- Check duplicate checksums before storing/registering another copy, while preserving per-owner licensing/visibility decisions.
- Inspect available OS/2 embedding/licensing flags where the format exposes them. Treat restricted or unknown embedding rights conservatively and never claim that technical flags replace the actual license.
- Serve fonts with a correct allowlisted font MIME type, `nosniff`, same-origin credentials, and the existing protected-media authorization. Do not expose physical paths.
- The media policy must allow private font reads only to the owner/authorized retained-project path and tenant font reads only to appropriate tenant users.
- Validate optional license documents independently; never render uploaded license HTML.

Never load arbitrary remote image URLs directly. A future importer must use HTTPS, host/IP and private-network protections, redirect/time/size limits, MIME/dimension checks, and local re-storage.

## Export

- PNG supports transparency where applicable.
- JPEG requires a solid background and bounded quality.
- WebP is offered only where encoding is supported.
- Bounded scale factors must not change logical layout.
- Enforce side and total-pixel limits before allocating output.
- Exclude controls, selection handles, guides, and hidden elements.
- Wait for every authorized font and image; fail clearly instead of producing blank content or fallback typography.
- Use sanitized deterministic filenames.
- Revoke object URLs and release off-screen canvases.
- If persisted, upload with the shared uploader, then register only after server verification.

Do not claim professional print color management in Phase 1. PDF/bleed support needs explicit DPI, page size, font embedding, and PDF-library verification.

## Frontend State and Performance

- Keep the renderer instance outside deeply reactive Vue state; Vue owns serializable UI state/summaries.
- Tear down listeners, observers, object URLs, and renderer instances.
- Coalesce transform events; never save on every pointer move.
- Use bounded in-memory undo commands/snapshots.
- Generate previews after meaningful saves, not every edit.
- Lazy-load previews/thumbnails and paginate all lists.
- Never place original image bytes in Vue state, local storage, service payloads, or revision rows.
- Never place font bytes in Vue state, local storage, project JSON, or revision rows.
- Show progress/errors for uploads, saves, duplication, templates, and exports.

## Accessibility and Compatibility

- Give controls accessible names, labels, and visible focus.
- Use semantic buttons and an appropriate live region for save/errors.
- Ensure contrast and keyboard access.
- Preserve modal focus and sensible focus after deletion.
- Do not rely on color alone for status.
- Test supported browsers, Webdock, and admin-dock.
- Do not assume one Bootstrap version; keep styles self-contained.

## Security Checklist

- Require authentication for all Phase 1 services.
- Verify ownership on every project/revision/asset/template/export read and write.
- Verify font ownership/visibility/status on every font read, project save, preview, template publication, and export.
- Check publisher/admin permission in every template-management handler.
- Keep normal view-object filtering unless an authorized path documents otherwise.
- Use schema-aware `SOSSData` and allowlisted filters/sorts.
- Never accept raw query fragments, physical paths, HTML, scripts, handlers, functions, or executable renderer objects.
- Escape user text in DOM UI; canvas text is data, not HTML.
- Strictly decode, bound, and normalize JSON.
- Protect against prototype pollution and excessive nesting.
- Apply suitable tenant/user storage quotas and rate limits.
- Soft-delete first; do not remove referenced assets/revisions during normal actions.
- Log useful server context without image bytes, full documents unnecessarily, cookies, tokens, or secrets.
- Never weaken uploader, firewall, SOSSData, or protected-media rules.

## Dependencies

Expected initial contract:

```json
{
  "apps": ["davvag-tools"],
  "schemas": [
    "profile",
    "image_editor_project",
    "image_editor_revision",
    "image_editor_page",
    "image_editor_element",
    "image_editor_asset",
    "image_editor_font",
    "image_editor_template"
  ],
  "workflows": [],
  "plugins": ["auth", "phpcache", "profile", "sossdata"],
  "php-extensions": []
}
```

Add `image_editor_export` only if implemented. Declare any server-side font parser as a pinned plugin/library dependency, include its license, and declare required PHP extensions. Add image/PDF extensions only when directly required and verified. Document any browser renderer even though it is not a PHP plugin. Keep `app.json`, API plugin dependencies, PHP imports, schema dependencies, and actual files aligned.

## Testing and Acceptance

Do not report completion from file creation or syntax checks alone.

### Static checks

- Parse every new/changed JSON file.
- Run `php -l` on every new/changed PHP file.
- Run `node --check` on app-owned JavaScript that is directly checkable.
- Verify startup/onLoad/routes/resources/service class/handlers/serviceNamespaces/dependencies against files.
- Verify schema relations and third-party license/version files.
- Verify bundled font license files, uploaded-font media policy registration, and font parser availability.

### Framework routes

Verify:

```text
GET /components/object/appdescriptor/image-editor
GET /components/image-editor/{component}/object?object=desc
GET /components/image-editor/{component}/file/script.js
GET /components/image-editor/{component}/file/partial.html
GET /components/object/apps
```

Exercise every API with proper roles plus unauthenticated, wrong-owner, invalid-ID/state, missing-dependency, and invalid-input cases.

### Service/persistence tests

Prove:

- A user can create/save/reload/rename/duplicate/archive/restore their project.
- ID changes cannot access another user's project, revision, asset, export, or private template.
- Duplicate `clientSaveKey` does not duplicate completed revisions.
- Stale `baseRevisionId` conflicts without overwriting.
- Failed revision writing leaves the old complete pointer.
- Unknown versions/types/properties, duplicate UUIDs, oversized payloads, unsafe keys, invalid assets, non-finite transforms, and excessive dimensions are rejected.
- Published templates remain unchanged after source edits.
- Create-from-template deep-copies and sets current ownership.
- Normal users cannot publish/unpublish.
- Private fonts are visible only to their owner; pending/rejected fonts cannot be selected; only authorized administrators can approve tenant fonts.
- Project saves reject inaccessible font IDs, and template publication rejects private or unavailable fonts.
- Duplicate font content is detected by checksum without leaking another user's private font metadata.
- Archived referenced fonts are retained according to policy rather than physically deleted.
- Soft-deleted projects are hidden normally and recoverable by policy.
- Protected media denies unauthenticated/wrong-owner access and allows authorized access.

### Browser tests

- Create a blank 1280x720 design.
- Add a heading, shape, and uploaded image; crop where applicable.
- Upload a licensed test font, apply it to text, save/reload, and verify the exact face, weight, style, wrapping, and line height.
- Verify an unauthorized user cannot list or fetch another user's private font and cannot inject its ID into a project.
- Approve a tenant test font as an administrator and verify it becomes available to another authenticated creator.
- Simulate a font load failure and verify save/export warnings do not silently substitute a fallback.
- Move, resize, rotate, lock, hide, reorder, duplicate, and delete.
- Undo/redo supported edits.
- Save, refresh, and verify appearance/layer order.
- Simulate save failure and verify dirty state/retry.
- Open two tabs and verify stale-save conflict.
- Publish a template with authorization and clone it as a normal user.
- Export PNG/JPEG/WebP at permitted scales; verify dimensions, appearance, transparency/background, fonts, and images.
- Confirm exports exclude controls/handles/guides.
- Confirm buttons cannot double-fire.
- Test laptop/tablet widths, keyboard navigation, modal focus, both docks, and a near-limit project.

## Definition of Done

1. Active tenant is resolved and documented.
2. App files, schemas, dependencies, tenant registration, and group visibility align.
3. Every Phase 1 behavior is implemented.
4. Authoritative validation/permissions run server-side.
5. Saves are revisioned, idempotent, conflict-aware, and partial-save safe.
6. Image/font media uses shared DAVVAG tools and protected owner/template/font rules.
7. Uploaded fonts are validated, licensed/attested, access-controlled, reliably loaded, and verified during export.
8. Published templates are immutable snapshots, clone independently, and reference only tenant-approved/built-in fonts.
9. Static, service, framework-route, permission, browser, responsive, font, and export tests actually ran.
10. `README.md` documents architecture, routes, roles, schemas, dependencies, upload/font/export limits, licenses, test commands, deployment, and limitations.
11. The report lists files, schemas, services, routes, dependencies, security decisions, tests/results, and known limits without claiming unexecuted tests.

## Prohibited Shortcuts

- Do not copy Canva assets, templates, trademarks, or proprietary UI verbatim.
- Do not put the full app in one HTML/JavaScript file.
- Do not store images as database base64.
- Do not store uploaded font bytes as base64 or inside project/revision JSON.
- Do not persist arbitrary renderer JSON, HTML, JavaScript, or remote URLs.
- Do not treat hidden app visibility as authorization.
- Do not trust project IDs without ownership checks.
- Do not add another uploader, cropper, auth system, profile table, router, or database connector.
- Do not use a CDN-only editor dependency.
- Do not use a runtime font CDN, trust font extensions alone, bypass licensing attestation, or allow private fonts in published tenant templates.
- Do not write to the backend on every pointer movement.
- Do not overwrite published template revisions.
- Do not physically delete referenced media during normal project deletion.
- Do not edit framework core for app behavior without a separately reviewed framework defect.
- Do not claim Canva parity, professional print fidelity, collaboration safety, or passed browser/export tests unless implemented and verified.

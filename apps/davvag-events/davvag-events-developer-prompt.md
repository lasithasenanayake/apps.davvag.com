# Codex developer prompt for davvag-events

Use the following content as the development brief for building `davvag-events` in the existing DAVVAG repository. It specifies the implementation task; it does not indicate that the application has already been built.

## Role and objective

You are an expert DAVVAG full-stack architect, senior PHP developer, and senior UX designer. Design, implement, and verify `davvag-events`, an Event Management and Web Invitation Platform for weddings, birthday parties, business parties, corporate events, and ticketed gatherings.

Build a working DAVVAG tenant application with persistent data, secure public invitation flows, event administration, seating, check-in, greetings, and host-owned Google Drive media collection. Ticketed events must support capacity control and a configurable payment provider. Deliver functional vertical slices through the final acceptance criteria, rather than stopping at architecture or static screens.

Repository: `C:\xampp\htdocs\davvag-core`

Framework documentation: `C:\xampp\htdocs\davvag-core\docs`

Application code: `davvag-events`

PHP namespace: `davvag_events`

Datastore namespace prefix: `davvag_events_`

## Inspect the repository before implementation

1. Read applicable `AGENTS.md` files, the root README, `docs/README.md`, and documentation files `01` through `05`, `08` through `14`, and `app-dependencies.md`. Read `06` and `07` when implementing workflows or plugins. Use the feature inventory to find reusable apps; verify their real contracts in source.
2. Resolve the active tenant from the effective configuration, including any `DAVVAGCONFIG` override, `RESOURCE_LOCATION`, `LOCAL_DEV_HOST`, and expected request host. Derive `TENANT_RESOURCE_LOCATION`; do not hardcode a tenant based on examples. Never log secret configuration values.
3. Inspect `configloader.php`, `components/component_manager.php`, `components/common.php`, `plugins/auth/auth.php`, `plugins/sossdata/SOSSData.php`, the selected datastore adapter, and its schema/query implementation. Confirm available PHP extensions, JavaScript/Vue versions, routing, and dependency installation conventions.
4. Inspect the sample app, a maintained multi-screen app such as `course-manager`, the active dock shells, `davvag-tools`, and relevant notification, scheduler, and payment modules. Reuse compatible helpers after reviewing their authorization and runtime behavior.
5. If `davvag-events` already exists, extend it while preserving unrelated work. Check the working tree before making changes. Do not reset or overwrite another developer's edits.
6. Report the resolved tenant, actual browser base URL, intended files, and any documentation/source discrepancies, then proceed with implementation. Ask only for information that cannot be discovered and materially blocks a dependent operation.

The inspected configuration on 25 November 2026 pointed `RESOURCE_LOCATION` to `C:\xampp\htdocs\davvag-core\davvag-core`; `localhost` was the tenant directory present, and `LOCAL_DEV_HOST` was absent. Treat this as a starting observation, not a permanent runtime guarantee. Paths in older documentation containing `\git\` are examples, not this checkout's location.

## Use DAVVAG architecture

- Use the existing Apache/PHP runtime, Webdock component loading, installed Vue conventions, DAVVAG auth, and SOSSData with the configured tenant adapter. Do not introduce Next.js, Express, FastAPI, Supabase, MongoDB, or a separate identity system as the default architecture.
- Use scoped application CSS and the shell's existing UI resources. Tailwind is optional only if an existing compatible build supports it without global resets or a new runtime requirement. Do not assume a modern Vue API is available in the installed version.
- Put application code under `{TENANT_RESOURCE_LOCATION}/apps/davvag-events`, schemas under the tenant `schemas/` folder, and optional workflows under `davvag-flow/davvag-events/`. Use tenant-local plugins only for reusable backend capabilities that need them.
- Keep application behavior out of framework core. Prefer existing extension points. If a required framework capability is missing, identify the exact gap, complete independent work, and present a narrowly scoped change rather than silently weakening a requirement.
- Preserve framework names including `Auth::Autendicate()`, `SOSSPlatform::intialize()`, `global/templetes/`, `scopData`, and `excutionStack` wherever used.

### Descriptors and component contracts

Provide `app.json`, `app.php`, an icon asset, UI component descriptors, service descriptors, and real resources referenced by those descriptors.

Each UI component should have `component.json`, `script.js`, `partial.html`, and scoped CSS. Follow the installed `WEBDOCK.component().register(function (exports) { ... })` and `exports.vue` pattern. Obtain services through `exports.getComponent(...)` and shell navigation through `exports.getShellComponent("soss-routes")`. Use the framework's actual promise/error conventions. Use sibling navigation such as `../guests?eventId=...` where the router appends paths to the current route.

For PHP services:

- Match descriptor `serviceHandler.class` to the PHP namespace and class exactly, for example `davvag_events\EventService`.
- Register explicit method names and HTTP verbs. `POST SaveEvent` maps to `postSaveEvent($req, $res)`; `GET ListEvents` maps to `getListEvents($req, $res)`.
- Use `$req->Body(true)`, `$req->Query()`, and the framework response/error helpers. For signature validation, preserve the raw body through `$req->Body(false)` or the verified runtime equivalent.
- Set appropriate HTTP status codes using supported mechanisms and preserve the framework `success/result` envelope. Test error propagation so an inner datastore failure is not accidentally returned as outer success.
- Do not expose a generic arbitrary namespace CRUD endpoint or unguarded transformer for event data.

Every app manifest must declare `dependencies.apps`, `schemas`, `workflows`, `plugins`, and `php-extensions`. Populate actual dependencies and omit blank entries. Documentation shows a flat plugin array, while inspected app manifests use `plugins: { "local": [...], "global": [...] }`. Inspect the active installer/consumer and follow its accepted contract; do not copy an incompatible example or normalize other apps.

Merge registration into `tenant.json` and the appropriate group files without removing entries or changing startup apps unnecessarily. Public invitations intentionally require anonymous app access; verify method-level restrictions and service authorization before adding that access. Do not add the app as an anonymous dock launcher unless intended. Bump versions when changing descriptors, scripts, or styles so resources refresh in both docks.

## Product roles and access

Use framework user/profile identity for authenticated administrators and staff. Invitation guests do not need framework accounts.

| Role | Access |
| --- | --- |
| Tenant administrator | Manage tenant event administration under existing framework permissions; audit privileged access. |
| Event owner | Manage their event, collaborators, publication, guests, seating, Drive connection, payments, and exports. |
| Event manager | Manage explicitly granted event operations; no automatic access to owner credentials or payout settings. |
| Check-in staff | Validate admissions and record attendance for assigned events; receive only operational guest information. |
| Moderator | Approve or reject greetings for assigned events. |
| Guest | View published invitation content and perform only actions authorized by an event or guest capability. |

Authorize every service request against the resolved tenant, authenticated identity or capability, event membership, and operation. Scope every child lookup to its event. An ID supplied by the browser is never proof of access. Resolve audit actors on the server.

Keep event-level membership separate from broad framework groups. Preserve `Auth::GetAccess()` and normal `sysviewobject` filtering, then apply product authorization in services. UI visibility and group registration alone are insufficient. The inspected virtual firewall returns `true`; do not rely on it for event ownership or staff restrictions.

Use the existing schema protection mechanism where present: mark sensitive app schemas `serviceOnly: true` and declare minimal `serviceHandler.serviceNamespaces` in protected server descriptors. Verify that the dispatcher applies `SOSSData::WithServiceNamespaces(...)`. This capability permits datastore access; business authorization must still run inside every handler. Never accept capability namespace lists from requests. Test generic datastore and transformer routes to ensure they cannot bypass application checks.

## Event lifecycle and invitation templates

Support draft, published, closed, cancelled, and archived states with defined transition rules. Keep publication state separate from RSVP, ticket sales, check-in, greeting, and media collection windows. Closed RSVP must not accidentally disable a valid event-day seat lookup.

An event stores title, occasion type, owner profile, timezone, start/end instants, venue, address, validated Google Maps link, host message, RSVP deadline, privacy mode, enabled modules, and publication settings. Store instants consistently in UTC and retain an IANA event timezone for editing and display. Do not infer currency, timezone, or provider eligibility from the developer's locale.

Provide five usable starter templates: wedding, birthday, business party, corporate gathering, and ticketed event. Each should have appropriate typography, color, imagery treatment, invitation wording, and sections. Ticketed templates include admission and agenda information; wedding templates support couple/host details.

Build an editor for colors, supported fonts, cover image, text, venue, agenda, section visibility/order, and mobile preview. Templates are versioned structured data with allowed design tokens and sections, never executable PHP/JavaScript or unrestricted HTML/CSS. A template update must not silently alter an existing published invitation. Separate draft changes from the published snapshot; publish atomically and allow an owner to preview unpublished content securely.

Persist ordered agenda items. Provide invitation sharing, event QR code, calendar download with timezone-safe dates, and a working Maps link. Use runtime-derived base URLs and DAVVAG routing. Clean public aliases are optional; do not assume a new `/events/...` rewrite exists.

## Guest registry and RSVP

- Support manual guest entry, validated CSV import/export, guest groups, VIP status, email/WhatsApp contact fields, invitation parties, allowed plus-ones, dietary/accessibility notes, and response deadlines. CSV imports need a validation preview and explicit duplicate handling; never silently merge people by name.
- Distinguish an invitation party from individual attendees. Each accepted plus-one becomes an attendee record so seating, tickets, capacity, and check-in count people consistently. Guests may share names and contact details.
- Track `Pending`, `Attending`, and `Declined` per attendee as needed, with response timestamps and a history of changes. Owners may record responses manually. Enforce invited party size and permitted plus-ones on the server.
- Issue unique cryptographically random invitation credentials with at least 128 bits of entropy. Persist a hash, event/party association, scope, expiry, and revocation state. A QR code contains a URL or opaque credential, never raw guest PII. Keep RSVP credentials separate from admission credentials.
- A guest link reveals only that party's information and permits changes only within its invitation. Support revocation and rotation; exchanging a link for a short-lived scoped session should reduce repeated exposure of bearer credentials.
- Define invitation resends and ticket redisplay explicitly: use deliberate credential rotation or encrypted, access-controlled delivery material when recovery is required. Never attempt to reconstruct a secret from its hash or expose raw credentials through list/export endpoints.
- Define capacity policy for private invitations and open registration. Declining releases applicable holds or allocations; changing a response must not leave invalid seats or admissions behind. Where a paid admission is required, RSVP alone does not create an issued ticket.
- Show clear confirmation, existing-response, deadline-passed, expired-link, full-event, and cancelled-event states. Retried submissions must not create duplicate attendees.

Default public pages must never expose a searchable guest directory, contact details, dietary notes, or full RSVP lists. Redact bearer credentials from logs and analytics, use a restrictive referrer policy on invitation pages, and avoid third-party requests that could leak guest URLs.

## Seating designer and guest seat lookup

Create a persisted visual floor plan supporting round tables, rectangular tables, and theatre rows. Store stable table/row and seat IDs, label, shape, position, rotation, capacity, accessible-seat flags, and layout revision. Render an interactive SVG or equivalent accessible editor with zoom and mobile-safe controls.

Provide an unassigned-attendee list, group/VIP/RSVP filters, manual drag-and-drop, keyboard and form-based assignment, move/unassign controls, and a printable seating list/plan. Report capacity and assignment conflicts clearly.

Auto-assignment must produce a preview before applying. Keep groups together when possible, honor locked assignments and accessibility constraints, allow configured VIP placement, and report unsatisfied constraints. Never exceed capacity, silently split a group against policy, or claim a valid assignment when none exists. Use a deterministic documented heuristic; do not require AI for seating.

Commit assignments on the server with uniqueness and capacity guarantees. Concurrent administrators assigning the same seat must produce one success and one conflict, not an overbooking. Detect stale layout revisions. Reducing capacity, deleting an occupied table, or declining an assigned attendee must require a defined relocation/release outcome.

Guest seat lookup lives on the invitation page. Default to a valid RSVP credential or private lookup code. The owner may opt into exact-name lookup within an event access gate, returning only minimal table/seat information. Handle duplicate names through an additional private code rather than returning a directory. Rate-limit lookup, prevent wildcard enumeration, and never include other guests' private data. Offer a readable list alternative to the visual plan.

## Greetings and memory wall

Guests can submit a name/display preference, greeting, well wishes, or advice through an event-scoped portal. Enforce lengths, publication window, abuse controls, and the event's access policy. Escape output; do not allow executable HTML.

Support moderation enabled by default, with pending, approved, and rejected states. Owners may disable pre-approval. Publish only approved content when moderation is enabled. Pending messages must not appear in public API responses, caches, or live updates.

Provide moderation actions, safe deletion, paginated public history, a mobile wall, and a presentation mode suitable for event displays. Use polling compatible with PHP hosting unless the repository already supplies reliable push infrastructure. Approved content should appear within 15 seconds during a healthy connection; show reconnect and empty states. Any polling interval is configurable and pauses appropriately when the page is hidden.

## Google Drive guest media collection

The host connects their own Google account once. Guests upload without signing into Google; they authenticate only through the app's event/guest access policy. Google authentication and file transfer happen on the server using the host's authorized connection. This server-mediated design is an application architecture choice.

Implement OAuth authorization-code flow for a confidential web application. Bind one-time, expiring `state` to the initiating owner/session and tenant; verify it on callback. Use registered redirect URIs, offline access, encrypted refresh-token storage with keys outside public files, token refresh, disconnect/revocation, and reconnect states. Preserve an existing refresh token if a later exchange omits one. [Google OAuth web server flow](https://developers.google.com/identity/protocols/oauth2/web-server).

Prefer `https://www.googleapis.com/auth/drive.file` for app-created event folders and files. If selecting an existing folder, use an explicit compatible picker/authorization flow; do not assume this scope permits arbitrary Drive browsing. Verify the required consent-screen configuration before deployment. [Google Drive scopes](https://developers.google.com/workspace/drive/api/guides/api-specific-auth).

Create and persist folder IDs for `Events/{Event_Name}_{Date}/Guest_Uploads`. Drive uses folder resources and parent IDs, not filesystem paths. Resolve or create folders idempotently and retain IDs even if a host renames a folder. Do not accept a destination folder ID directly from a guest.

Provide a mobile upload portal with camera/file selection, previews, configurable count/size limits, progress, cancellation, retry, and per-file status. Handle unsupported phone media types explicitly. Stream uploads through a protected server staging area; avoid buffering whole videos in PHP memory. Use Drive resumable uploads for large files and unreliable connections, keeping upload session URLs on the server. [Drive upload guide](https://developers.google.com/workspace/drive/api/guides/manage-uploads).

Keep uploader attribution, timestamp, consent, original filename, detected MIME type, byte count, checksum, event/guest association, and Drive file ID in app metadata. Small non-secret IDs may also use Drive `appProperties`; keep full private metadata in the application. [Drive custom properties](https://developers.google.com/workspace/drive/api/guides/properties).

Use explicit states such as queued, uploading, uploaded, failed, and cancelled. A durable background job resumes transfer after a transient failure, reconciles an ambiguous Drive success before creating another file, and records a useful retry reason. Bound retries and apply backoff. Handle full storage, rate limits, expired authorization, removed folders, and host disconnect. Expose a host queue with retry and reconnect actions. Remove staging data under a documented retention policy after success, cancellation, or expiry.

Uploads are private by default. Never share the host's OAuth credentials, resumable session URL, whole Drive folder, or upload list with guests. A gallery is an optional separate owner-controlled feature.

## Ticketing and attendance

Support ticket types, sale windows, event/type capacity, prices in integer minor units, configured currency, per-order limits, time-limited inventory holds, and attendee details. Free tickets follow a real server issuance path without a payment provider.

Reuse a maintained DAVVAG payment module only after verifying it supports secure server-side checkout and webhook handling. Otherwise implement a small PHP provider adapter, initially for Stripe hosted Checkout if the host can use it. Treat Razorpay as an alternative configured adapter, not a second mandatory integration. Verify current provider support for the merchant's country/currency before enabling sales. Do not collect card details in this app.

Calculate prices, totals, availability, event associations, and order identifiers on the server. Persist pending orders before checkout. Use provider idempotency keys for repeat creation requests. Fulfill only after a trusted server-side payment determination; never mark an order paid solely because a browser visits a success URL. Match the merchant/account, order, currency, amount, and payment status. Handle delayed payment methods, expired holds, late successful payments, refunds, and cancellations with an explicit state machine. If inventory cannot be honored, use a documented compensation/refund path. [Stripe fulfillment](https://docs.stripe.com/checkout/fulfillment.md?ui=full-page).

Verify webhook signatures using the unmodified raw body and the configured signing secret. Deduplicate provider events and make fulfillment itself idempotent under simultaneous deliveries. Persist accepted webhook work durably before acknowledging; retry processing through the job system when needed. Do not assume delivery order. Keep webhook transport status compatible with provider retry behavior. [Stripe webhooks](https://docs.stripe.com/webhooks).

Issue one revocable admission credential per attendee/ticket. Store its hash and present the QR securely after issuance. QR display or a guest opening a link must never perform check-in. Staff scanner mode requires authenticated event access, camera permission with HTTPS where required, a manual code alternative, and clear valid/already-used/wrong-event/expired/refunded results.

Record check-in atomically with a unique admission key. Concurrent scans must produce one attendance record and an already-used response thereafter. RSVP, ticket payment/issuance, and attendance are separate states. For non-ticketed invitations, issue a separate attendance credential or permit authorized staff to locate an attendee. Any manual override or check-in reversal requires an authorized actor, a reason, and audit history. Default to online validation; do not claim offline replay protection without implementing synchronization semantics.

## Data model and invariants

Create tenant JSON schemas before using namespaces. Use DAVVAG `fields`, supported `dataType` values, `annotations`, and stable `relations` metadata. Do not manually redefine framework system columns. Relationship metadata is documentation, not proof of database foreign keys or uniqueness.

Use the following logical namespaces; consolidate only where integrity and clarity remain equivalent:

| Namespace suffix after `davvag_events_` | Purpose and principal fields |
| --- | --- |
| `events` | Event ID, owner profile, slug, occasion, lifecycle, timezone, dates, windows, settings, draft/published revisions. |
| `event_members` | Event ID, profile ID, role and explicit grants. |
| `templates` | Template ID, occasion, version, allowed tokens and structured sections. |
| `agenda` | Event ID, item ID, order, timing, title and description. |
| `guest_groups` | Event ID, group ID, label and assignment preferences. |
| `parties` | Event ID, party ID, invitation contact and permitted attendee count. |
| `guests` | Event ID, guest ID, party/group, display name, contact, VIP flag, RSVP and private notes. |
| `access_tokens` | Hashed credential, scope, event/party/guest/ticket reference, expiry and revocation. |
| `tables` | Event ID, table/row ID, geometry, label, capacity and layout revision. |
| `seats` | Event ID, seat ID, table ID, label, position and accessibility. |
| `seat_assignments` | Event ID, seat ID, guest ID, lock flag, revision and assignment actor. |
| `greetings` | Event ID, author/display preference, message, moderation state and timestamps. |
| `drive_connections` | Owner profile, tenant association, encrypted credentials, scopes and connection state. |
| `media` | Event ID, upload ID, guest/uploader, consent, file metadata, staging reference, Drive ID and state. |
| `ticket_types` | Event ID, type ID, price/currency, stock, sale window and limits. |
| `orders` and `order_lines` | Order ID, event ID, buyer, amounts, state, provider references and expiry. |
| `payments` | Order ID, provider payment/event references, verified amounts and processing state. |
| `tickets` | Ticket ID, event/order/type/guest association, issuance and revocation state. |
| `checkins` | Event ID, unique admission key, attendee/ticket association, staff actor and timestamp. |
| `jobs` | Type, event/reference, idempotency key, state, attempts, next run, claim lease and sanitized failure. |
| `audit` | Event/reference, action, actor, time and minimal relevant before/after information. |

Every event-owned record must have event scope, and every relationship must be validated within that scope. Drive connections belong to an authorized host; events may reference them only with that host's permission. Tokens, credentials, private guest records, orders, payments, check-ins, jobs, and audit records must be accessible only through authorized services.

Required database invariants include unique event slug within its intended tenant, unique event/profile membership, unique seat per assignment, at most one active seat assignment per attendee, unique credential hash, unique provider event/payment references, unique admission check-in, and unique job/operation idempotency keys. Enforce inventory, ticket issuance, and assignment capacity atomically.

Use real constraints and versioned, repeatable migrations where schema JSON cannot express them. Store installation SQL in protected tenant locations consistent with the framework. Do not invent annotations such as a documented `unique` flag without verifying adapter support. Apply necessary indexes for event-scoped lists, token resolution, job claiming, and admission lookup.

Use `SOSSData::Query()` with validated advanced query arrays where the active adapter supports them: `conditions`, canonical `column`, `operator`, `value`, `sorting`, `pageSize`, and `pageFrom`. Conditions are AND-combined and `pageFrom` is a row offset. Use bounded app page sizes and allowlisted filter/sort columns. Never forward browser query objects unchanged.

For controlled joins and reporting, keep SQL in protected schema `rawquery` definitions with declared placeholders and bound scalar parameters via `SOSSData::ExecuteRaw()`. Raw queries require explicit event, membership, and visibility restrictions because normal view-object filtering is not added automatically. Preserve the query firewall; do not create keyword blacklists.

The inspected SOSSData facade has no public begin/commit/rollback API. The plugin named `transactions` is not evidence of database ACID support. Inspect the adapter before selecting an atomic implementation. Prefer a verified transaction facility, or versioned parameterized stored procedures invoked through supported SOSSData raw-query contracts, with locks, constraints, and status result sets. Keep all writes in an atomic operation on one database connection. Do not simulate transactions with unrelated SOSSData calls or introduce a parallel ORM. Prove atomicity with concurrent tests before calling seating, inventory, fulfillment, or check-in complete.

## Services and routes

Organize services by trust boundary: event administration, guest administration, public invitation/RSVP, seating administration, guest seat lookup, greetings, media ingress/transfer, OAuth connection, ticket sales, payment webhook, and staff check-in. Separate classes/components where this makes permitted operations and namespace scopes clearer.

Expose DAVVAG component endpoints such as `/components/davvag-events/{service}/service/{Handler}`. Define and document each handler's method, identity/capability, inputs, validation, result, failure codes, and idempotency rules. Do not rely on descriptor method declarations alone if runtime dispatch permits calling other public methods; service methods and shared business operations must enforce authorization internally.

Provide explicit owner/staff and public route components. Suggested app routes are dashboard, event editor, guests, seating, greetings moderation, media, ticketing, check-in, reports, and event settings; guest routes cover invitation, RSVP, seat lookup, wall, upload, and tickets. Resolve query/route values safely and URL-encode parameters. Direct anonymous navigation to an administrative route must not reveal data.

## Jobs and communication

Use durable jobs for invitation delivery, reminders, Drive transfer, payment fulfillment, and cleanup when appropriate. Prefer a compatible installed scheduler; otherwise provide an authenticated/CLI worker with documented Windows and production cron invocation. Jobs need bounded batches, atomic claims, retry/backoff, leases for crashed workers, idempotency, and a host-visible failure state. Do not run long transfers from a page polling request.

Provide real email delivery through existing PHP notification/SMTP facilities when configured. WhatsApp sharing links can work immediately; automatic WhatsApp messaging is a separate opt-in provider integration with current provider requirements verified before enabling it. Persist channel consent and delivery state. Do not use Nodemailer as a reason to introduce Node.js, and do not treat Twitch as a messaging gateway.

When credentials are unavailable, keep dependent actions disabled with actionable setup instructions. Provide deterministic tests and clearly identified provider test doubles for development, never a fake production success. Continue local implementation and verification that do not require those credentials.

## Security and data handling

- Use server validation and field allowlists to prevent mass assignment, ownership spoofing, cross-event references, and invalid lifecycle changes. Reject arbitrary callback URLs, folder paths, namespaces, template code, or SQL fragments.
- Protect authenticated mutations with the repository's verified CSRF mechanism, or implement an app-scoped token tied to the session if none exists. Verify request origin as appropriate. OAuth callbacks use state validation and payment webhooks use provider signatures instead of a browser CSRF token.
- Rate-limit public RSVP, lookup, greeting, upload, and checkout actions with shared durable controls suitable for the deployment. Limit upload windows and event/guest quotas to prevent the host's Drive being abused.
- Validate uploads using an extension allowlist, server-detected type/signature, safe generated names, size/count limits, and private storage. Reject executable, HTML, and active SVG payloads. Preserve originals only under an explicit policy. Do not trust client MIME types; add a malware scan hook where available. [OWASP upload guidance](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html).
- Inspect `plugins/protected-media/ProtectedMedia.php` and tenant `global/config/media-access.json` before reusing `davvag-tools`/`soss-uploader`. Protect both upload and retrieval paths and merge policies without overwriting existing stores. Avoid a publicly retrievable staging store; use a dedicated authorized ingress if the generic uploader cannot meet guest permissions, size limits, and streaming needs.
- Keep secrets and staging data outside component file resources and public asset/media routes. Verify attempted retrieval through component-file, `/files`, and generic uploader endpoints. Encrypt OAuth tokens at rest and keep encryption keys outside the database and client bundles.
- Use escaped text rendering, safe URL protocols, no arbitrary external fetch proxy, and redacted logs. Sanitize CSV exports against spreadsheet formula injection. Exclude private information and tokens from public caches; invalidate published wall/invitation caches on permitted changes.
- Keep guest consent, retention, host export, and deletion workflows explicit. Archive/cancel immediately revokes affected actions; permanent deletion uses a scoped, logged cleanup process. Do not automatically delete unrelated host Drive content. Let owners choose the fate of app-created files and report partial cleanup failures.
- Use HTTPS in production, same-origin APIs by default, and compatible secure session settings. Do not enable a bypass or broad credentialed CORS to make integration tests pass.

## UX and accessibility

Create a cohesive, responsive interface for real hosts and event-day guests. Use distinct occasion styling with shared readable controls. Prioritize guest mobile flows and quick check-in; avoid exposing framework implementation terminology to attendees.

The owner dashboard should show RSVP totals, confirmed attendees, remaining capacity, seating progress, greeting moderation, media status, and ticket/check-in summaries. Counts come from consistent server data and distinguish attendees, parties, tickets, and checked-in admissions.

Every screen needs loading, empty, validation, success, permission, network failure, and retry states. Preserve unsaved form data when a recoverable request fails. Label all fields; support keyboard navigation, visible focus, sufficient contrast, reduced motion, accessible announcements, and touch-sized controls. Provide alternatives to drag-and-drop and camera scanning. Verify at 360 px, tablet, and desktop widths. Test both the default and admin docks without leaking styles into other apps.

Use representative fictional seed events for all five occasions. Include duplicate names, a plus-one party, a declined guest, a full table, pending greetings, a failed upload, and issued/used/refunded tickets. Seed credentials belong only in isolated test fixtures; never seed production bearer links.

## Implementation sequence

Complete each phase as a usable vertical slice, validate it, and continue to the next phase. Keep a short progress checklist and an app-local architecture/API/schema reference as the code evolves.

1. **Foundation:** resolve tenant/runtime, create descriptors and registrations, protected schemas, authorization helpers, migrations, shared styles, event CRUD, membership, and audit.
2. **Invitations:** five templates, structured editor, draft/published snapshots, agenda, preview, public routing, party/attendee registry, import/export, invitation credentials, RSVP, and capacity policy.
3. **Seating and attendance:** visual layout, manual and automatic assignment, atomic conflicts, private guest lookup, admission credentials, scanner/manual check-in, and printable plans.
4. **Greetings:** submissions, moderation, approved-only wall, display mode, and abuse controls.
5. **Drive:** host OAuth, protected upload ingress/staging, folder creation, durable resumable transfer, metadata, retry/reconnect, and cleanup.
6. **Ticketing and communication:** free issuance, configured hosted payment adapter, inventory holds, verified webhooks, refunds/revocation, invitation delivery, reminders, and operational queue screens.
7. **Release verification:** cross-role and public-path security tests, concurrency tests, end-to-end journeys, dock/mobile/accessibility checks, configuration guide, worker setup, and deployment/rollback notes.

Do not silently drop later phases to declare the app complete. If external credentials or deployment access block a live check, finish the local implementation, state the precise remaining setup and unverified behavior, and distinguish it from tested functionality.

## Verification and acceptance

Run relevant PHP lint, JavaScript syntax checks, JSON parsing, and descriptor/resource checks. Use meaningful service/integration tests for authorization, state transitions, and concurrency; use a disposable database or clearly scoped test data. Do not send real invitations, charge live payments, publish real events, or upload to a real host account during tests without authorization for those external effects.

Required acceptance journeys:

1. An owner creates and publishes each occasion type, previews changes privately, and shares a working invitation without exposing drafts. Another owner cannot read or change that event by guessing IDs.
2. A party responds with permitted attendees and dietary details; duplicate submissions are safe. A forged, expired, revoked, wrong-event, or excessive-plus-one request fails. A guest sees only their party's private data.
3. CSV import reports invalid and duplicate rows before applying them. Export preserves data without executing spreadsheet formulas. Names with apostrophes and Unicode work normally.
4. Staff assign attendees manually and by auto-plan. Two concurrent writers targeting the last seat cannot both succeed. Stale revisions, full tables, locked seats, declined guests, and unsatisfied group constraints have clear outcomes.
5. Guest lookup returns only permitted table/seat details, handles duplicate names privately, and resists bulk enumeration. The public invitation never returns a whole guest registry.
6. A pending greeting is absent from public history and updates. Approval makes it appear within the configured wall refresh interval. Script payloads render inertly.
7. A host connects Drive; a guest uploads without Google login. Tests cover folder reuse, resumable transfer, duplicate retry, quota/auth failure, worker restart, and staging expiry. Guests cannot access tokens, staging files, other uploads, or unrelated Drive folders.
8. A free or paid order creates the correct number of tickets. Forged payment callbacks, wrong amounts/currencies, repeated and simultaneous webhooks, delayed payment, expired stock holds, and refund/revocation cannot overissue admissions. Simultaneous checkout for the final ticket cannot oversell.
9. Authorized staff scan the correct admission once; concurrent repeats return already used. Wrong-event, revoked, refunded, and unauthorized scans fail. Opening the QR link as a guest does not check anyone in.
10. Direct generic SOSSData/transformer/uploader/file routes cannot read or mutate protected event data. Test both another authenticated owner and anonymous callers. Test CSRF failure and request field spoofing.
11. Restarting a worker does not duplicate messages, Drive files, or fulfillment. Multiple workers cannot claim the same active job. No credentials appear in responses, logs, rendered HTML, or static assets.
12. Main journeys work on mobile and in both supported docks, with keyboard/form alternatives for seating and check-in. Cancelling an event closes affected actions consistently while preserving authorized host access and audit history.

## Handoff requirements

Deliver the application files, schema definitions, migration/seed utilities, route/API reference, tests, and an app README covering tenant registration, capabilities/roles, provider setup, environment variables, PHP limits/extensions, worker invocation, retention, and deployment.

In the final report, state what works, where the app is registered, its actual URLs, the validation performed and results, relevant deployment constraints, and precisely which credential-dependent checks remain. Include changed-file links and setup instructions with placeholder names only. Never claim tests, migrations, provider connections, or deployment succeeded without observing them.

Start by inspecting the existing DAVVAG repository and resolving the active tenant. Then implement and verify `davvag-events` through these acceptance criteria.

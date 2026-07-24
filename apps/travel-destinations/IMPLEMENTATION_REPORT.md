# Travel Destinations MVP — Implementation Report

Date: 2026-07-24

## Active tenant

The runtime configuration resolves `localhost` to:

`C:\xampp\htdocs\davvag-core\davvag-core\localhost`

That path is a symbolic link to the active tenant repository at:

`C:\xampp\htdocs\apps.davvag.com`

All app, schema and group-registration changes were made in that active tenant.

## Discovery and reuse

The implementation follows the existing DAVVAG app/component/service architecture and reuses:

- DAVVAG authentication and the existing `profile` identity.
- SOSSData for all persistence; no direct MySQL access was added.
- `davvag-tools`, `davvag-file-uploader` and the existing uploader service for destination images.
- The shell `soss-routes` component for navigation.
- Existing component lifecycle, group registration, moderation and paginated-service patterns.
- The existing app-level profile plugin for current-profile lookup.

No reusable tenant map component met the app requirements. The MVP therefore uses a provider-neutral coordinate-map presentation and OpenStreetMap directions URLs without browser-side credentials. Existing Markdown precedents were reviewed; this app stores plain Markdown, strips HTML on the backend, and renders a deliberately small escaped formatting subset.

## Files and registrations

Created:

- `apps/travel-destinations/app.json`, `app.php`, `permissions.json`, installer and app icon.
- One API service descriptor, client proxy and PHP service implementation.
- Ten UI components: explorer, detail, submission form, submissions, favorites, admin destination list, admin moderation, admin categories, admin amenities and shared style.
- Thirteen `travel_destination*` schemas.
- Static/contract test runner and this report.

Modified without replacing existing registrations:

- `tenant.json`
- `anonymous.json`
- `web_user.json`
- `sysadmin.json`

`web_user` also receives `davvag-tools`, which the submission uploader requires.

The app descriptor registers 11 component/service entries and 11 partial routes. The app is versioned at `0.3.2`; UI component versions are bumped whenever resources change so DAVVAG docks do not reuse stale scripts or views.

## Data model and services

Schemas:

- Destination, category, category link, amenity, amenity link and media.
- Review, helpful vote, comment, favorite and submission log.
- Condition report and content report.

The API exposes 43 service operations covering:

- Public capabilities, search, map results, nearby search, details and approved community content.
- Traveler drafts, submission, eligible self-editing, shared media association, reviews, comments, helpful votes, favorites, condition reports and content reports.
- Administrator CRUD/state transitions, publication, submission and media moderation, community moderation, reports, duplicate merging, and category/amenity management.

No workflows were created. The existing notification capability was discovered but was not wired into this MVP.

## Security decisions

- Public group permissions contain only read operations; traveler write operations are assigned to `web_user`.
- Every traveler/admin write also checks the active profile, ownership or administrator status in PHP.
- Status transitions are constrained on the backend, and travelers cannot publish.
- Public queries only return published destinations and approved community/media records.
- Coordinate ranges, allowed radii, sort values, statuses, dates, URLs, uploaded media references, file extensions and file sizes are validated.
- Approximate/hidden/approved-only location policies are applied before directions links are built; exact sensitive coordinates are removed from public responses.
- Descriptions, reviews and comments are stripped of HTML server-side. The detail component escapes text before its limited Markdown rendering.
- Pagination and maximum page sizes are enforced. Nearby search uses a bounding-box pre-filter and Haversine distance.
- Rating averages and review counts are recalculated on the server.
- Duplicate active reviews, helpful votes, favorites, repeated comments and open reports are rejected or made idempotent as appropriate.
- UI action locks are released on both success and failure.
- No credentials, SQL fragments, filesystem paths or provider API keys are accepted from or exposed to the browser.

## Provider strategies

Map: provider-neutral MVP with coordinate markers, list synchronization, current-device location and OpenStreetMap directions URLs. It does not hardcode a tile/geocoder credential.

Weather: optional provider integration only. No weather provider is configured, and the app remains functional without one.

## Installation

`install-permissions.php` is CLI-only and idempotent. It installs the exact group/service permission rows and seeds the initial administrator-managed reference data.

Verified results:

- 35 permission rows present.
- 13 initial categories present.
- 22 initial amenities present.
- A second installer execution inserted zero permission or reference rows.

## Tests executed

- PHP syntax: 4 files checked, 0 failures.
- JSON parsing: 30 app/schema/registration files checked, 0 failures.
- JavaScript parsing with Node VM: 11 files checked, 0 failures.
- Mounted-state response simulations: all 9 data-driven UI components received realistic successful payloads and exposed the expected records, authorization state and loading completion; 0 failures.
- Static architecture/contract suite: 255 checks, 0 failures.
- UI descriptor and resource HTTP checks: 30 checks, 0 failures.
- Service-route HTTP sweep: all 43 declared handlers called using their declared HTTP methods; 0 missing handlers. Expected validation failures used HTTP 500 and protected calls were rejected by the framework permission layer.
- App listing and descriptor: HTTP 200; `travel-destinations` is visible.
- Reference endpoints: HTTP 200; returned 13 categories and 22 amenities.
- Empty public search: successful paginated empty result.
- Nearby search with valid coordinates and 10 km radius: successful paginated result.
- Invalid 7 km radius: rejected with the allowed-radius validation message.
- Anonymous draft write: rejected by the framework permission layer.
- The exact framework-generated stale permission-cache entry encountered during installation was removed and the local Apache runtime was restarted; public permissions were then verified over HTTP.

## Known limitations

- The current map is an MVP coordinate visualization. Tile rendering, marker clustering, drag-to-select, search-this-area, reverse geocoding, place search and GeoJSON trail visualization require a configured reusable map provider.
- The explorer UI exposes the primary filters; several advanced filters already accepted by the backend are not yet surfaced as controls.
- Weather, sunrise/sunset, notifications, GPX/GeoJSON processing, named lists and booking integration remain optional enhancements.
- No authenticated traveler or administrator credentials were available for an end-to-end live database session, so those protected HTTP routes were verified for registration and anonymous denial, while authorization/state rules were exercised by the static contract suite.
- The in-app browser runtime reported no available browser backend. Resource routes were verified over HTTP, but interactive visual QA in the public and admin docks could not be executed in this session.
- The app icon is a tenant-local placeholder and should be replaced by final brand artwork.

## Recommended next phase

Configure a tenant map provider abstraction first, then complete interactive authenticated QA with traveler and administrator test accounts. Follow with advanced discovery controls, notification workflows, trail-file visualization and an optional cached weather provider.
# Google Maps provider settings — v0.4.0

## v0.4.1 runtime compatibility fix

- Exposed the Google Maps adapter as an immediate browser singleton as well as a DAVVAG component export, avoiding component initialization-order differences between docks.
- Updated every map consumer to resolve the singleton, component runtime property or direct component export in that order.
- Added runtime method guards so missing helpers produce a visible fallback message instead of an uncaught `createMap` exception.
- Verified that both global and registry export paths contain `createMap`; all 13 JavaScript files parse and all 291 application checks pass.

## v0.4.2 map rendering fix

- Removed the deprecated `google.maps.Marker` fallback and now uses `AdvancedMarkerElement` for every marker.
- Uses the configured Google Cloud Map ID, or `DEMO_MAP_ID` only when the tenant has not saved one.
- Waits for the map element to be connected and measurable, assigns explicit map heights, and triggers map resize/centering after initialization.
- Observes responsive container resizing so tiles redraw correctly when the DAVVAG layout changes.
- Validation now passes 294 checks with zero failures; all 13 JavaScript resources parse successfully.

## v0.4.3 DAVVAG route-host fix

- Corrected map mounting when DAVVAG retains a detached component element during route rendering.
- The runtime now resolves the current connected, visible map element by its component data attribute before constructing Google Maps.
- Explorer rendering now waits for Vue’s DOM update plus two animation frames, ensuring the map view has its final dimensions.

## v0.4.4 coordinate precision and map-link import

- Added full-precision extraction for Google Maps `@lat,lng`, `!3d...!4d...`, query-coordinate and plain coordinate URL formats.
- Added a protected server resolver for allowlisted Google Maps short links, with manual redirect validation to prevent arbitrary URL fetching.
- Pasting a map URL in the submission form now fills latitude/longitude and immediately moves the draggable marker.
- Explorer markers are numbered to match result rows, and duplicate coordinate bounds no longer produce an incorrect zoom.
- Validation passes 303 checks with zero failures; permissions were installed idempotently and anonymous resolver access was rejected.

## v0.4.5 large destination descriptions

- Increased the logical `description_markdown` limit from 20,000 to 250,000 characters.
- Added ordered SOSSData description chunks so long UTF-8 Markdown is not truncated by the database `TEXT` byte limit.
- Preserved the existing `description_markdown` API field and existing destination compatibility.
- New chunks are written before old chunks are removed; failed writes roll back the new chunks and retain the previous description.
- Added a larger editor and live character counter to the submission form.
- Validation passes 312 checks with zero failures, and the live destination endpoint reconstructs descriptions successfully.

- Added the administrator route `#/app/travel-destinations/admin/map-settings`.
- Added encrypted-at-rest Google Maps browser API-key storage using the existing `DAVVAG_PROVIDER_SECRET` and AES-256-GCM provider-secret pattern.
- Added tenant-persistent map ID, language, region, default centre, zoom and optional Geocoding API settings.
- Added `GetMapConfiguration`, `GetAdminMapSettings` and `SaveMapSettings` service contracts with public/admin permission separation. The raw key is never returned to the admin settings form.
- Added a reusable Google Maps JavaScript runtime with weekly-version loading, Advanced Marker support when a Map ID is configured, standard-marker fallback, and provider-neutral UI fallback.
- Integrated Google Maps into public map discovery, destination detail, directions, and the destination form’s click/drag location picker.
- Added optional address search through Google Geocoding when explicitly enabled.
- API keys are browser-visible when the Maps JavaScript API runs, as required by Google’s client SDK. The settings screen instructs administrators to restrict the key by HTTP referrer and API.
- App and changed component versions were bumped to `0.4.0`.
- Validation: 291 PHP/static app checks passed, all 13 JavaScript resources parsed successfully, PHP lint passed, new descriptors returned HTTP 200, public disabled configuration exposed no key, the permission installer remained idempotent, and four permission entries were installed.

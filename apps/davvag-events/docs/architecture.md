# Architecture and schema reference

## Storage boundary

Every runtime database read/write uses SOSSData and the configured `phpmysql` adapter. Installation and disposable-test setup use MySQLi for DDL only. No alternate ORM, identity database, framework transaction facade, or filesystem datastore was introduced.

`davvag_events_events` contains one versioned JSON aggregate per event. The aggregate holds `draft`, immutable-on-edit `published`, `members`, `groups`, `parties`, `guests`, `tokens`, `tables` (with stable seats), `assignments`, `greetings`, `media`, `ticketTypes`, `orders` (with attendee lines), `tickets`, `checkins`, `jobs`, `providerEvents`, `operations`, and `audit`. These consolidate the brief's logical namespaces to keep one atomic boundary on the installed adapter. Templates are versioned allowlisted PHP data copied into each draft/snapshot; an application update cannot replace a saved invitation.

The aggregate is bounded to 900,000 encoded bytes and 1,000 attendees. IDs are random 128 bit hex strings; bearer credentials are independent 256 bit random values. Instants use ISO UTC strings; invitation timezone is retained as an IANA name. Ticket currencies are explicitly configured ISO codes with integer minor-unit prices.

Other protected namespaces:

| Namespace | Purpose |
| --- | --- |
| `davvag_events_drive_connections` | One owner/tenant connection, encrypted provider credentials, persistent folder IDs, connection state, CAS revision. |
| `davvag_events_keys` | Transactionally maintained uniqueness projections of event/profile membership, seats, seated attendees, token hashes, issued admissions, check-ins, provider events/payments, order operations, and job keys. |
| `davvag_events_limits` | Atomic fixed-window public action counters keyed by a hash of tenant, event, action, and source address. No forwarded address is trusted. |
| `davvag_events_commit` | Protected raw-query schema invoking `davvag_events_commit_v1`. |
| `davvag_events_connection_commit` | Protected raw-query schema invoking owner connection CAS. |
| `davvag_events_rate` | Protected raw-query schema invoking durable rate limiting. |

All schema JSON uses installed data types, supported annotations, and relation metadata. System columns are added in installation SQL, not manually duplicated in schema JSON. Relations are documentation; integrity relies on server validation, aggregate commits, and SQL constraints.

## Atomic writes

1. Load the event through normal SOSSData view-object filtering (no disabling of that filter).
2. Authorize the authenticated profile/role or scoped guest capability.
3. Validate input allowlists, references within the event, capacity, lifecycle, and invariants in PHP.
4. Send the expected revision and complete next state through bound scalar `ExecuteRaw` parameters.
5. The InnoDB procedure locks the event, verifies the revision and immutable owner, writes the next revision, replaces uniqueness projections, and commits on the same connection. Errors roll back the whole operation.

The unique slug and `(kind, scope, keyValue)` constraints reject database-level collisions. Stale writers get HTTP 409 without overwriting another request. Revision compare-and-swap also serializes capacity decisions, inventory holds, payment issuance, check-in and job claims. JSON business relationships are validated inside the service, since the storage engine cannot infer those relationships from schema metadata.

The event raw procedure accepts only trusted server-built projections. Namespace capability lists come from server descriptors, never requests. There are no generic app CRUD or transformers. Each public PHP handler enforces its own boundary; extra methods in the runtime dispatcher cannot access business helpers because helpers are separate classes or protected methods.

## Authentication and public capabilities

`Auth::Autendicate()` provides the user. A bounded profile lookup resolves `profile.linkeduserid`; multiple linked profiles are supported, with stable selection of the oldest accessible primary profile (or oldest linked legacy profile). Owner IDs and audit actors come from that identity. Membership is event-local and distinct from framework groups. `Auth::GetAccess()` remains an outer check; the installed `phpauth` connector requires database permission records in addition to launcher JSON.

Public invitations carry no PII in their QR URL. An RSVP credential is exchanged by CSRF-protected POST for a 30 minute scoped PHP session. Token expiry/revocation is rechecked against current state on every request. Private invitation/wall/seat data is projected narrowly; contacts, notes, the registry, pending greetings, connection secrets, staging references, and upload sessions never appear in public responses. CSV export defuses leading spreadsheet formula characters.

All public activity responses are private/no-store. Guest polling pauses when hidden and retries on network failure. Wall refresh is configured from 5–15 seconds. Output uses escaped Vue text interpolation, with no `v-html` and no executable template content. Provider requests use a fixed HTTPS host allowlist, no arbitrary fetch proxy, and no redirects.

## State policies

Lifecycle: draft → published/cancelled/archived; published → published/closed/cancelled; closed → published/cancelled/archived; cancelled → archived. Archived is terminal. Publishing takes a separate snapshot. RSVP/sales close on `closed`; valid private seat lookup remains available. Cancellation/archive revokes guest tokens, admissions, pending holds, and unfinished uploads.

Seating: confirmed attendees only; one seat per attendee, one attendee per seat. Declining releases a seat. Paid/ticketed attendees must first have an owner cancellation/refund. Occupied capacity reduction/deletion requires explicit release. Table revision detects stale layout edits. Auto-plan is deterministic (VIP, descending group size, stable IDs), preserves assignments, places accessibility needs first, and does not split groups unless explicitly allowed. A fresh server-derived preview is required before application.

Orders: holding → checkout → issued; expired/cancelled reservations release stock; a late verified payment may issue if capacity remains or enter refund_pending. Browser success cannot fulfill. Refund/revocation invalidates admissions and seat allocations. One admission is issued per attendee. Ticket display rotates codes rather than reconstructing hashes. Check-in requires staff; viewing QR is read-only. Manual admission/reversal records the actor and reason.

Jobs: queued → running (lease) → done, queued with bounded backoff, failed, or uncertain (email). Drive resumable state and reserved file IDs permit reconciliation after a crash. SMTP is deliberately not retried after ambiguous delivery. Scheduler screens do not execute transfers; use the CLI worker.

## Framework gap addressed

`components/component_manager.php` previously concatenated component file paths without canonical containment and could expose PHP/config sources. The narrow guard validates app/component references, canonicalizes resource paths, rejects traversal/hidden segments, and limits served extensions to browser assets. It retains descriptor-based loading and existing supported static libraries. This earlier core guard was reverted on the project owner's instruction; no core patch is part of the current app-only fix. Asset routing already has a containment guard. Private uploads use dedicated ingress and never use the generic uploader or `/files` storage.

## Release boundaries

The existing scheduler lacks durable atomic claims; the app supplies its own leased queue. Existing Stripe adapters are credit-specific/legacy; event Checkout uses a small event-scoped PHP adapter. Plugin metadata follows maintained manifests' `plugins.local`/`plugins.global` shape; the inspected downloader is a frontend launcher, not proof of a different installer contract. Do not normalize other apps.

Live host OAuth, real Drive ACLs/storage quotas, SMTP delivery, Stripe merchant eligibility and provider event timing must be verified with configured test accounts. Browser render/mobile/accessibility/dock interaction QA could not run because the browser access host was unavailable; VNode compatibility is a narrower check.


## Service file organization (1.1.1)

Each HTTP handler lives in the service file declared by its own component descriptor:

| Service folder | Handler class |
| --- | --- |
| `services/events/service.php` | `davvag_events\EventService` |
| `services/guests/service.php` | `davvag_events\GuestService` |
| `services/public/service.php` | `davvag_events\PublicService` |
| `services/seating/service.php` | `davvag_events\SeatingService` |
| `services/greetings/service.php` | `davvag_events\GreetingService` |
| `services/media/service.php` | `davvag_events\MediaService` |
| `services/oauth/service.php` | `davvag_events\OAuthService` |
| `services/sales/service.php` | `davvag_events\SalesService` |
| `services/webhook/service.php` | `davvag_events\WebhookService` |
| `services/checkin/service.php` | `davvag_events\CheckinService` |

`lib/bootstrap.php` loads reusable support, domain, seating, ticketing, provider and job helpers. It does not load HTTP handler classes. CLI jobs and domain tests load that reusable bootstrap; handler tests explicitly load the relevant service file. The former `lib/Services.php` monolith has been removed. Handler bodies, namespaces, routes, authorization and method mappings are unchanged.

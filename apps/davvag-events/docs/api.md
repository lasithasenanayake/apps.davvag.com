# API reference

Base: `/components/davvag-events/{service}/service/{Handler}`. Successful responses keep the framework `{success:true,result:...}` envelope; failures use HTTP status plus `{success:false,result:{code,message}}`. All browser POSTs require the Session CSRF token. OAuth Callback validates state; Stripe webhook validates its raw-body signature instead. No GET performs check-in.

Every event-scoped call requires `eventId`. Owner/staff calls resolve authenticated profile membership on the server. Mutation inputs use explicit field allowlists; owner profile, audit actor, namespaces, provider totals and destination folders cannot be supplied by clients.

| Service | Handler | HTTP | Trust boundary |
| --- | --- | --- | --- |
| `checkin` | `Attendees` | GET | Assigned staff; owner/guest grant for issuance |
| `checkin` | `Scan` | POST | Assigned staff; owner/guest grant for issuance |
| `checkin` | `IssueAdmission` | POST | Assigned staff; owner/guest grant for issuance |
| `checkin` | `Manual` | POST | Assigned staff; owner/guest grant for issuance |
| `checkin` | `Reverse` | POST | Assigned staff; owner/guest grant for issuance |
| `events` | `Session` | GET | Authenticated profile; Session is public |
| `events` | `ListEvents` | GET | Authenticated profile; Session is public |
| `events` | `Event` | GET | Authenticated profile; Session is public |
| `events` | `SaveEvent` | POST | Authenticated profile; Session is public |
| `events` | `Transition` | POST | Authenticated profile; Session is public |
| `events` | `Member` | POST | Authenticated profile; Session is public |
| `events` | `Preview` | GET | Authenticated profile; Session is public |
| `events` | `Report` | GET | Authenticated profile; Session is public |
| `events` | `RetryJob` | POST | Authenticated profile; Session is public |
| `events` | `DeleteEvent` | POST | Authenticated profile; Session is public |
| `greetings` | `Wall` | GET | Event access for Wall/Submit; moderator for queue/actions |
| `greetings` | `Submit` | POST | Event access for Wall/Submit; moderator for queue/actions |
| `greetings` | `Moderation` | GET | Event access for Wall/Submit; moderator for queue/actions |
| `greetings` | `Moderate` | POST | Event access for Wall/Submit; moderator for queue/actions |
| `guests` | `Guests` | GET | Owner / explicit guest or report grant |
| `guests` | `AddParty` | POST | Owner / explicit guest or report grant |
| `guests` | `Respond` | POST | Owner / explicit guest or report grant |
| `guests` | `RotateInvitation` | POST | Owner / explicit guest or report grant |
| `guests` | `PreviewImport` | POST | Owner / explicit guest or report grant |
| `guests` | `ApplyImport` | POST | Owner / explicit guest or report grant |
| `guests` | `Export` | GET | Owner / explicit guest or report grant |
| `guests` | `QueueInvitation` | POST | Owner / explicit guest or report grant |
| `media` | `Queue` | GET | RSVP session for Upload/Cancel; media grant for Queue |
| `media` | `Upload` | POST | RSVP session for Upload/Cancel; media grant for Queue |
| `media` | `Cancel` | POST | RSVP session for Upload/Cancel; media grant for Queue |
| `oauth` | `Status` | GET | Initiating event owner; expiring state on callback |
| `oauth` | `Connect` | POST | Initiating event owner; expiring state on callback |
| `oauth` | `Callback` | GET | Initiating event owner; expiring state on callback |
| `oauth` | `Disconnect` | POST | Initiating event owner; expiring state on callback |
| `public` | `Exchange` | POST | Published policy / scoped invitation session |
| `public` | `Invitation` | GET | Published policy / scoped invitation session |
| `public` | `Unlock` | POST | Published policy / scoped invitation session |
| `public` | `Respond` | POST | Published policy / scoped invitation session |
| `public` | `Seat` | GET | Published policy / scoped invitation session |
| `public` | `Calendar` | GET | Published policy / scoped invitation session |
| `sales` | `Types` | GET | Event access / order session; owner for type/refund |
| `sales` | `SaveType` | POST | Event access / order session; owner for type/refund |
| `sales` | `Checkout` | POST | Event access / order session; owner for type/refund |
| `sales` | `DisplayTickets` | POST | Event access / order session; owner for type/refund |
| `sales` | `Admission` | POST | Event access / order session; owner for type/refund |
| `sales` | `Refund` | POST | Event access / order session; owner for type/refund |
| `seating` | `Plan` | GET | Owner / explicit seating grant |
| `seating` | `SaveTable` | POST | Owner / explicit seating grant |
| `seating` | `Assign` | POST | Owner / explicit seating grant |
| `seating` | `AutoPreview` | POST | Owner / explicit seating grant |
| `seating` | `AutoApply` | POST | Owner / explicit seating grant |
| `webhook` | `Stripe` | POST | Verified provider signature and stored order match |

## Main inputs and results

- `SaveEvent`: optional eventId/revision; title, occasion on creation, timezone, offset-bearing start/end/deadline, venue/address/maps, structured design/agenda, modules/windows and settings. Returns authorised event view. Unknown ownership/system fields are ignored.
- `Transition`: eventId, revision, status. Returns updated view; invalid lifecycle yields LIFECYCLE 409.
- `Member`: eventId, revision, linked profileId, manager/checkin/moderator role, allowlisted grants; remove=true revokes membership. Owner/admin only.
- `AddParty`: names[], plusOnes, email/WhatsApp, group, VIP, emailConsent. Returns a new party. Manual Respond requires partyId and attendees[].
- `PreviewImport` / `ApplyImport`: exact CSV, duplicatePolicy reject/skip/allow. Apply requires confirmed=true and the same session/actor, event revision and preview content within ten minutes. Returns row errors/duplicates or import count. Export returns formula-safe CSV text.
- `RotateInvitation`: partyId; revokeOnly optionally revokes without replacement. A replacement credential/URL is returned once and old links are invalidated. QueueInvitation additionally requires delivery consent, stable operation, and optional offset-bearing runAt.
- `Exchange`: credential sent in the POST body, eventId, CSRF. Establishes a 30 minute RSVP capability session. Invitation/Seat/Calendar reuse this session.
- Public `Respond`: attendees[] plus stable operation key. Existing attendee IDs must belong to this party; unnamed plus-ones become stable attendees. Same operation retries return current party without adding another person. Declines release seats.
- `SaveTable`: revision, layoutRevision, optional tableId, shape round/rectangle/theatre, label, capacity, x/y/rotation, accessibleSeats[] (1-based), VIP; delete/releaseOccupied explicitly handles removal. Assign uses guestId, seatId (empty to unassign), locked and unlock.
- `AutoPreview`: optional allowSplit. Returns assignments, unsatisfied groups, hash, revision and heuristic. AutoApply requires that fresh hash/revision in the same actor session.
- Greeting Submit: displayName or anonymous, plain-text message, operation. Returns pending/approved; Wall returns only approved projections, pagination offset and pollSeconds. Moderate uses greetingId and approved/rejected/pending/delete.
- Media Upload: multipart file, eventId, stable 32-hex operation key, uploader, consent plus X-Davvag-CSRF header; requires RSVP session. Returns uploadId/status only. Cancel accepts uploadId scoped to the same party. Queue is host-only.
- OAuth Connect returns a Google URL. Callback accepts provider code/state bound to initiating owner/session/tenant. Status never returns credentials; Disconnect revokes the owner connection.
- `SaveType`: name, integer price, explicit currency, capacity, limit, offset-bearing sales start/end. Checkout accepts typeId, attendee names[], email/consent, stable operation. Server returns order ID, hosted URL or free issuance status, and one-time order recovery material. No client amount is trusted.
- `DisplayTickets`: orderId and scoped order session or recovery credential. Rotates issued admission codes. Admission reads one opaque credential by POST and never checks in. Refund requires owner, orderId and reason.
- Stripe webhook: raw body plus Stripe-Signature. Event ID is deduplicated and a durable verification/fulfillment job is committed before success acknowledgement. Worker retrieves stored Checkout and verifies tenant/event/order/account/amount/currency/paid status.
- Scan: opaque admission credential, authenticated staff/event. Returns valid or already_used. Manual accepts guestId/reason; Reverse requires owner, checkinId and reason. Both retain audit.
- Report returns bounded recent audit, queue and seating projections. RetryJob only retries failed work; ambiguous email delivery requires explicit review and a new rotated invitation. DeleteEvent requires archived lifecycle, exact title confirmation and keepDriveFiles=true.

## Failures and idempotency

Typical codes: AUTH_REQUIRED 401, FORBIDDEN/CSRF/PRIVATE/CREDENTIAL 403, NOT_FOUND 404, CONFLICT/SEAT_TAKEN/LAYOUT_STALE/FULL/SOLD_OUT/DEADLINE/WINDOW_CLOSED 409, expired credential/event 410, validation 422, RATE_LIMIT 429, SETUP/DATASTORE/provider availability 503. Datastore failures call the framework response error helper so they cannot become an outer success.

Mutations use revision CAS. Checkout operation keys, RSVP operations, job keys, provider event/payment references and admission keys are retained and uniquely enforced. Stale conflicts are retryable after refreshing server state. Provider idempotency keys derive from tenant+order; requests cannot choose raw provider keys. SMTP ambiguity is reported explicitly.

## 1.1.0 editor additions

`SaveEvent` accepts localStart, localEnd, localRsvpDeadline and timezone for date-picker input, plus templateId/templateVersion. Dates are converted server-side and event snapshots come from the installed template library. `Session` supplies supported timezones and available templates. `POST events/SaveTemplate` is tenant-admin-only and CSRF protected; it saves a new version of an app-owned HTML/CSS card. See [template guide](invitation-templates.md) for inputs, placeholders and rendering constraints.

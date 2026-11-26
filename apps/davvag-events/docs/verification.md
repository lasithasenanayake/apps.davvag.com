# Verification and progress

Verified locally on 25 November 2026. This is a local implementation handoff, not a production release approval.

## Observed results

- PHP 8.2.12, installed Vue 2.0.3, MySQLi/mysqlnd and required extensions confirmed.
- Migration `001_atomic.sql` installed in the effective `localhost` datastore; repeat invocation reported already installed and method permissions remained idempotent.
- 66 business/security/provider-double/concurrency checks passed against a newly created isolated test database. The database was removed afterward.
- 19 HTTP journey and public-path checks passed through Apache and the real component dispatcher. Scoped fictional events/profiles/PHP sessions were removed afterward.
- All 14 route templates compiled and rendered as VNodes using installed Vue 2.0.3. This does not verify CSS, browser layout, camera behavior, or accessibility interactions.
- 187 PHP lint, JavaScript syntax, JSON, class/method descriptor and referenced-resource checks passed. The validation script reports the precise resource/check count on each run.
- Local unauthenticated Session endpoint returned the five templates and disabled Drive/Stripe setup flags. No actual provider account was connected and no emails/payments/uploads to external accounts were sent.

Tests cover draft/published separation; another owner's denial; staff restrictions; party privacy; random, expired, revoked and wrong-event credentials; plus-one bounds; duplicate RSVP retries; Unicode/apostrophes; CSV preview/formula protection; locked/stale/insufficient seating; decline seat release; independent RSVP closure/lookup; actual concurrent last-seat assignment, last-ticket checkout, paid fulfillment, admission scans and job claims; free ticket issuance; delayed/unpaid and mismatched provider responses; late-payment compensation; refunds/revocation; raw webhook signatures; one-time OAuth state; encrypted tokens and refresh-token preservation; Drive folder reuse, ranged transfer with an ambiguous success and reserved-ID reconciliation, quota/auth failure, retention; durable rate limits; CSRF; generic SOSS protection; public greeting moderation; and direct file/source retrieval denial.

## Phase checklist

- [x] Foundation, runtime tenant discovery, protected schemas, transactional migration, roles, audit, event persistence and source-package installer.
- [x] Five structured templates, editable draft, publication snapshot, agenda, calendar, share/QR, private invitation exchange, party/attendee registry, CSV preview/export, RSVP.
- [x] Persisted round/rectangular/theatre layouts, form/drag controls, manual assignment, deterministic preview/apply, locked/accessibility constraints, private lookup and online admission.
- [x] Greeting submissions, moderation, approved-only paginated wall, configurable hidden-aware polling and presentation layout.
- [x] Drive OAuth and encrypted owner connections, private multipart ingress, quotas, durable resumable transfer, retry/reconnect and staging cleanup; deterministic provider doubles.
- [x] Free issuance, hosted Stripe adapter, holds, signed durable webhooks, verified worker fulfillment, compensation/refund path, consent-based email jobs and manual WhatsApp sharing.
- [ ] Live Google OAuth/consent/refresh/revocation and Drive transfer/storage/ACL checks with an authorized test account.
- [ ] Stripe test-mode merchant/currency eligibility, live endpoint delivery/retry ordering, delayed methods and refunds with an authorized merchant.
- [ ] SMTP delivery with authorized test recipients and deployment worker environment/restart checks.
- [ ] Browser/mobile/tablet/desktop visual checks in both docks, keyboard/screen-reader review and camera permission checks. Tool reported `The browser access host is unavailable`.

## Known scope limits

- Ticketing is Stripe plus free issuance; Razorpay and automated WhatsApp are not implemented.
- Cover selection uses administrator-installed local image assets. It does not supply a cover upload/crop library or arbitrary external URLs.
- Browser ingress retries restart one failed file. Drive transfer resumes ranges durably.
- Per-event volume is bounded to a 900 KB aggregate / 1,000 attendees; more scale requires a reviewed storage migration.
- Private lookup uses personal invitation capabilities; optional exact-name mode operates behind event access and requires personal links to disambiguate duplicate names.
- Email crash ambiguity is shown for review rather than promising exactly-once SMTP delivery.
- Owners retain host Drive files during metadata deletion and can remove app-created files in Drive; automated bulk Drive deletion is not implemented.
- Production encryption keys, private staging directories, server limits, HTTPS/session settings, provider credentials and recurring worker scheduling must be configured on the deployment host.

## 1.0.1 sign-in correction

The active account had two linked primary profiles, which the original app incorrectly rejected. Identity resolution now selects a stable primary profile without modifying account data or relaxing event authorization. Session distinguishes missing linkage from being signed out; Retry reloads Session. Eleven isolated PHP identity/session checks and two JavaScript Retry checks passed. PHP/JavaScript syntax and 14 installed-Vue route template checks passed. A read-only check resolved the affected account successfully, and Apache served manifest version 1.0.1 with the Retry fix. Core component changes were reverted; this correction changes only davvag-events files.

## 1.1.0 editor revision

Details-first workflow, native date/time controls, separate design/colour choice, isolated animated card rendering and administrator versioned HTML/CSS library were added only inside the app. Twenty-six PHP date/template checks and thirteen JavaScript editor checks passed. Existing account records and event content were not changed by this update. See [editor/template guide](invitation-templates.md).


## 1.2.0 seating revision

Changes are confined to the installed davvag-events app. Add/edit popups support round, oval, square and rectangular numbered tables, plus numbered row plans. Named coloured sections and guest groups feed the visual layout, manual assignment and suggestion rules. Existing seat IDs and assignments survive edits. No live event content was rewritten during verification.

Verified on 2026-11-25:

- 36 isolated PHP seating checks: numbering, shapes, stable IDs, occupied removal, sections, row labels, bulk retries, group membership, section placement, partly seated groups, legacy tables, Unicode keys and aggregate integrity.
- 17 JavaScript seating checks: popup defaults and payloads, revisions, closing/focus restoration, row setup, groups, geometry, occupied seats, assignment and Escape.
- 22 service organization, 11 identity/session, 26 editor/date/template, 13 JavaScript editor-flow and existing Session Retry regression checks passed.
- 14 route templates compiled and rendered as VNodes with installed Vue 2.0.3, including all seating popup states and table shapes. This does not verify browser layout.
- 221 descriptor/resource/JSON/PHP/JavaScript checks passed.
- Apache served 1.2.0 seating/style component descriptors and the updated seating HTML, script and stylesheet over HTTP.

Tests used fictional in-memory seating records. The existing template regression test created and removed its own app-local temporary library files. No real guests were moved. Visual browser and device QA remains unverified because browser access was unavailable; interaction behavior was checked with the isolated JavaScript harness.


## 1.2.1 plan viewport fix

Removed the forced 1,100 px minimum SVG width and automatic intrinsic-height dependency. The canvas now has explicit dimensions within a tall responsive viewport. A case-preserving object binding supplies SVG viewBox/preserveAspectRatio even when the browser normalizes HTML attribute names. Fit bounds follow visible content and include rotated rows; dragging uses the fitted SVG scale and freezes the view while moving. Plan and side panels use the full workspace width. Fit and expanded-view controls reset zoom and preserve keyboard access.

Twenty-three seating interaction checks and fourteen installed-Vue route renders passed, including SVG dimensions, fit binding, zoom in both dimensions, section fitting, rotated rows and Escape from expanded view. The 221 resource/descriptor/JSON/PHP/JavaScript checks passed. No backend or live event records changed. Browser layout remains visually unverified in this session.

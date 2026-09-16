# Codex implementation prompt: lesson-market-place

Build a complete DAVVAG application named `lesson-market-place` in this existing project. Implement the application and its necessary integrations, tests, and documentation; continue beyond analysis or scaffolding until the requested user journeys work or a specific external prerequisite prevents completion.

## 1. Read the project and resolve the tenant

Start with:

- Architecture authority: `C:\xampp\htdocs\davvag-core\DAVVAG-Framework-App-Development-AI-Context.md`, including sections 68, 77 and 78 and its required reading order.
- Framework root: `C:\xampp\htdocs\davvag-core\davvag-core`.
- Lesson Manager: `C:\xampp\htdocs\davvag-core\davvag-core\localhost\apps\lesson-manager`.
- Credit Points: `C:\xampp\htdocs\davvag-core\davvag-core\localhost\apps\davvag-credit-points`.
- Related tenant apps: `course-manager`, `productapp`, `davvag-cms-v7`, `davvag-tools`, `currency-configuration`, and the existing payment provider app used by Credit Points.

Read applicable `AGENTS.md` files, descriptors, services, component scripts, schemas, tests and relevant framework documentation. Inspect existing changes and preserve unrelated work. Resolve the active tenant using framework configuration. The inspected `localhost` path is a symlink to `C:\xampp\htdocs\apps.davvag.com`; verify the real path before edits and include it in the final report. Do not edit both paths as separate tenants.

Create the new app under the resolved equivalent of `localhost/apps/lesson-market-place`. Use PHP, DAVVAG Webdock/Vue components, JSON descriptors and tenant schemas. Follow the existing framework rather than introducing a separate application stack.

## 2. Business objective

An authorized staff member can package one or more existing lessons under a DAVVAG product, create a page showing that package, and accept learner enrolments. Packages can be free or priced in whole-number credits. They can enrol automatically or require staff approval.

Reuse Lesson Manager for teaching content, quizzes, assignments, marks and progress. Reuse Credit Points for wallets, credit purchases, financial transactions and balance/history. Reuse existing profiles, authentication, product catalog, media tools and CMS capabilities.

Use the following defaults unless repository rules require an alternative; document any necessary difference and its reason.

## 3. Staff package management

Provide searchable, paginated package management with create, edit, preview, publish, unpublish and archive actions.

Each package must have:

- A name, unique stable slug/code, summary, sanitized rich description, cover image, learning outcomes, audience and prerequisites.
- A valid `product_id` linked to `products.itemid`. Let staff select an existing manageable product or create one using an appropriate existing product service. Require product linkage before publication. Use one package identity per linked product and version package changes.
- One or more existing lesson references, organized by their actual course and subject, with a package display order. Never copy lesson content into package records.
- Independent settings: `pricing_mode = free | credits`, `credit_price`, and `approval_required`.
- Owner/staff scope, lifecycle status, and creation/update/publication timestamps.

Free means a price of zero; paid means a positive whole-number credit price. Store the credit price in the lesson package domain, not in a fiat product price or credit top-up package. Reject duplicate, missing, deleted or unauthorized lesson references. Require published lessons for publication. Validate course/subject relationships from the server.

Keep draft edits separate from published terms. Snapshot the package version, included lesson IDs, price, approval setting and displayed terms when a learner submits a request or confirms immediate enrolment. Changes must not silently rewrite existing entitlements or pending agreed terms. Honour the recorded version for pending requests where still deliverable; otherwise explain why it is unavailable and require a new explicit request. Archiving prevents new enrolments while retaining valid existing access and history.

For the initial release, access is permanent and one enrolment per learner/package is allowed. Do not introduce subscriptions, recurring charges, revenue sharing or automatic refunds. For overlapping packages, disclose already-accessible lessons and the full package price; do not invent partial-pricing rules or charge again when opening lessons.

## 4. Package pages and CMS integration

Provide these routes, adapting only to verified router constraints:

- `#/app/lesson-market-place/` — published package catalog.
- `#/app/lesson-market-place/package?slug=<slug>` — package page.
- `#/app/lesson-market-place/my-enrolments` — learner requests and enrolled packages.
- `#/app/lesson-market-place/admin/packages` — staff management.
- `#/app/lesson-market-place/admin/package?id=<id>` — package editor/preview.
- `#/app/lesson-market-place/admin/enrolments` — staff request review.

The package page must show the description, price/free label, approval requirement, outcomes, prerequisites and included lessons grouped by subject/course. Use safe preview metadata; do not expose protected lesson bodies, resource URLs or quiz answers before entitlement.

Show the correct action for the signed-in learner: Sign in to enrol, Enrol free, Request enrolment, Awaiting approval, Buy credits, Confirm enrolment for N credits, Rejected, or Continue learning. Give learners a clear purchase confirmation showing package, included lessons, credit cost, available balance and resulting balance.

Implement a reusable registered package/enrolment component for CMS HTML sections. Staff must be able to create or select a CMS page through the existing CMS flow and place the selected package component on it, preview the page and copy its shareable link. Use the section 77 embedding contract with explicit source app `lesson-market-place`. Inspect the loader and establish a documented, supported per-instance package input; do not assume arbitrary attributes become component props. Multiple packages embedded on one page must not share mutable instance state.

Use existing media upload and rich-text tools. Validate/sanitize authored HTML and URLs. Public browsing of published package metadata is allowed; enrolment and purchase require a real authenticated profile. Wire group visibility narrowly and enforce backend access even when a component is embedded. Preserve an internal return destination through sign-in and credit purchase; reject external return URLs.

## 5. Enrolment rules

Implement all four combinations:

| Pricing | Approval | Required sequence |
| --- | --- | --- |
| Free | No | Enrol confirmation -> active enrolment and included lesson access |
| Free | Yes | Request -> pending approval -> staff approval -> active access |
| Credits | No | Confirm price -> atomic credit debit and enrolment/access -> active |
| Credits | Yes | Request -> pending approval -> staff approval -> awaiting payment -> learner confirms debit -> active |

For paid approval packages, charge and reserve nothing while staff review the request. Approval makes payment available and does not authorize an automatic debit. Rejection/cancellation before payment creates no charge or access. A learner with enough credits can purchase immediately without topping up. Insufficient credits must not create active access or a negative balance.

Use explicit server-validated transitions such as `pending_approval`, `awaiting_payment`, `active`, `rejected`, and `cancelled`, plus internal processing/recovery states only if needed. Permit cancellation before activation. A later application after rejection/cancellation must preserve history and use an explicit new attempt; distinguish that from replaying the previous request. An active enrolment is returned unchanged on retries.

Staff can filter requests, inspect the requested package version and learner, approve or reject with a recorded decision and optional learner-visible explanation. Keep internal notes private. Enforce staff scope and ownership rules; a teacher role alone must not confer unrelated package access. Record actor, time, previous/new state and reason. Reject conflicting or duplicate decisions safely. Reuse existing notification infrastructure for request, approval, rejection and activation events with duplicate suppression.

## 6. Credit integration and complete buy-credits journey

Use the authoritative PHP library:

`localhost/apps/davvag-credit-points/lib/CreditLedgerService.php`

The inspected class is `davvag_credit_points\CreditLedgerService`; verify current signatures. Its context uses `programCode`, `sourceApp`, `referenceType`, `referenceId`, `idempotencyKey`, `description`, and optional `metadata`/`actorProfileId`. Use `sourceApp = lesson-market-place` and an enrolment reference. Read the actual configured program rather than guessing currency or conversion rates.

Expose narrow authenticated marketplace actions such as `RequestEnrolment` and `ConfirmEnrolment`. Derive learner identity, stored package version, amount, program, state and ledger reference on the server. The generic ledger HTTP debit/reservation methods are administrator-only; do not make them public or have learners invoke them directly.

Charge once per package enrolment using a deterministic server-controlled operation key and a database-enforced unique learner/package constraint. Enforce uniqueness across changed versions and new client keys. Validate matching request payloads on replay. Protect against simultaneous tabs, repeated clicks, lost responses and concurrent staff actions.

Commit the debit, active enrolment and all included lesson grants atomically. The existing `debit` side-effect callback receives the ledger transaction database; use that same connection for the enrolment/grant writes. Do not nest the current `CreditDatabase::transaction()` wrapper or assume a separate SOSSData connection participates in it. Initialize schemas/migrations beforehand. Roll back the complete operation if any grant fails. For free enrolments, atomically create enrolment/grants without a financial transaction or a requirement for positive wallet balance. If a reservation design is needed elsewhere, implement capture/release and recovery explicitly; do not reserve credits indefinitely awaiting approval.

On insufficient funds, retain the intended package/request and take the learner to the existing Credit Points buy flow. After top-up, re-read authoritative order status, wallet balance, package availability and enrolment state. Return to the same package for explicit confirmation; a browser return flag is never proof of payment.

Important inspected gap: the current Credit Points package-store UI creates a purchase order and displays instructions, but does not initiate provider checkout. The completion endpoint is an HMAC-signed provider-neutral boundary. Inspect and integrate the existing configured provider to make checkout initiation, verified settlement, wallet crediting and return navigation work end to end. Validate provider, order ownership/reference, settled status, expected amount/currency and replay protection on the server before crediting. Keep the provider bridge in the appropriate shared credit/payment app. Never fabricate successful payment or treat the signed internal boundary as provider verification by itself. If live credentials are unavailable, implement/test the adapter with supported sandbox fixtures and report the exact configuration still required.

## 7. Lesson access integration — required correctness work

Current Lesson Manager `ApiService` uses course enrolment, subject progression and standalone paid lesson unlocks. `StartLesson` can debit an individual lesson automatically after its existing confirmation flow. Package enrolment must integrate with these checks.

Implement one authoritative lesson-access resolver that recognizes existing course assignments and standalone paid unlocks, and active marketplace grants for the specific learner/lesson. Marketplace access covers a lesson's financial requirement, including paid lessons in a free package, without modifying its global price. It does not bypass publication, availability or required learning progression.

Do not create unrestricted course enrolments as a shortcut: that would expose unrelated free lessons. A package-only learner must discover the relevant course/subject in My Learning while seeing and accessing only entitled lessons. Preserve broad access for existing legitimate course enrolments.

Update all affected server and UI paths: `StudentCourses`, `LearningCourse`, `StartLesson`, activity completion, quiz questions/start/submit/results, assignments/submissions and protected resources. Check ownership when reading existing progress or submissions. Direct API calls must not bypass package boundaries. Opening an included lesson must never invoke another lesson debit.

Use source-aware grants so duplicate/overlapping packages and standalone purchases remain distinguishable. Do not create synthetic permanent `davvag_credit_lesson_unlock` records for package access. The inspected `hasLessonUnlock()` ignores the schema's status field, so do not assume status changes revoke such rows. Preserve pre-existing standalone purchases and progress.

Keep the lesson hierarchy and subject progression intact. Validate that a published package includes every required preceding published lesson under enabled progression; show missing prerequisites to staff and prevent publication until resolved. Revalidate deliverability before charging, and report content changes that invalidate a package. Package display ordering must not rewrite subject lesson ordering. Teacher approval of lesson completion remains independent of marketplace enrolment approval.

Protect internal lesson download/stream endpoints with the same entitlement checks where applicable. Do not claim that public third-party video URLs can be made private by a frontend lock; document provider protection limits.

## 8. Persistence, framework integration and security

Design tenant schemas for package identity/version, version lesson membership, enrolment/request attempts, source-aware lesson grants and audit events. Prefer clear namespaces such as `lmp_*`; adapt to repository conventions. Include product/profile/lesson/transaction references, timestamps and necessary immutable snapshots. Declare actual unique indexes and lookup indexes, and implement safe repeatable migrations because schema declarations alone may not update existing tables.

Use SOSSData for ordinary persistence and framework tenant resolution. Keep financial atomicity inside the existing shared ledger transaction boundary. Do not duplicate balances, payment orders, content, marks, authentication or profile systems. Add only the focused changes needed in existing apps. Preserve all existing data and avoid dependency cycles when placing the shared access contract.

Register `app.php`, `app.json`, icon, components, service descriptors, routes, onLoad dependencies, schemas and group visibility. Follow `exports.vue.onReady`, the framework response envelope, declared service components and supported cross-app routing. Declare real app/plugin/schema/PHP dependencies and bump versions of changed resources.

Enforce tenant, profile, role, staff scope and record ownership at each endpoint. Whitelist writable fields, validate input lengths/ranges, paginate lists, preserve view-object protections and use bound parameters for approved raw queries. Prevent generic CRUD from editing enrolments, grants, published snapshots or financial fields. Return safe errors without secrets or database details.

Provide responsive, accessible screens with loading, empty, error, retry and success states. Lock action buttons until requests settle and retain the same operation identity for a retry after an uncertain result. Browser locks supplement server-side concurrency protection.

## 9. Validation and acceptance

Add meaningful business-rule and isolated database integration tests for:

1. All four pricing/approval combinations and valid/invalid transitions.
2. Free enrolment without credits; exact balance, insufficient balance and an unavailable/suspended wallet for paid enrolment.
3. Repeated requests, changed payloads under one key, new client keys for the same enrolment, concurrent purchases and concurrent staff decisions.
4. Exactly one debit and one active enrolment; no partial lesson grants after injected failure; recovery after a lost response; no nested-transaction partial commit.
5. Correct learner/tenant/staff scope; draft and unpublished package restrictions; tampered identity, price, approval and lesson fields.
6. Package-only learners accessing included lessons and being denied unrelated lessons through every relevant API; standalone course/purchase access remaining valid; no double charge on lesson open.
7. Prerequisite validation, subject progression, overlapping entitlements, immutable purchased/pending versions and preserved learning progress.
8. Credit-store checkout/return, failed or cancelled payment, repeated callback, invalid provider verification and mismatched amount/currency; a return URL alone grants nothing.
9. CMS embedding, multiple component instances, anonymous metadata-only access, sign-in return and staff page creation.

Run existing relevant Lesson Msanager and Credit Points regression tests after inspecting their setup and cleanup behavior. Run database/concurrency tests only against a verified isolated test database. Validate PHP, JavaScript and JSON syntax, descriptors, service names, routes, dependencies and migrations. Perform browser checks in supported docks/CMS for the core learner and staff journeys when the environment permits. Report tests actually executed, failures, skips and prerequisites separately.

## 10. Deliverables

Deliver the implemented app, focused integration changes, schemas/migrations, automated tests, and an app README documenting configuration, states, credit/entitlement guarantees, staff permissions, routes, CMS page setup and payment-provider setup.

Update the architecture context with the final implemented contract, clearly separating verified behavior from remaining limitations. Finish with a concise report listing real paths, files changed, routes, migrations, reused services, tests executed and any external setup still needed. Do not claim successful live payments or browser validation unless actually performed.

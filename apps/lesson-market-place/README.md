# Lesson Marketplace

Lesson Marketplace packages existing Lesson Manager lessons under one DAVVAG product. It adds versioned terms, enrolment review, atomic credit payment, source-aware lesson grants, Course Manager cohort synchronization, and CMS embeds while retaining Lesson Manager as the content and progress authority.

All ordinary marketplace persistence goes through `SOSSData` using schema-checked advanced queries and `Insert`/`Update`. `MarketplaceData` is the app-local facade that keeps service-only namespace scopes narrow and preserves normal view-object filtering. The sole direct runtime business path is inside the callback owned by `CreditLedgerService::debit()`, where the debit, grants, enrolment activation, and audit event must share one transaction because the current public SOSSData API does not expose transaction handles. The administrator-run migration separately inspects physical table metadata and indexes.

## Routes

- `#/app/lesson-market-place/` — published catalog
- `#/app/lesson-market-place/package?slug=<slug>` — package details and enrolment
- `#/app/lesson-market-place/my-enrolments` — learner history
- `#/app/lesson-market-place/admin/packages` — staff packages
- `#/app/lesson-market-place/admin/package?id=<id>` — editor and preview
- `#/app/lesson-market-place/admin/enrolments` — staff review

`admin` and `sysadmin` may manage every package. `staff` and `teacher` may create packages only from subjects whose `teacher_id` is their profile, and may manage/review only packages they own. Every service checks this scope; dock visibility alone conveys no authority.

## States and guarantees

| Price | Approval | Request result | Activation |
| --- | --- | --- | --- |
| Free | No | `active` | Creates the lesson grants and Course Manager cohort membership; no wallet is needed. |
| Free | Yes | `pending_approval` | Staff approval creates the grants and cohort membership. |
| Credits | No | `awaiting_payment` | Learner confirmation atomically debits and creates the grants and cohort membership. |
| Credits | Yes | `pending_approval` | Approval changes to `awaiting_payment`; confirmation later performs the atomic activation. |

Rejected or cancelled requests remain in history. Applying again names the previous attempt and creates a new attempt. A unique learner/package record and deterministic debit key protect repeated clicks, simultaneous tabs, and lost responses. Published versions and accepted snapshots are immutable. Archiving stops new enrolments and retains active grants.

Each package is tied to one active Course Manager cohort, and all included lessons must belong to that cohort's course. Activation creates an idempotent, source-tagged cohort membership. It makes the learner available to cohort assignments and every timetable-slot attendance roster; attendance records are created only when staff save the roster, so activation does not falsely mark a learner present.

Package grants cover the included lessons' financial requirement, including paid lessons in a free package. The synchronized cohort membership has `access_scope=package_lessons`, so it does not grant broad Lesson Manager course access or bypass publication, availability, or subject progression. Existing manual course enrolments and standalone lesson unlocks remain separate entitlement sources.

## Installation and migration

Install this app and the accompanying focused changes to Lesson Manager, Credit Points, Stripe, DAVVAG Tools, User App, Course Manager, the shared SOSSData/component manager, and protected media policy. Then run from a shell with database access:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\apps.davvag.com\apps\lesson-market-place\bin\migrate.php localhost C:\xampp\htdocs\davvag-core
```

The repeatable migration creates eight `lmp_*` tables and `davvag_credit_checkout`, extends `course_manager_enrollment` with source/scope metadata, installs the narrow group/operation permission manifest, verifies InnoDB and exact unique/index definitions, adds missing nullable columns, and only widens compatible text columns. It stops for incompatible primary keys, types, or indexes. Back up the tenant database before a production migration.

The new `serviceOnly` schema flag prevents generic SOSS CRUD from reading or mutating marketplace, lesson, submission/mark, and credit records. Installed application services receive narrow capabilities in PHP. Protected uploader namespaces use `global/config/media-access.json` and Lesson Manager's entitlement resolver.

## Stripe credit checkout

Credit packages used by the store must have `payment_channel = STRIPE`. Configure:

- `DAVVAG_CREDIT_STRIPE_SECRET_KEY` with a restricted or secret Stripe test/live key; or set `DAVVAG_CREDIT_STRIPE_ACCOUNT_ID` to an existing `davvag_stripe.id` containing `stripeSecretKey`.
- `DAVVAG_CREDIT_CHECKOUT_BASE_URL` to the public HTTPS application base. Local development may use `http://localhost[:port][/path]`.
- `DAVVAG_CREDIT_PAYMENT_WEBHOOK_SECRET` (at least 32 characters) only when the legacy signed callback bridge is enabled.
- Optional `DAVVAG_LMP_CREDIT_PROGRAM`; otherwise the Credit Points configured default program is used.

Checkout sends the stored order amount/currency and tenant, learner, and order identity to Stripe Checkout. Settlement is accepted only after retrieving the stored session from Stripe and matching every value with `status=complete` and `payment_status=paid`. Browser return parameters never credit a wallet. Run the administrator-only `ReconcileCheckouts` operation from the existing scheduler to recover paid sessions when the learner does not return.

The inspected local tenant currently has an active `CREDIT` program, but its credit packages use `EXTERNAL` and `davvag_stripe` has no initialized account. Change/create a credit package with `STRIPE` and configure credentials before live checkout can start.

## CMS placement

In the package editor, load or name a CMS page and choose **Add package component**. A new page is saved as a draft and must be published in CMS settings. The supported embed is:

```html
<div webdock-component="package-card" webdock-app="lesson-market-place" webdock-data="{&quot;slug&quot;:&quot;package-code&quot;}"></div>
```

The downloader parses and clones `webdock-data` for each mount. Multiple cards therefore have separate Vue state, locks, and package input. Anonymous users receive only published package metadata; sign-in is required for enrolment and wallet state.

## Operational limits

- Access is permanent in this release. There are no subscriptions, partial prices, revenue sharing, automatic refunds, or revocation of already active grants.
- External video/document providers control their own URLs. DAVVAG can enforce its uploader service but cannot make a public third-party URL private.
- Internal media checks fail closed. A material must be referenced by a published entitled lesson; staged uploads are tied to the uploader's authenticated session until saved.
- Package drafts support at most 200 unique lessons. Lesson and media scans have bounded server limits and should be revisited for unusually large tenants.

## Validation

The isolated integration runner generates a random `lmp_test_<hex>` database, proves it did not exist, and drops only that exact database. It covers all state combinations, replay/concurrency, cohort synchronization, rollback injection, source-aware API access, immutable versions, protected resources, provider verification/expiry/replay, CMS placement, and repeatable migrations.

```powershell
python .tmp/lmp-build/prepare_tests.py
C:\xampp\php\php.exe -d xdebug.mode=off .tmp/lmp-build/test-tenant/apps/lesson-market-place/tests/business_rules_test.php
C:\xampp\php\php.exe -d xdebug.mode=off .tmp/lmp-build/test-tenant/apps/lesson-market-place/tests/database_integration_test.php
$env:CMS_EMBED_APP_ROOT='C:/xampp/htdocs/davvag-core/.tmp/lmp-build/test-tenant/apps'
node --test tests/cms-html-embeds.test.cjs
```

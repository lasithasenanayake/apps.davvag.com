# DAVVAG Events

Local PHP/Webdock/Vue event management and private invitations. Release 1.1.1 implements the main host and guest flows; live provider verification and visual release QA remain outstanding. See [verification](docs/verification.md) for observed results and limits.

## This checkout

- Framework: `C:\xampp\htdocs\davvag-core`.
- Effective configuration: root `config.json`, no `DAVVAGCONFIG` or `LOCAL_DEV_HOST` override was present during installation.
- Tenant: `localhost`; `TENANT_RESOURCE_LOCATION` is `davvag-core/localhost`. This directory is a symbolic link to `C:\xampp\htdocs\apps.davvag.com`.
- Runtime app: `{TENANT_RESOURCE_LOCATION}/apps/davvag-events`.
- Versionable source: `packages/davvag-events/app`; schemas/migrations: `packages/davvag-events/schemas`. Git does not traverse the tenant symlink. The package is the earlier 1.0.0 snapshot. Current 1.0.1 fixes are maintained directly in the tenant apps directory per project policy; do not reinstall that older snapshot over this application.
- Default workspace: <http://localhost/davvag-core/#/app/davvag-events/dashboard>.
- Admin dock: <http://localhost/davvag-core/admin#/app/davvag-events/dashboard>.
- Public route: `http://localhost/davvag-core/#/app/davvag-events/invitation?eventId=EVENT_ID`. Private links also carry an opaque credential in the fragment. The app exchanges it by POST and removes it from the address bar.

Registered in `tenant.json`, `web_user.json`, and `sysadmin.json`. Startup remains `davvag-cms-v7` / `dock`. Public methods have explicit `usergroup_permission` rows for `anonymous`; the app is deliberately absent from the anonymous launcher registry. Service authorization remains mandatory regardless of framework permissions.

## Install and operate

Run from the framework root, with the existing MySQL/Apache services available:

```powershell
& C:\xampp\php\php.exe packages/davvag-events/install.php
& C:\xampp\php\php.exe davvag-core/localhost/apps/davvag-events/bin/worker.php 20
```

The installer copies only the app and its schema files, merges registrations, applies checksum-verified migrations, adds missing method permissions, and installs the invitation email template without replacing an existing one. It does not install a second server or identity system. Use `DAVVAG_EVENTS_FRAMEWORK_ROOT` when running CLI scripts from another directory; set `DAVVAG_EVENTS_HOST` when the expected request host is not `localhost`. `DAVVAGCONFIG` retains the framework's normal meaning.

PHP 8.2 was verified. Required extensions: `mysqli`, `mysqlnd`, `curl`, `openssl`, `fileinfo`, `mbstring`, `json`, `session`. The current adapter is `phpmysql`; another adapter must implement the same atomic procedures before this app can use it. The installation DB user needs table/index/routine privileges. Runtime needs execution of these procedures and normal SOSS reads. Do not enable generic writes on the protected schemas.

Windows Task Scheduler: run `C:\xampp\php\php.exe` with arguments `C:\xampp\htdocs\davvag-core\davvag-core\localhost\apps\davvag-events\bin\worker.php 20`, starting in the framework root, every minute, using the same provider environment as Apache. On Linux, use a cron entry such as `* * * * * cd /srv/davvag && /usr/bin/php /srv/tenant/apps/davvag-events/bin/worker.php 20`. Multiple workers use leased atomic claims. Run the worker often enough for your upload volume; one Drive job transfers one bounded 8 MiB chunk per claim.

## Roles and privacy

Users need an accessible existing DAVVAG `profile.linkeduserid` associated with their authenticated account. Multiple linked profiles are supported: the oldest primary profile is selected consistently, with a fallback to the oldest accessible linked profile for legacy records. No account or profile records are changed during resolution. Owners control their events. Tenant `sysadmin` can access events and writes are audited; privileged Event reads are also audited. Managers receive event-specific grants. Check-in staff receive operational attendee data; moderators receive greeting queues. Managers cannot use owner credential, payment, membership, or deletion operations.

Guests use RSVP capabilities scoped to one invitation party. Individual attendees are separate records, including plus-ones. Admission capabilities are separate from RSVP. The database stores only hashes; invitation resend and ticket redisplay deliberately rotate credentials. Private links should be shared only with the intended party. Public endpoints return projections, never the aggregate or a guest directory.

Authenticated and guest browser mutations use session CSRF tokens and same-origin checks. OAuth callback state replaces browser CSRF for that callback; signed provider webhooks use raw-body signature verification. Configure HTTPS and secure PHP session cookies (`HttpOnly`, `Secure`, `SameSite=Lax`) in production; this app does not weaken CORS or global auth to enable tests.

## Google Drive setup

Configure server environment only, using your own values:

```text
DAVVAG_EVENTS_GOOGLE_CLIENT_ID=YOUR_WEB_CLIENT_ID
DAVVAG_EVENTS_GOOGLE_CLIENT_SECRET=YOUR_WEB_CLIENT_SECRET
DAVVAG_EVENTS_GOOGLE_REDIRECT_URI=https://YOUR_HOST/YOUR_BASE/components/davvag-events/oauth/service/Callback
DAVVAG_EVENTS_KEY_FILE=ABSOLUTE_PRIVATE_KEY_FILE
DAVVAG_EVENTS_STAGING_DIR=ABSOLUTE_PRIVATE_STAGING_DIRECTORY
```

The key file must contain a base64-encoded random 32 byte key. Keep it outside the web root, framework media directories, repository, and database; back it up separately. Staging must be writable and outside both the web root and `MEDIA_FOLDER`. Set filesystem ACLs for only the PHP/worker service accounts. Apache and the scheduled worker must use the same key and staging directory. Restart Apache after changing its environment.

Enable the Drive API and configure a confidential web application, consent screen, test users/publishing, and the exact redirect URI. The app requests `drive.file` with offline access; it creates its own `Events/{Event_Name}_{Date}/Guest_Uploads` resources and retains folder IDs. Arbitrary Drive browsing is intentionally unavailable. See [Google OAuth](https://developers.google.com/identity/protocols/oauth2/web-server), [Drive scopes](https://developers.google.com/workspace/drive/api/guides/api-specific-auth), and [resumable uploads](https://developers.google.com/workspace/drive/api/guides/manage-uploads).

Connect as the event owner under Media, enable media collection in invitation settings, save, and publish. Guests upload through their personal invitation without a Google login. The host must remain connected. The app detects JPEG, PNG, WebP, MP4, and MOV server-side; unsupported HEIC/HEVC must be converted before upload. Configure PHP `upload_max_filesize`, `post_max_size` (including multipart overhead), web-server limits, and disk space consistently with the per-event limit. `DAVVAG_EVENTS_SCAN_HOOK` may point to a protected PHP file returning a callable `(path, mime): bool`; scan failures reject ingress. The entire file is staged on disk and hashed; Drive transfer streams bounded chunks rather than buffering whole videos.

Files remain private in the host's Drive. The app never grants public folder permissions or exposes tokens, upload sessions, or other guests' uploads. Progress/cancel/retry during browser ingress is implemented; browser-to-server uploads restart a failed file, while server-to-Drive transfer resumes by range. The queue stores a reserved Drive file ID and reconciles it after ambiguous success.

## Ticketing and payments

Free tickets use the same persisted order and server issuance path. Paid actions remain disabled until these settings exist:

```text
DAVVAG_EVENTS_STRIPE_KEY=YOUR_STRIPE_SECRET_KEY
DAVVAG_EVENTS_STRIPE_WEBHOOK_SECRET=YOUR_ENDPOINT_SIGNING_SECRET
DAVVAG_EVENTS_STRIPE_ACCOUNT=YOUR_EXPECTED_MERCHANT_ACCOUNT_ID
DAVVAG_EVENTS_STRIPE_ELIGIBILITY_CONFIRMED=yes
```

Before setting the eligibility flag, verify the actual merchant's country and each enabled currency against [Stripe availability](https://stripe.com/global) and your merchant account. No country/currency is inferred from the developer's locale. Configure ticket prices as integer minor units. The app uses Stripe hosted Checkout; card details never enter DAVVAG. Razorpay is not implemented in this release.

Webhook URL: `https://YOUR_HOST/YOUR_BASE/components/davvag-events/webhook/service/Stripe`. Subscribe to `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, and `charge.refunded`. Preserve the raw body. Accepted event IDs and fulfillment jobs persist atomically before acknowledgement. The worker retrieves Checkout, matches tenant/event/order, amount/currency, merchant, and paid status before issuance. Browser redirects never mark an order paid. See [Stripe fulfillment](https://docs.stripe.com/checkout/fulfillment.md?ui=full-page) and [webhooks](https://docs.stripe.com/webhooks).

Holds last 35 minutes. Expired allocations release stock; late payment is fulfilled only when capacity is still available, otherwise a refund is queued. Host refunds revoke admissions immediately and leave an explicit `refund_pending` state until the worker verifies provider success. Admission display rotates previous codes; use the latest display. Opening an admission URL does not check in. Staff can scan on supported HTTPS browsers or use manual codes and reasoned overrides; no offline admission guarantee is made.

## Email and sharing

Uses existing `plugins/notify`/PHPMailer and protected tenant `global/config/emailsmtp.conf`. The installer adds `global/templetes/email/davvag-events-invitation.tmp` only when missing. Invitation email requires recorded party consent. Queue invitations/reminders through `QueueInvitation` with a stable operation key and optional UTC `runAt`. The UI sends immediately to the durable queue; scheduled reminder times are available through the API.

SMTP has no universal delivery idempotency key. A worker crash during email delivery marks the job `uncertain`; it does not automatically resend a possibly delivered message. Review and explicitly rotate/resend from Guests. No real emails were sent during verification. WhatsApp uses deliberate sharing links only; no automated WhatsApp provider is configured.

## Limits, retention, and deletion

- Up to 1,000 attendees, 500 invitation parties, 100 tables/rows, and 500 greetings per event. The event aggregate is bounded to 900,000 encoded bytes to remain within the SOSS raw-parameter firewall. Long-lived/high-volume deployments need a reviewed normalized storage migration before exceeding this limit.
- Server staging expires after 24 hours, or is removed on the next cleanup worker pass following upload success/cancellation. Expired files become cancelled and must be uploaded again. A stopped worker postpones cleanup; monitor disk usage and the queue.
- Failed provider jobs back off with a maximum of six attempts and expose sanitized reasons. Restarting a worker resumes leases and reconciles Drive IDs. Long transfers never run from page polling.
- Cancellation/archival revokes guest actions and admissions immediately. Hosts retain authorized export/audit access.
- Deletion requires an archived event and exact title confirmation; private records are removed and a minimal audit tombstone remains. Host Drive files are retained by default; remove unwanted app-created files in the host's Drive. The application does not delete unrelated Drive content.

## Verification and release

```powershell
& C:\xampp\php\php.exe -d xdebug.mode=off packages/davvag-events/app/tests/run.php
& C:\xampp\php\php.exe -d xdebug.mode=off packages/davvag-events/app/tests/http.php
python packages/davvag-events/app/tests/validate.py
node packages/davvag-events/app/tests/vue-template.cjs
```

`tests/seed.php` creates representative five-occasion fixtures (duplicate names, plus-one, declined guest, full table, pending greeting, failed upload, issued/used/refunded admissions) in a dedicated test database. It prints a scoped cleanup command and never returns bearer links. It does not seed the normal tenant database.

`run.php` creates and removes its own narrowly named test database; it needs local test DB creation rights. `http.php` uses scoped fictional event/profile/session fixtures against the local runtime and removes them in `finally`. Provider doubles are callable only in isolated CLI tests; production cannot activate them. Tests never send email, connect an actual Drive, or charge/refund real money.

Before production: test at 360 px, tablet, and desktop widths in both docks; complete keyboard/accessibility checks; connect a test host Drive; run Stripe test-mode delayed/refund/out-of-order webhook journeys; verify scheduled-worker environment and restart behavior; verify upload ACLs and retention on the deployment host. Browser QA was blocked by the unavailable browser access host in this session.

Rollback: disable the app in the tenant/group registries, stop its worker, and remove its `usergroup_permission` entries scoped to appCode `davvag-events`. Keep backups of the event tables, encrypted connection records, private key, and staging until rollback is confirmed. Do not drop event tables, the encryption key, or host Drive files as part of a code rollback. The earlier component resource guard was reverted at the project owner's request; ongoing fixes are confined to the tenant app directory.

See [architecture and schema](docs/architecture.md), [API](docs/api.md), and [verification/progress](docs/verification.md).

The 1.1.0 editor collects event details with date/time pickers before template and colour selection. Administrators can create reusable animated HTML/CSS cards from the design step. See [invitation editor and template guide](docs/invitation-templates.md).

Service handlers are organized in their respective `services/*/service.php` files. `lib/bootstrap.php` contains only shared-helper loading; see [architecture](docs/architecture.md#service-file-organization-111).

Version 1.2.0 adds seating popups, numbered tables in four shapes, numbered rows, room sections and guest grouping. See the [seating guide](docs/seating.md). Refresh the app with Ctrl+F5 after updating.

Version 1.2.1 fixes the seating viewport with full-width content fitting, a taller map, Fit plan and Expand controls. Use Ctrl+F5 to refresh cached components.

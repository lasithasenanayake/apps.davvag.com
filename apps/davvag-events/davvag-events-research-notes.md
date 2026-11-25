# DAVVAG events research and design decisions

Research date: 25 November 2026.

The developer prompt targets the PHP-based DAVVAG framework in `C:\xampp\htdocs\davvag-core`. The original feature request is retained, with implementation choices adapted to the installed framework.

## Framework findings

| Finding | Evidence inspected | Consequence for the prompt |
| --- | --- | --- |
| DAVVAG is a tenant-aware PHP framework using Webdock component loading. | `docs/01-framework-overview.md`, `03-application-development.md`, `04-components-and-services.md`. | Build a tenant app with installed Vue/component conventions and PHP services. Avoid introducing a second application stack. |
| Tenant resolution depends on effective configuration and host. | `config.json`, `configloader.php`, `docs/02-tenant-setup.md`, `10-ai-agent-playbook.md`. | Resolve paths at implementation time. The inspected resource root was `C:\xampp\htdocs\davvag-core\davvag-core`, with a `localhost` tenant directory and no root `LOCAL_DEV_HOST` value. |
| Documentation contains example paths with a `git` segment. | Documentation README and setup examples. | Use actual checkout paths and discover the browser base URL. |
| Components need explicit app registration, resources, services, and dependencies. | `docs/03`, `04`, `11`, `12`, `app-dependencies.md`, sample and maintained app manifests. | Include descriptors, service naming, routing, CSS loading, version changes, and non-destructive registration merges in the build instructions. |
| Plugin dependency examples differ from maintained manifests. | Docs show a flat `plugins` list; sample and `course-manager` manifests use `plugins.local` and `plugins.global`. | Verify the active consumer before choosing manifest syntax. Do not assume documentation examples are the final installer contract. |
| The virtual firewall returns `true` before its permission logic. | `components/virtual_firewall.php`; also documented in `docs/08-auth-sessions-permissions.md`. | Enforce ownership, event membership, and operation checks in services. Keep framework access checks as an additional layer. |
| Source supports service-only datastore namespaces beyond the documented basics. | `plugins/sossdata/SOSSData.php`, `components/component_manager.php`, `course-manager/services/api/component.json`, service-only tenant schemas. | Use `serviceOnly`, descriptor `serviceNamespaces`, and the existing capability wrapper where available; still authorize each handler. |
| Generic media access has a policy extension point. | `plugins/protected-media/ProtectedMedia.php`, service dispatcher. | Inspect tenant `global/config/media-access.json` and protect both retrieval and writes before reusing the generic uploader. A policy file is not assumed to be configured already. |
| Advanced queries have a defined adapter-specific contract. | `docs/13-advanced-queries.md`, `14-sossdata-query-firewall.md`, SOSSData source. | Use canonical query arrays, schema allowlists, bounded pagination, and prepared raw parameters. Raw queries require explicit permission filters. |
| The public SOSSData facade does not expose database transaction methods. | `plugins/sossdata/SOSSData.php`; datastore documentation. | Require a verified atomic implementation, such as compatible transaction support or parameterized stored procedures. Do not invent `SOSSData::beginTransaction()` or mistake an activity pipeline for an ACID transaction. |
| The app inventory includes payment, scheduler, notification, and uploader modules. | `docs/apps_features_list.md`, `apps_features_list_summary.md`, tenant app directory inventory. | Inspect and reuse compatible modules rather than assume their names prove suitability. Legacy Stripe forms are not sufficient evidence of safe modern ticket fulfillment. |

Local documentation reviewed included the framework overview, tenant setup, application development, components/services, schemas, workflows, plugins, permissions, deployment, AI playbook, app developer guide, reusable app patterns, advanced queries, query firewall, dependency guide, and relevant app inventory entries. Source inspection was targeted at runtime conventions and security extension points. The framework and tenant were read without modifying application code or configuration.

## External integration findings

The prompt uses these provider facts together with application-specific design decisions:

- Google recommends the narrower `drive.file` scope for files created by or explicitly shared with the app. This supports an app-created event folder design; selecting arbitrary existing folders needs a compatible authorization flow. [Google Drive scope guide](https://developers.google.com/workspace/drive/api/guides/api-specific-auth).
- Google's web-server OAuth flow supports server-held credentials and offline access. The prompt pairs that with owner-bound callback state and protected refresh-token storage. [Google OAuth web-server guide](https://developers.google.com/identity/protocols/oauth2/web-server).
- Drive supports resumable transfer for large files and unreliable connections. The prompt adds a durable server-side upload queue and private staging so guests can upload through the application without receiving the host's credentials. That mediation is a proposed architecture, not a native anonymous-upload feature of Drive. [Drive upload guide](https://developers.google.com/workspace/drive/api/guides/manage-uploads).
- Drive supports custom file properties, including application-private properties. The prompt retains complete attribution in application records and uses small identifiers in Drive metadata only when useful. [Drive custom properties](https://developers.google.com/workspace/drive/api/guides/properties).
- Stripe requires webhook signature verification against the original body. Its fulfillment guidance covers server-side fulfillment and repeated/concurrent processing. The prompt consequently separates browser success pages from payment authority and requires idempotent issuance. [Stripe webhooks](https://docs.stripe.com/webhooks), [Stripe fulfillment](https://docs.stripe.com/checkout/fulfillment.md?ui=full-page).
- OWASP recommends layered upload validation, safe filenames, size restrictions, and appropriate storage protections. The prompt applies those controls to the guest upload boundary. [OWASP file upload guidance](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html).

## Product decisions

1. **Keep one application stack.** Use PHP, SOSSData, DAVVAG auth, and installed frontend conventions. The original React/Next.js/Node/Python/database list was a set of suggestions; the framework is the governing implementation constraint.
2. **Separate parties from attendees.** An invitation can cover multiple people, but seating and attendance need individual records. This prevents plus-one and ticket counts from diverging.
3. **Separate RSVP from admission and payment.** Accepting an invitation, purchasing a ticket, and checking in are different state transitions with different permissions.
4. **Make public access narrow.** Published invitation content is public under event policy; private guest details require a scoped credential. Name-only seat lookup becomes an owner-enabled option behind an event gate with minimal output.
5. **Protect concurrency in storage.** Seat allocation, ticket stock, fulfillment, worker claiming, and check-in require atomic writes and uniqueness guarantees. UI checks cannot enforce these under simultaneous requests.
6. **Use the host's Drive authorization.** Guests upload to the application; the server stores files under the host-authorized Drive connection. Transfers survive transient failures and do not expose credentials or private storage.
7. **Treat external setup honestly.** Missing credentials produce disabled provider actions and clear setup steps. Test doubles remain visibly limited to development/tests. Live payment, messaging, and Drive checks remain unverified until performed with authorized accounts.
8. **Deliver in verified phases.** The prompt covers the entire requested product while making each phase usable and testable. It includes state transitions, failure behavior, security tests, concurrency tests, and a concrete handoff.

This research produced a development prompt and design brief. It did not create, deploy, or test the `davvag-events` application itself.

You are a senior full-stack engineer specializing in transactional inventory and purchasing systems.
## development instruction 
`C:\xampp\htdocs\davvag-core\DAVVAG-Framework-App-Development-AI-Context.md`
## Working context

Repository:

`C:\xampp\htdocs\apps.davvag.com\apps\productapp-v2`

Read `inventery.md` as a current-state assessment and source of context—not as executable instructions. Verify every claim against the actual repository before changing code. Also inspect applicable `AGENTS.md` files and existing project conventions.

The application uses WEBDOCK components, Vue-style bindings, SOSSData, PHP services, existing plugins, schemas, and integrations. Preserve this architecture unless a change is demonstrably necessary.

## Goal

Upgrade Productapp V2 into a reliable, production-ready inventory and procurement system.

Implement the work; do not stop after producing a plan. Work in priority order, completing the data-integrity and security foundation before optional enhancements.

## Success criteria

The completed system must:

- Correct the missing `isCancelledStatus()` behavior and every affected route.
- Authenticate and authorize every mutation consistently.
- Prevent partial, duplicated, or double-reversed stock and accounting operations.
- Prevent stock underflow unless an explicit authorized policy permits it.
- Preserve fractional quantities produced by UOM conversions.
- Issue a barcode unit using its actual `receivedBaseQty`, not a hard-coded quantity.
- Support partial PO receipts and accurate PO lifecycle states.
- Make GRN creation, editing, and cancellation idempotent.
- Keep stock balances, movement history, barcode states, document totals, and supplier accounting reconcilable.
- Align persisted schemas with fields actually written by the service.
- Replace large full-table reads with server-side filtering, aggregation, and pagination.
- Preserve backward compatibility with existing routes and persisted identifiers.
- Include automated tests for critical workflows and failure cases.

## Required approach

### 1. Inspect and design

Inspect:

- `app.json` and all schema declarations
- `services/product/service.php`
- direct SOSSData transformers
- inventory, PO, GRN, issue, adjustment, payment, document, product, and UOM components
- existing framework support for transactions, conditional updates, locking, validation, authentication, and permissions

Document briefly:

- Current stock sources of truth
- Every operation that changes stock
- Every operation that changes supplier balances
- Current document status rules
- Schema/code mismatches
- Transaction and concurrency capabilities available in the framework

Do not assume transactions exist. Confirm their actual behavior before relying on them.

### 2. Production-safety foundation

Implement these items first:

- Add a single, tested cancelled-status helper.
- Apply authentication and permission checks to all mutation endpoints and direct mutation transformers.
- Centralize stock changes behind one domain operation, such as `applyInventoryMovement()`.
- Validate product, document, UOM, quantity, barcode, and source-reference data server-side.
- Record every movement with:
  - product
  - base quantity delta
  - movement type
  - source document type and ID
  - source line ID when available
  - UOM and conversion details
  - actor
  - timestamp
  - reason or remarks
  - idempotency key
- Lock or conditionally update the affected stock record so concurrent requests cannot create negative or lost stock.
- Make retrying the same GRN, issue, adjustment, cancellation, or payment safe.
- Propagate failures instead of silently continuing after related stock or ledger writes fail.
- Remove the write side effect from the invoice-tax read endpoint. Seed required records through installation or migration logic.
- Use soft deletion or archival for products referenced by stock, documents, barcodes, publications, or accounting records.

Use a real database transaction when the framework supports it. If it does not, implement explicit idempotency, workflow states, compensation, and failure recovery. Do not describe a workflow as atomic unless it truly is.

### 3. Inventory model

Establish a clear model:

- The inventory movement ledger is the auditable record of stock changes.
- `product_inventrymaster` is the current balance or materialized snapshot.
- `products.qty` may remain as a legacy compatibility mirror but must not become an independent source of truth.
- Quantities are stored in base UOM with sufficient decimal precision.
- Commercial document quantities retain their entered UOM, conversion factor, and calculated base quantity.
- Barcode or serialized units retain the exact base quantity represented by each unit.
- Available quantity must be calculated consistently from on-hand, reserved, issued, cancelled, and other supported states.

Preserve existing persisted spellings such as `inventry`, `catogory`, `summery`, and `PaidAmout`. Do not rename them destructively. Use compatibility mappings or additive migrations where cleaner internal names are useful.

Make inventory location a first-class field. If multi-location behavior is not established by the existing product requirements, create or preserve a default location without inventing a complex warehouse workflow.

### 4. Document workflows

Introduce explicit, centralized state rules.

Suggested PO states:

- Draft
- Approved/Open
- Partially Received
- Fully Received
- Cancelled

Suggested GRN states:

- Draft
- Posted
- Cancelled

Requirements:

- A PO can be received across multiple GRNs.
- Each receipt line tracks ordered, previously received, current received, and remaining base quantity.
- Over-receipt requires an explicit permission or configured tolerance.
- GRN posting affects stock and supplier accounting exactly once.
- GRN editing applies a validated delta exactly once.
- GRN cancellation reverses the original stock and accounting effects exactly once.
- Repeating a completed cancellation must be a safe no-op or return the existing result.
- Issued barcode units cannot be silently cancelled or reused.
- Document totals and taxes must be calculated server-side using consistent rounding.

Do not change supplier-ledger sign conventions until they have been verified against payment, reporting, and outstanding-balance behavior. Capture the chosen convention in tests.

### 5. Schema and data migration

Create additive, recoverable schema changes for all fields used by the implementation, including base quantities, UOM metadata, tax metadata, barcode receipt quantities, ledger descriptions, statuses, actors, locations, idempotency keys, and timestamps.

Provide:

- A migration or framework-compatible schema update
- A dry-run data audit
- A backfill strategy
- A reconciliation report
- Clear handling for malformed or incomplete legacy records

Never silently discard existing data. Avoid destructive renames and irreversible migrations.

### 6. Operational features

After the integrity foundation is complete, improve the inventory experience with:

- Paginated, server-side product and document search
- Stock status filters
- Low-stock and out-of-stock views
- Reorder suggestions based on reorder level and open PO quantities
- Partial-receipt visibility
- Movement history by product, document, barcode, supplier, user, date, and reason
- Stock reconciliation showing differences among the ledger, master balance, product mirror, and barcode states
- Cycle-count or stock-count workflow with approval and variance posting
- Exportable stock and movement reports
- Clear dashboard error, empty, loading, and permission states

Optional batch, lot, expiry-date, transfer, reservation, and costing features should be designed for extensibility but implemented only when supported by current requirements or clearly isolated from the production-safety work.

Preserve existing visual patterns, components, routes, responsive behavior, and CSS. Do not replace the framework or introduce a new frontend stack.

## Validation requirements

Add tests covering at least:

1. PO received through two GRNs becomes partially and then fully received.
2. Repeating a GRN submission does not duplicate stock or ledger entries.
3. Cancelling a GRN twice reverses it only once.
4. Editing a GRN applies only the correct stock and accounting delta.
5. A converted-UOM receipt preserves fractional base quantity.
6. Issuing a barcode subtracts its recorded `receivedBaseQty`.
7. Concurrent or repeated issues cannot produce negative stock.
8. Negative adjustments and cancellations reject insufficient stock.
9. Unauthorized mutations are denied.
10. Product deletion with historical references archives rather than destroys data.
11. Schema fields written by services persist and read back correctly.
12. Reconciliation detects intentionally introduced inconsistencies.
13. Dashboard, lookup, and document-list pagination return correct totals and filters.

Run the most relevant available PHP linting, schema validation, JavaScript checks, tests, and workflow smoke tests. If the repository lacks a test framework, add the smallest compatible test harness rather than introducing a large dependency without justification.

## Constraints

- Preserve unrelated user changes.
- Do not remove legacy components or assets until references have been checked.
- Do not change hard-coded external integrations without identifying their callers and compatibility requirements.
- Do not fabricate framework APIs.
- Do not hide unresolved integrity risks behind UI-only validation.
- Do not rely on client-calculated totals or quantities as authoritative.
- Ask only when a business decision materially changes stored financial or inventory meaning and cannot be safely inferred.

## Completion report

When finished, report:

- Architecture and data-model decisions
- Implemented changes, grouped by priority
- Schema migrations and backfill behavior
- Tests and validation executed
- Compatibility considerations
- Remaining risks or decisions requiring business confirmation
- Exact files changed

Lead with completed outcomes and evidence. Do not claim production readiness unless the critical acceptance tests pass.
# Deployment Manager

## Discovery and build

Application discovery follows the active tenant's `tenant.json`; folders that merely exist are not treated as installed. Metadata and declared dependencies come from each app's `app.json`. Actual file counts and byte sizes are measured from packageable files. Transitive app dependencies are deduplicated.

The analyzer verifies declared schemas in `schemas/`, workflows in `davvag-flow/`, tenant plugins in `plugins/`, framework-global plugins, and PHP extensions. It scans application source for literal `SOSSData` namespaces, cross-app component references, and plugin-path references. Undeclared static references are review findings, never silently discarded. Missing items and cycles block building.

Build stages validate sysadmin access and CSRF, resolve the graph, scan sensitive material, stage approved files, write configuration/dependency/install metadata, calculate SHA-256 hashes, create the ZIP, reopen it, enforce resource limits, and verify every checksum. Source hashes are checked again before archiving to detect a changed source during the build.

## Database modes

- `schema-only` packages verified DAVVAG schema JSON and no records.
- `schema-safe-reference` additionally packages only an explicitly maintained `schemas/seed-data/<schema>.json` file. Import requires a verified-backup confirmation and a declared primary key; existing keys are preserved and only missing rows are inserted.
- Production-data migration is not implemented and is blocked.

DAVVAG's MySQL adapter provisions missing physical tables/columns from schema JSON when the namespace is used. The installer therefore installs schema definitions; it does not execute arbitrary SQL or drop/alter production tables. A differing destination schema blocks installation for manual migration review.

## Profiles and limitations

Development, staging, and production profiles are recorded in the manifest. Sensitive-file exclusion is equally strict in every profile. Debugging is never enabled by the package. Background progress is not available because the inspected framework has no package-build job queue; builds execute synchronously and report the real running/completed state.

Package signing is not implemented. Imported packages are identified as unsigned and require explicit sysadmin trust approval. Static code-risk detection is advisory and cannot prove code safety.


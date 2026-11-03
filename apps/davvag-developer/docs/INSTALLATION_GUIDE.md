# Installation Guide

## Destination workflow

1. Back up the tenant application, schema, workflow, and plugin folders plus its database.
2. Sign in as `sysadmin` and open **Install Package**.
3. Upload the ZIP. Verification checks paths, links, file/resource limits, format, hashes, PHP/extensions, existing versions, and risky PHP constructs.
4. Review actions and blocking conflicts. No destination change occurs during planning. Confirm a recoverable database backup when approved reference data is present.
5. Confirm that the source is trusted. Checksums are integrity controls, not publisher authentication.
6. Choose whether to activate registrations, then install.

Applications are copied to a same-volume staging folder. Existing versions move to protected recovery storage before the staged directory is renamed into place. A newer installed version blocks installation. Existing plugin conflicts and differing schemas block silent overwrite. New schemas are copied; physical datastore changes remain DAVVAG's normal schema-driven responsibility. Tenant and `sysadmin` registrations are updated atomically after payload installation.

## Configuration and secrets

Apps may declare portable requirements at `deployment/config-requirements.json` using objects with a `key` and descriptive metadata. Values and secrets are stripped from bundles. Configure database, SMTP, API, signing, payment, and machine-path values directly on the destination through its secure environment process.

## Failure and recovery

Audit events contain no tokens or secrets. Installation records list completed checkpoints and recovery locations. If installation stops after an existing application was moved but before replacement activation, the installer attempts to restore the recovery directory. Database rollback is not claimed because schema-backed physical creation may be irreversible; restore the verified pre-install database backup when necessary.

Do not manually execute scripts from an untrusted archive. The installer never runs package-supplied installer scripts. For a schema conflict, reconcile the JSON and physical database through a reviewed migration outside the automatic installer, then retry.


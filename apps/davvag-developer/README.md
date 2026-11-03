# DAVVAG Developer — Deployment Manager

This application adds three sysadmin-only tools to DAVVAG Developer: **Deployment Manager**, **Install Package**, and **Package History**.

It discovers apps from `tenant.json`, follows declared dependencies in `app.json`, supplements them with conservative source analysis, packages tenant schemas/plugins/workflows, validates SHA-256 checksums, and installs only after a separate plan and explicit trust approval. Framework-global plugins are requirements, not copied core code.

## Use

1. Open DAVVAG Developer as a `sysadmin`.
2. Select applications and run dependency analysis.
3. Resolve every missing dependency or cycle.
4. Choose a package name, semantic version, profile, and database mode.
5. Build and download the validated ZIP.
6. On the destination, upload it under **Install Package**, review verification and conflicts, approve its provenance, and install.

The local PHP runtime may use `PharData` when `ZipArchive` is unavailable. The active driver is displayed in the sidebar. Artifacts, uploads, audit events, recovery copies, and history are stored below the denied `storage/` directory.

## Test

```powershell
C:\xampp\php\php.exe tests\run.php
```

See `docs/` for the format, dependency rules, installation process, security limitations, backup expectations, and recovery behavior.


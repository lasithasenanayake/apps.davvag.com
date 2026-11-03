# DAVVAG Package Format 1.0

The ZIP root contains:

```text
manifest.json
checksums.json
README.md
applications/<app-id>/
plugins/<tenant-plugin>/
workflows/<declared-path>.json
database/schemas/<namespace>.json
database/seed-data/<namespace>.json
config/requirements.json
dependencies/dependency-graph.json
install/installation-metadata.json
```

`manifest.json` fields are: `package_format` (`davvag-bundle`), `format_version`, package name/version/build/time, deployment profile, application metadata, plugin classifications, workflow metadata, database mode/schema/migration lists, dependency edges, framework/PHP/extension requirements, portable configuration requirements, and provenance state.

`checksums.json` maps every other file's safe relative path to a SHA-256 digest. Checksums detect changes but do not authenticate a publisher. Version 1.0 import rejects unknown format versions. Paths must be relative, unique, non-traversing, and non-symlink. Limits are 256 MiB compressed, 512 MiB expanded, 20,000 files, 64 MiB per file, and a 200:1 per-entry compression ratio.

Framework-global plugins are listed as `shared-framework-component`; they are not duplicated. Tenant-local plugins are listed and included as `bundled-installable-plugin`.


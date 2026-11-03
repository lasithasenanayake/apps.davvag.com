# Dependency Resolution

Resolution starts with selected IDs and traverses `dependencies.apps`. The final graph contains each application once. DFS cycle detection blocks cyclic graphs because DAVVAG does not publish a reliable cyclic activation order.

Declared keys supported from `app.json` are `apps`, `schemas`, `workflows`, `plugins`, `php-extensions`, and `optional`. `plugins` uses explicit `local` and `global` arrays. Legacy flat plugin arrays remain readable for packages and applications created before the scoped format. Schema ownership is never inferred from a name prefix. A schema is included only when declared or found as a literal `SOSSData::Query/Insert/Update/Delete/ExecuteRaw` namespace in selected application source. Static discoveries are shown for review.

Plugin resolution honors the declared scope. A local dependency must exist under the tenant plugin root and is copied into the portable package. A global dependency must exist under the framework plugin root and is recorded as a destination requirement; a same-named tenant plugin cannot satisfy it. The installer verifies every global requirement before changing destination files and blocks installation when one is missing. An unresolved plugin blocks the build. Component descriptors remain part of the app payload and their `PLUGIN_PATH_LOCAL` or `PLUGIN_PATH` references are used with the matching scope during source analysis.

Workflow files are copied only from declared verified paths. Runtime history, logs, queued state, and scheduler state are excluded. A name suggesting cron/schedule is flagged as scheduled metadata. Workflow files are copied without automatically starting external scheduling.

External libraries with no verified portable source remain unresolved requirements. Version compatibility is compared only where an actual version is declared; absent versions are displayed as undeclared rather than fabricated.


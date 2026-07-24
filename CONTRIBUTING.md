# Contributing

Work one approved milestone at a time. Before changing code, identify SRS/RTM IDs, schema/API/UI/permission/workflow impacts and tests. Do not silently change OpenAPI or approved architecture.

Use focused branches and commits. Run the relevant formatter, linter, static analysis, tests, contract checks and builds. Update OpenAPI, generated types, traceability and documentation in the same change. Report exact failures; never weaken a test or security control to obtain a green build.

Business logic belongs in domain/application layers, not controllers or UI. Repositories exist only when they isolate persistence meaningfully. User-facing text uses translation keys. Client-provided permissions, organization, totals, risk, distance and workflow decisions are untrusted.

Pull requests must state scope, requirements, migrations/rollback, API changes, security impact, commands/results and unresolved decisions.

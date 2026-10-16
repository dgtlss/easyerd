# Changelog

## [1.1.0](https://github.com/dgtlss/easyerd/compare/v1.0.0...v1.1.0) - 2026-10-17

EasyERD now produces diagrams you can commit and check in CI, while keeping migration parsing as the default schema source. Migration mode works without a database, and Mermaid/text output needs no Graphviz installation.

### Added

- Laravel 13 support alongside Laravel 11 and 12, with PHP 8.3+.
- Mermaid output with `--type=mermaid`, saved to `docs/erd.md` by default. Use a `.mmd` output path for raw Mermaid.
- Optional live schema inspection with `--source=database` and `--connection`, including columns, primary keys, and foreign keys.
- Model relationships in every output format: `belongsTo`, `hasMany`, `hasOne`, and `belongsToMany`, including typed methods and literal custom keys.
- `--tables`, `--exclude`, and `--depth` filters, with traversal in either relationship direction and exclusions taking precedence.
- A read-only `--check` command that fails when the saved Mermaid diagram is missing or out of date.
- Regression and integration tests, plus a CI matrix for Laravel 11–13 and PHP 8.3–8.5.

### Fixed

- Fully qualified model classes in `foreignIdFor()` now resolve to the correct column and table names. Fixes [#1](https://github.com/dgtlss/easyerd/issues/1).
- Explicit `constrained()` table/column arguments and custom `foreignIdFor()` column names are respected.
- Default columns such as `id()`, `timestamps()`, `softDeletes()`, and `rememberToken()` are included.
- Nested braces, comments, quoted delimiters, and empty argument lists no longer truncate migration parsing.
- Migration parsing reads `up()` declarations in filename order, supports literal `Schema::table()` declarations, and resolves model imports and aliases.
- Foreign-key declarations no longer become extra columns, and explicit constraints replace inferred targets.
- Repeated generation resets collected data; model/migration relationships are deduplicated and Mermaid ordering is deterministic.
- Image generation handles paths safely, escapes table/column labels, and cleans up temporary files on failure.
- Command options use configured quality settings when no override is supplied.

### Upgrade notes

- Existing image/text commands and timestamped filenames remain available. Mermaid has its own stable output path.
- Existing published configuration is merged with the new defaults; republishing is optional.
- Composer now derives the package version from Git tags rather than a hardcoded `composer.json` version.
- Static parsing remains best effort for dynamic PHP, macros, inherited relationships, and complex schema alterations. Use the database source when those determine your schema.
- Laravel 11 has upstream security advisories; its isolated compatibility CI jobs allow those dependencies. This exception does not apply to Laravel 12/13 jobs or consumer applications.

## [1.0.0](https://github.com/dgtlss/easyerd/releases/tag/v1.0.0) - 2025-10-10

- Initial release with migration/model analysis, Graphviz image output, text diagrams, and configurable styling.

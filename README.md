# EasyERD

Generate entity relationship diagrams for Laravel **without a database**. EasyERD reads migrations and model declarations as source code, so you can document an application before its database exists, on a fresh checkout, or in CI.

Supports Laravel 11, 12, and 13 on PHP 8.3+. Mermaid and text output need no external tools. PDF, PNG, SVG, and JPG output use [Graphviz](https://graphviz.org/download/).

Latest release: **v1.1.0**. See the [release notes](CHANGELOG.md) for changes and upgrade notes.

## Installation

```bash
composer require --dev dgtlss/easyerd
```

Laravel discovers the service provider automatically.

## Generate a diagram

```bash
# GitHub-ready Mermaid diagram, saved to docs/erd.md
php artisan easyerd:generate --type=mermaid

# Raw Mermaid for other tools
php artisan easyerd:generate --type=mermaid --output=docs/erd.mmd

# Plain text, with no Graphviz dependency
php artisan easyerd:generate --type=text

# Image and text (the existing default)
php artisan easyerd:generate

# SVG image
php artisan easyerd:generate --type=image --format=svg
```

Markdown output contains a fenced `mermaid` block that GitHub renders as a diagram. Mermaid files have stable names and deterministic table/relationship ordering; text and image files retain their timestamped names.

## Choose the schema source

Migration parsing is the default and never connects to a database or executes migration/model files. It reads PHP migrations recursively in filename order, including literal `Schema::create()` and `Schema::table()` declarations. It recognizes default columns such as `id()`, `timestamps()`, `softDeletes()`, and `rememberToken()`, plus conventional and explicit foreign keys:

```php
$table->foreignIdFor(\App\Models\Organisation::class);
$table->foreignIdFor(User::class, 'author_id')->constrained('users');
$table->foreignId('author_id')->constrained('users', 'uuid');
$table->foreign('team_id')->references('id')->on('teams');
```

Model namespaces and import aliases are resolved before inferring names. Literal model `$table` and `$primaryKey` properties and UUID/ULID key traits are also recognized. Bare `foreignId()` and `foreignIdFor()` calls infer relationships even without a database constraint.

For the actual deployed schema, including changes made outside migrations, use Laravel's [schema inspection APIs](https://laravel.com/docs/13.x/database#inspecting-your-databases):

```bash
php artisan easyerd:generate --type=mermaid --source=database
php artisan easyerd:generate --type=mermaid --source=database --connection=reporting
```

This reads table, column, primary-key, and foreign-key metadata using the selected Laravel connection. It does not run migrations or modify the database. Connection errors fail the command; choose `--source=migrations` when there is no database.

Static parsing is best effort: it cannot evaluate variables, arbitrary PHP expressions, macros, runtime conditions, or all schema alterations. Columns in each visible conditional branch are collected. Use the database source when those features determine your schema.

## Model relationships

`belongsTo`, `hasMany`, `hasOne`, and `belongsToMany` declarations are included in Mermaid, text, and image diagrams. Typed methods, related model aliases, and literal custom foreign/local keys are supported. Relationships already described by a foreign key are drawn once. In migration mode, models without parsed migrations appear as tables with no column details; in database mode, model edges are added only between tables present in the database.

Polymorphic and through relationships, inherited/dynamic relationship definitions, and dynamically computed model table/key names are not interpreted. Set `include_models` to `false` for a schema-only diagram.

## Keep large diagrams readable

```bash
# Only these tables and the relationships between them
php artisan easyerd:generate --type=mermaid --tables=users,posts

# The users table plus tables one relationship away, in either direction
php artisan easyerd:generate --type=mermaid --tables=users --depth=1

# Exclude tables, including edges to them
php artisan easyerd:generate --type=mermaid --exclude=jobs,failed_jobs

# Repeat options when convenient
php artisan easyerd:generate --type=mermaid --tables=users --tables=teams --depth=2
```

`--depth=0` includes only the selected tables. Depth requires `--tables`; without depth, the selection is exact. Exclusions take precedence, including configured exclusions, and excluded tables cannot act as a bridge during traversal. These filters work with every output type and both schema sources.

## Check diagram drift in CI

Generate and commit the diagram once:

```bash
php artisan easyerd:generate --type=mermaid
git add docs/erd.md
```

Then run:

```bash
php artisan easyerd:generate --check
```

`--check` generates Mermaid in memory and exits with status `1` if `docs/erd.md` is missing or differs. It writes nothing. Use the same `--source`, `--connection`, `--tables`, `--exclude`, `--depth`, and `--output` options when generating and checking.

Add the check to an existing Laravel CI job, or use this example:

```yaml
name: ERD drift
on: [push, pull_request]
permissions:
  contents: read
jobs:
  erd:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: mbstring
          coverage: none
      - run: composer install --prefer-dist --no-interaction --no-progress
      - run: php artisan easyerd:generate --check
```

The default migration source needs neither a database service nor Graphviz in CI.

## Options

| Option | Behavior |
| --- | --- |
| `--type` | `image`, `text`, `both` (default), or `mermaid` |
| `--output` | Output directory for images/text; `.md`/`.mmd` file or directory for Mermaid |
| `--format` | `pdf` (default), `png`, `svg`, `jpg`, or `jpeg` |
| `--source` | `migrations` (default) or `database` |
| `--connection` | Laravel connection for the database source |
| `--tables` | Included tables; repeat or comma-separate |
| `--exclude` | Additional excluded tables; repeat or comma-separate |
| `--depth` | Non-negative relationship hops from `--tables` |
| `--check` | Compare Mermaid with the saved file; write nothing |
| `--width`, `--height`, `--dpi` | Raster image dimensions and resolution |
| `--font-size`, `--nodesep`, `--ranksep` | Graphviz text size and spacing |

Options without explicit defaults use configuration values.

## Configuration

```bash
php artisan vendor:publish --provider="Dgtlss\EasyErd\EasyErdServiceProvider" --tag=config
```

See [the full configuration](src/Config/easyerd.php) for Graphviz styling, dark mode, resolution, and layout options. The schema/output options are:

```php
'output_path' => storage_path('app/erd'), // Images and text
'mermaid_path' => base_path('docs/erd.md'),
'source' => 'migrations',
'connection' => null,
'include_migrations' => true,
'include_models' => true,
'tables' => [],
'excluded_tables' => [],
'depth' => null,
```

## Development

```bash
composer install
composer test
```

The test suite covers migration parsing, live SQLite introspection, model relationships, output, filtering, command failures, and drift checks. GitHub Actions runs it against Laravel 11–13 and PHP 8.3–8.5.

Laravel 11 has upstream security advisories. Its isolated compatibility jobs allow those dependencies to test legacy installations; the exception is confined to that CI matrix entry.

# EasyERD

A Laravel package to generate ERD (Entity Relationship Diagram) diagrams from your models and migrations.

## Features

- Generate ERD diagrams from Laravel models and migrations
- Output in multiple formats (PNG, SVG, JPG)
- Text-based ERD representation
- Configurable table styles and relationships
- Exclude specific tables from diagrams
- Customizable output paths

## Installation

```bash
composer require dgtlss/easyerd
```

## Usage

### Generate ERD

```bash
php artisan easyerd:generate
```

### Options

- `--type`: Type of ERD to generate (image, text, both) - Default: both
- `--output`: Custom output path for the ERD files
- `--format`: Image format (png, svg, jpg) - Default: png

### Examples

```bash
# Generate both image and text ERD
php artisan easyerd:generate

# Generate only image ERD in SVG format
php artisan easyerd:generate --type=image --format=svg

# Generate only text ERD with custom output path
php artisan easyerd:generate --type=text --output=/path/to/output

# Generate ERD with custom output and format
php artisan easyerd:generate --output=/custom/path --format=jpg
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --provider="Dgtlss\EasyErd\EasyErdServiceProvider"
```

This will create `config/easyerd.php` with the following options:

```php
return [
    'output_path' => env('EASYERD_OUTPUT_PATH', storage_path('app/erd')),
    'image_format' => env('EASYERD_IMAGE_FORMAT', 'png'),
    'include_models' => env('EASYERD_INCLUDE_MODELS', true),
    'include_migrations' => env('EASYERD_INCLUDE_MIGRATIONS', true),
    'graphviz_path' => env('EASYERD_GRAPHVIZ_PATH', 'dot'),
    'table_styles' => [
        'primary_key' => 'bgcolor="lightyellow"',
        'foreign_key' => 'bgcolor="lightpink"',
        'regular_column' => '',
    ],
    'excluded_tables' => [
        'migrations',
        'password_resets',
        'personal_access_tokens',
        'failed_jobs',
        'job_batches',
        'cache',
        'cache_locks',
        'sessions',
    ],
    'relationship_types' => [
        'belongs_to' => '->',
        'has_many' => '<-',
        'has_one' => '<-',
        'belongs_to_many' => '<->',
    ],
];
```

## Requirements

- PHP 8.3+
- Laravel 11.0 or 12.0
- Graphviz (for image generation)

### Installing Graphviz

**macOS:**
```bash
brew install graphviz
```

**Ubuntu/Debian:**
```bash
sudo apt-get install graphviz
```

**Windows:**
Download from [Graphviz official website](https://graphviz.org/download/)

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).
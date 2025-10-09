# EasyERD

A Laravel package to generate ERD (Entity Relationship Diagram) diagrams from your models and migrations.

## Features

- Generate ERD diagrams from Laravel models and migrations
- Output in multiple formats (PDF, PNG, SVG, JPG)
- Text-based ERD representation
- Configurable table styles and relationships
- Exclude specific tables from diagrams
- Customizable output paths
- Dark mode support
- High-resolution image settings
- Grid-friendly layout options

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
- `--format`: Image format (pdf, png, svg, jpg) - Default: pdf

### Examples

```bash
# Generate both image and text ERD
php artisan easyerd:generate

# Generate only image ERD in SVG format
php artisan easyerd:generate --type=image --format=svg

# Generate only image ERD in PDF format
php artisan easyerd:generate --type=image --format=pdf

# Generate only text ERD with custom output path
php artisan easyerd:generate --type=text --output=/path/to/output

# Generate ERD with custom output and format
php artisan easyerd:generate --output=/custom/path --format=jpg

# Generate ERD in PDF format with high resolution
php artisan easyerd:generate --format=pdf
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --provider="Dgtlss\EasyErd\EasyErdServiceProvider"
```

This will create `config/easyerd.php` with the following options:

```php
return [
    'output_path' => storage_path('app/erd'),
    
    'image_format' => 'pdf',
    
    'include_models' => true,
    
    'include_migrations' => true,
    
    'graphviz_path' => 'dot',
    
    'orthogonal_splines' => false,
    
    'grid_splines' => 'spline', // Grid-friendly curves
    
    'dark_mode' => false,
    
    // New high-resolution settings
    'image_width' => 4000,
    
    'image_height' => 3000,
    
    'dpi' => 600,
    
    'font_size' => 11, // Slightly smaller for grid
    
    'nodesep' => 3.0, // Wider spacing for grid
    
    'ranksep' => 2.8, // Compact vertical for grid,
    
    'table_styles' => [
        'primary_key' => 'bgcolor="lightyellow"',
        'foreign_key' => 'bgcolor="lightpink"',
        'regular_column' => '',
    ],
    
    'dark_table_styles' => [
        'primary_key' => 'bgcolor="#2d3748"',
        'foreign_key' => 'bgcolor="#4a5568"',
        'regular_column' => '',
        'header_bg' => 'bgcolor="#1a202c"',
        'header_text' => 'color="white"',
        'cell_bg' => 'bgcolor="#2d3748"',
        'cell_text' => 'color="white"',
        'border' => 'color="#4a5568"',
    ],
    
    'excluded_tables' => [
        //
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
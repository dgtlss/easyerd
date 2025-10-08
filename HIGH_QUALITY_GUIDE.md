# EasyERD High-Quality Image Guide

## Overview
EasyERD now supports high-quality image generation perfect for large databases and professional documentation.

## Key Improvements

### 1. Vector Formats (Perfect for Zooming)
- **SVG**: Scalable Vector Graphics - zoom infinitely without quality loss
- **PDF**: High-resolution vector format - great for printing and professional documentation

### 2. Enhanced Bitmap Format
- **PNG**: Now defaults to 300 DPI with large dimensions (4000x3000)
- **JPG**: Also supports high-resolution settings

### 3. Configuration Options

#### Command Line Options
```bash
# High-resolution PNG (default settings)
php artisan easyerd:generate --format=png --dpi=300 --width=4000 --height=3000

# SVG - Perfect for zooming
php artisan easyerd:generate --format=svg

# PDF - Great for printing
php artisan easyerd:generate --format=pdf

# Custom settings for complex databases
php artisan easyerd:generate --format=png --dpi=600 --width=8000 --height=6000 --font-size=16 --nodesep=2.0 --ranksep=2.5
```

#### Configuration File (.env or config/easyerd.php)
```php
// Environment variables you can set
EASYERD_IMAGE_FORMAT=svg
EASYERD_IMAGE_WIDTH=5000
EASYERD_IMAGE_HEIGHT=4000  
EASYERD_DPI=300
EASYERD_FONT_SIZE=14
EASYERD_NODESEP=1.5
EASYERD_RANKSEP=2.0
```

### 4. Recommended Settings by Use Case

#### For Zooming and Digital Viewing
```bash
php artisan easyerd:generate --format=svg
```

#### For High-Quality PNG (Zoom without blur)
```bash
php artisan easyerd:generate --format=png --dpi=300 --width=5000 --height=4000
```

#### For Printing and Documentation
```bash
php artisan easyerd:generate --format=pdf
```

#### For Very Large Systems
```bash
php artisan easyerd:generate --format=svg
# or for PNG with maximum quality:
# php artisan easyerd:generate --format=png --dpi=600 --width=10000 --height=8000 --font-size=16
```

## Technical Details

### Image Formats
- **SVG**: Vector format, scales infinitely, smallest file size, fastest generation
- **PDF**: Vector format, professional output, great for reports
- **PNG**: Raster format, defaults to 300 DPI, large dimensions for detail
- **JPG**: Raster format, supports DPI settings

### Quality Settings
- **Width/Height**: Dimensions in pixels for raster formats
- **DPI**: Dots per inch (150-600 recommended)
- **Font Size**: Better readability for complex diagrams
- **Node Separation**: Space between tables (1.0-3.0)
- **Rank Separation**: Space between table ranks (1.5-4.0)

### Default Settings
- **Format**: SVG (vector for best zooming)
- **Width**: 4000px
- **Height**: 3000px
- **DPI**: 300
- **Font Size**: 12pt
- **Node Separation**: 1.2
- **Rank Separation**: 1.8
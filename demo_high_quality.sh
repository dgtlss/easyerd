#!/bin/bash

# Example commands for generating high-quality ERD diagrams

echo "Generating high-quality ERD examples..."

# Generate high-resolution PNG (300 DPI, large dimensions)
php artisan easyerd:generate --type=image --format=png --dpi=300 --width=5000 --height=4000 --font-size=14

# Generate SVG (vector format, perfect for zooming)
php artisan easyerd:generate --type=image --format=svg

# Generate PDF (vector format, great for printing)
php artisan easyerd:generate --type=image --format=pdf

# Generate with better spacing for complex databases
php artisan easyerd:generate --type=image --format=png --nodesep=2.0 --ranksep=2.5

echo "High-quality ERD examples generated!"
echo ""
echo "Key improvements:"
echo "- SVG format: Vector graphics, can zoom infinitely without quality loss"
echo "- PDF format: High-quality vector graphics, great for printing or zooming"
echo "- PNG format: 300 DPI by default, much higher resolution than before"
echo "- Configurable dimensions: up to 5000x4000 pixels"
echo "- Better spacing options for complex databases"
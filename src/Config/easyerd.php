<?php

return [
    'output_path' => env('EASYERD_OUTPUT_PATH', storage_path('app/erd')),
    
    'image_format' => env('EASYERD_IMAGE_FORMAT', 'svg'),
    
    'include_models' => env('EASYERD_INCLUDE_MODELS', true),
    
    'include_migrations' => env('EASYERD_INCLUDE_MIGRATIONS', true),
    
    'graphviz_path' => env('EASYERD_GRAPHVIZ_PATH', 'dot'),
    
    'orthogonal_splines' => env('EASYERD_ORTHOGONAL_SPLINES', false),
    
    'dark_mode' => env('EASYERD_DARK_MODE', false),
    
    
    // New high-resolution settings
    'image_width' => env('EASYERD_IMAGE_WIDTH', 4000),
    
    'image_height' => env('EASYERD_IMAGE_HEIGHT', 3000),
    
    'dpi' => env('EASYERD_DPI', 300),
    
    'font_size' => env('EASYERD_FONT_SIZE', 12),
    
    'nodesep' => env('EASYERD_NODESEP', 1.2),
    
    'ranksep' => env('EASYERD_RANKSEP', 1.8),
    
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
<?php

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
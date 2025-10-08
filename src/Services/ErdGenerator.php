<?php

namespace Dgtlss\EasyErd\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;

class ErdGenerator
{
    public $config = [];
    protected $tables = [];
    protected $relationships = [];
    protected $models = [];
    protected $relationshipKeys = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function generate()
    {
        if ($this->config['include_migrations'] ?? true) {
            $this->analyzeMigrations();
        }
        
        if ($this->config['include_models'] ?? true) {
            $this->analyzeModels();
        }
        
        return [
            'tables' => $this->tables,
            'relationships' => $this->relationships,
            'models' => $this->models
        ];
    }

    public function generateImage($outputPath = null, $format = null, $width = null, $height = null, $dpi = null)
    {
        $data = $this->generate();
        $dotContent = $this->generateDotContent($data);
        
        $tempFile = tempnam(sys_get_temp_dir(), 'erd_');
        file_put_contents($tempFile, $dotContent);
        
        $format = $format ?? $this->config['image_format'] ?? 'pdf';
        $outputPath = $outputPath ?? "erd.{$format}";
        $graphvizPath = $this->config['graphviz_path'] ?? 'dot';
        
        // High-resolution settings
        $width = $width ?? $this->config['image_width'] ?? 
                (($format === 'svg') ? null : 4000);
        $height = $height ?? $this->config['image_height'] ?? 
                (($format === 'svg') ? null : 3000);
        $dpi = $dpi ?? $this->config['dpi'] ?? 300;
        
        if (!shell_exec("which {$graphvizPath}")) {
            throw new \Exception("Graphviz ({$graphvizPath}) is not installed. Please install it to generate image ERDs. https://graphviz.org/download/");
        }

        $command = "{$graphvizPath}";
        
        // Add format-specific quality options
        switch ($format) {
            case 'png':
            case 'jpg':
            case 'jpeg':
                $command .= " -T{$format}";
                if ($dpi) $command .= " -Gdpi={$dpi}";
                if ($width || $height) {
                    $size = [];
                    if ($width) $size[] = "width={$width}";
                    if ($height) $size[] = "height={$height}";
                    $command .= " -Gsize='" . implode(',', $size) . "'";
                }
                break;
            case 'svg':
                $command .= " -Tsvg";
                break;
            case 'pdf':
                $command .= " -Tpdf";
                break;
            default:
                $command .= " -T{$format}";
        }
        
        $command .= " {$tempFile} -o {$outputPath}";
        
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            unlink($tempFile);
            throw new \Exception("Graphviz command failed: " . implode("\n", $output));
        }
        
        unlink($tempFile);
        
        return $outputPath;
    }

    public function generateText()
    {
        $data = $this->generate();
        $output = "DATABASE ERD - TABLE RELATIONSHIPS\n";
        $output .= "=================================\n\n";
        
        foreach ($data['tables'] as $tableName => $table) {
            $output .= "TABLE: {$tableName}\n";
            $output .= str_repeat('-', strlen($tableName) + 7) . "\n";
            
            foreach ($table['columns'] as $column) {
                $output .= "- {$column['name']} ({$column['type']})";
                if ($column['primary']) {
                    $output .= " [PK]";
                }
                if ($column['foreign']) {
                    $output .= " [FK]";
                }
                $output .= "\n";
            }
            $output .= "\n";
        }
        
        $output .= "\nRELATIONSHIPS\n";
        $output .= "=============\n\n";
        
        foreach ($data['relationships'] as $relationship) {
            $fromTable = $this->sanitizeDotIdentifier($relationship['from_table']);
            $toTable = $this->sanitizeDotIdentifier($relationship['to_table']);
            $fromColumn = $this->sanitizeDotLabel($relationship['from_column']);
            
            $relationshipType = $relationship['type'] ?? 'belongs_to';
            $relationshipSymbol = $this->config['relationship_types'][$relationshipType] ?? '->';
            
            $output .= "{$fromTable} {$relationshipSymbol} {$toTable} ({$fromColumn})\n";
        }
        
        return $output;
    }

    protected function analyzeMigrations()
    {
        $migrationPath = database_path('migrations');
        
        if (!File::exists($migrationPath)) {
            return;
        }
        
        // Use allFiles() to get files recursively from subdirectories
        $files = File::allFiles($migrationPath);
        
        foreach ($files as $file) {
            // Only process PHP files
            if ($file->getExtension() === 'php') {
                $content = File::get($file->getPathname());
                $this->parseMigration($content);
            }
        }
    }

    protected function parseMigration($content)
    {
        $excludedTables = $this->config['excluded_tables'] ?? [];
        
        // Find all Schema::create blocks with their table content
        preg_match_all("/Schema::create\('([^']+)'\s*,\s*function\s*\(\s*Blueprint\s+\\\$table\s*\)\s*{([^}]*)}/s", $content, $matches);
        
        if (empty($matches[1])) {
            return;
        }
        
        for ($i = 0; $i < count($matches[1]); $i++) {
            $tableName = $matches[1][$i];
            
            // Skip excluded tables
            if (in_array($tableName, $excludedTables)) {
                continue;
            }
            
            $tableContent = $matches[2][$i];
            
            // Parse column definitions within this specific table block
            preg_match_all('/\$table->(\w+)\(([^)]+)\)/', $tableContent, $columnMatches);
            
            $columns = [];
            for ($columnIndex = 0; $columnIndex < count($columnMatches[0]); $columnIndex++) {
                $type = $columnMatches[1][$columnIndex];
                $params = trim($columnMatches[2][$columnIndex], '\'" ');
                
                $isForeign = false;
                
                // Handle different parameter formats
                if ($type === 'foreignIdFor') {
                    // Extract model class from foreignIdFor(User::class)
                    $modelClass = $params;
                    
                    // Handle User::class format
                    if (str_contains($modelClass, '::class')) {
                        $modelName = str_replace('::class', '', $modelClass);
                    } else {
                        $modelName = basename(str_replace('\\', '/', $modelClass));
                    }
                    
                    $name = Str::snake($modelName) . '_id';
                    $isForeign = true;
                } elseif (str_contains($params, ',')) {
                    // Handle cases with length parameter: string('name', 255)
                    $parts = explode(',', $params);
                    $name = trim($parts[0], '\'" ');
                    $length = trim($parts[1], '\'" ') ?? null;
                } else {
                    // Simple case: string('name')
                    $name = trim($params, '\'" ');
                    $length = null;
                }
                
                $columns[] = [
                    'name' => $name,
                    'type' => $this->mapColumnType($type, $length ?? null),
                    'primary' => $type === 'id' || str_contains($tableContent, "->primary('{$name}')"),
                    'foreign' => $isForeign || str_contains($tableContent, "->foreign('{$name}')") || 
                                str_contains($tableContent, "->foreignId('{$name}')")
                ];
            }
            
            $this->tables[$tableName] = [
                'name' => $tableName,
                'columns' => $columns
            ];
            
            $this->extractRelationships($tableContent, $tableName);
        }
    }

    protected function extractRelationships($content, $tableName)
    {
        // Handle traditional foreign key constraints
        preg_match_all('/\$table->foreign\(\'([^\']+)\'\)->references\(\'([^\']+)\'\)->on\(\'([^\']+)\'\)/', $content, $matches);
        
        for ($relationshipIndex = 0; $relationshipIndex < count($matches[0]); $relationshipIndex++) {
            $relationshipKey = $tableName . '_' . $matches[3][$relationshipIndex] . '_' . $matches[1][$relationshipIndex];
            
            // Avoid duplicate relationships
            if (!isset($this->relationshipKeys[$relationshipKey])) {
                $this->relationships[] = [
                    'from_table' => $tableName,
                    'from_column' => $matches[1][$relationshipIndex],
                    'to_table' => $matches[3][$relationshipIndex],
                    'to_column' => $matches[2][$relationshipIndex],
                    'type' => 'belongs_to'
                ];
                $this->relationshipKeys[$relationshipKey] = true;
            }
        }
        
        // Handle foreignIdFor() method
        preg_match_all('/\$table->foreignIdFor\(([^)]+)\)/', $content, $foreignIdMatches);
        
        for ($i = 0; $i < count($foreignIdMatches[0]); $i++) {
            $modelClass = trim($foreignIdMatches[1][$i], '\'" ');
            
            // Handle User::class format
            if (str_contains($modelClass, '::class')) {
                $modelName = str_replace('::class', '', $modelClass);
            } else {
                // Extract the model name from the full class path
                $modelName = basename(str_replace('\\', '/', $modelClass));
            }
            
            // Convert model name to table name (Model -> models)
            $relatedTable = Str::plural(Str::snake($modelName));
            
            // The column name is typically the model name in snake_case + _id
            $columnName = Str::snake($modelName) . '_id';
            
            // Validate that we have a proper table name
            if ($relatedTable && !str_contains($relatedTable, '::')) {
                $relationshipKey = $tableName . '_' . $relatedTable . '_' . $columnName;
                
                // Avoid duplicate relationships
                if (!isset($this->relationshipKeys[$relationshipKey])) {
                    $this->relationships[] = [
                        'from_table' => $tableName,
                        'from_column' => $columnName,
                        'to_table' => $relatedTable,
                        'to_column' => 'id',
                        'type' => 'belongs_to'
                    ];
                    $this->relationshipKeys[$relationshipKey] = true;
                }
            }
        }
        
        // Handle foreignId() with constrained() method
        preg_match_all('/\$table->foreignId\(\'([^\']+)\'\)(?:->constrained\(\))?/', $content, $foreignIdConstrainedMatches);
        
        for ($i = 0; $i < count($foreignIdConstrainedMatches[0]); $i++) {
            $columnName = $foreignIdConstrainedMatches[1][$i];
            
            // Extract the table name from the column name (remove _id suffix)
            $relatedTable = Str::plural(str_replace('_id', '', $columnName));
            
            $relationshipKey = $tableName . '_' . $relatedTable . '_' . $columnName;
            
            // Avoid duplicate relationships
            if (!isset($this->relationshipKeys[$relationshipKey])) {
                $this->relationships[] = [
                    'from_table' => $tableName,
                    'from_column' => $columnName,
                    'to_table' => $relatedTable,
                    'to_column' => 'id',
                    'type' => 'belongs_to'
                ];
                $this->relationshipKeys[$relationshipKey] = true;
            }
        }
    }

    protected function analyzeModels()
    {
        $modelPath = app_path('Models');
        
        if (!File::exists($modelPath)) {
            return;
        }
        
        // Use allFiles() to get files recursively from subdirectories
        $files = File::allFiles($modelPath);
        
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $content = File::get($file->getPathname());
                $relativePath = str_replace($modelPath . DIRECTORY_SEPARATOR, '', $file->getPathname());
                $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
                $this->parseModel($content, $file->getFilenameWithoutExtension(), $relativePath);
            }
        }
    }

    protected function parseModel($content, $modelName, $relativePath = null)
    {
        preg_match('/protected \$table = \'([^\']+)\'/', $content, $tableMatches);
        $tableName = $tableMatches[1] ?? Str::plural(Str::snake($modelName));
        
        // Determine model namespace based on subdirectory structure
        $modelNamespace = $modelName;
        if ($relativePath) {
            $pathInfo = pathinfo($relativePath);
            if (isset($pathInfo['dirname']) && $pathInfo['dirname'] !== '.') {
                $namespaceParts = explode('/', $pathInfo['dirname']);
                $modelNamespace = implode('\\', $namespaceParts) . '\\' . $modelName;
            }
        }
        
        $relationships = [];
        
        if (preg_match_all('/public function (\w+)\(\)\s*\{[^}]*return \$this->(\w+)\(([^)]*)\)/', $content, $matches)) {
            for ($i = 0; $i < count($matches[0]); $i++) {
                $relationshipName = $matches[1][$i];
                $relationshipType = $matches[2][$i];
                $relatedModel = trim($matches[3][$i], '\'"' );
                
                $relationships[] = [
                    'name' => $relationshipName,
                    'type' => $relationshipType,
                    'related_model' => $relatedModel
                ];
            }
        }
        
        $this->models[$modelName] = [
            'name' => $modelName,
            'class' => $modelNamespace,
            'table' => $tableName,
            'relationships' => $relationships,
            'subdirectory' => $relativePath ? dirname($relativePath) : ''
        ];
    }

    protected function mapColumnType($type, $length = null)
    {
        $typeMap = [
            'string' => 'VARCHAR',
            'text' => 'TEXT',
            'integer' => 'INT',
            'bigInteger' => 'BIGINT',
            'boolean' => 'BOOLEAN',
            'date' => 'DATE',
            'datetime' => 'DATETIME',
            'timestamp' => 'TIMESTAMP',
            'time' => 'TIME',
            'decimal' => 'DECIMAL',
            'float' => 'FLOAT',
            'id' => 'INT',
            'foreignId' => 'BIGINT'
        ];
        
        $mappedType = $typeMap[$type] ?? strtoupper($type);
        
        if ($length && in_array($type, ['string', 'decimal'])) {
            $mappedType .= "({$length})";
        }
        
        return $mappedType;
    }

    protected function generateDotContent($data)
    {
        $dot = "digraph ERD {\n";
        
        // Use dot algorithm with grid-friendly settings
        $layout = $this->config['layout_algorithm'] ?? 'dot';
        $dot .= "    layout={$layout};\n";
        
        // Top-to-bottom with some horizontal organization
        $rankdir = $this->config['rank_direction'] ?? 'TB';
        $dot .= "    rankdir={$rankdir};\n";
        
        // Grid-optimized spacing
        $nodesep = $this->config['nodesep'] ?? 2.0; // Wider horizontal spacing
        $ranksep = $this->config['ranksep'] ?? 1.8; // Compact vertical spacing 
        $dot .= "    nodesep={$nodesep};\n";
        $dot .= "    ranksep={$ranksep};\n";
        
        // Use splines for smooth connections
        $splines = $this->config['grid_splines'] ?? 'spline';
        $dot .= "    splines={$splines};\n";
        
        // Enhanced node styling with better spacing
        $dot .= "    node [shape=plaintext, fontname=\"Arial, Helvetica, sans-serif\", margin=\"0.15,0.1\"];\n";
        $dot .= "    edge [arrowhead=vee, fontname=\"Arial, Helvetica, sans-serif\", fontsize=\"9\"];\n\n";
        
        // Global styling
        $fontSize = $this->config['font_size'] ?? 11;
        $dot .= "    fontname=\"Arial, Helvetica, sans-serif\";\n";
        $dot .= "    fontsize={$fontSize};\n\n";
        
        // Create grid-based layout
        $gridLayout = $this->createGridLayout($data['tables'], $data['relationships']);
        
        foreach ($data['tables'] as $tableName => $table) {
            $sanitizedName = $this->sanitizeDotIdentifier($tableName);
            $dot .= "    {$sanitizedName} [label=<\n";
            $dot .= "        <table border=\"1\" cellborder=\"1\" cellspacing=\"0\">\n";
            
            $isDarkMode = $this->config['dark_mode'] ?? false;
            if ($isDarkMode) {
                $headerBg = $this->config['dark_table_styles']['header_bg'] ?? 'bgcolor="#1a202c"';
                $headerText = $this->config['dark_table_styles']['header_text'] ?? 'color="white"';
                $dot .= "            <tr><td {$headerBg} {$headerText}><b>{$tableName}</b></td></tr>\n";
            } else {
                $dot .= "            <tr><td bgcolor=\"lightblue\"><b>{$tableName}</b></td></tr>\n";
            }
            
            foreach ($table['columns'] as $column) {
                $style = '';
                if ($column['primary']) {
                    $style = $this->getColumnStyle('primary_key', $isDarkMode);
                } elseif ($column['foreign']) {
                    $style = $this->getColumnStyle('foreign_key', $isDarkMode);
                } else {
                    $style = $this->getColumnStyle('regular_column', $isDarkMode);
                }
                
                // Ensure proper spacing in HTML attributes
                $styleStr = $style ? ' ' . $style : '';
                $dot .= "            <tr><td{$styleStr}>{$column['name']}<br/><font point-size=\"8\">{$column['type']}</font></td></tr>\n";
            }
            
            $dot .= "        </table>\n";
            $dot .= "    >];\n\n";
        }
        
        // Create grid-based layout
        $gridLayout = $this->createGridLayout($data['tables'], $data['relationships']);
        
        // Apply grid constraints for organized flow
        foreach ($gridLayout['grid_rows'] as $row => $tables) {
            if (count($tables) > 1) {
                $dot .= "    { rank=same; ";
                foreach ($tables as $tableName) {
                    $sanitizedName = $this->sanitizeDotIdentifier($tableName);
                    $dot .= "{$sanitizedName} ";
                }
                $dot .= "}\n";
            }
        }
        $dot .= "\n";
        
        // Add individual table nodes
        
        foreach ($data['relationships'] as $relationship) {
            // Ensure table names are valid DOT identifiers
            $fromTable = $this->sanitizeDotIdentifier($relationship['from_table']);
            $toTable = $this->sanitizeDotIdentifier($relationship['to_table']);
            $fromColumn = $this->sanitizeDotLabel($relationship['from_column']);
            
            // Clean relationship styling for better visibility
            $relationshipType = $relationship['type'] ?? 'belongs_to';
            $edgeStyle = $this->getGridEdgeStyle($relationshipType);
            
            $dot .= "    {$fromTable} -> {$toTable} [label=\"{$fromColumn}\"{$edgeStyle}];\n";
        }
        
        $dot .= "}\n";
        
        return $dot;
    }
    
    protected function sanitizeDotIdentifier($identifier)
    {
        // Replace any characters that might cause issues in DOT identifiers
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $identifier);
    }
    
    protected function sanitizeDotLabel($label)
    {
        // Escape quotes and other special characters for DOT labels
        return htmlspecialchars($label, ENT_QUOTES);
    }
    
    protected function groupTablesByRelationships($tables, $relationships)
    {
        $groups = [];
        $visited = [];
        $groupIndex = 0;
        
        // First, put each table in its own group
        foreach (array_keys($tables) as $tableName) {
            $groups["group_{$groupIndex}"] = [$tableName];
            $visited[$tableName] = $groupIndex;
            $groupIndex++;
        }
        
        // Then, group related tables together
        foreach ($relationships as $relationship) {
            $fromTable = $relationship['from_table'];
            $toTable = $relationship['to_table'];
            
            if (isset($visited[$fromTable]) && isset($visited[$toTable])) {
                $fromGroup = $visited[$fromTable];
                $toGroup = $visited[$toTable];
                
                if ($fromGroup !== $toGroup) {
                    // Merge groups
                    $mergedGroup = "group_{$groupIndex}";
                    $groups[$mergedGroup] = array_merge($groups["group_{$fromGroup}"], $groups["group_{$toGroup}"]);
                    
                    // Update visited table
                    foreach ($groups[$mergedGroup] as $tableName) {
                        $visited[$tableName] = $groupIndex;
                    }
                    
                    // Remove old groups
                    unset($groups["group_{$fromGroup}"]);
                    unset($groups["group_{$toGroup}"]);
                    
                    $groupIndex++;
                }
            }
        }
        
        // Rename groups to be more descriptive
        $finalGroups = [];
        foreach ($groups as $group) {
            if (count($group) === 1) {
                $finalGroups["Standalone"][] = $group[0];
            } else {
                $finalGroups["Related Tables"] = $group;
            }
        }
        
        return $finalGroups;
    }
    
    protected function getColumnStyle($type, $isDarkMode = false)
    {
        if ($isDarkMode) {
            $styles = $this->config['dark_table_styles'] ?? [];
        } else {
            $styles = $this->config['table_styles'] ?? [];
        }
        
        return $styles[$type] ?? '';
    }
    
    protected function createGridLayout($tables, $relationships)
    {
        // Create a clean grid layout that avoids clustering issues
        $gridRows = [];
        $processedTables = [];
        $currentRow = 0;
        
        // Calculate centrality for better placement
        $centrality = [];
        $referenceCounts = [];
        $fromCounts = [];
        
        foreach ($relationships as $relationship) {
            $toTable = $relationship['to_table'];
            $fromTable = $relationship['from_table'];
            
            if (!isset($referenceCounts[$toTable])) {
                $referenceCounts[$toTable] = 0;
            }
            if (!isset($fromCounts[$fromTable])) {
                $fromCounts[$fromTable] = 0;
            }
            
            $referenceCounts[$toTable]++;
            $fromCounts[$fromTable]++;
        }
        
        // Calculate centrality (connection density)
        foreach ($tables as $tableName => $table) {
            $refCount = isset($referenceCounts[$tableName]) ? $referenceCounts[$tableName] : 0;
            $fromCount = isset($fromCounts[$tableName]) ? $fromCounts[$tableName] : 0;
            $centrality[$tableName] = $refCount + $fromCount;
        }
        
        // Sort by centrality for row organization
        arsort($centrality);
        
        // Create grid rows based on centrality
        $tablesPerRow = 4; // Default grid width
        $totalTables = count($tables);
        
        // Optimize table count per row based on total tables
        if ($totalTables <= 6) {
            $tablesPerRow = 3;
        } elseif ($totalTables <= 10) {
            $tablesPerRow = 4;
        } elseif ($totalTables <= 15) {
            $tablesPerRow = 5;
        } else {
            $tablesPerRow = 6;
        }
        
        // Build grid from centrality-sorted tables
        $sortedTables = array_keys($centrality);
        
        foreach ($sortedTables as $index => $tableName) {
            $row = intval($index / $tablesPerRow);
            if (!isset($gridRows[$row])) {
                $gridRows[$row] = [];
            }
            $gridRows[$row][] = $tableName;
            $processedTables[$tableName] = true;
        }
        
        return [
            'grid_rows' => $gridRows,
            'tables_per_row' => $tablesPerRow
        ];
    }
    
    protected function getGridEdgeStyle($relationshipType)
    {
        // Simple, clean styling for grid layout
        $styles = [
            'belongs_to' => ',color="darkblue",fontcolor="darkblue",fontsize=9,weight=1',
            'has_many' => ',color="darkgreen",fontcolor="darkgreen",fontsize=9,weight=1,style=dashed',
            'has_one' => ',color="darkorange",fontcolor="darkorange",fontsize=9,weight=1,style=dotted',
            'belongs_to_many' => ',color="purple",fontcolor="purple",fontsize=9,weight=2',
        ];
        
        return $styles[$relationshipType] ?? ',color="darkblue",fontcolor="darkblue",fontsize=9,weight=1';
    }
}

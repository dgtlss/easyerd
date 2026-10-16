<?php

namespace Dgtlss\EasyErd\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

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
        $this->tables = $this->relationships = $this->models = $this->relationshipKeys = [];

        $source = $this->config['source'] ?? 'migrations';
        if (!in_array($source, ['migrations', 'database'])) {
            throw new \InvalidArgumentException('Source must be migrations or database.');
        }

        if ($this->config['include_models'] ?? true) {
            $this->analyzeModels();
        }

        if ($source === 'database') {
            $this->analyzeDatabase();
        } elseif ($this->config['include_migrations'] ?? true) {
            $this->analyzeMigrations();
        }
        
        if ($this->config['include_models'] ?? true) {
            $this->addModelRelationships();
        }

        $this->applyFilters();

        ksort($this->tables);
        ksort($this->models);
        usort($this->relationships, fn ($a, $b) => [$a['from_table'], $a['from_column'], $a['to_table'], $a['to_column'], $a['type']] <=> [$b['from_table'], $b['from_column'], $b['to_table'], $b['to_column'], $b['type']]);

        return [
            'tables' => $this->tables,
            'relationships' => $this->relationships,
            'models' => $this->models
        ];
    }

    public function generateImage($outputPath = null, $format = null, $width = null, $height = null, $dpi = null)
    {
        $format ??= $this->config['image_format'] ?? 'pdf';
        if (!in_array($format, ['png', 'jpg', 'jpeg', 'svg', 'pdf'])) {
            throw new \InvalidArgumentException('Image format must be png, jpg, jpeg, svg, or pdf.');
        }
        $outputPath ??= "erd.{$format}";
        $width ??= $this->config['image_width'] ?? 4000;
        $height ??= $this->config['image_height'] ?? 3000;
        $dpi ??= $this->config['dpi'] ?? 300;
        foreach ([$width, $height, $dpi] as $value) {
            if (!is_numeric($value) || $value <= 0) {
                throw new \InvalidArgumentException('Image dimensions and DPI must be positive numbers.');
            }
        }

        $dot = $this->generateDotContent($this->generate());
        $tempFile = tempnam(sys_get_temp_dir(), 'erd_');
        if ($tempFile === false) {
            throw new \RuntimeException('Could not create a temporary Graphviz file.');
        }

        try {
            if (file_put_contents($tempFile, $dot) === false) {
                throw new \RuntimeException('Could not write the temporary Graphviz file.');
            }
            $command = [$this->config['graphviz_path'] ?? 'dot', '-T'.$format];
            if (in_array($format, ['png', 'jpg', 'jpeg'])) {
                $command[] = '-Gdpi='.$dpi;
                $command[] = '-Gsize='.($width / $dpi).','.($height / $dpi);
            }
            array_push($command, $tempFile, '-o', $outputPath);
            $process = new Process($command);
            $process->setTimeout(null);
            $process->run();
            if (!$process->isSuccessful()) {
                throw new \RuntimeException('Graphviz failed. Ensure Graphviz is installed and graphviz_path is correct. '.$process->getErrorOutput());
            }
        } finally {
            unlink($tempFile);
        }

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
            $fromTable = $relationship['from_table'];
            $toTable = $relationship['to_table'];
            $fromColumn = $relationship['from_column'];
            
            $relationshipType = $relationship['type'] ?? 'belongs_to';
            $relationshipSymbol = $this->config['relationship_types'][$relationshipType] ?? '->';
            
            $output .= "{$fromTable} {$relationshipSymbol} {$toTable} ({$fromColumn})\n";
        }
        
        return $output;
    }

    public function generateMermaid(bool $markdown = true): string
    {
        $data = $this->generate();
        $output = "erDiagram\n";

        foreach ($data['tables'] as $tableName => $table) {
            $entity = $this->mermaidEntity($tableName);
            if (!$table['columns']) {
                $output .= "    {$entity}\n";
                continue;
            }
            $output .= "    {$entity} {\n";
            foreach ($table['columns'] as $column) {
                $type = $this->mermaidAttribute($column['type']);
                $name = $this->mermaidAttribute($column['name']);
                $keys = array_filter([$column['primary'] ? 'PK' : null, $column['foreign'] ? 'FK' : null]);
                $output .= "        {$type} {$name}".($keys ? ' '.implode(', ', $keys) : '')."\n";
            }
            $output .= "    }\n";
        }

        foreach ($data['relationships'] as $relationship) {
            $from = $this->mermaidEntity($relationship['from_table']);
            $to = $this->mermaidEntity($relationship['to_table']);
            $fromColumns = array_column($data['tables'][$relationship['from_table']]['columns'] ?? [], null, 'name');
            $nullable = $fromColumns[$relationship['from_column']]['nullable'] ?? false;
            $cardinality = match ($relationship['type']) {
                'has_one' => '||--o|',
                'has_many' => '||--o{',
                'belongs_to_many' => '}o--o{',
                default => $nullable ? '}o--o|' : '}o--||',
            };
            $label = $this->mermaidQuoted($relationship['name'] ?? $relationship['from_column']);
            $output .= "    {$from} {$cardinality} {$to} : {$label}\n";
        }

        return $markdown ? "```mermaid\n{$output}```\n" : $output;
    }

    protected function mermaidEntity(string $name): string
    {
        return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) ? $name : $this->mermaidQuoted($name);
    }

    protected function mermaidQuoted(string $value): string
    {
        return '"'.str_replace(['&', '"', '`', "\r", "\n"], ['&amp;', '&quot;', '&#96;', ' ', ' '], $value).'"';
    }

    protected function mermaidAttribute(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9_()\[\]-]/', '_', $value);

        return preg_match('/^[a-zA-Z]/', $value) ? $value : 'column_'.$value;
    }

    protected function analyzeMigrations()
    {
        $migrationPath = database_path('migrations');
        if (!File::exists($migrationPath)) {
            return;
        }

        $files = File::allFiles($migrationPath);
        usort($files, fn ($a, $b) => [$a->getFilename(), $a->getPathname()] <=> [$b->getFilename(), $b->getPathname()]);
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $this->parseMigration(File::get($file->getPathname()));
            }
        }
    }

    protected function analyzeDatabase(): void
    {
        $connection = DB::connection($this->config['connection'] ?? null);
        $schema = $connection->getSchemaBuilder();
        $prefix = $connection->getTablePrefix();
        $tables = $schema->getTables();
        $multipleSchemas = count(array_unique(array_column($tables, 'schema'))) > 1;

        foreach ($tables as $table) {
            $name = $prefix && str_starts_with($table['name'], $prefix) ? substr($table['name'], strlen($prefix)) : $table['name'];
            $qualified = !empty($table['schema']) ? $table['schema'].'.'.$name : $name;
            $tableName = $multipleSchemas ? $qualified : $name;
            $primary = [];
            foreach ($schema->getIndexes($qualified) as $index) {
                if ($index['primary']) {
                    array_push($primary, ...$index['columns']);
                }
            }

            $foreignKeys = $schema->getForeignKeys($qualified);
            $foreignColumns = [];
            foreach ($foreignKeys as $foreign) {
                array_push($foreignColumns, ...$foreign['columns']);
                $target = $foreign['foreign_table'];
                if ($prefix && str_starts_with($target, $prefix)) {
                    $target = substr($target, strlen($prefix));
                }
                if ($multipleSchemas && !empty($foreign['foreign_schema'])) {
                    $target = $foreign['foreign_schema'].'.'.$target;
                }
                foreach ($foreign['columns'] as $i => $column) {
                    $this->addRelationship($tableName, $column, $target, $foreign['foreign_columns'][$i]);
                }
            }

            $columns = [];
            foreach ($schema->getColumns($qualified) as $column) {
                $columns[] = $this->column(
                    $column['name'],
                    strtoupper($column['type']),
                    in_array($column['name'], $primary, true),
                    in_array($column['name'], $foreignColumns, true),
                    $column['nullable'],
                );
            }
            $this->tables[$tableName] = ['name' => $tableName, 'columns' => $columns];
        }
    }

    protected function parseMigration($content)
    {
        $tokens = PhpSource::tokens($content);
        $body = PhpSource::methods($tokens)['up'] ?? $tokens;
        $calls = [];
        foreach (['Schema', '\\Schema', '\\Illuminate\\Support\\Facades\\Schema'] as $schema) {
            array_push($calls, ...PhpSource::calls($body, $schema, '::'));
        }

        foreach ($calls as $call) {
            if (!in_array($call['method'], ['create', 'table'])) {
                continue;
            }
            $tableName = PhpSource::literal(PhpSource::argument($call['arguments'], 0, 'table'));
            $callback = $call['arguments'][1] ?? [];
            $offset = array_search('{', $callback, true);
            if ($tableName === null || $offset === false) {
                continue;
            }
            $variable = '$table';
            foreach (array_slice($callback, 0, $offset) as $token) {
                if (str_starts_with($token, '$')) {
                    $variable = $token;
                    break;
                }
            }
            $tableBody = PhpSource::group($callback, $offset);
            $columns = $call['method'] === 'create' ? [] : array_column($this->tables[$tableName]['columns'] ?? [], null, 'name');

            foreach (PhpSource::calls($tableBody, $variable) as $columnCall) {
                $this->parseColumn($columnCall, $tableName, $columns, $tokens);
            }

            $this->tables[$tableName] = ['name' => $tableName, 'columns' => array_values($columns)];
        }
    }

    protected function parseColumn(array $call, string $tableName, array &$columns, array $tokens): void
    {
        $method = $call['method'];
        $args = $call['arguments'];
        $chain = $call['chain'];
        $name = PhpSource::literal(PhpSource::argument($args, 0, 'column'));

        if ($method === 'foreign') {
            $from = $this->columnNames($args);
            $to = $this->columnNames($chain['references'] ?? []);
            $relatedTable = PhpSource::literal(PhpSource::argument($chain['on'] ?? [], 0, 'table'));
            foreach ($from as $i => $column) {
                if (isset($columns[$column])) {
                    $columns[$column]['foreign'] = true;
                }
                if ($relatedTable !== null && isset($to[$i])) {
                    $this->addRelationship($tableName, $column, $relatedTable, $to[$i]);
                }
            }
            return;
        }
        if ($method === 'primary') {
            foreach ($this->columnNames($args) as $column) {
                if (isset($columns[$column])) {
                    $columns[$column]['primary'] = true;
                }
            }
            return;
        }
        if (str_starts_with($method, 'drop')) {
            if ($method === 'dropColumn') {
                foreach ($this->columnNames($args, true) as $column) {
                    unset($columns[$column]);
                }
            }
            return;
        }
        if ($method === 'renameColumn') {
            $newName = PhpSource::literal(PhpSource::argument($args, 1, 'to'));
            if (isset($columns[$name]) && $newName !== null) {
                $columns[$name]['name'] = $newName;
                $columns[$newName] = $columns[$name];
                unset($columns[$name]);
                foreach ($this->relationships as &$relationship) {
                    if ($relationship['from_table'] === $tableName && $relationship['from_column'] === $name) {
                        $relationship['from_column'] = $newName;
                    }
                }
                unset($relationship);
            }
            return;
        }
        if (in_array($method, ['index', 'unique', 'fullText', 'spatialIndex', 'comment'])) {
            return;
        }

        $nullable = isset($chain['nullable']) && PhpSource::argument($chain['nullable'], 0) !== 'false';
        $primary = $method === 'id' || str_ends_with($method, 'Increments') || $method === 'increments' || isset($chain['primary']);
        $foreign = in_array($method, ['foreignId', 'foreignIdFor', 'foreignUuid', 'foreignUlid']);
        $type = $this->mapColumnType($method, PhpSource::argument($args, 1, 'length'));

        if (in_array($method, ['timestamps', 'timestampsTz', 'nullableTimestamps'])) {
            foreach (['created_at', 'updated_at'] as $timestamp) {
                $columns[$timestamp] = $this->column($timestamp, 'TIMESTAMP', false, false, true);
            }
            return;
        }
        if (in_array($method, ['softDeletes', 'softDeletesTz', 'softDeletesDatetime'])) {
            $name ??= 'deleted_at';
            $type = $method === 'softDeletesDatetime' ? 'DATETIME' : 'TIMESTAMP';
            $nullable = true;
        } elseif ($method === 'rememberToken') {
            $name ??= 'remember_token';
            $type = 'VARCHAR(100)';
            $nullable = true;
        } elseif ($method === 'id') {
            $name ??= 'id';
        } elseif (in_array($method, ['morphs', 'nullableMorphs', 'uuidMorphs', 'nullableUuidMorphs', 'ulidMorphs', 'nullableUlidMorphs']) && $name !== null) {
            $nullable = str_starts_with($method, 'nullable');
            $columns[$name.'_type'] = $this->column($name.'_type', 'VARCHAR', false, false, $nullable);
            $morphType = stripos($method, 'uuid') !== false ? 'UUID' : (stripos($method, 'ulid') !== false ? 'ULID' : 'BIGINT');
            $columns[$name.'_id'] = $this->column($name.'_id', $morphType, false, false, $nullable);
            return;
        } elseif ($method === 'foreignIdFor') {
            $expression = PhpSource::argument($args, 0, 'model');
            if ($expression === null || (!str_ends_with(strtolower($expression), '::class') && PhpSource::literal($expression) === null)) {
                return;
            }
            $class = PhpSource::resolveClass($expression, $tokens);
            $model = $this->models[$class] ?? [];
            $key = $model['key'] ?? 'id';
            $name = PhpSource::literal(PhpSource::argument($args, 1, 'column')) ?? Str::snake(class_basename($class)).'_'.$key;
            $relatedTable = $model['table'] ?? Str::snake(Str::pluralStudly(class_basename($class)));
            $type = $model['key_type'] ?? 'BIGINT';
        }

        if ($name === null) {
            return;
        }
        $columns[$name] = $this->column($name, $type, $primary, $foreign, $nullable);
        if ($foreign) {
            $constrained = $chain['constrained'] ?? [];
            $toColumn = PhpSource::literal(PhpSource::argument($constrained, 1, 'column'))
                ?? PhpSource::literal(PhpSource::argument($chain['references'] ?? [], 0)) ?? ($key ?? 'id');
            $toTable = PhpSource::literal(PhpSource::argument($constrained, 0, 'table'))
                ?? PhpSource::literal(PhpSource::argument($chain['on'] ?? [], 0))
                ?? ($relatedTable ?? Str::plural(Str::beforeLast($name, '_'.$toColumn)));
            $this->addRelationship($tableName, $name, $toTable, $toColumn, inferred: !isset($chain['constrained']) && !isset($chain['on']));
        }
    }

    protected function column(string $name, string $type, bool $primary = false, bool $foreign = false, bool $nullable = false): array
    {
        return compact('name', 'type', 'primary', 'foreign', 'nullable');
    }

    protected function columnNames(array $arguments, bool $all = false): array
    {
        $tokens = $arguments[0] ?? [];
        if (($tokens[0] ?? null) === '[') {
            $arguments = PhpSource::arguments(array_slice($tokens, 1, -1));
        } elseif (!$all) {
            $arguments = array_slice($arguments, 0, 1);
        }

        return array_values(array_filter(array_map(fn ($arg) => PhpSource::literal(implode('', $arg)), $arguments), fn ($name) => $name !== null));
    }

    protected function addRelationship(string $fromTable, string $fromColumn, string $toTable, string $toColumn = 'id', string $type = 'belongs_to', ?string $name = null, bool $inferred = false): void
    {
        if ($type === 'belongs_to') {
            foreach ($this->relationships as $index => $relationship) {
                if ($relationship['from_table'] === $fromTable && $relationship['from_column'] === $fromColumn && $relationship['type'] === 'belongs_to') {
                    $oldKey = json_encode([$relationship['from_table'], $relationship['from_column'], $relationship['to_table'], $relationship['to_column'], $relationship['type']]);
                    $wasInferred = $this->relationshipKeys[$oldKey] ?? false;
                    if ($inferred && !$wasInferred) {
                        return;
                    }
                    if (!$inferred && $wasInferred) {
                        unset($this->relationships[$index], $this->relationshipKeys[$oldKey]);
                    }
                }
            }
        }
        $key = json_encode([$fromTable, $fromColumn, $toTable, $toColumn, $type]);
        if (!isset($this->relationshipKeys[$key])) {
            $this->relationships[] = [
                'from_table' => $fromTable,
                'from_column' => $fromColumn,
                'to_table' => $toTable,
                'to_column' => $toColumn,
                'type' => $type,
                'name' => $name,
            ];
            $this->relationshipKeys[$key] = $inferred;
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
        usort($files, fn ($a, $b) => $a->getPathname() <=> $b->getPathname());
        
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
        $tokens = PhpSource::tokens($content);
        $class = PhpSource::resolveClass($modelName, $tokens);
        $properties = [];
        foreach ($tokens as $i => $token) {
            if (in_array($token, ['$table', '$primaryKey', '$keyType'])
                && in_array($tokens[$i - 1] ?? null, ['public', 'protected', 'private'])
                && ($tokens[$i + 1] ?? null) === '=' && ($tokens[$i + 3] ?? null) === ';') {
                $properties[$token] = PhpSource::literal($tokens[$i + 2] ?? null);
            }
        }
        $keyType = ($properties['$keyType'] ?? null) === 'string' ? 'UUID' : 'BIGINT';
        foreach ($tokens as $token) {
            if (class_basename($token) === 'HasUuids') {
                $keyType = 'UUID';
            }
            if (class_basename($token) === 'HasUlids') {
                $keyType = 'ULID';
                break;
            }
        }

        $relationships = [];
        foreach (PhpSource::methods($tokens) as $name => $body) {
            foreach (PhpSource::calls($body, '$this') as $call) {
                if (!in_array($call['method'], ['belongsTo', 'hasMany', 'hasOne', 'belongsToMany'])) {
                    continue;
                }
                $related = PhpSource::argument($call['arguments'], 0, 'related');
                if ($related === null || (!str_ends_with(strtolower($related), '::class') && PhpSource::literal($related) === null)) {
                    continue;
                }
                $relationships[] = [
                    'name' => $name,
                    'type' => $call['method'],
                    'related_model' => PhpSource::resolveClass($related, $tokens),
                    'arguments' => $call['arguments'],
                ];
            }
        }

        $this->models[$class] = [
            'name' => $modelName,
            'class' => $class,
            'table' => $properties['$table'] ?? Str::snake(Str::pluralStudly($modelName)),
            'key' => $properties['$primaryKey'] ?? 'id',
            'key_type' => $keyType,
            'relationships' => $relationships,
            'subdirectory' => $relativePath ? dirname($relativePath) : '',
        ];
    }

    protected function addModelRelationships(): void
    {
        foreach ($this->models as $model) {
            $fromTable = $model['table'];
            if (($this->config['source'] ?? 'migrations') === 'database' && !isset($this->tables[$fromTable])) {
                continue;
            }
            $this->tables[$fromTable] ??= ['name' => $fromTable, 'columns' => []];
            foreach ($model['relationships'] as $relation) {
                $related = $this->models[$relation['related_model']] ?? [
                    'name' => class_basename($relation['related_model']),
                    'table' => Str::snake(Str::pluralStudly(class_basename($relation['related_model']))),
                    'key' => 'id',
                ];
                $toTable = $related['table'];
                if (($this->config['source'] ?? 'migrations') === 'database' && !isset($this->tables[$toTable])) {
                    continue;
                }
                $this->tables[$toTable] ??= ['name' => $toTable, 'columns' => []];
                $args = $relation['arguments'];
                $type = Str::snake($relation['type']);
                if ($type === 'belongs_to') {
                    $fromColumn = PhpSource::literal(PhpSource::argument($args, 1, 'foreignKey')) ?? Str::snake($relation['name']).'_'.$related['key'];
                    $toColumn = PhpSource::literal(PhpSource::argument($args, 2, 'ownerKey')) ?? $related['key'];
                } elseif ($type === 'belongs_to_many') {
                    $fromColumn = PhpSource::literal(PhpSource::argument($args, 4, 'parentKey')) ?? $model['key'];
                    $toColumn = PhpSource::literal(PhpSource::argument($args, 5, 'relatedKey')) ?? $related['key'];
                } else {
                    $fromColumn = PhpSource::literal(PhpSource::argument($args, 2, 'localKey')) ?? $model['key'];
                    $toColumn = PhpSource::literal(PhpSource::argument($args, 1, 'foreignKey')) ?? Str::snake($model['name']).'_'.$model['key'];
                }

                $duplicate = false;
                foreach ($this->relationships as &$existing) {
                    $same = [$fromTable, $fromColumn, $toTable, $toColumn] === [$existing['from_table'], $existing['from_column'], $existing['to_table'], $existing['to_column']];
                    $inverse = [$toTable, $toColumn, $fromTable, $fromColumn] === [$existing['from_table'], $existing['from_column'], $existing['to_table'], $existing['to_column']];
                    if (($same || $inverse) && ($type === 'belongs_to_many') === ($existing['type'] === 'belongs_to_many')) {
                        $duplicate = true;
                        if ($type === 'has_one') {
                            $existing = ['from_table' => $fromTable, 'from_column' => $fromColumn, 'to_table' => $toTable, 'to_column' => $toColumn, 'type' => $type, 'name' => $relation['name']];
                        } elseif ($same && $existing['name'] === null) {
                            $existing['name'] = $relation['name'];
                        }
                        break;
                    }
                }
                unset($existing);
                if (!$duplicate) {
                    $this->addRelationship($fromTable, $fromColumn, $toTable, $toColumn, $type, $relation['name']);
                }
            }
        }
    }

    protected function applyFilters(): void
    {
        $included = $this->config['tables'] ?? [];
        $excluded = $this->config['excluded_tables'] ?? [];
        $depth = $this->config['depth'] ?? null;
        if ($depth !== null && (filter_var($depth, FILTER_VALIDATE_INT) === false || $depth < 0 || !$included)) {
            throw new \InvalidArgumentException('Depth must be a non-negative integer and requires --tables.');
        }

        $available = array_fill_keys(array_keys($this->tables), true);
        foreach ($this->relationships as $relationship) {
            $available[$relationship['from_table']] = true;
            $available[$relationship['to_table']] = true;
        }
        $available = array_diff_key($available, array_fill_keys($excluded, true));
        $selected = $included ? array_intersect_key($available, array_fill_keys($included, true)) : $available;

        if ($included && $depth !== null) {
            $adjacent = [];
            foreach ($this->relationships as $relationship) {
                $from = $relationship['from_table'];
                $to = $relationship['to_table'];
                if (isset($available[$from], $available[$to])) {
                    $adjacent[$from][] = $to;
                    $adjacent[$to][] = $from;
                }
            }
            $frontier = array_keys($selected);
            for ($level = 0; $level < (int) $depth && $frontier; $level++) {
                $next = [];
                foreach ($frontier as $table) {
                    foreach ($adjacent[$table] ?? [] as $neighbor) {
                        if (!isset($selected[$neighbor])) {
                            $selected[$neighbor] = true;
                            $next[] = $neighbor;
                        }
                    }
                }
                $frontier = $next;
            }
        }

        $this->tables = array_intersect_key($this->tables, $selected);
        $this->relationships = array_values(array_filter($this->relationships, fn ($relationship) => isset($selected[$relationship['from_table']], $selected[$relationship['to_table']])));
        $this->models = array_filter($this->models, fn ($model) => isset($selected[$model['table']]));
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
            'id' => 'BIGINT',
            'increments' => 'INT',
            'bigIncrements' => 'BIGINT',
            'unsignedBigInteger' => 'BIGINT',
            'unsignedInteger' => 'INT',
            'dateTime' => 'DATETIME',
            'dateTimeTz' => 'DATETIME',
            'timestampTz' => 'TIMESTAMP',
            'foreignId' => 'BIGINT',
            'foreignIdFor' => 'BIGINT',
            'foreignUuid' => 'UUID',
            'foreignUlid' => 'ULID'
        ];
        
        $mappedType = $typeMap[$type] ?? strtoupper($type);
        
        if ($length && is_numeric($length) && in_array($type, ['string', 'decimal'])) {
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
        
        foreach ($data['tables'] as $tableName => $table) {
            $sanitizedName = $this->sanitizeDotIdentifier($tableName);
            $tableLabel = $this->sanitizeDotLabel($tableName);
            $dot .= "    {$sanitizedName} [label=<\n";
            $dot .= "        <table border=\"1\" cellborder=\"1\" cellspacing=\"0\">\n";
            
            $isDarkMode = $this->config['dark_mode'] ?? false;
            if ($isDarkMode) {
                $headerBg = $this->config['dark_table_styles']['header_bg'] ?? 'bgcolor="#1a202c"';
                $headerText = $this->config['dark_table_styles']['header_text'] ?? 'color="white"';
                $dot .= "            <tr><td {$headerBg} {$headerText}><b>{$tableLabel}</b></td></tr>\n";
            } else {
                $dot .= "            <tr><td bgcolor=\"lightblue\"><b>{$tableLabel}</b></td></tr>\n";
            }
            
            foreach ($table['columns'] as $column) {
                $columnName = $this->sanitizeDotLabel($column['name']);
                $columnType = $this->sanitizeDotLabel($column['type']);
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
                $dot .= "            <tr><td{$styleStr}>{$columnName}<br/><font point-size=\"8\">{$columnType}</font></td></tr>\n";
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
            $fromColumn = addcslashes($relationship['name'] ?? $relationship['from_column'], "\\\"\r\n");
            
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
        return '"'.addcslashes($identifier, "\\\"\r\n").'"';
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

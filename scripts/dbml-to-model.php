<?php

class DbmlToModel
{
    private $tables = [];
    private $references = [];

    public function parseFile($filePath)
    {
        if (!file_exists($filePath)) {
            die("File tidak ditemukan: {$filePath}\n");
        }

        $content = file_get_contents($filePath);
        $this->parse($content);
    }

    public function parse($dbmlContent)
    {
        echo "Parsing DBML...\n";

        $lines = explode("\n", $dbmlContent);
        $currentTable = null;
        $inTable = false;

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);

            if (empty($line) || strpos($line, '//') === 0) {
                continue;
            }

            // Table start
            if (preg_match('/^Table\s+(\w+)\s*\{/', $line, $matches)) {
                $currentTable = $matches[1];
                $this->tables[$currentTable] = ['columns' => [], 'relationships' => []];
                $inTable = true;
                echo "  Found table: {$currentTable}\n";
                continue;
            }

            // Table end
            if ($inTable && strpos($line, '}') !== false && !preg_match('/\[.*\}.*\]/', $line)) {
                $inTable = false;
                $currentTable = null;
                continue;
            }

            // Parse column
            if ($inTable && $currentTable) {
                if (preg_match('/^(\w+)\s+(\w+)/', $line, $matches)) {
                    $colName = $matches[1];
                    $colType = $matches[2];

                    $this->tables[$currentTable]['columns'][] = [
                        'name' => $colName,
                        'type' => $colType,
                        'nullable' => stripos($line, 'null') !== false && stripos($line, 'not null') === false,
                        'primary' => stripos($line, 'pk') !== false,
                        'increment' => stripos($line, 'increment') !== false,
                        'line' => $line
                    ];

                    // Check for inline reference: [ref: > table.column]
                    if (preg_match('/\[.*ref:\s*>\s*(\w+)\.(\w+).*\]/i', $line, $refMatch)) {
                        $this->references[] = [
                            'from_table' => $currentTable,
                            'from_column' => $colName,
                            'to_table' => $refMatch[1],
                            'to_column' => $refMatch[2]
                        ];
                        echo "  Found inline reference: {$currentTable}.{$colName} -> {$refMatch[1]}.{$refMatch[2]}\n";
                    }
                }
            }

            // Parse standalone reference: Ref: table1.column1 > table2.column2
            if (preg_match('/^Ref:\s*(\w+)\.(\w+)\s*>\s*(\w+)\.(\w+)/', $line, $matches)) {
                $this->references[] = [
                    'from_table' => $matches[1],
                    'from_column' => $matches[2],
                    'to_table' => $matches[3],
                    'to_column' => $matches[4]
                ];
                echo "  Found reference: {$matches[1]}.{$matches[2]} -> {$matches[3]}.{$matches[4]}\n";
            }

            // Parse reference with < (one to many from this side)
            if (preg_match('/^Ref:\s*(\w+)\.(\w+)\s*<\s*(\w+)\.(\w+)/', $line, $matches)) {
                $this->references[] = [
                    'from_table' => $matches[3],
                    'from_column' => $matches[4],
                    'to_table' => $matches[1],
                    'to_column' => $matches[2]
                ];
                echo "  Found reference: {$matches[3]}.{$matches[4]} -> {$matches[1]}.{$matches[2]}\n";
            }
        }

        echo "\nTotal tables parsed: " . count($this->tables) . "\n";
        echo "Total references parsed: " . count($this->references) . "\n\n";

        $this->buildRelationships();
    }

    private function buildRelationships()
    {
        // Remove duplicate references
        $uniqueRefs = [];
        foreach ($this->references as $ref) {
            $key = "{$ref['from_table']}.{$ref['from_column']}->{$ref['to_table']}.{$ref['to_column']}";
            if (!isset($uniqueRefs[$key])) {
                $uniqueRefs[$key] = $ref;
            }
        }
        $this->references = array_values($uniqueRefs);

        foreach ($this->references as $ref) {
            // belongsTo relationship (many to one)
            if (isset($this->tables[$ref['from_table']])) {
                $this->tables[$ref['from_table']]['relationships'][] = [
                    'type' => 'belongsTo',
                    'model' => $this->toModelName($ref['to_table']),
                    'method' => $this->camel($this->singular($ref['to_table'])),
                    'foreign_key' => $ref['from_column'],
                    'owner_key' => $ref['to_column']
                ];
            }

            // hasMany relationship (one to many)
            if (isset($this->tables[$ref['to_table']])) {
                $this->tables[$ref['to_table']]['relationships'][] = [
                    'type' => 'hasMany',
                    'model' => $this->toModelName($ref['from_table']),
                    'method' => $this->camel($ref['from_table']), // plural
                    'foreign_key' => $ref['from_column'],
                    'local_key' => $ref['to_column']
                ];
            }
        }
    }

    public function generateModels($outputDir = './app/Models')
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
            echo "Created directory: {$outputDir}\n\n";
        }

        foreach ($this->tables as $tableName => $tableData) {
            $modelName = $this->toModelName($tableName);
            $filename = "{$outputDir}/{$modelName}.php";

            $content = $this->buildModelContent($modelName, $tableName, $tableData);
            file_put_contents($filename, $content);

            echo "✓ Generated: {$filename}\n";
        }

        echo "\n✓ All models generated successfully!\n";
    }

    private function buildModelContent($modelName, $tableName, $tableData)
    {
        $fillable = $this->getFillable($tableData['columns']);
        $casts = $this->getCasts($tableData['columns']);
        $hidden = $this->getHidden($tableData['columns']);
        $timestamps = $this->hasTimestamps($tableData['columns']);
        $softDeletes = $this->hasSoftDeletes($tableData['columns']);

        $code = "<?php\n\n";
        $code .= "namespace App\\Models;\n\n";
        $code .= "use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\n";
        $code .= "use Illuminate\\Database\\Eloquent\\Model;\n";

        if ($softDeletes) {
            $code .= "use Illuminate\\Database\\Eloquent\\SoftDeletes;\n";
        }

        $code .= "\n";
        $code .= "class {$modelName} extends Model\n{\n";
        $code .= "    /** @use HasFactory<\\Database\\Factories\\{$modelName}Factory> */\n";
        $code .= "    use HasFactory";
        if ($softDeletes) {
            $code .= ", SoftDeletes";
        }
        $code .= ";\n\n";

        // Table name
        $expectedTableName = $this->toTableName($modelName);
        if ($tableName !== $expectedTableName) {
            $code .= "    protected \$table = '{$tableName}';\n\n";
        }

        // Primary key (if not 'id')
        $primaryKey = $this->getPrimaryKey($tableData['columns']);
        if ($primaryKey && $primaryKey !== 'id') {
            $code .= "    protected \$primaryKey = '{$primaryKey}';\n\n";
        }

        // Timestamps
        if (!$timestamps) {
            $code .= "    public \$timestamps = false;\n\n";
        }

        // Fillable
        if (!empty($fillable)) {
            $code .= "    protected \$fillable = [\n";
            foreach ($fillable as $field) {
                $code .= "        '{$field}',\n";
            }
            $code .= "    ];\n\n";
        }

        // Hidden
        if (!empty($hidden)) {
            $code .= "    protected \$hidden = [\n";
            foreach ($hidden as $field) {
                $code .= "        '{$field}',\n";
            }
            $code .= "    ];\n\n";
        }

        // Casts
        if (!empty($casts)) {
            $code .= "    protected \$casts = [\n";
            foreach ($casts as $field => $type) {
                $code .= "        '{$field}' => '{$type}',\n";
            }
            $code .= "    ];\n\n";
        }

        // Relationships
        if (!empty($tableData['relationships'])) {
            $addedMethods = [];
            foreach ($tableData['relationships'] as $rel) {
                // Avoid duplicates
                if (in_array($rel['method'], $addedMethods)) {
                    continue;
                }
                $addedMethods[] = $rel['method'];

                $code .= "    /**\n";
                $code .= "     * Get the " . str_replace('_', ' ', $rel['method']) . " that owns/belongs to this {$modelName}.\n";
                $code .= "     */\n";
                $code .= "    public function {$rel['method']}()\n";
                $code .= "    {\n";
                $code .= "        return \$this->{$rel['type']}({$rel['model']}::class";

                // Add foreign key if not following convention
                if ($rel['type'] === 'belongsTo') {
                    $expectedForeignKey = strtolower($rel['model']) . '_id';
                    if (isset($rel['foreign_key']) && $rel['foreign_key'] !== $expectedForeignKey) {
                        $code .= ", '{$rel['foreign_key']}'";
                    }
                    if (isset($rel['owner_key']) && $rel['owner_key'] !== 'id') {
                        if (!isset($rel['foreign_key']) || $rel['foreign_key'] === $expectedForeignKey) {
                            $code .= ", '{$expectedForeignKey}'";
                        }
                        $code .= ", '{$rel['owner_key']}'";
                    }
                } elseif (in_array($rel['type'], ['hasMany', 'hasOne'])) {
                    $expectedForeignKey = strtolower($modelName) . '_id';
                    if (isset($rel['foreign_key']) && $rel['foreign_key'] !== $expectedForeignKey) {
                        $code .= ", '{$rel['foreign_key']}'";
                    }
                    if (isset($rel['local_key']) && $rel['local_key'] !== 'id') {
                        if (!isset($rel['foreign_key']) || $rel['foreign_key'] === $expectedForeignKey) {
                            $code .= ", '{$expectedForeignKey}'";
                        }
                        $code .= ", '{$rel['local_key']}'";
                    }
                }

                $code .= ");\n";
                $code .= "    }\n\n";
            }
        }

        $code .= "}\n";

        return $code;
    }

    private function getFillable($columns)
    {
        $fillable = [];
        $skip = ['id', 'created_at', 'updated_at', 'deleted_at'];

        foreach ($columns as $col) {
            if (!in_array($col['name'], $skip) && !$col['increment']) {
                $fillable[] = $col['name'];
            }
        }

        return $fillable;
    }

    private function getCasts($columns)
    {
        $casts = [];

        foreach ($columns as $col) {
            $type = strtolower($col['type']);

            if (in_array($type, ['boolean', 'bool'])) {
                $casts[$col['name']] = 'boolean';
            } elseif (in_array($type, ['int', 'integer', 'bigint'])) {
                if (!in_array($col['name'], ['id'])) {
                    $casts[$col['name']] = 'integer';
                }
            } elseif (in_array($type, ['json', 'jsonb'])) {
                $casts[$col['name']] = 'array';
            } elseif ($type === 'datetime' || $type === 'timestamp') {
                if (!in_array($col['name'], ['created_at', 'updated_at', 'deleted_at'])) {
                    $casts[$col['name']] = 'datetime';
                }
            } elseif ($type === 'date') {
                $casts[$col['name']] = 'date';
            } elseif (in_array($type, ['decimal', 'float', 'double'])) {
                $casts[$col['name']] = 'decimal:2';
            }
        }

        return $casts;
    }

    private function getHidden($columns)
    {
        $hidden = [];

        foreach ($columns as $col) {
            if (in_array($col['name'], ['password', 'remember_token'])) {
                $hidden[] = $col['name'];
            }
        }

        return $hidden;
    }

    private function hasTimestamps($columns)
    {
        $hasCreated = false;
        $hasUpdated = false;

        foreach ($columns as $col) {
            if ($col['name'] === 'created_at') $hasCreated = true;
            if ($col['name'] === 'updated_at') $hasUpdated = true;
        }

        return $hasCreated && $hasUpdated;
    }

    private function hasSoftDeletes($columns)
    {
        foreach ($columns as $col) {
            if ($col['name'] === 'deleted_at') {
                return true;
            }
        }
        return false;
    }

    private function getPrimaryKey($columns)
    {
        foreach ($columns as $col) {
            if ($col['primary'] && $col['name'] !== 'id') {
                return $col['name'];
            }
        }
        return null;
    }

    private function toModelName($tableName)
    {
        $singular = $this->singular($tableName);
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $singular)));
    }

    private function toTableName($modelName)
    {
        $snake = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $modelName));
        return $this->plural($snake);
    }

    private function singular($word)
    {
        if (substr($word, -3) === 'ies') {
            return substr($word, 0, -3) . 'y';
        }
        if (substr($word, -3) === 'ses') {
            return substr($word, 0, -2);
        }
        if (substr($word, -1) === 's') {
            return substr($word, 0, -1);
        }
        return $word;
    }

    private function plural($word)
    {
        if (substr($word, -1) === 'y') {
            return substr($word, 0, -1) . 'ies';
        }
        if (substr($word, -1) === 's') {
            return $word;
        }
        return $word . 's';
    }

    private function camel($input)
    {
        return lcfirst(str_replace('_', '', ucwords($input, '_')));
    }
}

$filename = $argv[1];
$directory = explode('.', $filename)[0];

$converter = new DbmlToModel();
$converter->parseFile($filename);
$converter->generateModels("./$directory/app/Models");

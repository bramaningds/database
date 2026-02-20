<?php

/**
 * DbmlToLaravel - Unified DBML to Laravel Generator
 *
 * Generates Migration, Model, Factory, and FormRequest files
 * from a single DBML file in one execution.
 *
 * Usage: php dbml-to-laravel.php <file.dbml> [--migration] [--model] [--factory] [--form-request] [--force]
 *        (tanpa flag = generate semua)
 */
class DbmlToLaravel
{
    private $tables = [];
    private $enums = [];
    private $references = [];
    private $forceOverwrite = false;
    private $columnReferences = []; // Map table.column -> target_table

    // ─────────────────────────────────────────────
    //  PARSER
    // ─────────────────────────────────────────────

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
        $currentEnum = null;
        $inTable = false;
        $braceLevel = 0;

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line) || strpos($line, '//') === 0) {
                continue;
            }

            // Parse Enum
            if (preg_match('/^Enum\s+(\w+)\s*\{/', $line, $matches)) {
                $currentEnum = $matches[1];
                $this->enums[$currentEnum] = [];
                continue;
            }

            // Parse Table
            if (preg_match('/^Table\s+(\w+)(?:\s+as\s+(\w+))?\s*\{/', $line, $matches)) {
                $tableName = $matches[1];
                $currentTable = $tableName;
                $this->tables[$tableName] = [
                    'columns' => [],
                    'indexes' => [],
                    'relationships' => [],
                    'notes' => ''
                ];
                $inTable = true;
                echo "  Found table: {$currentTable}\n";
                continue;
            }

            // Track braces
            $braceLevel += substr_count($line, '{') - substr_count($line, '}');

            // Parse enum values
            if ($currentEnum && !$currentTable) {
                if (preg_match('/^(\w+)/', $line, $matches)) {
                    $this->enums[$currentEnum][] = $matches[1];
                }
                if (strpos($line, '}') !== false) {
                    $currentEnum = null;
                }
                continue;
            }

            // Parse columns
            if ($currentTable && $inTable && preg_match('/^(\w+)\s+(\w+)(?:\(([^)]+)\))?(.*)/', $line, $matches)) {
                $colName = $matches[1];
                $colType = $matches[2];
                $params = isset($matches[3]) ? $matches[3] : '';
                $constraints = isset($matches[4]) ? $matches[4] : '';

                // Skip "indexes" keyword
                if ($colName === 'indexes') continue;

                $note = '';
                if (preg_match('/note:\s*[\'"]([^\'"]+)[\'"]/', $constraints, $noteMatch)) {
                    $note = $noteMatch[1];
                }

                $default = null;
                if (preg_match('/default:\s*[`\'"]?([^`\'">\]\s]+)[`\'"]?/i', $constraints, $defaultMatch)) {
                    $default = trim($defaultMatch[1]);
                }

                $column = [
                    'name' => $colName,
                    'type' => $colType,
                    'params' => $params,
                    'nullable' => stripos($constraints, 'null') !== false && stripos($constraints, 'not null') === false,
                    'primary' => stripos($constraints, 'pk') !== false || stripos($constraints, 'primary key') !== false,
                    'unique' => stripos($constraints, 'unique') !== false,
                    'increment' => stripos($constraints, 'increment') !== false,
                    'default' => $default,
                    'note' => $note,
                    'line' => $line
                ];

                $this->tables[$currentTable]['columns'][] = $column;

                // Check for inline reference: [ref: > table.column]
                if (preg_match('/\[.*ref:\s*>\s*(\w+)\.(\w+).*\]/i', $line, $refMatch)) {
                    $this->references[] = [
                        'from_table' => $currentTable,
                        'from_column' => $colName,
                        'to_table' => $refMatch[1],
                        'to_column' => $refMatch[2]
                    ];
                    echo "  Found inline ref: {$currentTable}.{$colName} -> {$refMatch[1]}.{$refMatch[2]}\n";
                }
            }

            // Parse indexes
            if ($currentTable && preg_match('/^\(([^)]+)\)\s*\[(.+)\]/', $line, $matches)) {
                $columns = array_map('trim', explode(',', $matches[1]));
                $indexOptions = $matches[2];

                $index = [
                    'columns' => $columns,
                    'unique' => strpos($indexOptions, 'unique') !== false,
                    'primary' => strpos($indexOptions, 'pk') !== false,
                    'name' => ''
                ];

                if (preg_match('/name:\s*[\'"]([^\'"]+)[\'"]/', $indexOptions, $nameMatch)) {
                    $index['name'] = $nameMatch[1];
                }

                $this->tables[$currentTable]['indexes'][] = $index;
            }

            // Parse standalone references
            if (preg_match('/^Ref:\s*(\w+)\.(\w+)\s*>\s*(\w+)\.(\w+)/', $line, $matches)) {
                $this->references[] = [
                    'from_table' => $matches[1],
                    'from_column' => $matches[2],
                    'to_table' => $matches[3],
                    'to_column' => $matches[4],
                    'type' => '>'
                ];
                echo "  Found ref: {$matches[1]}.{$matches[2]} -> {$matches[3]}.{$matches[4]}\n";
            }

            if (preg_match('/^Ref:\s*(\w+)\.(\w+)\s*<\s*(\w+)\.(\w+)/', $line, $matches)) {
                $this->references[] = [
                    'from_table' => $matches[3],
                    'from_column' => $matches[4],
                    'to_table' => $matches[1],
                    'to_column' => $matches[2],
                    'type' => '>'
                ];
                echo "  Found ref: {$matches[3]}.{$matches[4]} -> {$matches[1]}.{$matches[2]}\n";
            }

            // Close table
            if ($inTable && strpos($line, '}') !== false && $braceLevel <= 0) {
                $inTable = false;
                $currentTable = null;
            }
        }

        echo "\nTotal tables: " . count($this->tables) . "\n";
        echo "Total references: " . count($this->references) . "\n\n";

        $this->buildRelationships();
    }

    private function buildRelationships()
    {
        // Remove duplicate references & build column reference map
        $uniqueRefs = [];
        foreach ($this->references as $ref) {
            $key = "{$ref['from_table']}.{$ref['from_column']}->{$ref['to_table']}.{$ref['to_column']}";
            if (!isset($uniqueRefs[$key])) {
                $uniqueRefs[$key] = $ref;
                // Store mapping: table.column -> target_table
                $this->columnReferences["{$ref['from_table']}.{$ref['from_column']}"] = $ref['to_table'];
            }
        }
        $this->references = array_values($uniqueRefs);

        foreach ($this->references as $ref) {
            // belongsTo (many to one)
            if (isset($this->tables[$ref['from_table']])) {
                $this->tables[$ref['from_table']]['relationships'][] = [
                    'type' => 'belongsTo',
                    'model' => $this->toModelName($ref['to_table']),
                    'method' => $this->camelCase($this->singular($ref['to_table'])),
                    'foreign_key' => $ref['from_column'],
                    'owner_key' => $ref['to_column']
                ];
            }

            // hasMany (one to many)
            if (isset($this->tables[$ref['to_table']])) {
                $this->tables[$ref['to_table']]['relationships'][] = [
                    'type' => 'hasMany',
                    'model' => $this->toModelName($ref['from_table']),
                    'method' => $this->camelCase($ref['from_table']),
                    'foreign_key' => $ref['from_column'],
                    'local_key' => $ref['to_column']
                ];
            }
        }
    }

    public function setForceOverwrite($force = true)
    {
        $this->forceOverwrite = $force;
    }

    // ─────────────────────────────────────────────
    //  GENERATE ALL
    // ─────────────────────────────────────────────

    public function generateAll($baseDir)
    {
        echo "==========================================\n";
        echo " Generating Laravel files...\n";
        echo "==========================================\n\n";

        $this->generateMigrations("{$baseDir}/database/migrations");
        echo "\n";
        $this->generateModels("{$baseDir}/app/Models");
        echo "\n";
        $this->generateFactories("{$baseDir}/database/factories");
        echo "\n";
        $this->generateFormRequests("{$baseDir}/app/Http/Requests");

        echo "\n==========================================\n";
        echo " All files generated successfully!\n";
        echo "==========================================\n";
    }

    // ─────────────────────────────────────────────
    //  1. MIGRATION GENERATOR
    // ─────────────────────────────────────────────

    public function generateMigrations($outputDir = './migrations')
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        echo "[Migration]\n";

        $counter = 0;

        foreach ($this->tables as $tableName => $tableData) {
            $counter++;
            $migrationTimestamp = date('Y_m_d_His', strtotime("+$counter seconds"));
            $className = 'Create' . $this->toPascalCase($tableName) . 'Table';
            $filename = $outputDir . '/' . $migrationTimestamp . '_create_' . $tableName . '_table.php';

            if (file_exists($filename) && !$this->forceOverwrite) {
                echo "  ⊘ {$filename} (exists)\n";
            } else {
                $content = $this->buildMigrationContent($className, $tableName, $tableData);
                file_put_contents($filename, $content);
                echo "  ✓ {$filename}\n";
            }
        }

        // Generate foreign keys migration
        if (!empty($this->references)) {
            $counter++;
            $migrationTimestamp = date('Y_m_d_His', strtotime("+$counter seconds"));
            $filename = $outputDir . '/' . $migrationTimestamp . '_add_foreign_keys.php';
            if (file_exists($filename) && !$this->forceOverwrite) {
                echo "  ⊘ {$filename} (exists)\n";
            } else {
                $content = $this->buildForeignKeysMigration();
                file_put_contents($filename, $content);
                echo "  ✓ {$filename}\n";
            }
        }
    }

    private function buildMigrationContent($className, $tableName, $tableData)
    {
        $columns = $this->buildMigrationColumns($tableData['columns']);
        $indexes = $this->buildMigrationIndexes($tableData['indexes'] ?? []);

        return "<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('{$tableName}', function (Blueprint \$table) {
{$columns}{$indexes}
        });
    }

    public function down()
    {
        Schema::dropIfExists('{$tableName}');
    }
};
";
    }

    private function buildMigrationColumns($columns)
    {
        $result = '';
        $hasCreatedAt = false;
        $hasUpdatedAt = false;
        $hasDeletedAt = false;

        foreach ($columns as $col) {
            if ($col['name'] === 'created_at' && $col['type'] === 'timestamp') $hasCreatedAt = true;
            if ($col['name'] === 'updated_at' && $col['type'] === 'timestamp') $hasUpdatedAt = true;
            if ($col['name'] === 'deleted_at' && $col['type'] === 'timestamp' && $col['nullable']) $hasDeletedAt = true;
        }

        foreach ($columns as $col) {
            // Skip timestamps
            if (($col['name'] === 'created_at' || $col['name'] === 'updated_at') && $col['type'] === 'timestamp') continue;
            if ($col['name'] === 'deleted_at' && $col['type'] === 'timestamp' && $col['nullable']) continue;

            $line = '            $table->';

            $laravelType = $this->mapColumnType($col['type'], $col['params']);
            $line .= $laravelType . "('" . $col['name'] . "'";

            if (in_array($col['type'], ['varchar', 'char', 'decimal']) && !empty($col['params'])) {
                $params = explode(',', $col['params']);
                foreach ($params as $param) {
                    $line .= ', ' . trim($param);
                }
            }

            $line .= ')';

            if ($col['increment']) {
                $line = '            $table->id()';
            }

            if ($col['nullable']) $line .= '->nullable()';
            if ($col['unique']) $line .= '->unique()';

            if ($col['default'] !== null) {
                $def = $col['default'];
                if (in_array(strtolower($def), ['true', 'false'])) {
                    $line .= '->default(' . strtolower($def) . ')';
                } elseif (is_numeric($def)) {
                    $line .= '->default(' . $def . ')';
                } elseif (in_array(strtolower($def), ['now()', 'current_timestamp'])) {
                    $line .= '->useCurrent()';
                } else {
                    $line .= "->default('" . addslashes($def) . "')";
                }
            }

            if (!empty($col['note'])) {
                $line .= "->comment('" . addslashes($col['note']) . "')";
            }

            $line .= ";\n";
            $result .= $line;
        }

        if ($hasCreatedAt && $hasUpdatedAt) $result .= "            \$table->timestamps();\n";
        if ($hasDeletedAt) $result .= "            \$table->softDeletes();\n";

        return $result;
    }

    private function buildMigrationIndexes($indexes)
    {
        $result = '';

        foreach ($indexes as $index) {
            $columns = "['" . implode("', '", $index['columns']) . "']";
            if ($index['primary']) {
                $result .= "            \$table->primary({$columns});\n";
            } elseif ($index['unique']) {
                $name = !empty($index['name']) ? ", '{$index['name']}'" : '';
                $result .= "            \$table->unique({$columns}{$name});\n";
            } else {
                $name = !empty($index['name']) ? ", '{$index['name']}'" : '';
                $result .= "            \$table->index({$columns}{$name});\n";
            }
        }

        return $result;
    }

    private function buildForeignKeysMigration()
    {
        $up = '';
        $down = '';

        foreach ($this->references as $ref) {
            $up .= "        Schema::table('{$ref['from_table']}', function (Blueprint \$table) {\n";
            $up .= "            \$table->foreign('{$ref['from_column']}')->references('{$ref['to_column']}')->on('{$ref['to_table']}')->onDelete('cascade');\n";
            $up .= "        });\n\n";

            $down .= "        Schema::table('{$ref['from_table']}', function (Blueprint \$table) {\n";
            $down .= "            \$table->dropForeign(['{$ref['from_column']}']);\n";
            $down .= "        });\n\n";
        }

        return "<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up()
    {
{$up}    }

    public function down()
    {
{$down}    }
};
";
    }

    // ─────────────────────────────────────────────
    //  2. MODEL GENERATOR
    // ─────────────────────────────────────────────

    public function generateModels($outputDir = './app/Models')
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        echo "[Model]\n";

        foreach ($this->tables as $tableName => $tableData) {
            $modelName = $this->toModelName($tableName);
            $filename = "{$outputDir}/{$modelName}.php";

            if (file_exists($filename) && !$this->forceOverwrite) {
                echo "  ⊘ {$filename} (exists)\n";
            } else {
                $content = $this->buildModelContent($modelName, $tableName, $tableData);
                file_put_contents($filename, $content);
                echo "  ✓ {$filename}\n";
            }
        }
    }

    private function buildModelContent($modelName, $tableName, $tableData)
    {
        $columns = $tableData['columns'];
        $fillable = $this->getModelFillable($columns);
        $casts = $this->getModelCasts($columns);
        $hidden = $this->getModelHidden($columns);
        $timestamps = $this->hasTimestamps($columns);
        $softDeletes = $this->hasSoftDeletes($columns);

        $code = "<?php\n\n";
        $code .= "namespace App\\Models;\n\n";
        $code .= "use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\n";
        $code .= "use Illuminate\\Database\\Eloquent\\Model;\n";

        if ($softDeletes) {
            $code .= "use Illuminate\\Database\\Eloquent\\SoftDeletes;\n";
        }

        $code .= "\nclass {$modelName} extends Model\n{\n";
        $code .= "    /** @use HasFactory<\\Database\\Factories\\{$modelName}Factory> */\n";
        $code .= "    use HasFactory";
        if ($softDeletes) $code .= ", SoftDeletes";
        $code .= ";\n\n";

        // Table name
        $expectedTable = $this->toSnakePlural($modelName);
        if ($tableName !== $expectedTable) {
            $code .= "    protected \$table = '{$tableName}';\n\n";
        }

        // Primary key
        $pk = $this->getPrimaryKey($columns);
        if ($pk && $pk !== 'id') {
            $code .= "    protected \$primaryKey = '{$pk}';\n\n";
        }

        if (!$timestamps) {
            $code .= "    public \$timestamps = false;\n\n";
        }

        // Fillable
        if (!empty($fillable)) {
            $code .= "    protected \$fillable = [\n";
            foreach ($fillable as $f) $code .= "        '{$f}',\n";
            $code .= "    ];\n\n";
        }

        // Hidden
        if (!empty($hidden)) {
            $code .= "    protected \$hidden = [\n";
            foreach ($hidden as $h) $code .= "        '{$h}',\n";
            $code .= "    ];\n\n";
        }

        // Casts
        if (!empty($casts)) {
            $code .= "    protected \$casts = [\n";
            foreach ($casts as $field => $type) $code .= "        '{$field}' => '{$type}',\n";
            $code .= "    ];\n\n";
        }

        // Relationships
        if (!empty($tableData['relationships'])) {
            $addedMethods = [];
            foreach ($tableData['relationships'] as $rel) {
                if (in_array($rel['method'], $addedMethods)) continue;
                $addedMethods[] = $rel['method'];

                $code .= "    public function {$rel['method']}()\n";
                $code .= "    {\n";
                $code .= "        return \$this->{$rel['type']}({$rel['model']}::class";

                if ($rel['type'] === 'belongsTo') {
                    $expectedFK = strtolower($rel['model']) . '_id';
                    if (isset($rel['foreign_key']) && $rel['foreign_key'] !== $expectedFK) {
                        $code .= ", '{$rel['foreign_key']}'";
                    }
                    if (isset($rel['owner_key']) && $rel['owner_key'] !== 'id') {
                        if (!isset($rel['foreign_key']) || $rel['foreign_key'] === $expectedFK) {
                            $code .= ", '{$expectedFK}'";
                        }
                        $code .= ", '{$rel['owner_key']}'";
                    }
                } elseif (in_array($rel['type'], ['hasMany', 'hasOne'])) {
                    $expectedFK = strtolower($modelName) . '_id';
                    if (isset($rel['foreign_key']) && $rel['foreign_key'] !== $expectedFK) {
                        $code .= ", '{$rel['foreign_key']}'";
                    }
                    if (isset($rel['local_key']) && $rel['local_key'] !== 'id') {
                        if (!isset($rel['foreign_key']) || $rel['foreign_key'] === $expectedFK) {
                            $code .= ", '{$expectedFK}'";
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

    private function getModelFillable($columns)
    {
        $skip = ['id', 'created_at', 'updated_at', 'deleted_at'];
        $fillable = [];
        foreach ($columns as $col) {
            if (!in_array($col['name'], $skip) && !$col['increment']) {
                $fillable[] = $col['name'];
            }
        }
        return $fillable;
    }

    private function getModelCasts($columns)
    {
        $casts = [];
        foreach ($columns as $col) {
            $type = strtolower($col['type']);
            if (in_array($type, ['boolean', 'bool'])) {
                $casts[$col['name']] = 'boolean';
            } elseif (in_array($type, ['int', 'integer', 'bigint']) && $col['name'] !== 'id') {
                $casts[$col['name']] = 'integer';
            } elseif (in_array($type, ['json', 'jsonb'])) {
                $casts[$col['name']] = 'array';
            } elseif (in_array($type, ['datetime', 'timestamp']) && !in_array($col['name'], ['created_at', 'updated_at', 'deleted_at'])) {
                $casts[$col['name']] = 'datetime';
            } elseif ($type === 'date') {
                $casts[$col['name']] = 'date';
            } elseif (in_array($type, ['decimal', 'float', 'double'])) {
                $casts[$col['name']] = 'decimal:2';
            }
        }
        return $casts;
    }

    private function getModelHidden($columns)
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
        $c = $u = false;
        foreach ($columns as $col) {
            if ($col['name'] === 'created_at') $c = true;
            if ($col['name'] === 'updated_at') $u = true;
        }
        return $c && $u;
    }

    private function hasSoftDeletes($columns)
    {
        foreach ($columns as $col) {
            if ($col['name'] === 'deleted_at') return true;
        }
        return false;
    }

    private function getPrimaryKey($columns)
    {
        foreach ($columns as $col) {
            if ($col['primary'] && $col['name'] !== 'id') return $col['name'];
        }
        return null;
    }

    // ─────────────────────────────────────────────
    //  3. FACTORY GENERATOR
    // ─────────────────────────────────────────────

    public function generateFactories($outputDir = './database/factories')
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        echo "[Factory]\n";

        foreach ($this->tables as $tableName => $tableData) {
            $modelName = $this->toModelName($tableName);
            $factoryName = $modelName . 'Factory';
            $filename = "{$outputDir}/{$factoryName}.php";

            if (file_exists($filename) && !$this->forceOverwrite) {
                echo "  ⊘ {$filename} (exists)\n";
            } else {
                $content = $this->buildFactoryContent($factoryName, $modelName, $tableData, $tableName);
                file_put_contents($filename, $content);
                echo "  ✓ {$filename}\n";
            }
        }
    }

    private function buildFactoryContent($factoryName, $modelName, $tableData, $tableName = '')
    {
        $definitions = $this->buildFactoryDefinitions($tableData['columns'], $tableName);
        $states = $this->buildFactoryStates($modelName, $tableData['columns']);

        $code = "<?php\n\n";
        $code .= "namespace Database\\Factories;\n\n";
        $code .= "use App\\Models\\{$modelName};\n";
        $code .= "use Illuminate\\Database\\Eloquent\\Factories\\Factory;\n";
        $code .= "use Illuminate\\Support\\Str;\n\n";
        $code .= "/**\n * @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\App\\Models\\{$modelName}>\n */\n";
        $code .= "class {$factoryName} extends Factory\n{\n";
        $code .= "    protected \$model = {$modelName}::class;\n\n";
        $code .= "    public function definition(): array\n";
        $code .= "    {\n";
        $code .= "        return [\n";
        $code .= $definitions;
        $code .= "        ];\n";
        $code .= "    }\n";

        if (!empty($states)) {
            $code .= "\n" . $states;
        }

        $code .= "}\n";

        return $code;
    }

    private function buildFactoryDefinitions($columns, $tableName = '')
    {
        $defs = '';
        $skip = ['id', 'created_at', 'updated_at', 'deleted_at'];

        foreach ($columns as $col) {
            if (in_array($col['name'], $skip) || $col['increment']) continue;
            $faker = $this->getFakerMethod($col, $tableName);
            $defs .= "            '{$col['name']}' => {$faker},\n";
        }

        return $defs;
    }

    private function getFakerMethod($col, $tableName = '')
    {
        $name = $col['name'];
        $type = strtolower($col['type']);
        $note = strtolower($col['note'] ?? '');

        // By column name
        if ($name === 'email' || strpos($name, 'email') !== false) return "fake()->unique()->safeEmail()";
        if ($name === 'password') return "bcrypt('password')";
        if ($name === 'remember_token') return "Str::random(10)";
        if (strpos($name, 'first_name') !== false || $name === 'firstname') return "fake()->firstName()";
        if (strpos($name, 'last_name') !== false || $name === 'lastname') return "fake()->lastName()";
        if ($name === 'name' || strpos($name, 'name') !== false) return "fake()->name()";
        if ($name === 'username' || $name === 'user_name') return "fake()->unique()->userName()";
        if (strpos($name, 'phone') !== false || strpos($name, 'mobile') !== false) return "fake()->phoneNumber()";
        if (strpos($name, 'address') !== false) return "fake()->address()";
        if ($name === 'city' || strpos($name, 'city') !== false) return "fake()->city()";
        if ($name === 'country' || strpos($name, 'country') !== false) return "fake()->country()";
        if ($name === 'postal_code' || $name === 'zip_code' || $name === 'zipcode') return "fake()->postcode()";
        if (strpos($name, 'company') !== false) return "fake()->company()";
        if ($name === 'title' || strpos($name, 'title') !== false) return "fake()->sentence()";
        if ($name === 'slug') return "fake()->unique()->slug()";
        if ($name === 'description' || strpos($name, 'description') !== false) return "fake()->paragraph()";
        if ($name === 'content' || $name === 'body' || strpos($name, 'content') !== false) return "fake()->paragraphs(3, true)";
        if (strpos($name, 'url') !== false || strpos($name, 'link') !== false) return "fake()->url()";
        if (strpos($name, 'image') !== false || strpos($name, 'avatar') !== false || strpos($name, 'photo') !== false) return "fake()->imageUrl(640, 480)";
        if (strpos($name, 'color') !== false || strpos($name, 'colour') !== false) return "fake()->hexColor()";
        if (strpos($name, 'ip') !== false) return "fake()->ipv4()";
        if (strpos($name, 'uuid') !== false) return "fake()->uuid()";
        if (strpos($name, 'latitude') !== false || $name === 'lat') return "fake()->latitude()";
        if (strpos($name, 'longitude') !== false || $name === 'lng' || $name === 'lon') return "fake()->longitude()";
        if (strpos($name, 'price') !== false || strpos($name, 'amount') !== false || strpos($name, 'cost') !== false) return "fake()->randomFloat(2, 0, 1000)";
        if (strpos($name, 'count') !== false || strpos($name, 'total') !== false || strpos($name, 'quantity') !== false) return "fake()->numberBetween(0, 100)";
        if (strpos($name, 'rating') !== false || strpos($name, 'score') !== false) return "fake()->numberBetween(1, 5)";
        if (strpos($name, 'published') !== false || strpos($name, 'active') !== false || strpos($name, 'enabled') !== false) return "fake()->boolean()";
        if (strpos($name, 'verified') !== false) return "fake()->boolean(80)";
        if (strpos($name, '_at') !== false && $type === 'timestamp') return "fake()->dateTime()";
        if (strpos($name, '_date') !== false || $name === 'date') return "fake()->date()";
        if (strpos($name, 'birth') !== false) return "fake()->date('Y-m-d', '-18 years')";

        // Foreign key - check columnReferences first
        if (strpos($name, '_id') !== false && $name !== 'id') {
            $colKey = "{$tableName}.{$name}";
            if (isset($this->columnReferences[$colKey])) {
                // Use actual referenced table from DBML
                $relatedTable = $this->columnReferences[$colKey];
                $model = $this->toModelName($relatedTable);
                return "{$model}::factory()";
            } else {
                // Fallback to old behavior if no reference found
                $related = str_replace('_id', '', $name);
                $model = $this->toModelName($related);
                return "{$model}::factory()";
            }
        }

        // By type
        if (in_array($type, ['boolean', 'bool'])) return "fake()->boolean()";
        if (in_array($type, ['int', 'integer', 'bigint', 'smallint', 'tinyint'])) return "fake()->numberBetween(1, 100)";
        if (in_array($type, ['decimal', 'float', 'double'])) return "fake()->randomFloat(2, 0, 1000)";
        if (in_array($type, ['text', 'longtext', 'mediumtext'])) return "fake()->text()";
        if ($type === 'date') return "fake()->date()";
        if (in_array($type, ['datetime', 'timestamp'])) return "fake()->dateTime()";
        if ($type === 'time') return "fake()->time()";
        if (in_array($type, ['json', 'jsonb'])) return "json_encode(['key' => 'value'])";
        if ($type === 'uuid') return "fake()->uuid()";

        if (in_array($type, ['varchar', 'char'])) {
            $length = !empty($col['params']) ? intval($col['params']) : 255;
            if ($length <= 50) return "fake()->words(3, true)";
            if ($length <= 100) return "fake()->sentence()";
            return "fake()->sentence(10)";
        }

        return "fake()->word()";
    }

    private function buildFactoryStates($modelName, $columns)
    {
        $states = '';

        foreach ($columns as $col) {
            if ($col['name'] === 'published' && in_array(strtolower($col['type']), ['boolean', 'bool'])) {
                $states .= "    public function published(): static\n    {\n        return \$this->state(fn (array \$attributes) => ['published' => true]);\n    }\n\n";
                $states .= "    public function unpublished(): static\n    {\n        return \$this->state(fn (array \$attributes) => ['published' => false]);\n    }\n\n";
            }
            if ($col['name'] === 'is_active' && in_array(strtolower($col['type']), ['boolean', 'bool'])) {
                $states .= "    public function active(): static\n    {\n        return \$this->state(fn (array \$attributes) => ['is_active' => true]);\n    }\n\n";
                $states .= "    public function inactive(): static\n    {\n        return \$this->state(fn (array \$attributes) => ['is_active' => false]);\n    }\n\n";
            }
        }

        return $states;
    }

    // ─────────────────────────────────────────────
    //  4. FORM REQUEST GENERATOR
    // ─────────────────────────────────────────────

    public function generateFormRequests($outputDir = './app/Http/Requests')
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        echo "[FormRequest]\n";

        foreach ($this->tables as $tableName => $tableData) {
            $modelName = $this->toModelName($tableName);

            // Store Request
            $storeName = "Store{$modelName}Request";
            $storeFile = "{$outputDir}/{$storeName}.php";
            if (file_exists($storeFile) && !$this->forceOverwrite) {
                echo "  ⊘ {$storeFile} (exists)\n";
            } else {
                file_put_contents($storeFile, $this->buildFormRequestContent($storeName, $modelName, $tableData, 'store'));
                echo "  ✓ {$storeFile}\n";
            }

            // Update Request
            $updateName = "Update{$modelName}Request";
            $updateFile = "{$outputDir}/{$updateName}.php";
            if (file_exists($updateFile) && !$this->forceOverwrite) {
                echo "  ⊘ {$updateFile} (exists)\n";
            } else {
                file_put_contents($updateFile, $this->buildFormRequestContent($updateName, $modelName, $tableData, 'update'));
                echo "  ✓ {$updateFile}\n";
            }
        }
    }

    private function buildFormRequestContent($requestName, $modelName, $tableData, $action)
    {
        $rules = $this->buildValidationRules($tableData['columns'], $action, $modelName);
        $messages = $this->buildValidationMessages($tableData['columns']);
        $attributes = $this->buildValidationAttributes($tableData['columns']);

        $code = "<?php\n\n";
        $code .= "namespace App\\Http\\Requests;\n\n";
        $code .= "use Illuminate\\Foundation\\Http\\FormRequest;\n";

        if ($this->needsRuleClass($tableData['columns'])) {
            $code .= "use Illuminate\\Validation\\Rule;\n";
        }

        $code .= "\nclass {$requestName} extends FormRequest\n{\n";
        $code .= "    public function authorize(): bool\n";
        $code .= "    {\n        return true;\n    }\n\n";

        $code .= "    /**\n     * @return array<string, \\Illuminate\\Contracts\\Validation\\ValidationRule|array<mixed>|string>\n     */\n";
        $code .= "    public function rules(): array\n";
        $code .= "    {\n        return [\n";
        $code .= $rules;
        $code .= "        ];\n    }\n";

        if (!empty($messages)) {
            $code .= "\n    public function messages(): array\n    {\n        return [\n";
            $code .= $messages;
            $code .= "        ];\n    }\n";
        }

        if (!empty($attributes)) {
            $code .= "\n    public function attributes(): array\n    {\n        return [\n";
            $code .= $attributes;
            $code .= "        ];\n    }\n";
        }

        $code .= "}\n";

        return $code;
    }

    private function buildValidationRules($columns, $action, $modelName)
    {
        $result = '';
        $skip = ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token'];

        foreach ($columns as $col) {
            if (in_array($col['name'], $skip) || $col['increment']) continue;

            $rules = [];
            $name = $col['name'];
            $type = strtolower($col['type']);

            // Required or nullable
            if (!$col['nullable'] && $col['default'] === null) {
                $rules[] = "'required'";
            } else {
                $rules[] = "'nullable'";
            }

            // Type-based rules
            if ($name === 'email' || strpos($name, 'email') !== false) {
                $rules[] = "'email'";
                $rules[] = "'max:255'";
            } elseif ($name === 'password') {
                if ($action === 'store') {
                    $rules[] = "'string'";
                    $rules[] = "'min:8'";
                    $rules[] = "'confirmed'";
                } else {
                    $rules = ["'nullable'", "'string'", "'min:8'", "'confirmed'"];
                }
            } elseif (in_array($type, ['boolean', 'bool'])) {
                $rules[] = "'boolean'";
            } elseif (in_array($type, ['int', 'integer', 'bigint', 'smallint', 'tinyint'])) {
                $rules[] = "'integer'";
                if (strpos($name, 'count') !== false || strpos($name, 'quantity') !== false) $rules[] = "'min:0'";
            } elseif (in_array($type, ['decimal', 'float', 'double'])) {
                $rules[] = "'numeric'";
                if (strpos($name, 'price') !== false || strpos($name, 'amount') !== false) $rules[] = "'min:0'";
            } elseif ($type === 'date') {
                $rules[] = "'date'";
            } elseif (in_array($type, ['datetime', 'timestamp'])) {
                $rules[] = "'date'";
            } elseif (in_array($type, ['json', 'jsonb'])) {
                $rules[] = "'array'";
            } elseif (in_array($type, ['text', 'longtext', 'mediumtext'])) {
                $rules[] = "'string'";
            } elseif (in_array($type, ['varchar', 'char'])) {
                $rules[] = "'string'";
                $length = !empty($col['params']) ? intval($col['params']) : 255;
                $rules[] = "'max:{$length}'";
            } else {
                $rules[] = "'string'";
            }

            // Unique
            if ($col['unique']) {
                $table = $this->toSnakePlural($modelName);
                if ($action === 'update') {
                    $rules[] = "Rule::unique('{$table}')->ignore(\$this->route('" . strtolower($modelName) . "'))";
                } else {
                    $rules[] = "'unique:{$table},{$name}'";
                }
            }

            // Foreign key
            if (strpos($name, '_id') !== false && $name !== 'id') {
                $relatedTable = $this->plural(str_replace('_id', '', $name));
                $rules[] = "'exists:{$relatedTable},id'";
            }

            // Special name-based rules
            if (strpos($name, 'url') !== false || strpos($name, 'website') !== false || strpos($name, 'link') !== false) $rules[] = "'url'";
            if (strpos($name, 'phone') !== false || strpos($name, 'mobile') !== false) $rules[] = "'regex:/^([0-9\\s\\-\\+\\(\\)]*)$/'";
            if ($name === 'slug') $rules[] = "'alpha_dash'";
            if (strpos($name, 'ip') !== false) $rules[] = "'ip'";
            if ($name === 'uuid') $rules[] = "'uuid'";

            if (strpos($name, 'image') !== false || strpos($name, 'photo') !== false || strpos($name, 'avatar') !== false) {
                $rules = array_filter($rules, function ($r) { return $r !== "'required'"; });
                array_unshift($rules, "'nullable'");
                $rules[] = "'image'";
                $rules[] = "'mimes:jpeg,png,jpg,gif'";
                $rules[] = "'max:2048'";
            }

            if (strpos($name, 'file') !== false || strpos($name, 'document') !== false) {
                $rules = array_filter($rules, function ($r) { return $r !== "'required'"; });
                array_unshift($rules, "'nullable'");
                $rules[] = "'file'";
                $rules[] = "'max:5120'";
            }

            $result .= "            '{$name}' => [" . implode(', ', $rules) . "],\n";
        }

        return $result;
    }

    private function buildValidationMessages($columns)
    {
        $msgs = '';
        foreach ($columns as $col) {
            $name = $col['name'];
            if ($name === 'email') {
                $msgs .= "            '{$name}.required' => 'Email address is required.',\n";
                $msgs .= "            '{$name}.email' => 'Please provide a valid email address.',\n";
                $msgs .= "            '{$name}.unique' => 'This email is already registered.',\n";
            }
            if ($name === 'password') {
                $msgs .= "            '{$name}.required' => 'Password is required.',\n";
                $msgs .= "            '{$name}.min' => 'Password must be at least 8 characters.',\n";
                $msgs .= "            '{$name}.confirmed' => 'Password confirmation does not match.',\n";
            }
            if (strpos($name, '_id') !== false && $name !== 'id') {
                $fieldName = str_replace('_id', '', $name);
                $msgs .= "            '{$name}.exists' => 'The selected " . str_replace('_', ' ', $fieldName) . " is invalid.',\n";
            }
        }
        return $msgs;
    }

    private function buildValidationAttributes($columns)
    {
        $attrs = '';
        foreach ($columns as $col) {
            if (strpos($col['name'], '_') !== false) {
                $attrs .= "            '{$col['name']}' => '" . str_replace('_', ' ', $col['name']) . "',\n";
            }
        }
        return $attrs;
    }

    private function needsRuleClass($columns)
    {
        foreach ($columns as $col) {
            if ($col['unique']) return true;
        }
        return false;
    }

    // ─────────────────────────────────────────────
    //  SHARED HELPERS
    // ─────────────────────────────────────────────

    private function mapColumnType($dbmlType, $params = '')
    {
        $map = [
            'int' => 'integer', 'integer' => 'integer',
            'bigint' => 'bigInteger', 'smallint' => 'smallInteger', 'tinyint' => 'tinyInteger',
            'varchar' => 'string', 'char' => 'char',
            'text' => 'text', 'longtext' => 'longText', 'mediumtext' => 'mediumText',
            'datetime' => 'dateTime', 'timestamp' => 'timestamp', 'date' => 'date', 'time' => 'time',
            'boolean' => 'boolean', 'bool' => 'boolean',
            'decimal' => 'decimal', 'float' => 'float', 'double' => 'double',
            'json' => 'json', 'jsonb' => 'jsonb',
            'uuid' => 'uuid', 'enum' => 'enum'
        ];

        return $map[strtolower($dbmlType)] ?? 'string';
    }

    private function toModelName($tableName)
    {
        $singular = $this->singular($tableName);
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $singular)));
    }

    private function toPascalCase($string)
    {
        return str_replace('_', '', ucwords($string, '_'));
    }

    private function toSnakePlural($modelName)
    {
        $snake = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $modelName));
        return $this->plural($snake);
    }

    private function camelCase($input)
    {
        return lcfirst(str_replace('_', '', ucwords($input, '_')));
    }

    private function singular($word)
    {
        if (substr($word, -3) === 'ies') return substr($word, 0, -3) . 'y';
        if (substr($word, -3) === 'ses') return substr($word, 0, -2);
        if (substr($word, -1) === 's') return substr($word, 0, -1);
        return $word;
    }

    private function plural($word)
    {
        if (substr($word, -1) === 'y') return substr($word, 0, -1) . 'ies';
        if (substr($word, -1) === 's') return $word;
        return $word . 's';
    }
}

// ─────────────────────────────────────────────
//  CLI ENTRY POINT
// ─────────────────────────────────────────────

if (php_sapi_name() === 'cli') {
    if ($argc < 2) {
        echo "Usage: php dbml-to-laravel.php <file.dbml> [options]\n\n";
        echo "Options:\n";
        echo "  --output=<dir>    Destination folder (default: folder where .dbml file is)\n";
        echo "  --migration       Generate migrations only\n";
        echo "  --model           Generate models only\n";
        echo "  --factory         Generate factories only\n";
        echo "  --form-request    Generate form requests only\n";
        echo "  --force           Overwrite existing files\n";
        echo "  (no flags)        Generate ALL files\n\n";
        echo "Example:\n";
        echo "  php dbml-to-laravel.php sale.dbml\n";
        echo "  php dbml-to-laravel.php sale.dbml --output=./my-project\n";
        echo "  php dbml-to-laravel.php sale.dbml --output=./my-project --migration --model --force\n";
        exit(1);
    }

    $filename = $argv[1];
    
    // Get the directory where the DBML file is located
    if (is_file($filename)) {
        $dbmlDir = dirname(realpath($filename));
    } else {
        // Fallback if file not found
        $dbmlDir = getcwd();
    }
    $baseName = pathinfo($filename, PATHINFO_FILENAME);

    // Parse flags
    $flags = array_slice($argv, 2);
    $outputDir = null;
    $generatorFlags = [];
    $forceOverwrite = false;

    foreach ($flags as $flag) {
        if (strpos($flag, '--output=') === 0) {
            $outputDir = substr($flag, 9);
        } elseif ($flag === '--force') {
            $forceOverwrite = true;
        } else {
            $generatorFlags[] = $flag;
        }
    }

    // Default: <dbml_file_directory>/<dbml_name>
    if ($outputDir === null) {
        $outputDir = $dbmlDir . DIRECTORY_SEPARATOR . $baseName;
    } else {
        // Resolve relative output path to absolute
        if (!preg_match('/^([a-zA-Z]:|\\\\|\/)/i', $outputDir)) {
            $outputDir = getcwd() . DIRECTORY_SEPARATOR . $outputDir;
        }
    }

    // Normalize path: standardize separators, resolve .. and remove duplicates
    $outputDir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $outputDir);
    $outputDir = rtrim(preg_replace('#' . preg_quote(DIRECTORY_SEPARATOR, '#') . '+#', DIRECTORY_SEPARATOR, $outputDir), DIRECTORY_SEPARATOR);
    
    // Resolve .. and . in path
    $parts = explode(DIRECTORY_SEPARATOR, $outputDir);
    $resolved = [];
    foreach ($parts as $part) {
        if ($part === '..') {
            array_pop($resolved);
        } elseif ($part !== '.' && $part !== '') {
            $resolved[] = $part;
        }
    }
    $outputDir = implode(DIRECTORY_SEPARATOR, $resolved);

    $generateAll = empty($generatorFlags);

    $converter = new DbmlToLaravel();
    $converter->setForceOverwrite($forceOverwrite);
    $converter->parseFile($filename);

    echo "Output: {$outputDir}\n\n";

    if ($generateAll) {
        $converter->generateAll($outputDir);
    } else {
        echo "==========================================\n";
        echo " Generating selected Laravel files...\n";
        echo "==========================================\n\n";

        if (in_array('--migration', $generatorFlags)) {
            $converter->generateMigrations("{$outputDir}/database/migrations");
            echo "\n";
        }
        if (in_array('--model', $generatorFlags)) {
            $converter->generateModels("{$outputDir}/app/Models");
            echo "\n";
        }
        if (in_array('--factory', $generatorFlags)) {
            $converter->generateFactories("{$outputDir}/database/factories");
            echo "\n";
        }
        if (in_array('--form-request', $generatorFlags)) {
            $converter->generateFormRequests("{$outputDir}/app/Http/Requests");
            echo "\n";
        }

        echo "==========================================\n";
        echo " Done!\n";
        echo "==========================================\n";
    }
}
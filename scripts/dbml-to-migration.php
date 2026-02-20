<?php

class DbmlToLaravelMigration
{
    private $tables = [];
    private $enums = [];
    private $references = [];

    public function parse($dbmlContent)
    {
        $lines = explode("\n", $dbmlContent);
        $currentTable = null;
        $currentEnum = null;
        $braceLevel = 0;

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and empty lines
            if (empty($line) || strpos($line, '//') === 0) {
                continue;
            }

            // Parse Enum
            if (preg_match('/^Enum\s+(\w+)\s*{/', $line, $matches)) {
                $currentEnum = $matches[1];
                $this->enums[$currentEnum] = [];
                continue;
            }

            // Parse Table
            if (preg_match('/^Table\s+(\w+)(?:\s+as\s+(\w+))?\s*{/', $line, $matches)) {
                $tableName = $matches[1];
                $currentTable = $tableName;
                $this->tables[$tableName] = [
                    'columns' => [],
                    'indexes' => [],
                    'notes' => ''
                ];
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
            if ($currentTable && preg_match('/^(\w+)\s+(\w+)(?:\(([^)]+)\))?(.*)/', $line, $matches)) {
                $columnName = $matches[1];
                $columnType = $matches[2];
                $typeParams = isset($matches[3]) ? $matches[3] : '';
                $constraints = isset($matches[4]) ? $matches[4] : '';

                $column = [
                    'name' => $columnName,
                    'type' => $columnType,
                    'params' => $typeParams,
                    'nullable' => strpos($constraints, 'null') !== false && strpos($constraints, 'not null') === false,
                    'primary' => strpos($constraints, 'primary key') !== false || strpos($constraints, 'pk') !== false,
                    'unique' => strpos($constraints, 'unique') !== false,
                    'increment' => strpos($constraints, 'increment') !== false,
                    'default' => null,
                    'note' => ''
                ];

                // Parse default value
                if (preg_match('/default:\s*[`\'"]?([^`\'">\]]+)[`\'"]?/', $constraints, $defaultMatch)) {
                    $column['default'] = trim($defaultMatch[1]);
                }

                // Parse note
                if (preg_match('/note:\s*[\'"]([^\'""]+)[\'"]/', $constraints, $noteMatch)) {
                    $column['note'] = $noteMatch[1];
                }

                $this->tables[$currentTable]['columns'][] = $column;
            }

            // Parse indexes
            if ($currentTable && preg_match('/^indexes\s*{/', $line)) {
                continue;
            }

            if ($currentTable && preg_match('/^\(([^)]+)\)\s*\[(.+)\]/', $line, $matches)) {
                $columns = array_map('trim', explode(',', $matches[1]));
                $indexOptions = $matches[2];

                $index = [
                    'columns' => $columns,
                    'unique' => strpos($indexOptions, 'unique') !== false,
                    'primary' => strpos($indexOptions, 'pk') !== false,
                    'name' => ''
                ];

                if (preg_match('/name:\s*[\'"]([^\'""]+)[\'"]/', $indexOptions, $nameMatch)) {
                    $index['name'] = $nameMatch[1];
                }

                $this->tables[$currentTable]['indexes'][] = $index;
            }

            // Parse references (Ref)
            if (preg_match('/^Ref:\s*(\w+)\.(\w+)\s*([<>-]+)\s*(\w+)\.(\w+)/', $line, $matches)) {
                $this->references[] = [
                    'from_table' => $matches[1],
                    'from_column' => $matches[2],
                    'to_table' => $matches[4],
                    'to_column' => $matches[5],
                    'type' => $matches[3]
                ];
            }

            // Close table
            if (strpos($line, '}') !== false && $braceLevel == 0) {
                $currentTable = null;
            }
        }
    }

    public function generateMigrations($outputDir = './migrations')
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $timestamp = date('Y_m_d_His');
        $counter = 0;

        foreach ($this->tables as $tableName => $tableData) {
            $counter++;
            $migrationTimestamp = date('Y_m_d_His', strtotime("+$counter seconds"));
            $className = 'Create' . $this->toPascalCase($tableName) . 'Table';
            $filename = $outputDir . '/' . $migrationTimestamp . '_create_' . $tableName . '_table.php';

            $content = $this->generateMigrationContent($className, $tableName, $tableData);
            file_put_contents($filename, $content);

            echo "Generated: $filename\n";
        }

        // Generate foreign keys migration
        if (!empty($this->references)) {
            $counter++;
            $migrationTimestamp = date('Y_m_d_His', strtotime("+$counter seconds"));
            $filename = $outputDir . '/' . $migrationTimestamp . '_add_foreign_keys.php';
            $content = $this->generateForeignKeysMigration();
            file_put_contents($filename, $content);
            echo "Generated: $filename\n";
        }
    }

    private function generateMigrationContent($className, $tableName, $tableData)
    {
        $columns = $this->generateColumns($tableData['columns']);
        $indexes = $this->generateIndexes($tableData['indexes']);

        return "<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('$tableName', function (Blueprint \$table) {
$columns$indexes
        });
    }

    public function down()
    {
        Schema::dropIfExists('$tableName');
    }
};
";
    }

private function generateColumns($columns)
    {
        $result = '';
        $hasCreatedAt = false;
        $hasUpdatedAt = false;
        $hasDeletedAt = false;

        // Check for timestamp columns
        foreach ($columns as $column) {
            if ($column['name'] === 'created_at' && $column['type'] === 'timestamp') {
                $hasCreatedAt = true;
            }
            if ($column['name'] === 'updated_at' && $column['type'] === 'timestamp') {
                $hasUpdatedAt = true;
            }
            if ($column['name'] === 'deleted_at' && $column['type'] === 'timestamp' && $column['nullable']) {
                $hasDeletedAt = true;
            }
        }

        foreach ($columns as $column) {
            // Skip timestamp columns that will be handled by special methods
            if (($column['name'] === 'created_at' || $column['name'] === 'updated_at') && $column['type'] === 'timestamp') {
                continue;
            }
            if ($column['name'] === 'deleted_at' && $column['type'] === 'timestamp' && $column['nullable']) {
                continue;
            }

            $line = '            $table->';

            // Map DBML types to Laravel types
            $laravelType = $this->mapType($column['type'], $column['params']);
            $line .= $laravelType . "('" . $column['name'] . "'";

            // Add params for specific types
            if (in_array($column['type'], ['varchar', 'char', 'decimal']) && !empty($column['params'])) {
                $params = explode(',', $column['params']);
                foreach ($params as $param) {
                    $line .= ', ' . trim($param);
                }
            }

            $line .= ')';

            // Add modifiers
            if ($column['increment']) {
                // For auto increment, use id() or bigIncrements()
                $line = '            $table->id()';
            }

            if ($column['nullable']) {
                $line .= '->nullable()';
            }

            if ($column['unique']) {
                $line .= '->unique()';
            }

            if ($column['default'] !== null) {
                $defaultValue = $column['default'];
                if (in_array(strtolower($defaultValue), ['true', 'false'])) {
                    $line .= '->default(' . strtolower($defaultValue) . ')';
                } elseif (is_numeric($defaultValue)) {
                    $line .= '->default(' . $defaultValue . ')';
                } elseif (strtolower($defaultValue) === 'now()' || strtolower($defaultValue) === 'current_timestamp') {
                    $line .= '->useCurrent()';
                } else {
                    $line .= "->default('" . addslashes($defaultValue) . "')";
                }
            }

            if (!empty($column['note'])) {
                $line .= "->comment('" . addslashes($column['note']) . "')";
            }

            $line .= ";\n";
            $result .= $line;
        }

        // Add timestamps if both created_at and updated_at exist
        if ($hasCreatedAt && $hasUpdatedAt) {
            $result .= "            \$table->timestamps();\n";
        }

        // Add softDeletes if deleted_at exists and is nullable
        if ($hasDeletedAt) {
            $result .= "            \$table->softDeletes();\n";
        }

        return $result;
    }

    private function generateIndexes($indexes)
    {
        $result = '';

        foreach ($indexes as $index) {
            if ($index['primary']) {
                $columns = "['" . implode("', '", $index['columns']) . "']";
                $result .= "            \$table->primary($columns);\n";
            } elseif ($index['unique']) {
                $columns = "['" . implode("', '", $index['columns']) . "']";
                if (!empty($index['name'])) {
                    $result .= "            \$table->unique($columns, '" . $index['name'] . "');\n";
                } else {
                    $result .= "            \$table->unique($columns);\n";
                }
            } else {
                $columns = "['" . implode("', '", $index['columns']) . "']";
                if (!empty($index['name'])) {
                    $result .= "            \$table->index($columns, '" . $index['name'] . "');\n";
                } else {
                    $result .= "            \$table->index($columns);\n";
                }
            }
        }

        return $result;
    }

    private function generateForeignKeysMigration()
    {
        $upContent = '';
        $downContent = '';

        foreach ($this->references as $ref) {
            $upContent .= "        Schema::table('{$ref['from_table']}', function (Blueprint \$table) {\n";
            $upContent .= "            \$table->foreign('{$ref['from_column']}')->references('{$ref['to_column']}')->on('{$ref['to_table']}')";
            $upContent .= "->onDelete('cascade');\n";
            $upContent .= "        });\n\n";

            $downContent .= "        Schema::table('{$ref['from_table']}', function (Blueprint \$table) {\n";
            $downContent .= "            \$table->dropForeign(['{$ref['from_column']}']);\n";
            $downContent .= "        });\n\n";
        }

        return "<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
$upContent    }

    public function down()
    {
$downContent    }
};
";
    }

    private function mapType($dbmlType, $params = '')
    {
        $typeMap = [
            'int' => 'integer',
            'integer' => 'integer',
            'bigint' => 'bigInteger',
            'smallint' => 'smallInteger',
            'tinyint' => 'tinyInteger',
            'varchar' => 'string',
            'char' => 'char',
            'text' => 'text',
            'longtext' => 'longText',
            'mediumtext' => 'mediumText',
            'datetime' => 'dateTime',
            'timestamp' => 'timestamp',
            'date' => 'date',
            'time' => 'time',
            'boolean' => 'boolean',
            'bool' => 'boolean',
            'decimal' => 'decimal',
            'float' => 'float',
            'double' => 'double',
            'json' => 'json',
            'jsonb' => 'jsonb',
            'uuid' => 'uuid',
            'enum' => 'enum'
        ];

        return $typeMap[strtolower($dbmlType)] ?? 'string';
    }

    private function toPascalCase($string)
    {
        return str_replace('_', '', ucwords($string, '_'));
    }
}

$filename = $argv[1];
$directory = explode('.', $filename)[0];

$dbml = file_get_contents($filename);
$converter = new DbmlToLaravelMigration();
$converter->parse($dbml);
$converter->generateMigrations("./$directory/database/migrations");
<?php

class DbmlToFormRequest
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
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (empty($line) || strpos($line, '//') === 0) {
                continue;
            }
            
            // Table start
            if (preg_match('/^Table\s+(\w+)\s*\{/', $line, $matches)) {
                $currentTable = $matches[1];
                $this->tables[$currentTable] = ['columns' => []];
                $inTable = true;
                echo "  Found table: {$currentTable}\n";
                continue;
            }
            
            // Table end
            if ($inTable && strpos($line, '}') !== false) {
                $inTable = false;
                $currentTable = null;
                continue;
            }
            
            // Parse column
            if ($inTable && $currentTable) {
                if (preg_match('/^(\w+)\s+(\w+)(?:\(([^)]+)\))?(.*)/', $line, $matches)) {
                    $colName = $matches[1];
                    $colType = $matches[2];
                    $params = isset($matches[3]) ? $matches[3] : '';
                    $constraints = isset($matches[4]) ? $matches[4] : '';
                    
                    // Parse note
                    $note = '';
                    if (preg_match('/note:\s*[\'"]([^\'"]+)[\'"]/', $constraints, $noteMatch)) {
                        $note = $noteMatch[1];
                    }
                    
                    $this->tables[$currentTable]['columns'][] = [
                        'name' => $colName,
                        'type' => $colType,
                        'params' => $params,
                        'nullable' => stripos($constraints, 'null') !== false && stripos($constraints, 'not null') === false,
                        'primary' => stripos($constraints, 'pk') !== false,
                        'increment' => stripos($constraints, 'increment') !== false,
                        'unique' => stripos($constraints, 'unique') !== false,
                        'default' => $this->parseDefault($constraints),
                        'note' => $note,
                        'line' => $line
                    ];
                }
            }
            
            // Parse reference
            if (preg_match('/^Ref:\s*(\w+)\.(\w+)\s*>\s*(\w+)\.(\w+)/', $line, $matches)) {
                $this->references[] = [
                    'from_table' => $matches[1],
                    'from_column' => $matches[2],
                    'to_table' => $matches[3],
                    'to_column' => $matches[4]
                ];
            }
        }
        
        echo "\nTotal tables parsed: " . count($this->tables) . "\n\n";
    }
    
    private function parseDefault($constraints)
    {
        if (preg_match('/default:\s*[`\'"]?([^`\'">\]\s]+)[`\'"]?/i', $constraints, $match)) {
            return trim($match[1]);
        }
        return null;
    }
    
    public function generateFormRequests($outputDir = './app/Http/Requests')
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
            echo "Created directory: {$outputDir}\n\n";
        }
        
        foreach ($this->tables as $tableName => $tableData) {
            $modelName = $this->toModelName($tableName);
            
            // Generate Store Request
            $storeRequestName = "Store{$modelName}Request";
            $storeFilename = "{$outputDir}/{$storeRequestName}.php";
            $storeContent = $this->buildFormRequestContent($storeRequestName, $modelName, $tableData, 'store');
            file_put_contents($storeFilename, $storeContent);
            echo "✓ Generated: {$storeFilename}\n";
            
            // Generate Update Request
            $updateRequestName = "Update{$modelName}Request";
            $updateFilename = "{$outputDir}/{$updateRequestName}.php";
            $updateContent = $this->buildFormRequestContent($updateRequestName, $modelName, $tableData, 'update');
            file_put_contents($updateFilename, $updateContent);
            echo "✓ Generated: {$updateFilename}\n";
        }
        
        echo "\n✓ All form requests generated successfully!\n";
    }
    
    private function buildFormRequestContent($requestName, $modelName, $tableData, $action)
    {
        $rules = $this->generateRules($tableData['columns'], $action, $modelName);
        $messages = $this->generateMessages($tableData['columns']);
        $attributes = $this->generateAttributes($tableData['columns']);
        
        $code = "<?php\n\n";
        $code .= "namespace App\\Http\\Requests;\n\n";
        $code .= "use Illuminate\\Foundation\\Http\\FormRequest;\n";
        
        // Add Rule class if needed
        if ($this->needsRuleClass($tableData['columns'])) {
            $code .= "use Illuminate\\Validation\\Rule;\n";
        }
        
        $code .= "\n";
        $code .= "class {$requestName} extends FormRequest\n";
        $code .= "{\n";
        $code .= "    /**\n";
        $code .= "     * Determine if the user is authorized to make this request.\n";
        $code .= "     */\n";
        $code .= "    public function authorize(): bool\n";
        $code .= "    {\n";
        $code .= "        return true;\n";
        $code .= "    }\n\n";
        
        $code .= "    /**\n";
        $code .= "     * Get the validation rules that apply to the request.\n";
        $code .= "     *\n";
        $code .= "     * @return array<string, \\Illuminate\\Contracts\\Validation\\ValidationRule|array<mixed>|string>\n";
        $code .= "     */\n";
        $code .= "    public function rules(): array\n";
        $code .= "    {\n";
        $code .= "        return [\n";
        $code .= $rules;
        $code .= "        ];\n";
        $code .= "    }\n";
        
        // Add custom messages if any
        if (!empty($messages)) {
            $code .= "\n";
            $code .= "    /**\n";
            $code .= "     * Get custom messages for validator errors.\n";
            $code .= "     *\n";
            $code .= "     * @return array<string, string>\n";
            $code .= "     */\n";
            $code .= "    public function messages(): array\n";
            $code .= "    {\n";
            $code .= "        return [\n";
            $code .= $messages;
            $code .= "        ];\n";
            $code .= "    }\n";
        }
        
        // Add custom attributes if any
        if (!empty($attributes)) {
            $code .= "\n";
            $code .= "    /**\n";
            $code .= "     * Get custom attributes for validator errors.\n";
            $code .= "     *\n";
            $code .= "     * @return array<string, string>\n";
            $code .= "     */\n";
            $code .= "    public function attributes(): array\n";
            $code .= "    {\n";
            $code .= "        return [\n";
            $code .= $attributes;
            $code .= "        ];\n";
            $code .= "    }\n";
        }
        
        $code .= "}\n";
        
        return $code;
    }
    
    private function generateRules($columns, $action, $modelName)
    {
        $rules = '';
        $skip = ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token'];
        
        foreach ($columns as $col) {
            if (in_array($col['name'], $skip) || $col['increment']) {
                continue;
            }
            
            $validationRules = $this->getValidationRules($col, $action, $modelName);
            
            if (!empty($validationRules)) {
                $rules .= "            '{$col['name']}' => {$validationRules},\n";
            }
        }
        
        return $rules;
    }
    
    private function getValidationRules($col, $action, $modelName)
    {
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
                // For update, password is nullable
                $rules = ["'nullable'", "'string'", "'min:8'", "'confirmed'"];
            }
        } elseif (in_array($type, ['boolean', 'bool'])) {
            $rules[] = "'boolean'";
        } elseif (in_array($type, ['int', 'integer', 'bigint', 'smallint', 'tinyint'])) {
            $rules[] = "'integer'";
            if (strpos($name, 'count') !== false || strpos($name, 'quantity') !== false) {
                $rules[] = "'min:0'";
            }
        } elseif (in_array($type, ['decimal', 'float', 'double'])) {
            $rules[] = "'numeric'";
            if (strpos($name, 'price') !== false || strpos($name, 'amount') !== false) {
                $rules[] = "'min:0'";
            }
        } elseif ($type === 'date') {
            $rules[] = "'date'";
        } elseif ($type === 'datetime' || $type === 'timestamp') {
            $rules[] = "'date'";
        } elseif ($type === 'json' || $type === 'jsonb') {
            $rules[] = "'array'";
        } elseif ($type === 'text' || $type === 'longtext' || $type === 'mediumtext') {
            $rules[] = "'string'";
        } elseif ($type === 'varchar' || $type === 'char') {
            $rules[] = "'string'";
            $length = !empty($col['params']) ? intval($col['params']) : 255;
            $rules[] = "'max:{$length}'";
        } else {
            $rules[] = "'string'";
        }
        
        // Unique rules
        if ($col['unique']) {
            if ($action === 'update') {
                // For update, ignore current record
                $table = $this->toTableName($modelName);
                $rules[] = "Rule::unique('{$table}')->ignore(\$this->route('" . strtolower($modelName) . "'))";
            } else {
                $table = $this->toTableName($modelName);
                $rules[] = "'unique:{$table},{$name}'";
            }
        }
        
        // Foreign key rules
        if (strpos($name, '_id') !== false && $name !== 'id') {
            $relatedTable = str_replace('_id', '', $name);
            $relatedTablePlural = $this->plural($relatedTable);
            $rules[] = "'exists:{$relatedTablePlural},id'";
        }
        
        // Special rules based on name
        if (strpos($name, 'url') !== false || strpos($name, 'website') !== false || strpos($name, 'link') !== false) {
            $rules[] = "'url'";
        }
        
        if (strpos($name, 'phone') !== false || strpos($name, 'mobile') !== false) {
            $rules[] = "'regex:/^([0-9\s\-\+\(\)]*)$/'";
        }
        
        if ($name === 'slug') {
            $rules[] = "'alpha_dash'";
        }
        
        if (strpos($name, 'ip') !== false) {
            $rules[] = "'ip'";
        }
        
        if ($name === 'uuid') {
            $rules[] = "'uuid'";
        }
        
        if (strpos($name, 'image') !== false || strpos($name, 'photo') !== false || strpos($name, 'avatar') !== false) {
            if ($action === 'store') {
                $rules = array_filter($rules, function($r) { return $r !== "'required'"; });
                array_unshift($rules, "'nullable'");
            }
            $rules[] = "'image'";
            $rules[] = "'mimes:jpeg,png,jpg,gif'";
            $rules[] = "'max:2048'";
        }
        
        if (strpos($name, 'file') !== false || strpos($name, 'document') !== false) {
            if ($action === 'store') {
                $rules = array_filter($rules, function($r) { return $r !== "'required'"; });
                array_unshift($rules, "'nullable'");
            }
            $rules[] = "'file'";
            $rules[] = "'max:5120'";
        }
        
        return '[' . implode(', ', $rules) . ']';
    }
    
    private function generateMessages($columns)
    {
        $messages = '';
        
        // Add custom messages for common fields
        foreach ($columns as $col) {
            $name = $col['name'];
            
            if ($name === 'email') {
                $messages .= "            '{$name}.required' => 'Email address is required.',\n";
                $messages .= "            '{$name}.email' => 'Please provide a valid email address.',\n";
                $messages .= "            '{$name}.unique' => 'This email is already registered.',\n";
            }
            
            if ($name === 'password') {
                $messages .= "            '{$name}.required' => 'Password is required.',\n";
                $messages .= "            '{$name}.min' => 'Password must be at least 8 characters.',\n";
                $messages .= "            '{$name}.confirmed' => 'Password confirmation does not match.',\n";
            }
            
            if (strpos($name, '_id') !== false && $name !== 'id') {
                $fieldName = str_replace('_id', '', $name);
                $messages .= "            '{$name}.exists' => 'The selected " . str_replace('_', ' ', $fieldName) . " is invalid.',\n";
            }
        }
        
        return $messages;
    }
    
    private function generateAttributes($columns)
    {
        $attributes = '';
        
        foreach ($columns as $col) {
            $name = $col['name'];
            
            // Convert snake_case to human readable
            if (strpos($name, '_') !== false) {
                $humanName = str_replace('_', ' ', $name);
                $attributes .= "            '{$name}' => '{$humanName}',\n";
            }
        }
        
        return $attributes;
    }
    
    private function needsRuleClass($columns)
    {
        foreach ($columns as $col) {
            if ($col['unique']) {
                return true;
            }
        }
        return false;
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
}

$filename = $argv[1];
$directory = explode('.', $filename)[0];

$converter = new DbmlToFormRequest();
$converter->parseFile($filename);
$converter->generateFormRequests("./$directory/app/Http/Requests");
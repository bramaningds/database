<?php

class DbmlToFactory
{
    private $tables = [];
    
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
                    
                    // Parse note for hints
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
    
    public function generateFactories($outputDir = './database/factories')
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
            echo "Created directory: {$outputDir}\n\n";
        }
        
        foreach ($this->tables as $tableName => $tableData) {
            $modelName = $this->toModelName($tableName);
            $factoryName = $modelName . 'Factory';
            $filename = "{$outputDir}/{$factoryName}.php";
            
            $content = $this->buildFactoryContent($factoryName, $modelName, $tableData);
            file_put_contents($filename, $content);
            
            echo "✓ Generated: {$filename}\n";
        }
        
        echo "\n✓ All factories generated successfully!\n";
    }
    
    private function buildFactoryContent($factoryName, $modelName, $tableData)
    {
        $definitions = $this->generateDefinitions($tableData['columns']);
        
        $code = "<?php\n\n";
        $code .= "namespace Database\\Factories;\n\n";
        $code .= "use App\\Models\\{$modelName};\n";
        $code .= "use Illuminate\\Database\\Eloquent\\Factories\\Factory;\n";
        $code .= "use Illuminate\\Support\\Str;\n";
        $code .= "\n";
        $code .= "/**\n";
        $code .= " * @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\App\\Models\\{$modelName}>\n";
        $code .= " */\n";
        $code .= "class {$factoryName} extends Factory\n";
        $code .= "{\n";
        $code .= "    /**\n";
        $code .= "     * The name of the factory's corresponding model.\n";
        $code .= "     *\n";
        $code .= "     * @var string\n";
        $code .= "     */\n";
        $code .= "    protected \$model = {$modelName}::class;\n\n";
        $code .= "    /**\n";
        $code .= "     * Define the model's default state.\n";
        $code .= "     *\n";
        $code .= "     * @return array<string, mixed>\n";
        $code .= "     */\n";
        $code .= "    public function definition(): array\n";
        $code .= "    {\n";
        $code .= "        return [\n";
        $code .= $definitions;
        $code .= "        ];\n";
        $code .= "    }\n";
        
        // Add state methods if needed
        $states = $this->generateStates($modelName, $tableData['columns']);
        if (!empty($states)) {
            $code .= "\n" . $states;
        }
        
        $code .= "}\n";
        
        return $code;
    }
    
    private function generateDefinitions($columns)
    {
        $definitions = '';
        $skip = ['id', 'created_at', 'updated_at', 'deleted_at'];
        
        foreach ($columns as $col) {
            if (in_array($col['name'], $skip) || $col['increment']) {
                continue;
            }
            
            $faker = $this->getFakerMethod($col);
            $definitions .= "            '{$col['name']}' => {$faker},\n";
        }
        
        return $definitions;
    }
    
    private function getFakerMethod($col)
    {
        $name = $col['name'];
        $type = strtolower($col['type']);
        $note = strtolower($col['note']);
        
        // Detect by column name first
        if ($name === 'email' || strpos($name, 'email') !== false) {
            return "fake()->unique()->safeEmail()";
        }
        
        if ($name === 'password') {
            return "bcrypt('password') // password";
        }
        
        if ($name === 'remember_token') {
            return "Str::random(10)";
        }
        
        if (strpos($name, 'first_name') !== false || $name === 'firstname') {
            return "fake()->firstName()";
        }
        
        if (strpos($name, 'last_name') !== false || $name === 'lastname') {
            return "fake()->lastName()";
        }
        
        if ($name === 'name' || strpos($name, 'name') !== false) {
            return "fake()->name()";
        }
        
        if ($name === 'username' || $name === 'user_name') {
            return "fake()->unique()->userName()";
        }
        
        if (strpos($name, 'phone') !== false || strpos($name, 'mobile') !== false) {
            return "fake()->phoneNumber()";
        }
        
        if (strpos($name, 'address') !== false) {
            return "fake()->address()";
        }
        
        if ($name === 'city' || strpos($name, 'city') !== false) {
            return "fake()->city()";
        }
        
        if ($name === 'country' || strpos($name, 'country') !== false) {
            return "fake()->country()";
        }
        
        if ($name === 'postal_code' || $name === 'zip_code' || $name === 'zipcode') {
            return "fake()->postcode()";
        }
        
        if (strpos($name, 'company') !== false) {
            return "fake()->company()";
        }
        
        if ($name === 'title' || strpos($name, 'title') !== false) {
            return "fake()->sentence()";
        }
        
        if ($name === 'slug') {
            return "fake()->unique()->slug()";
        }
        
        if ($name === 'description' || strpos($name, 'description') !== false) {
            return "fake()->paragraph()";
        }
        
        if ($name === 'content' || $name === 'body' || strpos($name, 'content') !== false) {
            return "fake()->paragraphs(3, true)";
        }
        
        if (strpos($name, 'url') !== false || strpos($name, 'link') !== false) {
            return "fake()->url()";
        }
        
        if (strpos($name, 'image') !== false || strpos($name, 'avatar') !== false || strpos($name, 'photo') !== false) {
            return "fake()->imageUrl(640, 480)";
        }
        
        if (strpos($name, 'color') !== false || strpos($name, 'colour') !== false) {
            return "fake()->hexColor()";
        }
        
        if (strpos($name, 'ip') !== false) {
            return "fake()->ipv4()";
        }
        
        if (strpos($name, 'uuid') !== false) {
            return "fake()->uuid()";
        }
        
        if (strpos($name, 'latitude') !== false || $name === 'lat') {
            return "fake()->latitude()";
        }
        
        if (strpos($name, 'longitude') !== false || $name === 'lng' || $name === 'lon') {
            return "fake()->longitude()";
        }
        
        if (strpos($name, 'price') !== false || strpos($name, 'amount') !== false || strpos($name, 'cost') !== false) {
            return "fake()->randomFloat(2, 0, 1000)";
        }
        
        if (strpos($name, 'count') !== false || strpos($name, 'total') !== false || strpos($name, 'quantity') !== false) {
            return "fake()->numberBetween(0, 100)";
        }
        
        if (strpos($name, 'rating') !== false || strpos($name, 'score') !== false) {
            return "fake()->numberBetween(1, 5)";
        }
        
        if (strpos($name, 'published') !== false || strpos($name, 'active') !== false || strpos($name, 'enabled') !== false) {
            return "fake()->boolean()";
        }
        
        if (strpos($name, 'verified') !== false) {
            return "fake()->boolean(80) // 80% chance true";
        }
        
        if (strpos($name, '_at') !== false && $type === 'timestamp') {
            return "fake()->dateTime()";
        }
        
        if (strpos($name, '_date') !== false || $name === 'date') {
            return "fake()->date()";
        }
        
        if (strpos($name, 'birth') !== false) {
            return "fake()->date('Y-m-d', '-18 years')";
        }
        
        if (strpos($name, '_id') !== false && $name !== 'id') {
            // Foreign key - return a number, but add comment
            $relatedModel = str_replace('_id', '', $name);
            $modelName = $this->toModelName($relatedModel);
            return "{$modelName}::factory()";
        }
        
        // Detect by type
        if (in_array($type, ['boolean', 'bool'])) {
            return "fake()->boolean()";
        }
        
        if (in_array($type, ['int', 'integer', 'bigint', 'smallint', 'tinyint'])) {
            return "fake()->numberBetween(1, 100)";
        }
        
        if (in_array($type, ['decimal', 'float', 'double'])) {
            return "fake()->randomFloat(2, 0, 1000)";
        }
        
        if ($type === 'text' || $type === 'longtext' || $type === 'mediumtext') {
            return "fake()->text()";
        }
        
        if ($type === 'date') {
            return "fake()->date()";
        }
        
        if ($type === 'datetime' || $type === 'timestamp') {
            return "fake()->dateTime()";
        }
        
        if ($type === 'time') {
            return "fake()->time()";
        }
        
        if ($type === 'json' || $type === 'jsonb') {
            return "json_encode(['key' => 'value'])";
        }
        
        if ($type === 'uuid') {
            return "fake()->uuid()";
        }
        
        // Default to string
        if ($type === 'varchar' || $type === 'char') {
            $length = !empty($col['params']) ? intval($col['params']) : 255;
            
            if ($length <= 50) {
                return "fake()->words(3, true)";
            } elseif ($length <= 100) {
                return "fake()->sentence()";
            } else {
                return "fake()->sentence(10)";
            }
        }
        
        // Fallback
        return "fake()->word()";
    }
    
    private function generateStates($modelName, $columns)
    {
        $states = '';
        
        // Check for published/active states
        $hasPublished = false;
        $hasActive = false;
        
        foreach ($columns as $col) {
            if ($col['name'] === 'published' && in_array(strtolower($col['type']), ['boolean', 'bool'])) {
                $hasPublished = true;
            }
            if ($col['name'] === 'is_active' && in_array(strtolower($col['type']), ['boolean', 'bool'])) {
                $hasActive = true;
            }
        }
        
        if ($hasPublished) {
            $states .= "    /**\n";
            $states .= "     * Indicate that the {$modelName} is published.\n";
            $states .= "     */\n";
            $states .= "    public function published(): static\n";
            $states .= "    {\n";
            $states .= "        return \$this->state(fn (array \$attributes) => [\n";
            $states .= "            'published' => true,\n";
            $states .= "        ]);\n";
            $states .= "    }\n\n";
            
            $states .= "    /**\n";
            $states .= "     * Indicate that the {$modelName} is unpublished.\n";
            $states .= "     */\n";
            $states .= "    public function unpublished(): static\n";
            $states .= "    {\n";
            $states .= "        return \$this->state(fn (array \$attributes) => [\n";
            $states .= "            'published' => false,\n";
            $states .= "        ]);\n";
            $states .= "    }\n";
        }
        
        if ($hasActive) {
            $states .= "    /**\n";
            $states .= "     * Indicate that the {$modelName} is active.\n";
            $states .= "     */\n";
            $states .= "    public function active(): static\n";
            $states .= "    {\n";
            $states .= "        return \$this->state(fn (array \$attributes) => [\n";
            $states .= "            'is_active' => true,\n";
            $states .= "        ]);\n";
            $states .= "    }\n\n";
            
            $states .= "    /**\n";
            $states .= "     * Indicate that the {$modelName} is inactive.\n";
            $states .= "     */\n";
            $states .= "    public function inactive(): static\n";
            $states .= "    {\n";
            $states .= "        return \$this->state(fn (array \$attributes) => [\n";
            $states .= "            'is_active' => false,\n";
            $states .= "        ]);\n";
            $states .= "    }\n";
        }
        
        return $states;
    }
    
    private function toModelName($tableName)
    {
        $singular = $this->singular($tableName);
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $singular)));
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
}

$filename = $argv[1];
$directory = explode('.', $filename)[0];

$converter = new DbmlToFactory();
$converter->parseFile($filename);
$converter->generateFactories("./$directory/database/factories");
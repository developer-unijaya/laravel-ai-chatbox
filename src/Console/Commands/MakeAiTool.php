<?php
namespace DeveloperUnijaya\AiChatbox\Console\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Scaffolds an orchestrator tool class (ToolInterface) in the host app.
 *
 *   php artisan ai-chatbox:make-tool GetWeatherTool          # blank skeleton
 *   php artisan ai-chatbox:make-tool --model=Car             # model-backed read tool
 *   php artisan ai-chatbox:make-tool --model=Car --filterable=brand,year
 *
 * Model mode introspects the model's table to pre-fill parameters() (typed filter
 * arguments) and handle() (a read query). Generated tools are read-only and default
 * to authenticated-users-only — you still add per-user scoping and allow-list the class.
 */
class MakeAiTool extends GeneratorCommand
{
    protected $name = 'ai-chatbox:make-tool';

    protected $description = 'Generate an AI Chatbox orchestrator tool (ToolInterface) class';

    protected $type = 'Tool';

    /** Resolved model class name, or null for a blank tool. */
    protected ?string $modelClass = null;

    /** Table backing the resolved model. */
    protected string $table = '';

    /** @var array<int, string> Columns returned by the generated handle(). */
    protected array $returnColumns = [];

    /** @var array<int, string> Columns exposed as filter arguments. */
    protected array $filterCols = [];

    public function handle()
    {
        if (trim((string) $this->argument('name')) === '' && !$this->option('model')) {
            $this->error('Provide a tool class name or --model=. Example: php artisan ai-chatbox:make-tool --model=Car');
            return self::FAILURE;
        }

        $this->prepareModel();

        $result = parent::handle();

        if ($result === false) {
            return self::FAILURE;
        }

        $this->afterCreated();

        return self::SUCCESS;
    }

    // ── Stub & namespace ──────────────────────────────────────────────────────

    protected function getStub(): string
    {
        $stub = $this->modelClass ? 'aitool.model.stub' : 'aitool.blank.stub';

        // Allow the host app to override the stub (php artisan vendor:publish --tag=ai-chatbox-stubs)
        $published = $this->laravel->basePath('stubs/' . $stub);

        return file_exists($published) ? $published : dirname(__DIR__) . '/stubs/' . $stub;
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $this->option('namespace') ?: (trim($rootNamespace, '\\') . '\\AiTools');
    }

    protected function getNameInput(): string
    {
        $name = trim((string) $this->argument('name'));
        if ($name !== '') {
            return $name;
        }

        // Derive from the model: Car -> GetCarsTool
        $base = class_basename(str_replace('/', '\\', (string) $this->option('model')));

        return 'Get' . Str::studly(Str::plural(Str::snake($base))) . 'Tool';
    }

    // ── Class body ────────────────────────────────────────────────────────────

    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);

        $replacements = [
            '{{ toolName }}' => $this->toolName(),
            '{{ description }}' => $this->toolDescription(),
        ];

        if ($this->modelClass) {
            $replacements['{{ parametersBody }}'] = $this->renderParameters();
            $replacements['{{ handleBody }}'] = $this->renderHandle();
        }

        return str_replace(array_keys($replacements), array_values($replacements), $stub);
    }

    // ── Model introspection ───────────────────────────────────────────────────

    protected function prepareModel(): void
    {
        $model = $this->option('model');
        if (!$model) {
            return;
        }

        $class = $this->resolveModelClass($model);
        if ($class === null) {
            $this->warn("Model [{$model}] could not be resolved — generating a blank tool instead.");
            return;
        }

        try {
            $table = (new $class())->getTable();
            $columns = Schema::getColumnListing($table);
        } catch (\Throwable) {
            $this->warn("Could not read the schema for [{$class}] — generating a blank tool instead.");
            return;
        }

        if ($columns === []) {
            $this->warn("Table for [{$class}] has no columns — generating a blank tool instead.");
            return;
        }

        $this->modelClass = $class;
        $this->table = $table;

        $exclude = ['created_at', 'updated_at', 'deleted_at'];
        $usable = array_values(array_diff($columns, $exclude));

        $this->returnColumns = $this->sanitizeIdentifiers($this->columnList('columns') ?? $usable);

        // Default filters: everything usable except the primary key.
        $this->filterCols = $this->sanitizeIdentifiers($this->columnList('filterable') ?? array_values(array_filter($usable, fn($c) => $c !== 'id')));
    }

    /**
     * Keep only valid identifiers ([A-Za-z_][A-Za-z0-9_]*). Anything else — a crafted
     * --columns / --filterable value containing quotes or expressions — is dropped with
     * a warning, so it can never break out of the single-quoted string literals in the
     * generated tool into arbitrary PHP. Real DB column names always pass.
     *
     * @param  array<int, string>  $cols
     * @return array<int, string>
     */
    protected function sanitizeIdentifiers(array $cols): array
    {
        $safe = [];

        foreach ($cols as $col) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $col)) {
                $safe[] = $col;
            } else {
                $this->warn("Skipping invalid column identifier: '{$col}'");
            }
        }

        return $safe;
    }

    protected function resolveModelClass(string $model): ?string
    {
        if (str_contains($model, '\\')) {
            return class_exists($model) ? ltrim($model, '\\') : null;
        }

        foreach ([$this->rootNamespace() . 'Models\\' . $model, $this->rootNamespace() . $model] as $candidate) {
            if (class_exists($candidate)) {
                return ltrim($candidate, '\\');
            }
        }

        return null;
    }

    /**
     * @return array<int, string>|null
     */
    protected function columnList(string $option): ?array
    {
        $value = $this->option($option);
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    // ── Renderers ─────────────────────────────────────────────────────────────

    protected function toolName(): string
    {
        if ($this->option('tool-name')) {
            return Str::snake((string) $this->option('tool-name'));
        }

        if ($this->modelClass) {
            return 'get_' . Str::snake(Str::plural(class_basename($this->modelClass)));
        }

        return Str::snake(Str::replaceLast('Tool', '', class_basename($this->getNameInput())));
    }

    protected function toolDescription(): string
    {
        if ($this->modelClass) {
            $label = Str::of(class_basename($this->modelClass))->snake(' ')->plural();
            return "List {$label} records, optionally filtered. Returns the selected columns.";
        }

        return 'TODO: describe when the model should use this tool.';
    }

    protected function renderParameters(): string
    {
        $props = [];
        foreach ($this->filterCols as $col) {
            $type = $this->jsonType($col);
            $props[] = "                '{$col}' => ['type' => '{$type}', 'description' => 'Filter by {$col}.'],";
        }

        $properties = $props === []
        ? "                // no filterable columns"
        : implode("\n", $props);

        return "            'type' => 'object',\n"
            . "            'properties' => [\n"
            . $properties . "\n"
            . "            ],\n"
            . "            'required' => [],";
    }

    protected function renderHandle(): string
    {
        $model = '\\' . ltrim($this->modelClass, '\\');
        $lines = [];
        $lines[] = "        \$query = {$model}::query();";
        $lines[] = '';
        $lines[] = "        // TODO: scope this query to the current user, e.g. ->where('user_id', auth()->id())";

        foreach ($this->filterCols as $col) {
            $lines[] = "        if (array_key_exists('{$col}', \$arguments) && \$arguments['{$col}'] !== null) {";
            $lines[] = "            \$query->where('{$col}', \$arguments['{$col}']);";
            $lines[] = '        }';
        }

        $lines[] = '';
        $returnKey = Str::snake(Str::plural(class_basename($this->modelClass)));
        $cols = "['" . implode("', '", $this->returnColumns) . "']";
        $lines[] = '        return [';
        $lines[] = "            '{$returnKey}' => \$query->get({$cols})->toArray(),";
        $lines[] = '        ];';

        return implode("\n", $lines);
    }

    protected function jsonType(string $col): string
    {
        try {
            $raw = strtolower(trim(Schema::getColumnType($this->table, $col)));
        } catch (\Throwable) {
            return 'string';
        }

        // getColumnType returns native names on Laravel 11+ (mysql: int/varchar/tinyint(1))
        // and abstract names on older/dbal setups (integer/string/boolean) — handle both.
        if ($raw === 'tinyint(1)') {
            return 'boolean';
        }

        $base = trim(preg_replace('/\s*\(.*$/', '', $raw));

        return match (true) {
            in_array($base, ['bool', 'boolean'], true) => 'boolean',
            in_array($base, ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint', 'int2', 'int4', 'int8', 'serial', 'bigserial'], true) => 'integer',
            in_array($base, ['decimal', 'numeric', 'float', 'double', 'real', 'money'], true) => 'number',
            default => 'string',
        };
    }

    // ── After generation ──────────────────────────────────────────────────────

    protected function afterCreated(): void
    {
        $class = $this->qualifyClass($this->getNameInput());

        $this->newLine();
        $this->line('Add it to your allow-list to enable it:');
        $this->newLine();
        $this->line('    // config/ai-chatbox.php');
        $this->line("    'orchestrator_tools' => [");
        $this->line("        \\{$class}::class,");
        $this->line('    ],');
        $this->newLine();
        $this->line('Then set <info>AI_CHATBOX_ORCHESTRATOR=true</info>.');
    }

    // ── Console definition ────────────────────────────────────────────────────

    protected function getArguments(): array
    {
        return [
            ['name', InputArgument::OPTIONAL, 'The tool class name (e.g. GetCarsTool). Optional when --model is given.'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['model', null, InputOption::VALUE_OPTIONAL, 'Eloquent model to build a read tool from (e.g. Car or App\\Models\\Car)'],
            ['tool-name', null, InputOption::VALUE_OPTIONAL, "Override the tool's snake_case name() (default derived)"],
            ['columns', null, InputOption::VALUE_OPTIONAL, 'Comma list of columns the tool returns (default: all non-timestamp)'],
            ['filterable', null, InputOption::VALUE_OPTIONAL, 'Comma list of columns exposed as filter arguments'],
            ['namespace', null, InputOption::VALUE_OPTIONAL, 'Namespace for the generated tool (default {App}\\AiTools)'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the tool if it already exists'],
        ];
    }
}

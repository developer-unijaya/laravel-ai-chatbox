<?php
namespace DeveloperUnijaya\AiChatbox\Orchestration;

use DeveloperUnijaya\AiChatbox\Orchestration\Contracts\ToolInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Holds the tools available to the orchestrator. Registered as a singleton so tools
 * added at runtime (in a host service provider) persist for the request.
 *
 * Tools become usable ONLY when their class is on the `orchestrator_tools` allow-list;
 * loadFromConfig() resolves those class names through the container. Manual register()
 * is also allowed for programmatic setups and tests.
 */
class ToolRegistry
{
    /** @var array<string, ToolInterface>  keyed by tool name */
    private array $tools = [];

    private bool $loadedFromConfig = false;

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * @return array<string, ToolInterface>
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * Resolve and register the allow-listed tool classes from config. Idempotent —
     * runs its resolution once, then no-ops. Invalid entries are logged and skipped
     * (never fatal), so one bad tool cannot break the chatbox.
     *
     * @param  array<int, string>  $classNames
     */
    public function loadFromConfig(array $classNames): void
    {
        if ($this->loadedFromConfig) {
            return;
        }
        $this->loadedFromConfig = true;

        foreach ($classNames as $class) {
            if (!is_string($class) || $class === '') {
                continue;
            }

            try {
                $tool = app($class);
            } catch (\Throwable $e) {
                Log::warning('AI Chatbox orchestrator: could not resolve tool class.', [
                    'class' => $class,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (!$tool instanceof ToolInterface) {
                Log::warning('AI Chatbox orchestrator: tool class does not implement ToolInterface — skipped.', [
                    'class' => $class,
                ]);
                continue;
            }

            $this->register($tool);
        }
    }

    /**
     * Tools the given request is authorized to use.
     *
     * @return array<string, ToolInterface>
     */
    public function authorized(?Request $request = null): array
    {
        return array_filter($this->tools, function (ToolInterface $tool) use ($request) {
            try {
                return $tool->authorize($request);
            } catch (\Throwable $e) {
                Log::warning('AI Chatbox orchestrator: tool authorize() threw — treating as denied.', [
                    'tool' => $tool->name(),
                    'error' => $e->getMessage(),
                ]);
                return false;
            }
        });
    }

    /**
     * Normalized tool schemas for the engine, limited to authorized tools.
     *
     * @return array<int, array{name: string, description: string, parameters: array}>
     */
    public function schemas(?Request $request = null): array
    {
        return array_values(array_map(fn(ToolInterface $tool) => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => $tool->parameters(),
        ], $this->authorized($request)));
    }
}

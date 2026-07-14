<?php
namespace DeveloperUnijaya\AiChatbox\Tests\Unit;

use Illuminate\Http\Request;
use DeveloperUnijaya\AiChatbox\AiManager;
use DeveloperUnijaya\AiChatbox\Engine\Contracts\AiEngineInterface;
use DeveloperUnijaya\AiChatbox\Engine\Contracts\SupportsToolCalling;
use DeveloperUnijaya\AiChatbox\Engine\EngineResult;
use DeveloperUnijaya\AiChatbox\Engine\PromptBuilder;
use DeveloperUnijaya\AiChatbox\Orchestration\Contracts\ToolInterface;
use DeveloperUnijaya\AiChatbox\Orchestration\Exceptions\OrchestrationException;
use DeveloperUnijaya\AiChatbox\Orchestration\Orchestrator;
use DeveloperUnijaya\AiChatbox\Orchestration\ToolRegistry;
use DeveloperUnijaya\AiChatbox\Tests\TestCase;

class OrchestratorTest extends TestCase
{
    private function cfg(array $overrides = []): array
    {
        return array_merge([
            'api_url' => 'http://ai.example.com/v1/chat/completions',
            'api_token' => 'test-token',
            'api_model' => 'gpt-4o',
            'system_prompt' => '',
            'language' => '',
            'rag_enabled' => false,
            'orchestrator_enabled' => true,
            'orchestrator_max_steps' => 5,
            'orchestrator_timeout' => 60,
        ], $overrides);
    }

    private function orchestrator(FakeToolEngine $engine, ToolRegistry $registry): Orchestrator
    {
        $this->app->instance(AiEngineInterface::class, $engine);
        return new Orchestrator(app(AiManager::class), new PromptBuilder(), $registry);
    }

    private function registryWith(ToolInterface ...$tools): ToolRegistry
    {
        $registry = new ToolRegistry();
        foreach ($tools as $t) {
            $registry->register($t);
        }
        return $registry;
    }

    private function toolCallResult(string $name, array $args, string $id = 'call_1'): EngineResult
    {
        return EngineResult::toolCalls(
            [['id' => $id, 'name' => $name, 'arguments' => $args]],
            ['role' => 'assistant', 'content' => null],
        );
    }

    // ── Happy paths ───────────────────────────────────────────────────────────

    public function test_returns_text_immediately_when_no_tool_calls(): void
    {
        $engine = new FakeToolEngine([EngineResult::text('hello')]);
        $orch = $this->orchestrator($engine, $this->registryWith(new FakeEchoTool()));

        $result = $orch->run('hi', [], $this->cfg());

        $this->assertSame('hello', $result->reply);
        $this->assertSame([], $result->steps);
        $this->assertSame(1, $engine->completeWithToolsCalls);
    }

    public function test_executes_tool_then_returns_final_answer(): void
    {
        $engine = new FakeToolEngine([
            $this->toolCallResult('echo_tool', ['text' => 'ping']),
            EngineResult::text('done'),
        ]);
        $orch = $this->orchestrator($engine, $this->registryWith(new FakeEchoTool()));

        $result = $orch->run('hi', [], $this->cfg());

        $this->assertSame('done', $result->reply);
        $this->assertCount(1, $result->steps);
        $this->assertTrue($result->steps[0]->ok());
        $this->assertSame(['echo' => 'ping'], $result->steps[0]->result);
        $this->assertSame(2, $engine->completeWithToolsCalls);
    }

    // ── Safety limits ─────────────────────────────────────────────────────────

    public function test_stops_at_max_steps_with_O01(): void
    {
        $engine = new FakeToolEngine([
            $this->toolCallResult('echo_tool', ['text' => 'a']),
            $this->toolCallResult('echo_tool', ['text' => 'b']),
        ]);
        $orch = $this->orchestrator($engine, $this->registryWith(new FakeEchoTool()));

        try {
            $orch->run('hi', [], $this->cfg(['orchestrator_max_steps' => 2]));
            $this->fail('Expected OrchestrationException');
        } catch (OrchestrationException $e) {
            $this->assertSame('O01', $e->errorCode);
        }
    }

    public function test_times_out_with_O02(): void
    {
        // Engine sleeps just over 1s per turn; with a 1s budget the 2nd turn's check trips.
        $engine = new FakeToolEngine([
            $this->toolCallResult('echo_tool', ['text' => 'a']),
            $this->toolCallResult('echo_tool', ['text' => 'b']),
        ]);
        $engine->sleepMicros = 1_050_000;
        $orch = $this->orchestrator($engine, $this->registryWith(new FakeEchoTool()));

        try {
            $orch->run('hi', [], $this->cfg(['orchestrator_timeout' => 1]));
            $this->fail('Expected OrchestrationException');
        } catch (OrchestrationException $e) {
            $this->assertSame('O02', $e->errorCode);
        }
    }

    // ── Recoverable per-tool failures (fed back to the model, not fatal) ───────

    public function test_unknown_tool_reports_O03_and_continues(): void
    {
        $engine = new FakeToolEngine([
            $this->toolCallResult('does_not_exist', []),
            EngineResult::text('recovered'),
        ]);
        $orch = $this->orchestrator($engine, $this->registryWith(new FakeEchoTool()));

        $result = $orch->run('hi', [], $this->cfg());

        $this->assertSame('recovered', $result->reply);
        $this->assertSame('O03', $result->steps[0]->errorCode);
        $this->assertFalse($result->steps[0]->ok());
    }

    public function test_unauthorized_tool_reports_O04(): void
    {
        // An authorized tool must exist so the loop runs at all (unauthorized tools are
        // filtered out of the schema). The model then calls a registered-but-unauthorized
        // tool by name — dispatch()'s defense-in-depth check reports O04.
        $engine = new FakeToolEngine([
            $this->toolCallResult('secret_tool', ['text' => 'x']),
            EngineResult::text('ok'),
        ]);
        $orch = $this->orchestrator($engine, $this->registryWith(
            new FakeEchoTool(),
            new FakeEchoTool(authorized: false, toolName: 'secret_tool'),
        ));

        $result = $orch->run('hi', [], $this->cfg());

        $this->assertSame('O04', $result->steps[0]->errorCode);
    }

    public function test_missing_required_argument_reports_O05(): void
    {
        $engine = new FakeToolEngine([
            $this->toolCallResult('echo_tool', []), // missing required 'text'
            EngineResult::text('ok'),
        ]);
        $orch = $this->orchestrator($engine, $this->registryWith(new FakeEchoTool()));

        $result = $orch->run('hi', [], $this->cfg());

        $this->assertSame('O05', $result->steps[0]->errorCode);
    }

    public function test_throwing_tool_reports_O06_with_message(): void
    {
        $engine = new FakeToolEngine([
            $this->toolCallResult('echo_tool', ['text' => 'x']),
            EngineResult::text('ok'),
        ]);
        $orch = $this->orchestrator($engine, $this->registryWith(new FakeEchoTool(throws: true)));

        $result = $orch->run('hi', [], $this->cfg());

        $this->assertSame('O06', $result->steps[0]->errorCode);
        $this->assertStringContainsString('boom', (string) $result->steps[0]->error);
    }

    // ── Graceful degradation to a single plain completion ─────────────────────

    public function test_disabled_orchestrator_uses_plain_complete(): void
    {
        $engine = new FakeToolEngine([]);
        $orch = $this->orchestrator($engine, $this->registryWith(new FakeEchoTool()));

        $result = $orch->run('hi', [], $this->cfg(['orchestrator_enabled' => false]));

        $this->assertSame('PLAIN_COMPLETE', $result->reply);
        $this->assertSame(0, $engine->completeWithToolsCalls);
    }

    public function test_no_tools_registered_uses_plain_complete(): void
    {
        $engine = new FakeToolEngine([]);
        $orch = $this->orchestrator($engine, new ToolRegistry()); // empty

        $result = $orch->run('hi', [], $this->cfg());

        $this->assertSame('PLAIN_COMPLETE', $result->reply);
        $this->assertSame(0, $engine->completeWithToolsCalls);
    }

    public function test_engine_without_tool_support_falls_back(): void
    {
        $engine = new FakePlainEngine();
        $this->app->instance(AiEngineInterface::class, $engine);
        $orch = new Orchestrator(app(AiManager::class), new PromptBuilder(), $this->registryWith(new FakeEchoTool()));

        $result = $orch->run('hi', [], $this->cfg());

        $this->assertSame('PLAIN_ONLY', $result->reply);
    }
}

// ── Test doubles ────────────────────────────────────────────────────────────

class FakeToolEngine implements AiEngineInterface, SupportsToolCalling
{
    public int $completeWithToolsCalls = 0;
    public array $lastTools = [];
    public int $sleepMicros = 0;

    /** @param array<int, EngineResult> $scripted */
    public function __construct(private array $scripted) {}

    public function complete(array $messages, array $options = []): string
    {
        return 'PLAIN_COMPLETE';
    }

    public function validateConfig(array $options): void {}

    public function stream(array $messages, array $options, callable $onToken): string
    {
        return '';
    }

    public function beginStream(array $messages, array $options): \Closure
    {
        return fn(callable $onToken): string => '';
    }

    public function completeWithTools(array $messages, array $tools, array $options = []): EngineResult
    {
        $this->completeWithToolsCalls++;
        $this->lastTools = $tools;
        if ($this->sleepMicros > 0) {
            usleep($this->sleepMicros);
        }
        return array_shift($this->scripted) ?? EngineResult::text('FALLBACK');
    }

    public function toolResultMessages(EngineResult $result, array $toolResults): array
    {
        return [
            ['role' => 'assistant', 'content' => 'tool-call-turn'],
            ['role' => 'tool', 'content' => json_encode($toolResults)],
        ];
    }
}

class FakePlainEngine implements AiEngineInterface
{
    public function complete(array $messages, array $options = []): string
    {
        return 'PLAIN_ONLY';
    }
    public function validateConfig(array $options): void {}
    public function stream(array $messages, array $options, callable $onToken): string
    {
        return '';
    }
    public function beginStream(array $messages, array $options): \Closure
    {
        return fn(callable $onToken): string => '';
    }
}

class FakeEchoTool implements ToolInterface
{
    public function __construct(
        public bool $authorized = true,
        public bool $throws = false,
        public string $toolName = 'echo_tool',
    ) {}

    public function name(): string
    {
        return $this->toolName;
    }
    public function description(): string
    {
        return 'Echo the given text back.';
    }
    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']];
    }
    public function authorize(?Request $request = null): bool
    {
        return $this->authorized;
    }
    public function handle(array $arguments): mixed
    {
        if ($this->throws) {
            throw new \RuntimeException('boom');
        }
        return ['echo' => $arguments['text'] ?? null];
    }
}

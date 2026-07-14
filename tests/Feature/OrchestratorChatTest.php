<?php
namespace DeveloperUnijaya\AiChatbox\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use DeveloperUnijaya\AiChatbox\Orchestration\Contracts\ToolInterface;
use DeveloperUnijaya\AiChatbox\Tests\TestCase;

class OrchestratorChatTest extends TestCase
{
    public function test_message_endpoint_runs_tool_loop_when_enabled(): void
    {
        config()->set('ai-chatbox.orchestrator_enabled', true);
        config()->set('ai-chatbox.orchestrator_tools', [FeatureCarsTool::class]);
        config()->set('ai-chatbox.history_enabled', false);

        // Turn 1: model asks for the tool. Turn 2: model gives the final answer.
        $this->mockGuzzle([
            new Response(200, [], json_encode(['choices' => [['message' => [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'get_cars', 'arguments' => '{}'],
                ]],
            ]]]])),
            new Response(200, [], json_encode(['choices' => [['message' => [
                'content' => 'We have a Toyota and a Honda.',
            ]]]])),
        ]);

        $response = $this->postJson('/ai-chatbox/message', ['message' => 'what cars are there?']);

        $response->assertOk();
        $response->assertJson(['reply' => 'We have a Toyota and a Honda.']);
    }

    public function test_message_endpoint_is_unchanged_when_orchestrator_disabled(): void
    {
        config()->set('ai-chatbox.orchestrator_enabled', false);

        // Single completion, exactly as before the orchestrator existed.
        $this->mockGuzzle([
            new Response(200, [], json_encode(['choices' => [['message' => ['content' => 'plain reply']]]])),
        ]);

        $response = $this->postJson('/ai-chatbox/message', ['message' => 'hi']);

        $response->assertOk();
        $response->assertJson(['reply' => 'plain reply']);
    }

    public function test_tool_not_allow_listed_is_never_called(): void
    {
        // Enabled but empty allow-list → behaves like a single plain completion.
        config()->set('ai-chatbox.orchestrator_enabled', true);
        config()->set('ai-chatbox.orchestrator_tools', []);

        $this->mockGuzzle([
            new Response(200, [], json_encode(['choices' => [['message' => ['content' => 'no tools reply']]]])),
        ]);

        $response = $this->postJson('/ai-chatbox/message', ['message' => 'hi']);

        $response->assertOk();
        $response->assertJson(['reply' => 'no tools reply']);
    }
}

class FeatureCarsTool implements ToolInterface
{
    public function name(): string
    {
        return 'get_cars';
    }
    public function description(): string
    {
        return 'List the cars in the system.';
    }
    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }
    public function authorize(?Request $request = null): bool
    {
        return true;
    }
    public function handle(array $arguments): mixed
    {
        return ['cars' => ['Toyota', 'Honda']];
    }
}

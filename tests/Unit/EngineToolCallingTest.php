<?php
namespace DeveloperUnijaya\AiChatbox\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use DeveloperUnijaya\AiChatbox\Engine\AnthropicEngine;
use DeveloperUnijaya\AiChatbox\Engine\EngineResult;
use DeveloperUnijaya\AiChatbox\Engine\OpenAiCompatibleEngine;
use DeveloperUnijaya\AiChatbox\Tests\TestCase;

class EngineToolCallingTest extends TestCase
{
    private array $openAiOptions = [
        'api_url' => 'http://ai.example.com/v1/chat/completions',
        'api_token' => 'test-token',
        'api_model' => 'gpt-4o',
    ];

    private array $anthropicOptions = [
        'api_url' => 'https://api.anthropic.com/v1/messages',
        'api_token' => 'sk-ant-test',
        'api_model' => 'claude-sonnet-4-6',
    ];

    private array $tools = [
        ['name' => 'get_cars', 'description' => 'List cars', 'parameters' => ['type' => 'object', 'properties' => ['brand' => ['type' => 'string']], 'required' => []]],
    ];

    /**
     * Bind a Guzzle client that captures the outgoing JSON payload and returns $body.
     */
    private function capturingClient(array $body, &$captured): void
    {
        $this->app->bind('ai-chatbox.guzzle', function () use ($body, &$captured) {
            return function (array $config) use ($body, &$captured) {
                return new class($body, $captured) extends \GuzzleHttp\Client {
                    public function __construct(private array $body, private &$captured) {}
                    public function post($uri, array $options = []): \GuzzleHttp\Psr7\Response
                    {
                        $this->captured = $options['json'];
                        return new \GuzzleHttp\Psr7\Response(200, [], json_encode($this->body));
                    }
                };
            };
        });
    }

    // ── OpenAI-compatible ─────────────────────────────────────────────────────

    public function test_openai_returns_text_when_no_tool_calls(): void
    {
        $this->mockGuzzle([
            new Response(200, [], json_encode(['choices' => [['message' => ['content' => 'Just text.']]]])),
        ]);

        $result = (new OpenAiCompatibleEngine())->completeWithTools(
            [['role' => 'user', 'content' => 'hi']], $this->tools, $this->openAiOptions
        );

        $this->assertTrue($result->isText());
        $this->assertSame('Just text.', $result->text);
    }

    public function test_openai_parses_tool_calls_and_decodes_json_arguments(): void
    {
        $this->mockGuzzle([
            new Response(200, [], json_encode(['choices' => [['message' => [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_abc',
                    'type' => 'function',
                    'function' => ['name' => 'get_cars', 'arguments' => '{"brand":"Toyota"}'],
                ]],
            ]]]])),
        ]);

        $result = (new OpenAiCompatibleEngine())->completeWithTools(
            [['role' => 'user', 'content' => 'cars?']], $this->tools, $this->openAiOptions
        );

        $this->assertTrue($result->wantsTools());
        $this->assertCount(1, $result->toolCalls);
        $this->assertSame('call_abc', $result->toolCalls[0]['id']);
        $this->assertSame('get_cars', $result->toolCalls[0]['name']);
        $this->assertSame(['brand' => 'Toyota'], $result->toolCalls[0]['arguments']);
    }

    public function test_openai_sends_function_shaped_tools_in_payload(): void
    {
        $captured = null;
        $this->capturingClient(['choices' => [['message' => ['content' => 'ok']]]], $captured);

        (new OpenAiCompatibleEngine())->completeWithTools(
            [['role' => 'user', 'content' => 'hi']], $this->tools, $this->openAiOptions
        );

        $this->assertArrayHasKey('tools', $captured);
        $this->assertSame('function', $captured['tools'][0]['type']);
        $this->assertSame('get_cars', $captured['tools'][0]['function']['name']);
    }

    public function test_openai_tool_result_messages_shape(): void
    {
        $result = EngineResult::toolCalls(
            [['id' => 'call_1', 'name' => 'get_cars', 'arguments' => []]],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_1']]],
        );

        $messages = (new OpenAiCompatibleEngine())->toolResultMessages($result, [
            ['id' => 'call_1', 'content' => '{"cars":3}'],
        ]);

        $this->assertSame('assistant', $messages[0]['role']);
        $this->assertSame('tool', $messages[1]['role']);
        $this->assertSame('call_1', $messages[1]['tool_call_id']);
        $this->assertSame('{"cars":3}', $messages[1]['content']);
    }

    // ── Anthropic ─────────────────────────────────────────────────────────────

    public function test_anthropic_parses_tool_use_block(): void
    {
        $this->mockGuzzle([
            new Response(200, [], json_encode(['content' => [
                ['type' => 'text', 'text' => 'let me check'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_cars', 'input' => ['brand' => 'Honda']],
            ]])),
        ]);

        $result = (new AnthropicEngine())->completeWithTools(
            [['role' => 'user', 'content' => 'cars?']], $this->tools, $this->anthropicOptions
        );

        $this->assertTrue($result->wantsTools());
        $this->assertSame('toolu_1', $result->toolCalls[0]['id']);
        $this->assertSame('get_cars', $result->toolCalls[0]['name']);
        $this->assertSame(['brand' => 'Honda'], $result->toolCalls[0]['arguments']);
    }

    public function test_anthropic_returns_text_when_no_tool_use(): void
    {
        $this->mockGuzzle([
            new Response(200, [], json_encode(['content' => [['type' => 'text', 'text' => 'Hello.']]])),
        ]);

        $result = (new AnthropicEngine())->completeWithTools(
            [['role' => 'user', 'content' => 'hi']], $this->tools, $this->anthropicOptions
        );

        $this->assertTrue($result->isText());
        $this->assertSame('Hello.', $result->text);
    }

    public function test_anthropic_sends_input_schema_tools_in_payload(): void
    {
        $captured = null;
        $this->capturingClient(['content' => [['type' => 'text', 'text' => 'ok']]], $captured);

        (new AnthropicEngine())->completeWithTools(
            [['role' => 'user', 'content' => 'hi']], $this->tools, $this->anthropicOptions
        );

        $this->assertArrayHasKey('tools', $captured);
        $this->assertSame('get_cars', $captured['tools'][0]['name']);
        $this->assertArrayHasKey('input_schema', $captured['tools'][0]);
        $this->assertArrayNotHasKey('type', $captured['tools'][0]); // not the OpenAI shape
    }

    public function test_anthropic_preserves_empty_tool_input_as_object(): void
    {
        // Regression: a tool_use with empty input {} decodes to a PHP [] and, when echoed
        // back, must re-encode as {} (object) — Anthropic rejects "input": [].
        $this->mockGuzzle([
            new Response(200, [], json_encode(['content' => [
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_cars', 'input' => new \stdClass()],
            ]])),
        ]);

        $result = (new AnthropicEngine())->completeWithTools(
            [['role' => 'user', 'content' => 'cars?']], $this->tools, $this->anthropicOptions
        );

        $encoded = json_encode($result->rawAssistantMessage);
        $this->assertStringContainsString('"input":{}', $encoded);
        $this->assertStringNotContainsString('"input":[]', $encoded);
    }

    public function test_anthropic_tool_result_messages_shape(): void
    {
        $result = EngineResult::toolCalls(
            [['id' => 'toolu_1', 'name' => 'get_cars', 'arguments' => []]],
            ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_cars', 'input' => []]]],
        );

        $messages = (new AnthropicEngine())->toolResultMessages($result, [
            ['id' => 'toolu_1', 'content' => '{"cars":3}'],
        ]);

        $this->assertSame('assistant', $messages[0]['role']);
        $this->assertSame('user', $messages[1]['role']);
        $this->assertSame('tool_result', $messages[1]['content'][0]['type']);
        $this->assertSame('toolu_1', $messages[1]['content'][0]['tool_use_id']);
        $this->assertSame('{"cars":3}', $messages[1]['content'][0]['content']);
    }
}

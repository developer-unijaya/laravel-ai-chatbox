<?php
namespace DeveloperUnijaya\AiChatbox\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use DeveloperUnijaya\AiChatbox\Engine\AnthropicEngine;
use DeveloperUnijaya\AiChatbox\Engine\Exceptions\AiEngineException;
use DeveloperUnijaya\AiChatbox\Tests\TestCase;

class AnthropicEngineTest extends TestCase
{
    private array $baseOptions = [
        'api_url' => 'https://api.anthropic.com/v1/messages',
        'api_token' => 'sk-ant-test',
        'api_model' => 'claude-sonnet-4-6',
    ];

    // ── complete() ────────────────────────────────────────────────────────────

    public function test_complete_parses_anthropic_content_array(): void
    {
        $this->mockGuzzle([
            new Response(200, [], json_encode([
                'content' => [['type' => 'text', 'text' => 'Hello from Anthropic']],
            ])),
        ]);

        $engine = new AnthropicEngine();
        $reply = $engine->complete([['role' => 'user', 'content' => 'Hi']], $this->baseOptions);

        $this->assertSame('Hello from Anthropic', $reply);
    }

    public function test_complete_skips_leading_thinking_block(): void
    {
        // Adaptive thinking (default-on for newer models) puts a `thinking`
        // block first; reading content[0] would misread this as an empty reply.
        $this->mockGuzzle([
            new Response(200, [], json_encode([
                'content' => [
                    ['type' => 'thinking', 'thinking' => 'Let me consider the question...'],
                    ['type' => 'text', 'text' => 'The capital of France is Paris.'],
                ],
            ])),
        ]);

        $engine = new AnthropicEngine();
        $reply = $engine->complete([['role' => 'user', 'content' => 'Hi']], $this->baseOptions);

        $this->assertSame('The capital of France is Paris.', $reply);
    }

    public function test_complete_concatenates_multiple_text_blocks(): void
    {
        $this->mockGuzzle([
            new Response(200, [], json_encode([
                'content' => [
                    ['type' => 'text', 'text' => 'Part one. '],
                    ['type' => 'text', 'text' => 'Part two.'],
                ],
            ])),
        ]);

        $engine = new AnthropicEngine();
        $reply = $engine->complete([['role' => 'user', 'content' => 'Hi']], $this->baseOptions);

        $this->assertSame('Part one. Part two.', $reply);
    }

    public function test_complete_throws_e18_when_only_non_text_blocks(): void
    {
        // A response with a thinking block but no text is genuinely empty.
        $this->mockGuzzle([
            new Response(200, [], json_encode([
                'content' => [['type' => 'thinking', 'thinking' => 'hmm']],
            ])),
        ]);

        $engine = new AnthropicEngine();

        $this->expectException(\DeveloperUnijaya\AiChatbox\Engine\Exceptions\AiEngineException::class);
        $engine->complete([['role' => 'user', 'content' => 'Hi']], $this->baseOptions);
    }

    public function test_complete_extracts_system_message_from_messages(): void
    {
        $captured = null;
        $this->app->bind('ai-chatbox.guzzle', function () use (&$captured) {
            return function (array $config) use (&$captured) {
                return new class($captured) extends \GuzzleHttp\Client
                {
                    public function __construct(private  &$captured)
                    {}
                    public function post($uri, array $options = []): \GuzzleHttp\Psr7\Response
                    {
                        $this->captured = $options['json'];
                        return new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                            'content' => [['type' => 'text', 'text' => 'ok']],
                        ]));
                    }
                };
            };
        });

        $engine = new AnthropicEngine();
        $engine->complete([
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => 'Hi'],
        ], $this->baseOptions);

        $this->assertSame('You are a helpful assistant.', $captured['system']);
        $this->assertCount(1, $captured['messages']);
        $this->assertSame('user', $captured['messages'][0]['role']);
    }

    public function test_complete_joins_multiple_system_messages(): void
    {
        $captured = null;
        $this->app->bind('ai-chatbox.guzzle', function () use (&$captured) {
            return function (array $config) use (&$captured) {
                return new class($captured) extends \GuzzleHttp\Client
                {
                    public function __construct(private  &$captured)
                    {}
                    public function post($uri, array $options = []): \GuzzleHttp\Psr7\Response
                    {
                        $this->captured = $options['json'];
                        return new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                            'content' => [['type' => 'text', 'text' => 'ok']],
                        ]));
                    }
                };
            };
        });

        $engine = new AnthropicEngine();
        $engine->complete([
            ['role' => 'system', 'content' => 'Part one.'],
            ['role' => 'system', 'content' => 'Part two.'],
            ['role' => 'user', 'content' => 'Hi'],
        ], $this->baseOptions);

        $this->assertSame("Part one.\n\nPart two.", $captured['system']);
    }

    public function test_complete_omits_system_key_when_no_system_message(): void
    {
        $captured = null;
        $this->app->bind('ai-chatbox.guzzle', function () use (&$captured) {
            return function (array $config) use (&$captured) {
                return new class($captured) extends \GuzzleHttp\Client
                {
                    public function __construct(private  &$captured)
                    {}
                    public function post($uri, array $options = []): \GuzzleHttp\Psr7\Response
                    {
                        $this->captured = $options['json'];
                        return new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                            'content' => [['type' => 'text', 'text' => 'ok']],
                        ]));
                    }
                };
            };
        });

        $engine = new AnthropicEngine();
        $engine->complete([
            ['role' => 'user', 'content' => 'Hi'],
        ], $this->baseOptions);

        $this->assertArrayNotHasKey('system', $captured);
    }

    public function test_complete_throws_e18_on_empty_response(): void
    {
        $this->mockGuzzle([
            new Response(200, [], json_encode(['content' => []])),
        ]);

        $this->expectException(AiEngineException::class);

        $engine = new AnthropicEngine();
        $engine->complete([['role' => 'user', 'content' => 'Hi']], $this->baseOptions);
    }

    // ── anthropic-version header ──────────────────────────────────────────────

    public function test_complete_uses_default_anthropic_version_header(): void
    {
        $capturedHeaders = null;
        $this->app->bind('ai-chatbox.guzzle', function () use (&$capturedHeaders) {
            return function (array $config) use (&$capturedHeaders) {
                return new class($capturedHeaders) extends \GuzzleHttp\Client
                {
                    public function __construct(private  &$capturedHeaders)
                    {}
                    public function post($uri, array $options = []): \GuzzleHttp\Psr7\Response
                    {
                        $this->capturedHeaders = $options['headers'];
                        return new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                            'content' => [['type' => 'text', 'text' => 'ok']],
                        ]));
                    }
                };
            };
        });

        $engine = new AnthropicEngine();
        $engine->complete([['role' => 'user', 'content' => 'Hi']], $this->baseOptions);

        $this->assertSame('2023-06-01', $capturedHeaders['anthropic-version']);
        $this->assertArrayNotHasKey('Authorization', $capturedHeaders);
        $this->assertArrayHasKey('x-api-key', $capturedHeaders);
    }

    public function test_complete_uses_configured_anthropic_version(): void
    {
        $capturedHeaders = null;
        $this->app->bind('ai-chatbox.guzzle', function () use (&$capturedHeaders) {
            return function (array $config) use (&$capturedHeaders) {
                return new class($capturedHeaders) extends \GuzzleHttp\Client
                {
                    public function __construct(private  &$capturedHeaders)
                    {}
                    public function post($uri, array $options = []): \GuzzleHttp\Psr7\Response
                    {
                        $this->capturedHeaders = $options['headers'];
                        return new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                            'content' => [['type' => 'text', 'text' => 'ok']],
                        ]));
                    }
                };
            };
        });

        $engine = new AnthropicEngine();
        $engine->complete(
            [['role' => 'user', 'content' => 'Hi']],
            $this->baseOptions + ['anthropic_version' => '2024-06-01']
        );

        $this->assertSame('2024-06-01', $capturedHeaders['anthropic-version']);
    }

    // ── beginStream() ─────────────────────────────────────────────────────────

    public function test_begin_stream_parses_content_block_delta_events(): void
    {
        $events = [
            ['type' => 'message_start', 'message' => ['id' => 'msg_1']],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hello']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => ' world']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_stop'],
        ];

        $body = '';
        foreach ($events as $event) {
            $body .= 'data: ' . json_encode($event) . "\n\n";
        }

        $this->mockGuzzle([
            new Response(200, ['Content-Type' => 'text/event-stream'], Utils::streamFor($body)),
        ]);

        $engine = new AnthropicEngine();
        $reader = $engine->beginStream([['role' => 'user', 'content' => 'Hi']], $this->baseOptions);

        $tokens = [];
        $fullReply = $reader(function (string $t) use (&$tokens) {$tokens[] = $t;});

        $this->assertSame(['Hello', ' world'], $tokens);
        $this->assertSame('Hello world', $fullReply);
    }

    public function test_begin_stream_stops_at_message_stop(): void
    {
        $body = implode('', [
            'data: ' . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hi']]) . "\n\n",
            'data: ' . json_encode(['type' => 'message_stop']) . "\n\n",
            'data: ' . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => ' AFTER_STOP']]) . "\n\n",
        ]);

        $this->mockGuzzle([
            new Response(200, ['Content-Type' => 'text/event-stream'], Utils::streamFor($body)),
        ]);

        $engine = new AnthropicEngine();
        $reader = $engine->beginStream([['role' => 'user', 'content' => 'Hi']], $this->baseOptions);
        $fullReply = $reader(fn() => null);

        $this->assertSame('Hi', $fullReply);
    }

    public function test_begin_stream_ignores_event_lines_and_comments(): void
    {
        $body = implode('', [
            ": keep-alive\n\n",
            "event: content_block_delta\n",
            'data: ' . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'OK']]) . "\n\n",
            'data: ' . json_encode(['type' => 'message_stop']) . "\n\n",
        ]);

        $this->mockGuzzle([
            new Response(200, ['Content-Type' => 'text/event-stream'], Utils::streamFor($body)),
        ]);

        $engine = new AnthropicEngine();
        $reader = $engine->beginStream([['role' => 'user', 'content' => 'Hi']], $this->baseOptions);
        $fullReply = $reader(fn() => null);

        $this->assertSame('OK', $fullReply);
    }

    public function test_begin_stream_stops_on_mid_stream_error_event(): void
    {
        // Anthropic can emit an `error` event (e.g. overloaded_error) partway
        // through; it must end the stream, not be silently treated as content.
        $body = implode('', [
            'data: ' . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Partial']]) . "\n\n",
            'data: ' . json_encode(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]) . "\n\n",
            'data: ' . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => ' MORE']]) . "\n\n",
        ]);

        $this->mockGuzzle([
            new Response(200, ['Content-Type' => 'text/event-stream'], Utils::streamFor($body)),
        ]);

        $engine = new AnthropicEngine();
        $reader = $engine->beginStream([['role' => 'user', 'content' => 'Hi']], $this->baseOptions);
        $fullReply = $reader(fn() => null);

        // Content after the error event is not appended.
        $this->assertSame('Partial', $fullReply);
    }

    public function test_begin_stream_flushes_final_line_without_trailing_newline(): void
    {
        // A truncated stream whose last data line has no terminating newline
        // must still be processed rather than dropped at EOF.
        $body = 'data: ' . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Tail']]);

        $this->mockGuzzle([
            new Response(200, ['Content-Type' => 'text/event-stream'], Utils::streamFor($body)),
        ]);

        $engine = new AnthropicEngine();
        $reader = $engine->beginStream([['role' => 'user', 'content' => 'Hi']], $this->baseOptions);
        $fullReply = $reader(fn() => null);

        $this->assertSame('Tail', $fullReply);
    }

    public function test_begin_stream_throws_e12_on_401(): void
    {
        $this->mockGuzzle([
            new Response(401, [], json_encode(['error' => ['message' => 'Unauthorized']])),
        ]);

        $this->expectException(AiEngineException::class);

        $engine = new AnthropicEngine();
        $engine->beginStream([['role' => 'user', 'content' => 'Hi']], $this->baseOptions);
    }
}

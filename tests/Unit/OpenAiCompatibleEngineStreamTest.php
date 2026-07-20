<?php
namespace DeveloperUnijaya\AiChatbox\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use DeveloperUnijaya\AiChatbox\Engine\OpenAiCompatibleEngine;
use DeveloperUnijaya\AiChatbox\Tests\TestCase;

/**
 * Exercises the OpenAI-compatible branch of the shared streaming reader
 * (readEventStream): SSE token parsing, the [DONE] sentinel, Ollama-native
 * NDJSON with done:true, and the robustness fixes from issue #6 (trailing line
 * flushed at EOF).
 */
class OpenAiCompatibleEngineStreamTest extends TestCase
{
    private array $baseOptions = [
        'api_url' => 'http://ai.test/v1/chat/completions',
        'api_token' => 'test-token',
        'api_model' => 'test-model',
    ];

    private function stream(string $body): string
    {
        $this->mockGuzzle([
            new Response(200, ['Content-Type' => 'text/event-stream'], Utils::streamFor($body)),
        ]);

        $engine = new OpenAiCompatibleEngine();
        $reader = $engine->beginStream([['role' => 'user', 'content' => 'Hi']], $this->baseOptions);

        return $reader(fn () => null);
    }

    public function test_parses_openai_delta_tokens_and_stops_at_done(): void
    {
        $body = implode('', [
            'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Hello']]]]) . "\n\n",
            'data: ' . json_encode(['choices' => [['delta' => ['content' => ' world']]]]) . "\n\n",
            "data: [DONE]\n\n",
            'data: ' . json_encode(['choices' => [['delta' => ['content' => ' AFTER']]]]) . "\n\n",
        ]);

        $this->assertSame('Hello world', $this->stream($body));
    }

    public function test_done_sentinel_without_space_still_terminates(): void
    {
        // Some proxies emit "data:[DONE]" without the space.
        $body = implode('', [
            'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Hi']]]]) . "\n\n",
            "data:[DONE]\n\n",
            'data: ' . json_encode(['choices' => [['delta' => ['content' => ' AFTER']]]]) . "\n\n",
        ]);

        $this->assertSame('Hi', $this->stream($body));
    }

    public function test_ollama_native_ndjson_stops_on_done_true(): void
    {
        $body = implode('', [
            json_encode(['message' => ['content' => 'Wid']]) . "\n",
            json_encode(['message' => ['content' => 'gets'], 'done' => false]) . "\n",
            json_encode(['message' => ['content' => ''], 'done' => true]) . "\n",
            json_encode(['message' => ['content' => ' AFTER']]) . "\n",
        ]);

        $this->assertSame('Widgets', $this->stream($body));
    }

    public function test_flushes_final_line_without_trailing_newline(): void
    {
        // Truncated stream: last data line has no terminating newline.
        $body = 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'Tail']]]]);

        $this->assertSame('Tail', $this->stream($body));
    }

    public function test_skips_comments_and_blank_keepalives(): void
    {
        $body = implode('', [
            ": keep-alive\n\n",
            "\n",
            'data: ' . json_encode(['choices' => [['delta' => ['content' => 'OK']]]]) . "\n\n",
            "data: [DONE]\n\n",
        ]);

        $this->assertSame('OK', $this->stream($body));
    }
}

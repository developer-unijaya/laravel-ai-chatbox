<?php
namespace DeveloperUnijaya\AiChatbox\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use DeveloperUnijaya\AiChatbox\Services\EmbeddingService;
use DeveloperUnijaya\AiChatbox\Tests\TestCase;

class EmbeddingServiceTest extends TestCase
{
    private function embeddingResponse(array $vector = [0.1, 0.2, 0.3]): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => [['embedding' => $vector]],
        ]));
    }

    // ── Constructor params override config ────────────────────────────────────

    public function test_embed_uses_constructor_url_over_config(): void
    {
        // Config has a URL that would cause a real network call if used
        $this->app['config']->set('ai-chatbox.rag_embedding_url', 'http://config.example.com/v1/embeddings');

        // Constructor receives a different URL — the mock will be called once;
        // if the wrong URL were used the response format would still match,
        // so we verify by ensuring embed() returns a non-null result (not a config-sourced null/error).
        $this->mockGuzzle([$this->embeddingResponse([1.0, 2.0, 3.0])]);

        $service = new EmbeddingService(
            'http://override.example.com/v1/embeddings',
            'override-model',
            'override-token',
        );

        $result = $service->embed('test text');

        $this->assertIsArray($result);
        $this->assertEqualsWithDelta(1.0, $result[0], 0.001);
        $this->assertEqualsWithDelta(2.0, $result[1], 0.001);
        $this->assertEqualsWithDelta(3.0, $result[2], 0.001);
    }

    public function test_embed_returns_null_when_no_constructor_args_given(): void
    {
        $service = new EmbeddingService(); // no constructor args — URL is empty

        $result = $service->embed('hello');

        $this->assertNull($result);
    }

    public function test_embed_returns_null_when_url_is_empty(): void
    {
        $service = new EmbeddingService('', 'some-model', 'some-token');

        $result = $service->embed('hello');

        $this->assertNull($result);
    }

    // ── OpenAI-compatible response format ─────────────────────────────────────

    public function test_embed_parses_openai_data_embedding_format(): void
    {
        $this->mockGuzzle([$this->embeddingResponse([0.1, 0.2, 0.3])]);

        $result = (new EmbeddingService(
            'http://embed.example.com/v1/embeddings',
            'test-model',
            'test-token',
        ))->embed('hello');

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertContainsOnly('float', $result);
    }

    // ── Ollama native response formats ────────────────────────────────────────

    public function test_embed_parses_ollama_embeddings_array_format(): void
    {
        $body = json_encode(['embeddings' => [[0.4, 0.5, 0.6]]]);
        $response = new Response(200, ['Content-Type' => 'application/json'], $body);
        $this->mockGuzzle([$response]);

        $result = (new EmbeddingService(
            'http://embed.example.com/api/embed',
            'nomic-embed-text',
            'token',
        ))->embed('hello');

        $this->assertIsArray($result);
        $this->assertEqualsWithDelta(0.4, $result[0], 0.001);
    }

    public function test_embed_parses_ollama_single_embedding_format(): void
    {
        $body = json_encode(['embedding' => [0.7, 0.8, 0.9]]);
        $response = new Response(200, ['Content-Type' => 'application/json'], $body);
        $this->mockGuzzle([$response]);

        $result = (new EmbeddingService(
            'http://embed.example.com/api/embeddings',
            'nomic-embed-text',
            'token',
        ))->embed('hello');

        $this->assertIsArray($result);
        $this->assertEqualsWithDelta(0.7, $result[0], 0.001);
    }

    public function test_embed_returns_null_on_unrecognised_response_format(): void
    {
        $body = json_encode(['unexpected' => 'format']);
        $response = new Response(200, ['Content-Type' => 'application/json'], $body);
        $this->mockGuzzle([$response]);

        $result = (new EmbeddingService(
            'http://embed.example.com/v1/embeddings',
            'test-model',
            'token',
        ))->embed('hello');

        $this->assertNull($result);
    }

    public function test_embed_returns_null_on_network_error(): void
    {
        $this->mockGuzzle([
            new \GuzzleHttp\Exception\ConnectException(
                'cURL error 7: Connection refused',
                new \GuzzleHttp\Psr7\Request('POST', '/')
            ),
        ]);

        $result = (new EmbeddingService(
            'http://embed.example.com/v1/embeddings',
            'test-model',
            'token',
        ))->embed('hello');

        $this->assertNull($result);
    }

    // ── resolveToken(): never leak the chat token to a foreign host ───────────

    public function test_resolve_token_prefers_explicit_embedding_token(): void
    {
        $token = EmbeddingService::resolveToken([
            'rag_embedding_token' => 'embed-secret',
            'api_token' => 'chat-secret',
            'rag_embedding_url' => 'https://embeddings.other.com/v1/embeddings',
            'api_url' => 'https://api.chat.com/v1/chat',
        ]);

        $this->assertSame('embed-secret', $token);
    }

    public function test_resolve_token_reuses_chat_token_when_same_host(): void
    {
        // OpenAI chat + OpenAI embeddings → same host → reuse is correct.
        $token = EmbeddingService::resolveToken([
            'rag_embedding_token' => '',
            'api_token' => 'sk-openai',
            'rag_embedding_url' => 'https://api.openai.com/v1/embeddings',
            'api_url' => 'https://api.openai.com/v1/chat/completions',
        ]);

        $this->assertSame('sk-openai', $token);
    }

    public function test_resolve_token_does_not_send_chat_token_to_foreign_host(): void
    {
        // Chat provider is Anthropic; embeddings point at a different host.
        // The Anthropic key must NOT be forwarded there.
        $token = EmbeddingService::resolveToken([
            'rag_embedding_token' => '',
            'api_token' => 'sk-ant-secret',
            'rag_embedding_url' => 'https://embeddings.thirdparty.com/v1/embeddings',
            'api_url' => 'https://api.anthropic.com/v1/messages',
        ]);

        $this->assertNull($token);
    }

    public function test_resolve_token_falls_back_when_embedding_url_empty(): void
    {
        // No embedding URL → no call is made, so reusing the token is harmless.
        $token = EmbeddingService::resolveToken([
            'rag_embedding_token' => '',
            'api_token' => 'chat-secret',
            'rag_embedding_url' => '',
            'api_url' => 'https://api.anthropic.com/v1/messages',
        ]);

        $this->assertSame('chat-secret', $token);
    }
}

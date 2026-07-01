<?php
namespace DeveloperUnijaya\AiChatbox\Tests\Feature;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use DeveloperUnijaya\AiChatbox\Models\RagChunk;
use DeveloperUnijaya\AiChatbox\Models\RagDocument;
use DeveloperUnijaya\AiChatbox\Tests\TestCase;

/**
 * Covers RagController::chunks() (GET /{id}/chunks) and
 * RagController::chat()  (POST /{id}/chat).
 *
 * The base TestCase already wires:
 *   active_provider = testprovider  (api_url + api_token set)
 *   rag_embedding_url = 'http://embed.example.com/v1/embeddings'
 *   rag_embedding_model = 'test-embed'
 */
class RagChunksPageTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeReadyDocument(string $title = 'Test Doc'): RagDocument
    {
        return RagDocument::create([
            'title'             => $title,
            'original_filename' => 'test.txt',
            'file_type'         => 'txt',
            'status'            => 'ready',
            'chunk_count'       => 0,
            'content'           => 'Some document content.',
        ]);
    }

    private function addChunk(
        RagDocument $doc,
        string $content,
        ?array $embedding = null,
        int $index = 0,
    ): RagChunk {
        $chunk = RagChunk::create([
            'document_id' => $doc->id,
            'chunk_index' => $index,
            'content'     => $content,
            'embedding'   => $embedding,
        ]);
        $doc->increment('chunk_count');
        return $chunk;
    }

    private function sseResponse(array $tokens): Response
    {
        $body = '';
        foreach ($tokens as $token) {
            $body .= 'data: ' . json_encode(['choices' => [['delta' => ['content' => $token]]]]) . "\n\n";
        }
        $body .= "data: [DONE]\n\n";

        return new Response(200, ['Content-Type' => 'text/event-stream'], Utils::streamFor($body));
    }

    private function chatResponse(string $content): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'choices' => [['message' => ['content' => $content]]],
        ]));
    }

    private function embedResponse(array $vector = [1.0, 0.0, 0.0]): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => [['embedding' => $vector]],
        ]));
    }

    // ── GET /{id}/chunks — page rendering ─────────────────────────────────────

    public function test_chunks_page_returns_200_for_a_ready_document(): void
    {
        $doc = $this->makeReadyDocument();

        $this->withoutMiddleware()
            ->get("/ai-chatbox/rag/{$doc->id}/chunks")
            ->assertOk();
    }

    public function test_chunks_page_shows_the_document_title(): void
    {
        $doc = $this->makeReadyDocument('My Knowledge Base');

        $this->withoutMiddleware()
            ->get("/ai-chatbox/rag/{$doc->id}/chunks")
            ->assertOk()
            ->assertSee('My Knowledge Base');
    }

    public function test_chunks_page_shows_chunk_content(): void
    {
        $doc = $this->makeReadyDocument();
        $this->addChunk($doc, 'Laravel is a PHP web framework.');

        $this->withoutMiddleware()
            ->get("/ai-chatbox/rag/{$doc->id}/chunks")
            ->assertOk()
            ->assertSee('Laravel is a PHP web framework.');
    }

    public function test_chunks_page_returns_404_for_a_non_existent_document(): void
    {
        $this->withoutMiddleware()
            ->get('/ai-chatbox/rag/99999/chunks')
            ->assertNotFound();
    }

    public function test_chunks_page_shows_vector_badge_for_embedded_chunks(): void
    {
        $doc = $this->makeReadyDocument();
        $this->addChunk($doc, 'Vectorised chunk content.', [0.1, 0.2, 0.3]);

        $this->withoutMiddleware()
            ->get("/ai-chatbox/rag/{$doc->id}/chunks")
            ->assertOk()
            ->assertSee('Vector');
    }

    public function test_chunks_page_shows_keyword_badge_for_unembedded_chunks(): void
    {
        $doc = $this->makeReadyDocument();
        $this->addChunk($doc, 'Keyword-only chunk content.', null);

        $this->withoutMiddleware()
            ->get("/ai-chatbox/rag/{$doc->id}/chunks")
            ->assertOk()
            ->assertSee('Keyword');
    }

    public function test_chunks_page_disables_chat_when_api_url_is_missing(): void
    {
        $this->app['config']->set('ai-chatbox.providers.testprovider.api_url', '');
        $doc = $this->makeReadyDocument();

        $this->withoutMiddleware()
            ->get("/ai-chatbox/rag/{$doc->id}/chunks")
            ->assertOk()
            ->assertSee('api_url is not configured');
    }

    public function test_chunks_page_disables_chat_when_api_token_is_missing(): void
    {
        $this->app['config']->set('ai-chatbox.providers.testprovider.api_token', '');
        $doc = $this->makeReadyDocument();

        $this->withoutMiddleware()
            ->get("/ai-chatbox/rag/{$doc->id}/chunks")
            ->assertOk()
            ->assertSee('api_token is not configured');
    }

    // ── POST /{id}/chat — validation ──────────────────────────────────────────

    public function test_chat_returns_422_when_message_is_missing(): void
    {
        $doc = $this->makeReadyDocument();

        $this->withoutMiddleware()
            ->postJson("/ai-chatbox/rag/{$doc->id}/chat", [])
            ->assertStatus(422);
    }

    public function test_chat_returns_422_when_message_exceeds_max_length(): void
    {
        $doc = $this->makeReadyDocument();

        $this->withoutMiddleware()
            ->postJson("/ai-chatbox/rag/{$doc->id}/chat", ['message' => str_repeat('x', 2001)])
            ->assertStatus(422);
    }

    public function test_chat_returns_422_when_document_has_no_chunks(): void
    {
        $doc = RagDocument::create([
            'title'             => 'Empty',
            'original_filename' => 'empty.txt',
            'file_type'         => 'txt',
            'status'            => 'processing',
            'chunk_count'       => 0,
        ]);

        $this->withoutMiddleware()
            ->postJson("/ai-chatbox/rag/{$doc->id}/chat", ['message' => 'Hello'])
            ->assertStatus(422)
            ->assertJson(['error' => 'Document has no chunks to retrieve from.']);
    }

    public function test_chat_works_for_failed_document_that_still_has_chunks(): void
    {
        // Embedding failed for all chunks → status='failed', but chunk_count>0.
        // Keyword retrieval should still work.
        $doc = RagDocument::create([
            'title'             => 'Embed Failed',
            'original_filename' => 'failed.txt',
            'file_type'         => 'txt',
            'status'            => 'failed',
            'chunk_count'       => 1,
            'error_message'     => 'Embedding failed for all 1 chunks.',
        ]);
        $this->addChunk($doc, 'Laravel supports multiple database drivers.', null);

        $this->mockGuzzle([$this->chatResponse('Laravel supports SQLite, MySQL, and more.')]);

        $this->withoutMiddleware()
            ->postJson("/ai-chatbox/rag/{$doc->id}/chat", ['message' => 'database drivers'])
            ->assertOk()
            ->assertJsonPath('chunks_used', 1);
    }

    public function test_chat_returns_404_for_a_non_existent_document(): void
    {
        $this->withoutMiddleware()
            ->postJson('/ai-chatbox/rag/99999/chat', ['message' => 'Hello'])
            ->assertNotFound();
    }

    // ── POST /{id}/chat — no chunks / no context ──────────────────────────────

    public function test_chat_returns_422_when_document_has_no_chunks_at_all(): void
    {
        $doc = $this->makeReadyDocument(); // chunk_count = 0, no chunk rows

        $this->withoutMiddleware()
            ->postJson("/ai-chatbox/rag/{$doc->id}/chat", ['message' => 'Hello'])
            ->assertStatus(422)
            ->assertJson(['error' => 'Document has no chunks to retrieve from.']);
    }

    public function test_chat_returns_no_context_reply_when_nothing_matches(): void
    {
        // Chunk has no embedding → keyword fallback; query words won't match the chunk.
        // With no matching context, the rag_no_context_prompt guard is injected as a
        // system message and the AI is called (same as the live chatbox behaviour).
        $doc = $this->makeReadyDocument();
        $this->addChunk($doc, 'Laravel is a PHP framework.', null);

        $this->mockGuzzle([$this->chatResponse("I don't have that information in my knowledge base.")]);

        $this->withoutMiddleware()
            ->postJson("/ai-chatbox/rag/{$doc->id}/chat", ['message' => 'xyz zzz qqq'])
            ->assertOk()
            ->assertJson(['chunks_used' => 0]);
    }

    // ── POST /{id}/chat — keyword retrieval ───────────────────────────────────

    public function test_chat_returns_ai_reply_via_keyword_retrieval(): void
    {
        // Chunk has no embedding → retrieveContext() uses keyword fallback automatically
        $doc = $this->makeReadyDocument();
        $this->addChunk($doc, 'Laravel ships with the Artisan CLI.', null);

        $this->mockGuzzle([$this->chatResponse('Artisan is a powerful command-line tool.')]);

        $this->withoutMiddleware()
            ->postJson("/ai-chatbox/rag/{$doc->id}/chat", ['message' => 'Tell me about Artisan'])
            ->assertOk()
            ->assertJson([
                'reply'       => 'Artisan is a powerful command-line tool.',
                'chunks_used' => 1,
            ]);
    }

    public function test_chat_chunks_used_count_matches_retrieved_chunks(): void
    {
        $doc = $this->makeReadyDocument();
        foreach (range(1, 3) as $i) {
            $this->addChunk($doc, "Eloquent fact {$i}: important ORM detail.", null, $i - 1);
        }

        $this->mockGuzzle([$this->chatResponse('Here is some Eloquent info.')]);

        $response = $this->withoutMiddleware()
            ->postJson("/ai-chatbox/rag/{$doc->id}/chat", ['message' => 'Eloquent facts']);

        $response->assertOk();
        $chunksUsed = $response->json('chunks_used');
        $this->assertGreaterThanOrEqual(1, $chunksUsed);
        $this->assertLessThanOrEqual(3, $chunksUsed);
    }

    // ── POST /{id}/chat — vector retrieval ────────────────────────────────────

    public function test_chat_returns_ai_reply_via_vector_retrieval(): void
    {
        // The base test config has rag_embedding_url set globally, so the provider
        // config inherits it and vector retrieval is attempted for embedded chunks.
        $doc = $this->makeReadyDocument();
        $this->addChunk($doc, 'Laravel ships with Eloquent ORM.', [1.0, 0.0, 0.0]);

        // First Guzzle call: embed the query; second: AI chat completion
        $this->mockGuzzle([
            $this->embedResponse([1.0, 0.0, 0.0]), // identical vector → similarity 1.0
            $this->chatResponse('Eloquent ORM is Laravel\'s fluent query builder.'),
        ]);

        $this->withoutMiddleware()
            ->postJson("/ai-chatbox/rag/{$doc->id}/chat", ['message' => 'What ORM does Laravel use?'])
            ->assertOk()
            ->assertJson([
                'reply'       => "Eloquent ORM is Laravel's fluent query builder.",
                'chunks_used' => 1,
            ]);
    }

    public function test_chat_falls_back_to_keyword_when_vector_score_is_below_threshold(): void
    {
        // Chunk has an embedding; query embedding will be orthogonal → similarity 0 < threshold
        // → scored empty → falls through to keyword fallback
        $doc = $this->makeReadyDocument();
        $this->addChunk($doc, 'Laravel ships with Artisan commands.', [1.0, 0.0, 0.0]);

        $this->mockGuzzle([
            $this->embedResponse([0.0, 1.0, 0.0]), // orthogonal → cosine = 0.0 < 0.2 threshold
            $this->chatResponse('Artisan is great.'),
        ]);

        $this->withoutMiddleware()
            ->postJson("/ai-chatbox/rag/{$doc->id}/chat", ['message' => 'Tell me about Artisan'])
            ->assertOk()
            ->assertJsonPath('chunks_used', 1);
    }

    // ── POST /{id}/chat — no history persistence ──────────────────────────────

    public function test_chat_does_not_save_conversation_or_message_records(): void
    {
        $doc = $this->makeReadyDocument();
        $this->addChunk($doc, 'Laravel ships with Artisan CLI.', null);

        $this->mockGuzzle([$this->chatResponse('Artisan is a command-line tool.')]);

        $this->withoutMiddleware()
            ->postJson("/ai-chatbox/rag/{$doc->id}/chat", ['message' => 'Tell me about Artisan'])
            ->assertOk();

        $this->assertDatabaseCount('ai_chatbox_conversations', 0);
        $this->assertDatabaseCount('ai_chatbox_messages', 0);
    }

    // ── POST /{id}/chat — AI failure ──────────────────────────────────────────

    public function test_chat_returns_502_when_ai_call_fails(): void
    {
        // Chunk with no embedding → keyword fallback returns a match
        $doc = $this->makeReadyDocument();
        $this->addChunk($doc, 'Laravel uses Eloquent for database queries.', null);

        // AI call throws a network error
        $this->mockGuzzle([
            new ConnectException(
                'connection refused',
                new Request('POST', 'http://ai.example.com/v1/chat/completions'),
            ),
        ]);

        $this->withoutMiddleware()
            ->postJson("/ai-chatbox/rag/{$doc->id}/chat", ['message' => 'Eloquent queries'])
            ->assertStatus(502)
            ->assertJson(['error' => 'AI call failed. Check your provider config in the dashboard.']);
    }

    public function test_chat_returns_502_when_ai_returns_server_error(): void
    {
        $doc = $this->makeReadyDocument();
        $this->addChunk($doc, 'Laravel uses Eloquent for database queries.', null);

        $this->mockGuzzle([
            new Response(500, [], json_encode(['error' => 'Internal Server Error'])),
        ]);

        $this->withoutMiddleware()
            ->postJson("/ai-chatbox/rag/{$doc->id}/chat", ['message' => 'Eloquent queries'])
            ->assertStatus(502);
    }

    // ── POST /{id}/chat — streaming ───────────────────────────────────────────

    public function test_chat_streams_sse_when_stream_config_is_enabled(): void
    {
        $this->app['config']->set('ai-chatbox.stream', true);

        $doc = $this->makeReadyDocument();
        $this->addChunk($doc, 'Laravel ships with Artisan CLI.', null);

        $this->mockGuzzle([$this->sseResponse(['Artisan', ' is', ' great.'])]);

        $response = $this->withoutMiddleware()
            ->postJson("/ai-chatbox/rag/{$doc->id}/chat", ['message' => 'Tell me about Artisan']);

        $response->assertOk();
        $this->assertStringContainsString('text/event-stream', $response->headers->get('Content-Type'));

        $body = $response->streamedContent();
        $this->assertStringContainsString('"chunks_used"', $body);
        $this->assertStringContainsString('"token"', $body);
        $this->assertStringContainsString('[DONE]', $body);
    }

    public function test_chat_stream_first_event_carries_chunks_used_count(): void
    {
        $this->app['config']->set('ai-chatbox.stream', true);

        $doc = $this->makeReadyDocument();
        $this->addChunk($doc, 'Laravel uses Eloquent ORM.', null);

        $this->mockGuzzle([$this->sseResponse(['Eloquent', ' handles', ' queries.'])]);

        $body = $this->withoutMiddleware()
            ->postJson("/ai-chatbox/rag/{$doc->id}/chat", ['message' => 'Eloquent queries'])
            ->streamedContent();

        // First data line must be the metadata event with chunks_used
        $firstLine = explode("\n", ltrim($body))[0];
        $this->assertStringStartsWith('data: ', $firstLine);
        $meta = json_decode(substr($firstLine, 6), true);
        $this->assertArrayHasKey('chunks_used', $meta);
        $this->assertGreaterThanOrEqual(1, $meta['chunks_used']);
    }

    public function test_chat_returns_502_json_when_stream_connection_fails(): void
    {
        $this->app['config']->set('ai-chatbox.stream', true);

        $doc = $this->makeReadyDocument();
        $this->addChunk($doc, 'Laravel ships with Artisan CLI.', null);

        $this->mockGuzzle([
            new ConnectException(
                'connection refused',
                new Request('POST', 'http://ai.example.com/v1/chat/completions'),
            ),
        ]);

        $this->withoutMiddleware()
            ->postJson("/ai-chatbox/rag/{$doc->id}/chat", ['message' => 'Tell me about Artisan'])
            ->assertStatus(502)
            ->assertJson(['error' => 'AI call failed. Check your provider config in the dashboard.']);
    }
}

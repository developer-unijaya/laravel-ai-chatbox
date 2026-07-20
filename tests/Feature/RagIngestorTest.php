<?php
namespace DeveloperUnijaya\AiChatbox\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use DeveloperUnijaya\AiChatbox\Models\RagChunk;
use DeveloperUnijaya\AiChatbox\Models\RagDocument;
use DeveloperUnijaya\AiChatbox\Services\RagIngestor;
use DeveloperUnijaya\AiChatbox\Tests\TestCase;

class RagIngestorTest extends TestCase
{
    use RefreshDatabase;

    private function embeddingResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => [['embedding' => [0.1, 0.2, 0.3]]],
        ]));
    }

    /** @param array<string,mixed> $overrides */
    private function cfg(array $overrides = []): array
    {
        return array_merge([
            'rag_embedding_url' => 'http://embed.example.com/v1/embeddings',
            'rag_embedding_model' => 'test-embed',
            'rag_embedding_token' => 'embed-token',
            'rag_embedding_timeout' => 10,
            'rag_chunk_size' => 500,
            'rag_chunk_overlap' => 50,
        ], $overrides);
    }

    private function doc(string $content = 'Some content.'): RagDocument
    {
        return RagDocument::create([
            'title' => 'Doc',
            'original_filename' => 'doc.txt',
            'file_type' => 'txt',
            'status' => 'processing',
            'chunk_count' => 0,
            'content' => $content,
        ]);
    }

    public function test_reingest_replaces_previous_chunks_atomically(): void
    {
        $this->mockGuzzle([$this->embeddingResponse(), $this->embeddingResponse()]);
        $doc = $this->doc('First version.');

        // Seed a stale chunk from a "previous" ingest.
        RagChunk::create(['document_id' => $doc->id, 'chunk_index' => 0, 'content' => 'STALE', 'embedding' => null]);

        (new RagIngestor())->ingest($doc, 'Fresh content one. Fresh content two.', $this->cfg());

        $doc->refresh();
        $this->assertSame('ready', $doc->status);
        // Old chunk is gone; new set is present and counted.
        $this->assertSame(0, RagChunk::where('document_id', $doc->id)->where('content', 'STALE')->count());
        $this->assertSame($doc->chunk_count, RagChunk::where('document_id', $doc->id)->count());
        $this->assertGreaterThan(0, $doc->chunk_count);
    }

    public function test_keyword_only_ingest_stores_chunks_without_embeddings(): void
    {
        // No embedding URL → keyword-only, no HTTP calls.
        $doc = $this->doc('Alpha beta gamma.');

        (new RagIngestor())->ingest($doc, 'Alpha beta gamma.', $this->cfg(['rag_embedding_url' => '']));

        $doc->refresh();
        $this->assertSame('ready', $doc->status);
        $this->assertGreaterThan(0, $doc->chunk_count);
        $this->assertNull(RagChunk::where('document_id', $doc->id)->first()->embedding);
    }

    public function test_empty_content_with_embedding_enabled_is_ready_not_failed(): void
    {
        // 0 chunks must NOT be reported as "all embeddings failed".
        $doc = $this->doc('');

        (new RagIngestor())->ingest($doc, '', $this->cfg());

        $doc->refresh();
        $this->assertSame('ready', $doc->status);
        $this->assertSame(0, $doc->chunk_count);
    }

    public function test_all_embeddings_failing_marks_document_failed(): void
    {
        // Embedding endpoint returns an unusable payload → embed() returns null.
        $this->mockGuzzle([
            new Response(200, [], json_encode(['unexpected' => true])),
        ]);
        $doc = $this->doc('Only one chunk here.');

        (new RagIngestor())->ingest($doc, 'Only one chunk here.', $this->cfg());

        $doc->refresh();
        $this->assertSame('failed', $doc->status);
        $this->assertStringContainsString('Embedding failed for all', $doc->error_message);
    }
}

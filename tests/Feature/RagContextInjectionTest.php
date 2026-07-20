<?php
namespace DeveloperUnijaya\AiChatbox\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use DeveloperUnijaya\AiChatbox\Engine\PromptBuilder;
use DeveloperUnijaya\AiChatbox\Models\RagChunk;
use DeveloperUnijaya\AiChatbox\Models\RagDocument;
use DeveloperUnijaya\AiChatbox\Tests\TestCase;

/**
 * Verifies the grounding behaviour of PromptBuilder's RAG injection:
 *   - when chunks match  → the strict rag_context_prompt is injected
 *   - when nothing match → the rag_no_context_prompt guard is injected instead
 *     of leaving the model completely unconstrained
 */
class RagContextInjectionTest extends TestCase
{
    use RefreshDatabase;

    private PromptBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new PromptBuilder();
    }

    private function cfg(array $overrides = []): array
    {
        return array_merge([
            'language' => 'English',
            'system_prompt' => 'You are helpful. Reply in {language}.',
            'rag_enabled' => true,
            'rag_context_prompt' => "Answer using ONLY this context.\n\nContext:\n{chunks}",
            'rag_no_context_prompt' => 'NO_CONTEXT_GUARD: refuse factual answers.',
            'rag_embedding_url' => 'http://embed.example.com/v1/embeddings',
            'rag_embedding_model' => 'test-embed',
            'rag_embedding_timeout' => 10,
        ], $overrides);
    }

    /** Find the RAG grounding instruction (system role) before the final user turn. */
    private function ragSystemMessage(array $messages): ?string
    {
        // Order is [system, ...history, RAG system, user]; the RAG instruction is
        // the last system-role entry before the trailing user message.
        $user = array_pop($messages);
        $this->assertSame('user', $user['role']);

        $last = end($messages);
        return ($last && $last['role'] === 'system') ? $last['content'] : null;
    }

    /**
     * The final user turn's content. Retrieved chunks are folded in here (as
     * delimited reference data), not into the system role.
     */
    private function ragUserContent(array $messages): string
    {
        $user = end($messages);
        $this->assertSame('user', $user['role']);

        return $user['content'];
    }

    // ── No-match → grounding guard ────────────────────────────────────────────

    public function test_guard_is_injected_when_no_chunks_match(): void
    {
        // Global RAG is disabled in the test harness, so retrieve() returns []
        // immediately — exactly the "nothing relevant found" path.
        $messages = $this->builder->build('What is the capital of France?', [], $this->cfg());

        $injected = $this->ragSystemMessage($messages);
        $this->assertNotNull($injected, 'A RAG system message should be injected.');
        $this->assertStringContainsString('NO_CONTEXT_GUARD', $injected);
    }

    public function test_no_guard_injected_when_guard_prompt_is_empty(): void
    {
        $messages = $this->builder->build('hello', [], $this->cfg(['rag_no_context_prompt' => '']));

        // system prompt + user message only — nothing injected.
        $this->assertCount(2, $messages);
        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame('user', $messages[1]['role']);
    }

    public function test_guard_is_not_injected_when_rag_disabled(): void
    {
        $messages = $this->builder->build('hello', [], $this->cfg(['rag_enabled' => false]));

        $this->assertCount(2, $messages);
        $this->assertStringNotContainsString('NO_CONTEXT_GUARD', json_encode($messages));
    }

    // ── Keyword fallback (no embedding URL) ──────────────────────────────────

    public function test_keyword_fallback_injects_matching_chunk_when_embedding_unavailable(): void
    {
        config(['ai-chatbox.rag_enabled' => true, 'ai-chatbox.rag_keyword_fallback' => true]);

        $doc = RagDocument::create([
            'title' => 'Shipping Policy',
            'original_filename' => 'shipping.txt',
            'file_type' => 'txt',
            'status' => 'ready',
            'chunk_count' => 1,
            'content' => 'Widgets ship within three business days.',
        ]);

        RagChunk::create([
            'document_id' => $doc->id,
            'chunk_index' => 0,
            'content' => 'Widgets ship within three business days.',
            'embedding' => null,
        ]);

        // No embedding URL → EmbeddingService returns null immediately → keyword fallback fires.
        $messages = $this->builder->build(
            'When do widgets ship?',
            [],
            $this->cfg(['rag_embedding_url' => '', 'api_token' => 'tok'])
        );

        $injected = $this->ragSystemMessage($messages);
        $this->assertNotNull($injected);
        $this->assertStringNotContainsString('NO_CONTEXT_GUARD', $injected);
        // Chunk text is delivered in the user turn, not the system role.
        $this->assertStringContainsString('Widgets ship within three business days.', $this->ragUserContent($messages));
    }

    public function test_keyword_fallback_respects_top_k_limit(): void
    {
        config(['ai-chatbox.rag_enabled' => true, 'ai-chatbox.rag_keyword_fallback' => true, 'ai-chatbox.rag_top_k' => 2]);

        $doc = RagDocument::create([
            'title' => 'Policies',
            'original_filename' => 'policies.txt',
            'file_type' => 'txt',
            'status' => 'ready',
            'chunk_count' => 5,
            'content' => 'policy content',
        ]);

        foreach (range(1, 5) as $i) {
            RagChunk::create([
                'document_id' => $doc->id,
                'chunk_index' => $i - 1,
                'content' => "Policy chunk {$i}: this policy applies.",
                'embedding' => null,
            ]);
        }

        $messages = $this->builder->build(
            'policy',
            [],
            $this->cfg(['rag_embedding_url' => '', 'api_token' => 'tok'])
        );

        $this->assertNotNull($this->ragSystemMessage($messages));
        // With top_k=2, only 2 of the 5 matching chunks should be injected —
        // the chunk text now lives in the user turn.
        $this->assertSame(2, substr_count($this->ragUserContent($messages), 'Policy chunk'));
    }

    public function test_keyword_fallback_ignores_words_shorter_than_3_chars(): void
    {
        config(['ai-chatbox.rag_enabled' => true, 'ai-chatbox.rag_keyword_fallback' => true]);

        $doc = RagDocument::create([
            'title' => 'Doc',
            'original_filename' => 'doc.txt',
            'file_type' => 'txt',
            'status' => 'ready',
            'chunk_count' => 1,
            'content' => 'Unrelated content here.',
        ]);

        RagChunk::create([
            'document_id' => $doc->id,
            'chunk_index' => 0,
            'content' => 'Unrelated content here.',
            'embedding' => null,
        ]);

        // Query "is it ok" → all words are ≤2 chars → no match → guard injected.
        $messages = $this->builder->build(
            'is it ok',
            [],
            $this->cfg(['rag_embedding_url' => '', 'api_token' => 'tok'])
        );

        $injected = $this->ragSystemMessage($messages);
        $this->assertStringContainsString('NO_CONTEXT_GUARD', $injected);
    }

    public function test_keyword_fallback_disabled_returns_guard_when_embedding_unavailable(): void
    {
        config(['ai-chatbox.rag_enabled' => true, 'ai-chatbox.rag_keyword_fallback' => false]);

        $doc = RagDocument::create([
            'title' => 'Doc',
            'original_filename' => 'doc.txt',
            'file_type' => 'txt',
            'status' => 'ready',
            'chunk_count' => 1,
            'content' => 'Widgets ship within three business days.',
        ]);

        RagChunk::create([
            'document_id' => $doc->id,
            'chunk_index' => 0,
            'content' => 'Widgets ship within three business days.',
            'embedding' => null,
        ]);

        $messages = $this->builder->build(
            'When do widgets ship?',
            [],
            $this->cfg(['rag_embedding_url' => '', 'api_token' => 'tok'])
        );

        $injected = $this->ragSystemMessage($messages);
        // Fallback disabled → embedding null → guard injected, chunk NOT injected.
        $this->assertStringContainsString('NO_CONTEXT_GUARD', $injected);
        $this->assertStringNotContainsString('Widgets ship', $injected);
    }

    public function test_keyword_fallback_skips_documents_with_no_chunks(): void
    {
        // A still-processing document has chunk_count=0 — should not appear in results.
        config(['ai-chatbox.rag_enabled' => true, 'ai-chatbox.rag_keyword_fallback' => true]);

        RagDocument::create([
            'title' => 'Processing Doc',
            'original_filename' => 'processing.txt',
            'file_type' => 'txt',
            'status' => 'processing',
            'chunk_count' => 0,
            'content' => 'Widgets ship within three business days.',
        ]);

        $messages = $this->builder->build(
            'When do widgets ship?',
            [],
            $this->cfg(['rag_embedding_url' => '', 'api_token' => 'tok'])
        );

        $injected = $this->ragSystemMessage($messages);
        $this->assertStringContainsString('NO_CONTEXT_GUARD', $injected);
        $this->assertStringNotContainsString('Widgets ship', $injected);
    }

    public function test_keyword_fallback_includes_failed_documents_that_have_chunks(): void
    {
        // A document whose embedding failed (status='failed') but whose text was chunked
        // should still be searchable via keyword retrieval in the live chatbox.
        config(['ai-chatbox.rag_enabled' => true, 'ai-chatbox.rag_keyword_fallback' => true]);

        $doc = RagDocument::create([
            'title' => 'Embed Failed Doc',
            'original_filename' => 'failed.txt',
            'file_type' => 'txt',
            'status' => 'failed',
            'chunk_count' => 1,
            'error_message' => 'Embedding failed for all 1 chunks.',
            'content' => 'Widgets ship within three business days.',
        ]);

        RagChunk::create([
            'document_id' => $doc->id,
            'chunk_index' => 0,
            'content' => 'Widgets ship within three business days.',
            'embedding' => null,
        ]);

        $messages = $this->builder->build(
            'When do widgets ship?',
            [],
            $this->cfg(['rag_embedding_url' => '', 'api_token' => 'tok'])
        );

        $injected = $this->ragSystemMessage($messages);
        $this->assertNotNull($injected);
        $this->assertStringNotContainsString('NO_CONTEXT_GUARD', $injected);
        $this->assertStringContainsString('Widgets ship within three business days.', $this->ragUserContent($messages));
    }

    // ── Match → strict context prompt ─────────────────────────────────────────

    public function test_context_prompt_is_injected_when_a_chunk_matches(): void
    {
        config(['ai-chatbox.rag_enabled' => true]);

        $doc = RagDocument::create([
            'title' => 'Doc',
            'original_filename' => 'doc.txt',
            'file_type' => 'txt',
            'status' => 'ready',
            'chunk_count' => 1,
            'content' => 'Widgets ship in 3 days.',
        ]);

        RagChunk::create([
            'document_id' => $doc->id,
            'chunk_index' => 0,
            'content' => 'Widgets ship in 3 days.',
            'embedding' => [1.0, 0.0, 0.0],
        ]);

        // Query embedding identical to the chunk → cosine similarity 1.0 ≥ threshold.
        $this->mockGuzzle([
            new Response(200, [], json_encode(['data' => [['embedding' => [1.0, 0.0, 0.0]]]])),
        ]);

        $messages = $this->builder->build('When do widgets ship?', [], $this->cfg());

        $injected = $this->ragSystemMessage($messages);
        $this->assertNotNull($injected);
        // Grounding instruction stays in the system role...
        $this->assertStringContainsString('Answer using ONLY this context', $injected);
        $this->assertStringNotContainsString('NO_CONTEXT_GUARD', $injected);
        // ...while the untrusted chunk text is folded into the user turn.
        $user = $this->ragUserContent($messages);
        $this->assertStringContainsString('Widgets ship in 3 days.', $user);
        $this->assertStringContainsString('<reference_material>', $user);
    }
}

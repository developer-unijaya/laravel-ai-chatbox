<?php
namespace SyafiqUnijaya\AiChatbox\Tests\Feature;

use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SyafiqUnijaya\AiChatbox\Engine\PromptBuilder;
use SyafiqUnijaya\AiChatbox\Models\RagChunk;
use SyafiqUnijaya\AiChatbox\Models\RagDocument;
use SyafiqUnijaya\AiChatbox\Tests\TestCase;

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

    /** Find the system message injected immediately before the final user turn. */
    private function ragSystemMessage(array $messages): ?string
    {
        // Order is [system, ...history, RAG system, user]; the RAG message is the
        // last system-role entry before the trailing user message.
        $user = array_pop($messages);
        $this->assertSame('user', $user['role']);

        $last = end($messages);
        return ($last && $last['role'] === 'system') ? $last['content'] : null;
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
        $this->assertStringContainsString('Answer using ONLY this context', $injected);
        $this->assertStringContainsString('Widgets ship in 3 days.', $injected);
        $this->assertStringNotContainsString('NO_CONTEXT_GUARD', $injected);
    }
}

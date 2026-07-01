<?php
namespace DeveloperUnijaya\AiChatbox\Services;

use Illuminate\Support\Facades\Log;
use DeveloperUnijaya\AiChatbox\Models\RagChunk;

class RagRetriever
{
    public function __construct(private readonly EmbeddingService $embedder)
    {}

    /**
     * Retrieve the most relevant document chunks for the given query.
     *
     * Returns an ordered list of chunk content strings (best match first).
     * Returns an empty array when RAG is disabled, the embedding call fails,
     * or no chunk clears the similarity threshold.
     *
     * @return string[]
     */
    public function retrieve(string $query): array
    {
        if (!config('ai-chatbox.rag_enabled')) {
            return [];
        }

        $topK = max(1, (int) config('ai-chatbox.rag_top_k', 10));
        $threshold = (float) config('ai-chatbox.rag_similarity_threshold', 0.2);

        $queryEmbedding = $this->embedder->embed($query);

        if ($queryEmbedding === null) {
            if (config('ai-chatbox.rag_keyword_fallback', true)) {
                Log::info('AI Chatbox RAG: embedding unavailable — falling back to keyword search.', [
                    'embedding_url' => $this->embedder->resolvedUrl(),
                ]);
                return $this->keywordSearch($query, $topK);
            }

            Log::warning('AI Chatbox RAG: Query embedding failed — RAG context will not be injected for this message.', [
                'embedding_url' => $this->embedder->resolvedUrl(),
                'embedding_model' => $this->embedder->resolvedModel(),
            ]);
            return [];
        }

        // Load only id + embedding for scoring — content is fetched below for top-K only.
        // This avoids transferring chunk text (~500 chars each) for every chunk in the corpus.
        // Use chunk_count > 0 (not status = 'ready') so documents whose embedding failed
        // but whose text was chunked successfully remain searchable via keyword fallback.
        $chunks = RagChunk::whereHas(
            'document',
            fn($q) => $q->where('chunk_count', '>', 0)
        )->get(['id', 'embedding']);

        if ($chunks->isEmpty()) {
            return [];
        }

        $scored = [];
        $nullEmbeddings = 0;
        $belowThreshold = 0;

        foreach ($chunks as $chunk) {
            $embedding = $chunk->embedding;

            if (!is_array($embedding) || count($embedding) === 0) {
                $nullEmbeddings++;
                continue;
            }

            if (count($embedding) !== count($queryEmbedding)) {
                Log::warning('AI Chatbox RAG: Chunk embedding dimension mismatch — skipping chunk.', [
                    'chunk_id' => $chunk->id,
                    'chunk_dims' => count($embedding),
                    'query_dims' => count($queryEmbedding),
                    'embedding_model' => $this->embedder->resolvedModel(),
                ]);
                continue;
            }

            $score = $this->cosineSimilarity($queryEmbedding, $embedding);

            if ($score >= $threshold) {
                $scored[] = ['id' => $chunk->id, 'score' => $score];
            } else {
                $belowThreshold++;
            }
        }

        if ($nullEmbeddings > 0) {
            Log::warning('AI Chatbox RAG: Skipped chunks with no stored embedding — reprocess the document to fix.', [
                'skipped_count' => $nullEmbeddings,
                'embedding_url' => $this->embedder->resolvedUrl(),
                'embedding_model' => $this->embedder->resolvedModel(),
            ]);
        }

        if (empty($scored)) {
            if ($belowThreshold > 0) {
                Log::info('AI Chatbox RAG: No chunks met the similarity threshold — consider lowering rag_similarity_threshold in your published config.', [
                    'threshold' => $threshold,
                    'chunks_scored' => $belowThreshold,
                ]);
            }
            return [];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        $topIds = array_column(array_slice($scored, 0, $topK), 'id');
        $contentMap = RagChunk::whereIn('id', $topIds)->pluck('content', 'id');

        return array_map(fn($id) => $contentMap[$id] ?? '', $topIds);
    }

    /**
     * Simple keyword fallback used when the embedding service is unavailable.
     *
     * Strips punctuation, removes common stop words, then returns chunks from
     * ready documents whose content contains any of the remaining terms.
     * Results are ordered by the number of matching terms (most relevant first).
     *
     * @return string[]
     */
    private function keywordSearch(string $query, int $topK): array
    {
        $stopWords = config('ai-chatbox.rag_keyword_stop_words', [
            'what', 'which', 'where', 'when', 'how', 'why', 'who',
            'the', 'this', 'that', 'these', 'those',
            'are', 'was', 'were', 'will', 'would', 'can', 'could',
            'should', 'shall', 'may', 'might', 'must',
            'have', 'has', 'had', 'does', 'did',
            'for', 'and', 'but', 'not', 'you', 'your',
        ]);

        $rawTokens = preg_split('/\s+/', mb_strtolower(trim($query))) ?: [];

        $words = array_values(array_unique(array_filter(
            array_map(fn($w) => preg_replace('/[^\p{L}\p{N}]/u', '', $w), $rawTokens),
            fn($w) => mb_strlen($w) >= 3 && !in_array($w, $stopWords, true)
        )));

        if (empty($words)) {
            return [];
        }

        // Score each matching chunk by how many search terms it contains,
        // so the most relevant chunks surface first instead of earliest by id.
        $chunks = RagChunk::whereHas('document', fn($q) => $q->where('chunk_count', '>', 0))
            ->where(function ($q) use ($words) {
                foreach ($words as $word) {
                    $q->orWhere('content', 'like', '%' . $word . '%');
                }
            })
            ->get(['id', 'content']);

        if ($chunks->isEmpty()) {
            return [];
        }

        $scored = $chunks->map(function ($chunk) use ($words) {
            $lower = mb_strtolower($chunk->content);
            $hits = array_sum(array_map(fn($w) => substr_count($lower, $w), $words));
            return ['content' => $chunk->content, 'hits' => $hits];
        })->sortByDesc('hits')->take($topK);

        return $scored->pluck('content')->toArray();
    }

    /**
     * Compute cosine similarity between two equal-length float vectors.
     * Returns 0.0 for zero-magnitude vectors.
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = $normA = $normB = 0.0;

        foreach ($a as $i => $val) {
            $dot += $val * $b[$i];
            $normA += $val * $val;
            $normB += $b[$i] * $b[$i];
        }

        $denom = sqrt($normA) * sqrt($normB);

        return $denom > 0.0 ? (float) ($dot / $denom) : 0.0;
    }
}

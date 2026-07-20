<?php
namespace DeveloperUnijaya\AiChatbox\Services;

use DeveloperUnijaya\AiChatbox\Models\RagChunk;
use Illuminate\Support\Facades\Log;

class RagRetriever
{
    /**
     * Number of chunks fetched per DB round-trip while scoring. Bounds peak
     * memory to one page of decoded embeddings instead of the whole corpus.
     */
    private const SCORING_PAGE_SIZE = 500;

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

        // Score every chunk against the query embedding. Chunks are STREAMED with
        // lazyById() rather than loaded all at once: a single embedding is ~1536
        // floats (~tens of KB decoded), so ->get() over a large corpus would hold
        // the entire set in memory and OOM. lazyById keeps only one page resident
        // at a time; we retain just the tiny {id, score} results. (A DB-side vector
        // index — pgvector / MySQL VECTOR — is the real answer at very large scale.)
        // Load only id + embedding here; content is fetched below for top-K only.
        // Use chunk_count > 0 (not status = 'ready') so documents whose embedding
        // failed but whose text was chunked stay searchable via keyword fallback.
        $scored = [];
        $nullEmbeddings = 0;
        $belowThreshold = 0;
        $scanned = 0;

        RagChunk::whereHas('document', fn($q) => $q->where('chunk_count', '>', 0))
            ->select(['id', 'embedding'])
            ->lazyById(self::SCORING_PAGE_SIZE)
            ->each(function (RagChunk $chunk) use (
                &$scored, &$nullEmbeddings, &$belowThreshold, &$scanned, $queryEmbedding, $threshold
            ) {
                $scanned++;
                $embedding = $chunk->embedding;

                if (!is_array($embedding) || count($embedding) === 0) {
                    $nullEmbeddings++;
                    return;
                }

                if (count($embedding) !== count($queryEmbedding)) {
                    Log::warning('AI Chatbox RAG: Chunk embedding dimension mismatch — skipping chunk.', [
                        'chunk_id' => $chunk->id,
                        'chunk_dims' => count($embedding),
                        'query_dims' => count($queryEmbedding),
                        'embedding_model' => $this->embedder->resolvedModel(),
                    ]);
                    return;
                }

                $score = $this->cosineSimilarity($queryEmbedding, $embedding);

                if ($score >= $threshold) {
                    $scored[] = ['id' => $chunk->id, 'score' => $score];
                } else {
                    $belowThreshold++;
                }
            });

        if ($scanned === 0) {
            return [];
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

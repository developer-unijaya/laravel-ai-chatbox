<?php
namespace DeveloperUnijaya\AiChatbox\Services;

use DeveloperUnijaya\AiChatbox\Models\RagChunk;
use DeveloperUnijaya\AiChatbox\Models\RagDocument;
use Illuminate\Support\Facades\Log;

/**
 * Chunks a document's content and persists the chunks — with embeddings when an
 * embedding endpoint is configured, otherwise keyword-only — and updates the
 * document's status/chunk_count.
 *
 * Extracted from RagController so both the upload flow and the
 * `ai-chatbox:graphify` import command share one ingestion code path.
 */
class RagIngestor
{
    /**
     * @param  array  $cfg  Resolved active-provider config (AiManager::resolveConfig()).
     */
    public function ingest(RagDocument $document, string $content, array $cfg): void
    {
        // Embedding N chunks via HTTP can take well over 30 s on local models.
        // Lift PHP's execution time limit for this admin-only operation.
        set_time_limit((int) config('ai-chatbox.rag_processing_timeout', 0));

        $embeddingUrl = $cfg['rag_embedding_url'] ?? '';
        $skipEmbedding = empty($embeddingUrl);

        $chunker = new DocumentChunker();
        $chunkSize = (int) ($cfg['rag_chunk_size'] ?? 500);
        $overlap = (int) ($cfg['rag_chunk_overlap'] ?? 50);

        $textChunks = $chunker->chunk($content, $chunkSize, $overlap);

        // Clear any previous chunks
        $document->chunks()->delete();

        $count = 0;
        $embedFailed = 0;
        $embedSvc = null;

        if (!$skipEmbedding) {
            $embeddingToken = EmbeddingService::resolveToken($cfg);
            $embedSvc = new EmbeddingService(
                $embeddingUrl,
                $cfg['rag_embedding_model'] ?? null,
                $embeddingToken,
                (int) ($cfg['rag_embedding_timeout'] ?? 10),
            );
        }

        foreach ($textChunks as $i => $chunkText) {
            $embedding = null;

            if (!$skipEmbedding) {
                $embedding = $embedSvc->embed($chunkText);

                if ($embedding === null) {
                    $embedFailed++;
                    Log::warning('AI Chatbox RAG: Chunk embedding failed — chunk stored without a vector.', [
                        'document_id' => $document->id,
                        'chunk_index' => $i,
                        'embedding_url' => $embeddingUrl,
                        'embedding_model' => $cfg['rag_embedding_model'] ?? '',
                    ]);
                }
            }

            RagChunk::create([
                'document_id' => $document->id,
                'chunk_index' => $i,
                'content' => $chunkText,
                'embedding' => $embedding,
            ]);
            $count++;
        }

        // No embedding URL configured — stored for keyword-only retrieval.
        if ($skipEmbedding) {
            $document->update([
                'status' => 'ready',
                'chunk_count' => $count,
                'error_message' => null,
            ]);
            return;
        }

        if ($embedFailed === $count) {
            // Embedding URL was set but every call failed — document is unusable for vector retrieval.
            $document->update([
                'status' => 'failed',
                'chunk_count' => $count,
                'error_message' => "Embedding failed for all {$count} chunks. Check embedding URL ("
                . $embeddingUrl . ') and embedding model ('
                . ($cfg['rag_embedding_model'] ?? '') . ').',
            ]);
            Log::error('AI Chatbox RAG: All chunk embeddings failed — document marked as failed.', [
                'document_id' => $document->id,
                'title' => $document->title,
                'embedding_url' => $embeddingUrl,
            ]);
            return;
        }

        $errorMessage = $embedFailed > 0
        ? "{$embedFailed} of {$count} chunks failed to embed and will be skipped during vector retrieval."
        : null;

        $document->update([
            'status' => 'ready',
            'chunk_count' => $count,
            'error_message' => $errorMessage,
        ]);
    }
}

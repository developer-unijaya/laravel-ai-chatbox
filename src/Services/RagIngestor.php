<?php
namespace DeveloperUnijaya\AiChatbox\Services;

use DeveloperUnijaya\AiChatbox\Models\RagChunk;
use DeveloperUnijaya\AiChatbox\Models\RagDocument;
use Illuminate\Support\Facades\DB;
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

        $embedSvc = null;
        if (!$skipEmbedding) {
            $embedSvc = new EmbeddingService(
                $embeddingUrl,
                $cfg['rag_embedding_model'] ?? null,
                EmbeddingService::resolveToken($cfg),
                (int) ($cfg['rag_embedding_timeout'] ?? 10),
            );
        }

        // Phase 1 — embed each chunk and BUFFER the rows. The document's existing
        // chunks are left untouched during this potentially minutes-long phase,
        // so a crash here (OOM, deploy, fatal) leaves the previous version fully
        // intact rather than half-deleted and stuck.
        $now = now();
        $rows = [];
        $embedFailed = 0;

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

            $rows[] = [
                'document_id' => $document->id,
                'chunk_index' => $i,
                'content' => $chunkText,
                // insert() bypasses the model cast, so encode the vector here.
                'embedding' => $embedding !== null ? json_encode($embedding) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $count = count($rows);

        // Decide the final document state.
        if ($skipEmbedding) {
            $status = 'ready';
            $errorMessage = null;
        } elseif ($count > 0 && $embedFailed === $count) {
            // Embedding URL was set but every call failed — unusable for vector retrieval.
            $status = 'failed';
            $errorMessage = "Embedding failed for all {$count} chunks. Check embedding URL ("
                . $embeddingUrl . ') and embedding model ('
                . ($cfg['rag_embedding_model'] ?? '') . ').';
            Log::error('AI Chatbox RAG: All chunk embeddings failed — document marked as failed.', [
                'document_id' => $document->id,
                'title' => $document->title,
                'embedding_url' => $embeddingUrl,
            ]);
        } else {
            $status = 'ready';
            $errorMessage = $embedFailed > 0
                ? "{$embedFailed} of {$count} chunks failed to embed and will be skipped during vector retrieval."
                : null;
        }

        // Phase 2 — swap the chunks and update the document atomically, so
        // retrieval never sees a half-replaced set and any failure here rolls
        // back cleanly (leaving the old chunks in place). Rows are batch-inserted
        // rather than one query per chunk.
        DB::transaction(function () use ($document, $rows, $status, $count, $errorMessage) {
            $document->chunks()->delete();

            foreach (array_chunk($rows, 500) as $batch) {
                RagChunk::insert($batch);
            }

            $document->update([
                'status' => $status,
                'chunk_count' => $count,
                'error_message' => $errorMessage,
            ]);
        });
    }
}

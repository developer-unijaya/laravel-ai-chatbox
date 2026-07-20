<?php
namespace DeveloperUnijaya\AiChatbox\Http\Controllers;

use DeveloperUnijaya\AiChatbox\AiManager;
use DeveloperUnijaya\AiChatbox\Engine\PromptBuilder;
use DeveloperUnijaya\AiChatbox\Models\RagDocument;
use DeveloperUnijaya\AiChatbox\Services\EmbeddingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class RagController extends Controller
{
    public function __construct(
        private readonly AiManager $aiManager,
        private readonly PromptBuilder $promptBuilder,
    ) {}

    // ── List ─────────────────────────────────────────────────────────────────

    public function index(): View
    {
        $documents = RagDocument::withCount([
            'chunks',
            'chunks as vector_count' => fn($q) => $q->whereNotNull('embedding'),
        ])->latest()->get();
        $cfg = $this->effectiveConfig();

        $embeddingUrl = $cfg['rag_embedding_url'] ?? '';
        $embeddingModel = $cfg['rag_embedding_model'] ?? '';

        $embeddingConfigured = !empty($embeddingUrl) && !empty($embeddingModel);
        // URL absent → keyword-only mode: uploads work, no vectors generated.
        // URL present but model absent → broken config: uploads disabled.
        $keywordOnlyMode = empty($embeddingUrl);
        $uploadEnabled = $embeddingConfigured || $keywordOnlyMode;

        return view('ai-chatbox::rag', [
            'documents' => $documents,
            'ragEnabled' => (bool) ($cfg['rag_enabled'] ?? false),
            'embeddingUrl' => $embeddingUrl,
            'embeddingModel' => $embeddingModel,
            'embeddingConfigured' => $embeddingConfigured,
            'keywordOnlyMode' => $keywordOnlyMode,
            'uploadEnabled' => $uploadEnabled,
            'themeColor' => config('ai-chatbox.theme_color', '#0dad35'),
            'colorScheme' => config('ai-chatbox.color_scheme', 'auto'),
        ]);
    }

    // ── Upload ────────────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:txt,md', 'max:10240'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');
        $title = $request->input('title') ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $type = strtolower($file->getClientOriginalExtension() ?: 'txt');

        $content = file_get_contents($file->getRealPath());

        if ($content === false || trim($content) === '') {
            return back()->withErrors(['file' => 'The uploaded file is empty or unreadable.']);
        }

        $document = RagDocument::create([
            'title' => $title,
            'original_filename' => $file->getClientOriginalName(),
            'file_type' => $type,
            'status' => 'processing',
            'chunk_count' => 0,
            'content' => $content,
        ]);

        try {
            $this->processDocument($document, $content);
            return redirect()->route('ai-chatbox.rag.index')
                ->with('success', "'{$title}' indexed successfully ({$document->chunk_count} chunks).");

        } catch (Throwable $e) {
            $document->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            return redirect()->route('ai-chatbox.rag.index')
                ->with('error', "Failed to index '{$title}': " . $e->getMessage());
        }
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function destroy(int $id): RedirectResponse
    {
        $document = RagDocument::findOrFail($id);
        $title = $document->title;

        $document->chunks()->delete();
        $document->delete();

        return redirect()->route('ai-chatbox.rag.index')
            ->with('success', "'{$title}' deleted.");
    }

    // ── Reprocess (re-chunk + re-embed) ───────────────────────────────────────

    public function reprocess(int $id): RedirectResponse
    {
        $document = RagDocument::findOrFail($id);

        if (empty($document->content)) {
            return redirect()->route('ai-chatbox.rag.index')
                ->with('error', "Cannot reprocess '{$document->title}': original content was not stored.");
        }

        $document->update(['status' => 'processing', 'error_message' => null]);

        try {
            $this->processDocument($document, $document->content);
            return redirect()->route('ai-chatbox.rag.index')
                ->with('success', "'{$document->title}' reprocessed successfully ({$document->chunk_count} chunks).");

        } catch (Throwable $e) {
            $document->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            return redirect()->route('ai-chatbox.rag.index')
                ->with('error', "Failed to reprocess '{$document->title}': " . $e->getMessage());
        }
    }

    // ── Chunks viewer ────────────────────────────────────────────────────────

    public function chunks(int $id): View
    {
        $document = RagDocument::findOrFail($id);
        $chunks = $document->chunks()->get(['id', 'chunk_index', 'content', 'embedding']);
        $cfg = $this->effectiveConfig();

        $apiUrl = $cfg['api_url'] ?? '';
        $apiToken = $cfg['api_token'] ?? '';
        $providerConfigured = !empty($apiUrl) && !empty($apiToken);
        $providerIssue = match (true) {
            empty($apiUrl) => 'api_url is not configured for the active provider.',
            empty($apiToken) => 'api_token is not configured for the active provider.',
            default => null,
        };

        return view('ai-chatbox::rag-chunks', [
            'document' => $document,
            'chunks' => $chunks,
            'ragEnabled' => (bool) ($cfg['rag_enabled'] ?? false),
            'providerConfigured' => $providerConfigured,
            'providerIssue' => $providerIssue,
            'streamEnabled' => (bool) ($cfg['stream'] ?? false),
            'themeColor' => config('ai-chatbox.theme_color', '#0dad35'),
            'colorScheme' => config('ai-chatbox.color_scheme', 'auto'),
        ]);
    }

    // ── Chat test endpoint ────────────────────────────────────────────────────

    public function chat(Request $request, int $id): JsonResponse | StreamedResponse
    {
        $request->validate(['message' => ['required', 'string', 'max:2000']]);

        $document = RagDocument::findOrFail($id);

        if ($document->chunk_count === 0) {
            return response()->json(['error' => 'Document has no chunks to retrieve from.'], 422);
        }

        $cfg = $this->effectiveConfig();
        $query = $request->input('message');

        $chunks = $document->chunks()->get(['id', 'chunk_index', 'content', 'embedding']);
        $context = $this->retrieveContext($chunks, $query, $cfg);
        $messages = $this->promptBuilder->buildWithChunks($query, [], $cfg, $context);
        $chunksUsed = count($context);

        if ($cfg['stream'] ?? false) {
            try {
                $streamReader = $this->aiManager->resolveEngine($cfg)->beginStream($messages, $cfg);
            } catch (Throwable $e) {
                return response()->json(['error' => 'AI call failed. Check your provider config in the dashboard.'], 502);
            }

            return response()->stream(
                function () use ($streamReader, $chunksUsed) {
                    echo 'data: ' . json_encode(['chunks_used' => $chunksUsed]) . "\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }

                    flush();

                    try {
                        $streamReader(function (string $token) {
                            echo 'data: ' . json_encode(['token' => $token]) . "\n\n";
                            if (ob_get_level() > 0) {
                                ob_flush();
                            }

                            flush();
                        });
                    } catch (Throwable $e) {
                        // Stream read error — end gracefully
                    }

                    echo "data: [DONE]\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }

                    flush();
                },
                200,
                [
                    'Content-Type' => 'text/event-stream',
                    'Cache-Control' => 'no-cache',
                    'X-Accel-Buffering' => 'no',
                    'Connection' => 'keep-alive',
                ]
            );
        }

        try {
            $reply = $this->aiManager->resolveEngine($cfg)->complete($messages, $cfg);
            return response()->json(['reply' => $reply, 'chunks_used' => $chunksUsed]);
        } catch (Throwable $e) {
            return response()->json(['error' => 'AI call failed. Check your provider config in the dashboard.'], 502);
        }
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function effectiveConfig(): array
    {
        return $this->aiManager->resolveConfig(
            config('ai-chatbox.active_provider', 'default')
        );
    }

    private function retrieveContext($chunks, string $query, array $cfg): array
    {
        $topK = max(1, (int) ($cfg['rag_top_k'] ?? 10));
        $threshold = (float) ($cfg['rag_similarity_threshold'] ?? 0.2);

        $embeddableChunks = $chunks->filter(
            fn($c) => is_array($c->embedding) && count($c->embedding) > 0
        );

        if ($embeddableChunks->isNotEmpty()) {
            $embeddingUrl = $cfg['rag_embedding_url'] ?? '';

            if (!empty($embeddingUrl)) {
                $embeddingToken = EmbeddingService::resolveToken($cfg);
                $embedSvc = new EmbeddingService(
                    $embeddingUrl,
                    $cfg['rag_embedding_model'] ?? null,
                    $embeddingToken,
                    (int) ($cfg['rag_embedding_timeout'] ?? 10),
                );

                $queryEmbedding = $embedSvc->embed($query);

                if ($queryEmbedding !== null) {
                    $scored = [];
                    foreach ($embeddableChunks as $chunk) {
                        $emb = $chunk->embedding;
                        if (count($emb) !== count($queryEmbedding)) {
                            continue;
                        }
                        $score = $this->cosineSimilarity($queryEmbedding, $emb);
                        if ($score >= $threshold) {
                            $scored[] = ['content' => $chunk->content, 'score' => $score];
                        }
                    }

                    if (!empty($scored)) {
                        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
                        return array_column(array_slice($scored, 0, $topK), 'content');
                    }
                }
            }
        }

        // Keyword fallback
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

        return $chunks->filter(function ($chunk) use ($words) {
            $lower = mb_strtolower($chunk->content);
            foreach ($words as $word) {
                if (str_contains($lower, $word)) {
                    return true;
                }
            }
            return false;
        })->map(function ($chunk) use ($words) {
            $lower = mb_strtolower($chunk->content);
            $hits = array_sum(array_map(fn($w) => substr_count($lower, $w), $words));
            return ['content' => $chunk->content, 'hits' => $hits];
        })->sortByDesc('hits')->take($topK)->pluck('content')->toArray();
    }

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

    private function processDocument(RagDocument $document, string $content): void
    {
        app(\DeveloperUnijaya\AiChatbox\Services\RagIngestor::class)
            ->ingest($document, $content, $this->effectiveConfig());
    }
}

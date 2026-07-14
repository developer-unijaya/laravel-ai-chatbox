<?php
namespace DeveloperUnijaya\AiChatbox\Orchestration\Tools;

use DeveloperUnijaya\AiChatbox\AiManager;
use DeveloperUnijaya\AiChatbox\Orchestration\Contracts\ToolInterface;
use DeveloperUnijaya\AiChatbox\Services\EmbeddingService;
use DeveloperUnijaya\AiChatbox\Services\RagRetriever;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Lets the model search the RAG knowledge base on demand (vector or keyword,
 * following the same resolution as automatic RAG injection). This turns RAG from a
 * push (always injected) into a pull (model decides when it needs to look something up).
 *
 * Off by default — add it to `orchestrator_tools` to enable. Resolves embedding
 * settings from the active provider, exactly like PromptBuilder's auto-injection.
 */
class KnowledgeBaseSearchTool implements ToolInterface
{
    public function __construct(private readonly AiManager $aiManager)
    {}

    public function name(): string
    {
        return 'knowledge_base_search';
    }

    public function description(): string
    {
        return 'Search the application knowledge base for passages relevant to a query. '
            . 'Use when the user asks about documented information that may live in uploaded documents.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'The search query — what to look up in the knowledge base.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function authorize(?Request $request = null): bool
    {
        return true;
    }

    public function handle(array $arguments): mixed
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return ['error' => 'Empty query.'];
        }

        $cfg = $this->aiManager->resolveConfig(config('ai-chatbox.active_provider', 'default'));

        try {
            $embeddingToken = ($cfg['rag_embedding_token'] ?? '') ?: ($cfg['api_token'] ?? null);
            $retriever = new RagRetriever(new EmbeddingService(
                $cfg['rag_embedding_url'] ?? null,
                $cfg['rag_embedding_model'] ?? null,
                $embeddingToken,
                (int) ($cfg['rag_embedding_timeout'] ?? 10),
            ));
            $chunks = $retriever->retrieve($query);
        } catch (\Throwable $e) {
            Log::warning('AI Chatbox orchestrator: knowledge_base_search failed.', ['error' => $e->getMessage()]);
            return ['error' => 'Knowledge base search failed.'];
        }

        return [
            'query' => $query,
            'match_count' => count($chunks),
            'passages' => array_values($chunks),
        ];
    }
}

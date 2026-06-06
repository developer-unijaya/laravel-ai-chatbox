<?php
namespace SyafiqUnijaya\AiChatbox\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use SyafiqUnijaya\AiChatbox\AiManager;
use SyafiqUnijaya\AiChatbox\Engine\Exceptions\AiEngineException;
use SyafiqUnijaya\AiChatbox\Engine\HealthChecker;
use SyafiqUnijaya\AiChatbox\Engine\PromptBuilder;
use SyafiqUnijaya\AiChatbox\Memory\ContextManager;
use SyafiqUnijaya\AiChatbox\Memory\Contracts\ConversationRepositoryInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Layer 3 — UI
 *
 * Handles HTTP request/response only. All AI calls are delegated to the
 * Engine layer; all history persistence is delegated to the Memory layer.
 */
class ChatboxController extends Controller
{
    public function __construct(
        private readonly AiManager $aiManager,
        private readonly ConversationRepositoryInterface $repository,
        private readonly PromptBuilder $promptBuilder,
        private readonly ContextManager $contextManager,
        private readonly HealthChecker $healthChecker,
    ) {}

    // ── Endpoints ─────────────────────────────────────────────────────────────

    public function sendMessage(Request $request): JsonResponse
    {
        $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'thread_id' => ['nullable', 'string', 'max:36'],
        ]);

        $cfg = $this->effectiveConfig();
        $threadId = $request->input('thread_id', '');
        $userMsg = $request->input('message');

        // Memory layer: retrieve full history, then trim a copy for API context only
        $fullHistory = $this->repository->getHistory($threadId);
        $system = $this->promptBuilder->systemMessages($cfg);
        $contextHistory = $this->contextManager->trim($fullHistory, $system, $userMsg, $cfg, $this->ragBudget($cfg));

        // Persist the user message immediately before calling the AI
        if ($cfg['history_enabled'] ?? true) {
            try {
                $this->repository->saveMessage($threadId, 'user', $userMsg);
            } catch (Throwable $e) {
                Log::error('AI Chatbox: failed to save user message', [
                    'thread_id' => $threadId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Engine layer: build prompt and call AI
        $messages = $this->promptBuilder->build($userMsg, $contextHistory, $cfg);

        try {
            $reply = $this->aiManager->resolveEngine($cfg)->complete($messages, $cfg);
        } catch (AiEngineException $e) {
            return $this->engineError($e);
        }

        // Persist the AI reply and trim history to the configured limit
        if ($cfg['history_enabled'] ?? true) {
            try {
                $fullHistory[] = ['role' => 'user', 'content' => $userMsg];
                $fullHistory[] = ['role' => 'assistant', 'content' => $reply];
                $this->repository->saveHistory($threadId, $fullHistory);
                $this->repository->trimToLimit($threadId, (int) ($cfg['history_limit'] ?? 50));
            } catch (Throwable $e) {
                Log::error('AI Chatbox: failed to save AI reply', [
                    'thread_id' => $threadId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['reply' => $reply]);
    }

    public function streamMessage(Request $request): StreamedResponse | JsonResponse
    {
        $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'thread_id' => ['nullable', 'string', 'max:36'],
        ]);

        $cfg = $this->effectiveConfig();
        $threadId = $request->input('thread_id', '');
        $userMsg = $request->input('message');

        // Memory layer: retrieve full history, then trim a copy for API context only
        $fullHistory = $this->repository->getHistory($threadId);
        $system = $this->promptBuilder->systemMessages($cfg);
        $contextHistory = $this->contextManager->trim($fullHistory, $system, $userMsg, $cfg, $this->ragBudget($cfg));

        $messages = $this->promptBuilder->build($userMsg, $contextHistory, $cfg);
        $useHistory = $cfg['history_enabled'] ?? true;
        $historyLimit = (int) ($cfg['history_limit'] ?? 50);

        // Persist the user message immediately before the stream begins
        if ($useHistory) {
            try {
                $this->repository->saveMessage($threadId, 'user', $userMsg);
            } catch (Throwable $e) {
                Log::error('AI Chatbox: failed to save user message', [
                    'thread_id' => $threadId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Establish the AI connection before starting the HTTP stream response.
        // This ensures connection / config errors can still return proper JSON (non-200).
        try {
            $streamReader = $this->aiManager->resolveEngine($cfg)->beginStream($messages, $cfg);
        } catch (AiEngineException $e) {
            return $this->engineError($e);
        }

        return response()->stream(
            function () use ($streamReader, $threadId, $userMsg, $fullHistory, $useHistory, $historyLimit) {
                try {
                    $fullReply = $streamReader(function (string $token) {
                        echo 'data: ' . json_encode(['token' => $token]) . "\n\n";
                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
                    });
                } catch (AiEngineException $e) {
                    Log::error('AI Chatbox stream error', [
                        'code' => $e->errorCode,
                        'message' => $e->getMessage(),
                    ]);
                    $fullReply = '';
                } catch (Throwable $e) {
                    Log::error('AI Chatbox stream error', ['message' => $e->getMessage()]);
                    $fullReply = '';
                }

                if ($useHistory && $fullReply !== '') {
                    try {
                        $fullHistory[] = ['role' => 'user', 'content' => $userMsg];
                        $fullHistory[] = ['role' => 'assistant', 'content' => $fullReply];
                        $this->repository->saveHistory($threadId, $fullHistory);
                        $this->repository->trimToLimit($threadId, $historyLimit);
                        session()->save(); // required because the response has already started
                    } catch (Throwable $e) {
                        Log::error('AI Chatbox: failed to save AI reply', [
                            'thread_id' => $threadId,
                            'error' => $e->getMessage(),
                        ]);
                    }
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

    public function clearHistory(Request $request): JsonResponse
    {
        $request->validate([
            'thread_id' => ['nullable', 'string', 'max:36'],
        ]);

        $this->repository->clear($request->input('thread_id', ''));

        return response()->json(['status' => 'ok']);
    }

    public function healthCheck(Request $request): JsonResponse
    {
        $providerName = $request->query('provider');
        try {
            $cfg = $providerName
            ? $this->aiManager->resolveConfig((string) $providerName)
            : $this->effectiveConfig();
        } catch (\InvalidArgumentException) {
            return response()->json(['error' => 'Unknown provider.'], 400);
        }

        $result = $this->healthChecker->check($cfg);
        $status = $result['status'] === 'online' ? 200 : 503;

        return response()->json($result, $status);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Return the effective config for the chatbox widget, resolved from the
     * active provider. api_url, api_token, and api_model always come from
     * the provider entry — never from top-level env vars.
     */
    private function effectiveConfig(): array
    {
        return $this->aiManager->resolveConfig(
            config('ai-chatbox.active_provider', 'default')
        );
    }

    /**
     * Estimate the token budget that RAG context will consume so ContextManager
     * can reserve headroom before trimming history.
     */
    private function ragBudget(array $cfg): int
    {
        if (!($cfg['rag_enabled'] ?? false)) {
            return 0;
        }

        $topK = max(1, (int) ($cfg['rag_top_k'] ?? 10));
        $chunkSize = max(1, (int) ($cfg['rag_chunk_size'] ?? 500));

        return $topK * $chunkSize;
    }

    private function engineError(AiEngineException $e): JsonResponse
    {
        Log::error('AI Chatbox error', [
            'code' => $e->errorCode,
            'status' => $e->getHttpStatus(),
            'message' => $e->getMessage(),
        ]);

        return response()->json([
            'error' => $e->getMessage(),
            'code' => $e->errorCode,
        ], $e->getHttpStatus());
    }
}

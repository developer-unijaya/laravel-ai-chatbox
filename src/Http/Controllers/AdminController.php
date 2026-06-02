<?php
namespace SyafiqUnijaya\AiChatbox\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use SyafiqUnijaya\AiChatbox\AiManager;
use SyafiqUnijaya\AiChatbox\Memory\Models\Conversation;
use SyafiqUnijaya\AiChatbox\Memory\Models\Message;
use SyafiqUnijaya\AiChatbox\Models\RagChunk;
use SyafiqUnijaya\AiChatbox\Models\RagDocument;

class AdminController extends Controller
{
    private const TOKEN_PLACEHOLDERS = [
        'your-api-key', 'your-api-token', 'sk-xxx', 'changeme', 'secret',
        'your-ollama-token', 'your-token', 'placeholder', 'token',
    ];

    private const URL_PLACEHOLDERS = ['your-url', 'http://your-host', 'https://your-host'];

    private const PROD_ENVS = ['production', 'prod', 'live'];

    public function __construct(private readonly AiManager $aiManager)
    {}

    // ── Public endpoints ──────────────────────────────────────────────────────

    public function index(): View
    {
        $cfg = $this->resolveEffectiveConfig();
        $ragEnabled = (bool) ($cfg['rag_enabled'] ?? false);
        $ragStats = $ragEnabled ? $this->collectRagStats() : null;

        $rawToggleIcon = $cfg['toggle_icon'] ?? null;
        $toggleIconUrl = $rawToggleIcon
        ? (preg_match('#^https?://#i', $rawToggleIcon) ? $rawToggleIcon : asset($rawToggleIcon))
        : null;

        return view('ai-chatbox::admin', [
            'ragStats' => $ragStats,
            'memoryStats' => $this->collectMemoryStats(),
            'configGroups' => $this->buildConfigGroups($cfg),
            'namedProviders' => $cfg['providers'] ?? [],
            'env' => $this->collectEnv(),
            'diagnostics' => $this->buildDiagnostics($cfg, $ragStats, $ragEnabled),
            'themeColor' => $cfg['theme_color'] ?? '#4f46e5',
            'colorScheme' => $cfg['color_scheme'] ?? 'auto',
            'ragEnabled' => $ragEnabled,
            'frontend' => $cfg['frontend'] ?? 'vue',
            'toggleIconUrl' => $toggleIconUrl,
            'ragUrl' => route('ai-chatbox.rag.index'),
            'conversationsUrl' => config('ai-chatbox.memory_driver') === 'database'
            ? route('ai-chatbox.admin.conversations')
            : null,
        ]);
    }

    public function conversations(): View
    {
        return view('ai-chatbox::admin-conversations', [
            'themeColor' => config('ai-chatbox.theme_color', '#4f46e5'),
            'colorScheme' => config('ai-chatbox.color_scheme', 'auto'),
            'dataUrl' => route('ai-chatbox.admin.conversations.data'),
            'messagesUrl' => rtrim(route('ai-chatbox.admin.conversations'), '/') . '/__id__/messages',
        ]);
    }

    public function conversationsData(Request $request): JsonResponse
    {
        $perPage = 20;
        $page = max(1, (int) $request->query('page', 1));
        $search = trim((string) $request->query('search', ''));

        $query = Conversation::withCount('messages')
            ->with('latestMessage')
            ->orderByDesc('updated_at');

        if ($search !== '') {
            $query->whereHas('messages', fn($q) => $q->where('content', 'like', '%' . $search . '%'));
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $userNames = $this->resolveUserNames($paginator->getCollection()->pluck('user_id'));

        $items = $paginator->getCollection()->map(function (Conversation $c) use ($userNames) {
            $last = $c->latestMessage;
            return [
                'id' => $c->id,
                'thread_id' => $c->thread_id,
                'user_id' => $c->user_id,
                'user_name' => $userNames[$c->user_id] ?? null,
                'messages_count' => $c->messages_count,
                'last_role' => $last?->role,
                'last_preview' => $last ? mb_strimwidth($last->content, 0, 100, '…') : null,
                'created_at' => $c->created_at?->diffForHumans(),
                'updated_at' => $c->updated_at?->diffForHumans(),
            ];
        });

        return response()->json([
            'data' => $items,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
        ]);
    }

    public function conversationMessages(Request $request, int $id): JsonResponse
    {
        $conversation = Conversation::findOrFail($id);

        $perPage = 50;
        $page = max(1, (int) $request->query('page', 1));
        $paginator = Message::where('conversation_id', $id)
            ->orderBy('id')
            ->paginate($perPage, ['id', 'role', 'content', 'created_at'], 'page', $page);

        $messages = $paginator->getCollection()->map(fn(Message $m) => [
            'id' => $m->id,
            'role' => $m->role,
            'content' => $m->content,
            'created_at' => $m->created_at?->format('H:i · d M Y'),
        ]);

        $userName = null;
        if ($conversation->user_id) {
            $names = $this->resolveUserNames(collect([$conversation->user_id]));
            $userName = $names[$conversation->user_id] ?? null;
        }

        return response()->json([
            'thread_id' => $conversation->thread_id,
            'user_id' => $conversation->user_id,
            'user_name' => $userName,
            'messages' => $messages,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
        ]);
    }

    // ── Dashboard data builders ───────────────────────────────────────────────

    private function resolveEffectiveConfig(): array
    {
        try {
            return $this->aiManager->resolveConfig(
                config('ai-chatbox.active_provider', 'default')
            );
        } catch (\InvalidArgumentException) {
            return config('ai-chatbox', []);
        }
    }

    private function collectRagStats(): array
    {
        return [
            'documents' => RagDocument::count(),
            'documents_ready' => RagDocument::where('status', 'ready')->count(),
            'documents_failed' => RagDocument::where('status', 'failed')->count(),
            'total_chunks' => RagChunk::count(),
            'embedded_chunks' => RagChunk::whereNotNull('embedding')->count(),
            'null_chunks' => RagChunk::whereNull('embedding')->count(),
        ];
    }

    private function collectMemoryStats(): ?array
    {
        if (config('ai-chatbox.memory_driver') !== 'database') {
            return null;
        }

        try {
            return [
                'conversations' => Conversation::count(),
                'messages' => Message::count(),
            ];
        } catch (\Throwable) {
            return ['error' => 'Run php artisan migrate to create the conversations/messages tables.'];
        }
    }

    private function collectEnv(): array
    {
        return [
            'laravel' => app()->version(),
            'php' => phpversion(),
            'app_env' => config('app.env'),
            'app_debug' => config('app.debug'),
            'app_url' => config('app.url'),
        ];
    }

    private function buildConfigGroups(array $cfg): array
    {
        return [
            'AI API' => [
                'active_provider' => $cfg['active_provider'] ?? 'default',
                'api_url' => $cfg['api_url'] ?? null,
                'api_token' => $cfg['api_token'] ?? null,
                'api_model' => $cfg['api_model'] ?? null,
                'timeout' => $cfg['timeout'] ?? null,
                'rag_embedding_url' => $cfg['rag_embedding_url'] ?? null,
                'rag_embedding_model' => $cfg['rag_embedding_model'] ?? null,
            ],
            'Response' => [
                'language' => $cfg['language'] ?? null,
                'system_prompt' => $cfg['system_prompt'] ?? null,
                'temperature' => $cfg['temperature'] ?? null,
                'max_tokens' => $cfg['max_tokens'] ?? null,
            ],
            'Streaming & History' => [
                'stream' => $cfg['stream'] ?? null,
                'history_enabled' => $cfg['history_enabled'] ?? null,
                'history_limit' => $cfg['history_limit'] ?? null,
                'context_token_limit' => $cfg['context_token_limit'] ?? null,
                'memory_driver' => $cfg['memory_driver'] ?? null,
                'storage' => $cfg['storage'] ?? null,
            ],
            'Widget' => [
                'frontend' => $cfg['frontend'] ?? null,
                'title' => $cfg['title'] ?? null,
                'greeting' => $cfg['greeting'] ?? null,
                'placeholder' => $cfg['placeholder'] ?? null,
                'theme_color' => $cfg['theme_color'] ?? null,
                'color_scheme' => $cfg['color_scheme'] ?? null,
                'position' => $cfg['position'] ?? null,
                'toggle_icon' => $cfg['toggle_icon'] ?? null,
                'markdown' => $cfg['markdown'] ?? null,
                'sound' => $cfg['sound'] ?? null,
                'sound_volume' => $cfg['sound_volume'] ?? null,
            ],
            'Routes & Security' => [
                'route_prefix' => $cfg['route_prefix'] ?? null,
                'middleware' => $cfg['middleware'] ?? null,
                'rate_limit' => $cfg['rate_limit'] ?? null,
                'rate_window' => $cfg['rate_window'] ?? null,
                'health_check' => $cfg['health_check'] ?? null,
                'ssrf_protection' => $cfg['ssrf_protection'] ?? null,
                'allowed_origins' => $cfg['allowed_origins'] ?? null,
            ],
            'RAG' => [
                'rag_enabled' => $cfg['rag_enabled'] ?? null,
                'rag_embedding_url' => $cfg['rag_embedding_url'] ?? null,
                'rag_embedding_model' => $cfg['rag_embedding_model'] ?? null,
                'rag_top_k' => $cfg['rag_top_k'] ?? null,
                'rag_chunk_size' => $cfg['rag_chunk_size'] ?? null,
                'rag_chunk_overlap' => $cfg['rag_chunk_overlap'] ?? null,
                'rag_similarity_threshold' => $cfg['rag_similarity_threshold'] ?? null,
                'rag_processing_timeout' => $cfg['rag_processing_timeout'] ?? null,
                'rag_admin_middleware' => $cfg['rag_admin_middleware'] ?? null,
                'rag_context_prompt' => $cfg['rag_context_prompt'] ?? null,
            ],
        ];
    }

    // ── Diagnostics orchestrator ──────────────────────────────────────────────

    private function buildDiagnostics(array $cfg, ?array $ragStats, bool $ragEnabled): array
    {
        $diagnostics = [];

        $this->checkPhpExtensions($diagnostics);
        $this->checkActiveProvider($diagnostics, $cfg);
        $this->checkNamedProviders($diagnostics, $cfg);
        $this->checkSecurity($diagnostics, $cfg);
        $this->checkResponse($diagnostics, $cfg);
        $this->checkHistory($diagnostics, $cfg);
        $this->checkFrontendAndWidget($diagnostics, $cfg);
        $this->checkRag($diagnostics, $cfg, $ragStats, $ragEnabled);
        $this->checkMemoryDriver($diagnostics, $cfg);
        $this->checkAdminProtection($diagnostics, $cfg);
        $this->checkStreaming($diagnostics, $cfg);

        return $diagnostics;
    }

    // ── Diagnostic checks ─────────────────────────────────────────────────────

    private function checkPhpExtensions(array &$diagnostics): void
    {
        if (!extension_loaded('curl')) {
            $diagnostics[] = ['level' => 'error', 'group' => 'PHP', 'message' =>
                'The curl PHP extension is not loaded. All AI API calls use cURL and will fail with a fatal error. Enable extension=curl in php.ini.'];
        }
        if (!extension_loaded('mbstring')) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'PHP', 'message' =>
                'The mbstring PHP extension is not loaded. Multi-byte string operations used internally may produce incorrect results or warnings. Enable extension=mbstring in php.ini.'];
        }
    }

    private function checkActiveProvider(array &$diagnostics, array $cfg): void
    {
        $activeProvider = config('ai-chatbox.active_provider', 'default');
        $allProviders = config('ai-chatbox.providers', []);

        if ($activeProvider === 'default' || $activeProvider === '') {
            $activeProvider = (string) array_key_first($allProviders);
        }

        if (!array_key_exists($activeProvider, $allProviders)) {
            $diagnostics[] = ['level' => 'error', 'group' => 'Active Provider', 'message' =>
                "active_provider is set to \"{$activeProvider}\" but no such provider is defined under 'providers' in the config. "
                . 'The chatbox will throw an exception on every request.'];
            return;
        }

        $apiUrl = $cfg['api_url'] ?? '';

        if (empty($apiUrl)) {
            $diagnostics[] = ['level' => 'error', 'group' => 'Active Provider', 'message' =>
                "Provider \"{$activeProvider}\" has no api_url set. Configure its URL env var (e.g. OLLAMA_URL, OPENAI_URL)."];
        } elseif (in_array(strtolower($apiUrl), self::URL_PLACEHOLDERS) || !filter_var($apiUrl, FILTER_VALIDATE_URL)) {
            $diagnostics[] = ['level' => 'error', 'group' => 'Active Provider', 'message' =>
                "Provider \"{$activeProvider}\" api_url \"{$apiUrl}\" is not a valid URL. Fix its URL env var (e.g. OLLAMA_URL, OPENAI_URL)."];
        } else {
            $parsedHost = parse_url($apiUrl, PHP_URL_HOST) ?? '';
            $isLocal = $this->isLocalHost($parsedHost);

            if ($isLocal && config('app.env') === 'production') {
                $diagnostics[] = ['level' => 'warning', 'group' => 'Active Provider', 'message' =>
                    "Provider \"{$activeProvider}\" api_url points to a local/private address ({$parsedHost}) in a production environment. "
                    . 'Ensure your AI service is reachable from the production server.'];
            }
            if ($isLocal && ($cfg['ssrf_protection'] ?? true)) {
                $diagnostics[] = ['level' => 'warning', 'group' => 'Active Provider', 'message' =>
                    "Provider \"{$activeProvider}\" api_url points to a local address ({$parsedHost}) but ssrf_protection is enabled. "
                    . 'Health-check pings will be blocked. Set AI_CHATBOX_SSRF_PROTECTION=false for local AI services.'];
            }
            $scheme = strtolower((string) (parse_url($apiUrl, PHP_URL_SCHEME) ?? ''));
            if ($scheme === 'http' && in_array(strtolower((string) config('app.env', '')), self::PROD_ENVS, true)) {
                $diagnostics[] = ['level' => 'warning', 'group' => 'Active Provider', 'message' =>
                    "Provider \"{$activeProvider}\" api_url uses plain HTTP in a production environment. "
                    . 'Your API token is transmitted unencrypted over the network. Switch to an HTTPS endpoint.'];
            }
        }

        $apiToken = $cfg['api_token'] ?? '';
        if (empty($apiToken)) {
            $diagnostics[] = ['level' => 'error', 'group' => 'Active Provider', 'message' =>
                "Provider \"{$activeProvider}\" has no api_token set. Configure its token env var (e.g. OLLAMA_TOKEN, OPENAI_API_KEY)."];
        } elseif (in_array(strtolower($apiToken), self::TOKEN_PLACEHOLDERS)) {
            $diagnostics[] = ['level' => 'error', 'group' => 'Active Provider', 'message' =>
                "Provider \"{$activeProvider}\" api_token looks like a placeholder value. Set a real token via its env var (e.g. OLLAMA_TOKEN, OPENAI_API_KEY)."];
        }

        if (empty($cfg['api_model'])) {
            $diagnostics[] = ['level' => 'error', 'group' => 'Active Provider', 'message' =>
                "Provider \"{$activeProvider}\" has no api_model set. Configure its model env var (e.g. OLLAMA_MODEL, OPENAI_MODEL)."];
        }
    }

    private function checkNamedProviders(array &$diagnostics, array $cfg): void
    {
        $activeProvider = config('ai-chatbox.active_provider', 'default');
        $allProviders = config('ai-chatbox.providers', []);
        $defaultLocalTokens = ['ollama', 'lmstudio', ''];

        if ($activeProvider === 'default' || $activeProvider === '') {
            $activeProvider = (string) array_key_first($allProviders);
        }

        foreach ($allProviders as $name => $providerCfg) {
            if ($name === $activeProvider) {
                continue;
            }

            $pToken = $providerCfg['api_token'] ?? '';
            $pUrl = $providerCfg['api_url'] ?? '';
            $pModel = $providerCfg['api_model'] ?? '';

            $isCustom = (!empty($pToken) && !in_array(strtolower($pToken), $defaultLocalTokens))
            || !empty($pUrl)
            || !empty($pModel);

            if (!$isCustom) {
                continue;
            }

            if (!empty($pUrl) && !filter_var($pUrl, FILTER_VALIDATE_URL)) {
                $diagnostics[] = ['level' => 'error', 'group' => 'Providers', 'message' =>
                    "Provider \"{$name}\" has an invalid api_url: \"{$pUrl}\"."];
            }
            if (empty($pToken) || in_array(strtolower($pToken), self::TOKEN_PLACEHOLDERS)) {
                $diagnostics[] = ['level' => 'warning', 'group' => 'Providers', 'message' =>
                    "Provider \"{$name}\" has no valid api_token — calls to this provider will fail."];
            }
            if (empty($pModel)) {
                $diagnostics[] = ['level' => 'warning', 'group' => 'Providers', 'message' =>
                    "Provider \"{$name}\" has no api_model set — there is no global fallback."];
            }
        }
    }

    private function checkSecurity(array &$diagnostics, array $cfg): void
    {
        if (config('app.debug') && in_array(strtolower((string) config('app.env', '')), self::PROD_ENVS)) {
            $diagnostics[] = ['level' => 'error', 'group' => 'Security', 'message' =>
                'APP_DEBUG is true in production. This can expose stack traces, API tokens, and environment variables to end users.'];
        }

        if (empty($cfg['ssrf_protection']) || $cfg['ssrf_protection'] === false) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'Security', 'message' =>
                'ssrf_protection is disabled. Enable it to block requests to internal/private network addresses.'];
        }

        if (in_array('*', (array) ($cfg['allowed_origins'] ?? []))) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'Security', 'message' =>
                'allowed_origins includes "*" — CORS accepts requests from any origin. Restrict this to specific domains in production.'];
        }

        $invalidOrigins = array_filter(
            (array) ($cfg['allowed_origins'] ?? []),
            fn($o) => $o !== '*' && !filter_var($o, FILTER_VALIDATE_URL)
        );
        if (!empty($invalidOrigins)) {
            $joined = implode('", "', $invalidOrigins);
            $diagnostics[] = ['level' => 'warning', 'group' => 'Security', 'message' =>
                "allowed_origins contains invalid entries: \"{$joined}\". Each entry must be a full URL (e.g. https://example.com) or \"*\". Invalid entries are silently ignored by the CORS middleware."];
        }

        $rateLimit = (int) ($cfg['rate_limit'] ?? 20);
        $rateWindow = (int) ($cfg['rate_window'] ?? 1);

        if ($rateLimit === 0) {
            $diagnostics[] = ['level' => 'error', 'group' => 'Security', 'message' =>
                'rate_limit is 0. Rate limiting is effectively disabled, leaving your API key unprotected.'];
        } elseif ($rateLimit > 100) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'Security', 'message' =>
                "rate_limit is set to {$rateLimit} requests/window. This is unusually high — consider lowering it to protect your API quota."];
        }

        if ($rateWindow === 0) {
            $diagnostics[] = ['level' => 'error', 'group' => 'Security', 'message' =>
                'rate_window is 0. A zero-minute window makes rate limiting undefined — requests may never be throttled. Set a positive value (e.g. 1).'];
        }
    }

    private function checkResponse(array &$diagnostics, array $cfg): void
    {
        $temperature = (float) ($cfg['temperature'] ?? 0.7);
        if ($temperature < 0) {
            $diagnostics[] = ['level' => 'error', 'group' => 'Response', 'message' =>
                "temperature is {$temperature} — negative values are invalid for all known AI APIs and will cause a request error. Valid range: 0.0–2.0."];
        } elseif ($temperature > 1.5) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'Response', 'message' =>
                "temperature is {$temperature} — very high values produce incoherent or random responses. Typical range: 0.3–1.0."];
        }

        $maxTokens = $cfg['max_tokens'] ?? null;
        if ($maxTokens !== null && (int) $maxTokens === 0) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'Response', 'message' =>
                'max_tokens is 0. Some APIs treat this as "unlimited" while others reject the request outright. Set a positive value or null to let the model decide.'];
        } elseif ($maxTokens !== null && (int) $maxTokens < 64) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'Response', 'message' =>
                "max_tokens is set to {$maxTokens}. This is very low and will cut off most AI responses. Set to null to let the model decide, or at least 256."];
        }

        $timeout = (int) ($cfg['timeout'] ?? 30);
        if ($timeout < 10) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'Response', 'message' =>
                "timeout is {$timeout}s — too low for most AI providers, especially with streaming enabled. Recommended: 30–120s."];
        } elseif ($timeout > 300) {
            $diagnostics[] = ['level' => 'info', 'group' => 'Response', 'message' =>
                "timeout is {$timeout}s — very high. PHP workers will be held open for up to {$timeout}s on slow requests."];
        }

        $systemPrompt = $cfg['system_prompt'] ?? '';
        $language = $cfg['language'] ?? '';
        if (!empty($systemPrompt) && str_contains($systemPrompt, '{language}') && empty($language)) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'Response', 'message' =>
                'system_prompt contains the {language} placeholder but language is empty — the placeholder will not be substituted.'];
        }
    }

    private function checkHistory(array &$diagnostics, array $cfg): void
    {
        $historyEnabled = (bool) ($cfg['history_enabled'] ?? true);
        $historyLimit = (int) ($cfg['history_limit'] ?? 50);
        $contextTokens = (int) ($cfg['context_token_limit'] ?? 4000);

        if (!$historyEnabled) {
            return;
        }

        if ($historyLimit === 0) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'History', 'message' =>
                'history_enabled is true but history_limit is 0 — no history will be sent to the AI. Set a positive history_limit or disable history.'];
        }

        if ($contextTokens > 0 && $contextTokens < 500) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'History', 'message' =>
                "context_token_limit is very low ({$contextTokens} tokens). Most history will be trimmed before it reaches the AI. Typical values: 4000–32000."];
        }

        if ($contextTokens === 0 && $historyLimit > 20) {
            $diagnostics[] = ['level' => 'info', 'group' => 'History', 'message' =>
                "context_token_limit is 0 (disabled) and history_limit is {$historyLimit}. Large histories may exceed your model's context window. Consider enabling token trimming."];
        }

        if ($contextTokens > 0 && $historyLimit > 0) {
            $estimatedTokens = $historyLimit * 300; // ~150 tokens per message × 2 per pair
            if ($estimatedTokens > $contextTokens * 2) {
                $diagnostics[] = ['level' => 'info', 'group' => 'History', 'message' =>
                    "history_limit is {$historyLimit} pairs (~{$estimatedTokens} estimated tokens) but context_token_limit is only {$contextTokens}. "
                    . 'Most stored history will be trimmed on every request before reaching the AI. Consider lowering history_limit to better match your token budget.'];
            }
        }
    }

    private function checkFrontendAndWidget(array &$diagnostics, array $cfg): void
    {
        $frontend = $cfg['frontend'] ?? 'vue';
        if ($frontend === 'livewire' && !class_exists(\Livewire\Livewire::class)) {
            $diagnostics[] = ['level' => 'error', 'group' => 'Frontend', 'message' =>
                'frontend is set to "livewire" but the livewire/livewire package is not installed. Run: composer require livewire/livewire'];
        }

        $colorScheme = (string) ($cfg['color_scheme'] ?? 'auto');
        if (!in_array($colorScheme, ['auto', 'light', 'dark'], true)) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'Widget', 'message' =>
                "color_scheme is \"{$colorScheme}\" which is not a recognised value. Expected one of: auto, light, dark."];
        }

        $position = (string) ($cfg['position'] ?? 'bottom-right');
        if (!in_array($position, ['bottom-right', 'bottom-left'], true)) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'Widget', 'message' =>
                "position is \"{$position}\" which is not a recognised value. Expected one of: bottom-right, bottom-left."];
        }

        if ($cfg['sound'] ?? false) {
            $volume = $cfg['sound_volume'] ?? 0.5;
            if (!is_numeric($volume) || (float) $volume < 0.0 || (float) $volume > 1.0) {
                $diagnostics[] = ['level' => 'warning', 'group' => 'Widget', 'message' =>
                    "sound is enabled but sound_volume is \"{$volume}\" which is outside the valid range 0.0–1.0. The browser may clamp or ignore this value."];
            }
        }

        $toggleIcon = (string) ($cfg['toggle_icon'] ?? '');
        if ($toggleIcon !== ''
            && !preg_match('#^https?://#i', $toggleIcon)
            && !preg_match('#\.(png|jpg|jpeg|gif|svg|webp|ico)(\?.*)?$#i', $toggleIcon)
        ) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'Widget', 'message' =>
                "toggle_icon is set to \"{$toggleIcon}\" which does not look like a URL or an image asset path. "
                . 'Expected a full URL (https://…) or a local path ending in an image extension (e.g. /images/icon.svg).'];
        }
    }

    private function checkRag(array &$diagnostics, array $cfg, ?array $ragStats, bool $ragEnabled): void
    {
        // Embedding config is always validated — needed for upload/reprocess even when RAG chat is off
        $embeddingUrl = $cfg['rag_embedding_url'] ?? '';
        if (empty($embeddingUrl)) {
            $diagnostics[] = ['level' => 'error', 'group' => 'RAG', 'message' =>
                'rag_embedding_url is not set. Document upload and reprocessing will fail. Set AI_CHATBOX_EMBEDDING_URL (or the provider-specific variant, e.g. LMSTUDIO_EMBEDDING_URL).'];
        } else {
            $embHost = parse_url($embeddingUrl, PHP_URL_HOST);
            if ($this->isLocalHost((string) $embHost) && ($cfg['ssrf_protection'] ?? true)) {
                $diagnostics[] = ['level' => 'warning', 'group' => 'RAG', 'message' =>
                    "rag_embedding_url points to a local address ({$embHost}) but ssrf_protection is enabled — embedding requests will be blocked. Set AI_CHATBOX_SSRF_PROTECTION=false for local embedding services."];
            }
        }

        if (empty($cfg['rag_embedding_model'])) {
            $diagnostics[] = ['level' => 'error', 'group' => 'RAG', 'message' =>
                'rag_embedding_model is not set. Document upload and reprocessing will fail. Set AI_CHATBOX_EMBEDDING_MODEL (or the provider-specific variant, e.g. LMSTUDIO_EMBEDDING_MODEL).'];
        }

        if (!$ragEnabled) {
            return;
        }

        if (!Schema::hasTable('ai_chatbox_rag_documents')) {
            $diagnostics[] = ['level' => 'error', 'group' => 'RAG', 'message' =>
                'Table ai_chatbox_rag_documents is missing. Run: php artisan migrate'];
        }
        if (!Schema::hasTable('ai_chatbox_rag_chunks')) {
            $diagnostics[] = ['level' => 'error', 'group' => 'RAG', 'message' =>
                'Table ai_chatbox_rag_chunks is missing. Run: php artisan migrate'];
        }

        $threshold = (float) ($cfg['rag_similarity_threshold'] ?? 0.3);
        $chunkSize = (int) ($cfg['rag_chunk_size'] ?? 500);
        $chunkOverlap = (int) ($cfg['rag_chunk_overlap'] ?? 50);
        $topK = (int) ($cfg['rag_top_k'] ?? 3);

        if ($threshold <= 0) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'RAG', 'message' =>
                'rag_similarity_threshold is 0 — all chunks are returned regardless of relevance. Recommended: 0.3–0.85.'];
        } elseif ($threshold > 0.95) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'RAG', 'message' =>
                "rag_similarity_threshold is very high ({$threshold}). Most chunks will be filtered out, even relevant ones. Recommended: 0.3–0.85."];
        }

        if ($topK === 0) {
            $diagnostics[] = ['level' => 'error', 'group' => 'RAG', 'message' =>
                'rag_top_k is 0 — no chunks will be retrieved. RAG context will never be injected into AI requests.'];
        } elseif ($topK > 20) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'RAG', 'message' =>
                "rag_top_k is {$topK} — injecting this many chunks may exceed your model's context window. Recommended: 3–10."];
        }

        if ($chunkSize < 100) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'RAG', 'message' =>
                "rag_chunk_size is {$chunkSize} tokens — very small chunks may not contain enough context for meaningful retrieval. Recommended: 300–800."];
        }

        if ($chunkOverlap >= $chunkSize) {
            $diagnostics[] = ['level' => 'error', 'group' => 'RAG', 'message' =>
                "rag_chunk_overlap ({$chunkOverlap}) must be less than rag_chunk_size ({$chunkSize}). This will cause infinite chunking loops."];
        }

        if (isset($ragStats['documents']) && $ragStats['documents'] === 0) {
            $diagnostics[] = ['level' => 'info', 'group' => 'RAG', 'message' =>
                'RAG is enabled but no documents have been uploaded yet. Upload documents in the Knowledge Base to give the AI context.'];
        }

        if (isset($ragStats['null_chunks']) && $ragStats['null_chunks'] > 0) {
            $pct = $ragStats['total_chunks'] > 0
            ? round($ragStats['null_chunks'] / $ragStats['total_chunks'] * 100)
            : 0;
            $diagnostics[] = ['level' => 'warning', 'group' => 'RAG', 'message' =>
                "{$ragStats['null_chunks']} chunk(s) ({$pct}%) have no stored embedding. The AI cannot see that content. Check your embedding URL and reprocess affected documents."];
        }

        if (isset($ragStats['documents_failed']) && $ragStats['documents_failed'] > 0) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'RAG', 'message' =>
                "{$ragStats['documents_failed']} document(s) are in a failed state. Open the Knowledge Base and reprocess them."];
        }

        $ragContextPrompt = (string) ($cfg['rag_context_prompt'] ?? '');
        if ($ragContextPrompt === '') {
            $diagnostics[] = ['level' => 'warning', 'group' => 'RAG', 'message' =>
                'rag_context_prompt is empty. Retrieved chunks cannot be injected into the AI prompt — rag_enabled will have no effect. '
                . 'Set a template string containing the {chunks} placeholder (e.g. "Use the following context:\n{chunks}").'];
        } elseif (!str_contains($ragContextPrompt, '{chunks}')) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'RAG', 'message' =>
                'rag_context_prompt does not contain the {chunks} placeholder. Retrieved document chunks will never be injected into the AI prompt, '
                . 'so the AI cannot see your knowledge base content. Add {chunks} to the template.'];
        }

        $ragTimeout = (int) ($cfg['rag_processing_timeout'] ?? 0);
        if ($ragTimeout > 0 && $ragTimeout < 15) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'RAG', 'message' =>
                "rag_processing_timeout is {$ragTimeout}s — too low for document processing. Large files will time out during chunking and embedding. Set to 0 for no limit, or at least 60s."];
        }
    }

    private function checkMemoryDriver(array &$diagnostics, array $cfg): void
    {
        if (config('ai-chatbox.memory_driver') === 'database') {
            if (!Schema::hasTable('ai_chatbox_conversations')) {
                $diagnostics[] = ['level' => 'error', 'group' => 'Memory', 'message' =>
                    'Table ai_chatbox_conversations is missing. Run: php artisan migrate'];
            }
            if (!Schema::hasTable('ai_chatbox_messages')) {
                $diagnostics[] = ['level' => 'error', 'group' => 'Memory', 'message' =>
                    'Table ai_chatbox_messages is missing. Run: php artisan migrate'];
            }
        } else {
            $historyEnabled = (bool) ($cfg['history_enabled'] ?? true);
            $historyLimit = (int) ($cfg['history_limit'] ?? 50);
            if ($historyEnabled && $historyLimit > 0) {
                $diagnostics[] = ['level' => 'info', 'group' => 'Memory', 'message' =>
                    'memory_driver is "session" — conversation history is stored in the PHP session and lost when it expires. Switch to "database" for persistent, queryable history.'];
            }
        }

        if (($cfg['storage'] ?? 'local') === 'local' && config('app.env') === 'production') {
            $diagnostics[] = ['level' => 'info', 'group' => 'Memory', 'message' =>
                'Client-side storage is set to "local" (localStorage). Chat history survives browser restarts and is visible to anyone with access to that browser. Use "session" for sensitive conversations.'];
        }
    }

    private function checkAdminProtection(array &$diagnostics, array $cfg): void
    {
        $adminMiddleware = (array) ($cfg['admin_middleware'] ?? $cfg['rag_admin_middleware'] ?? ['web', 'auth']);
        $ragMiddleware = (array) ($cfg['rag_admin_middleware'] ?? ['web', 'auth']);
        $defaultSorted = ['auth', 'web'];

        $adminSorted = $adminMiddleware;
        sort($adminSorted);
        $ragSorted = $ragMiddleware;
        sort($ragSorted);

        $hasRoleCheck = fn(array $mw) => collect($mw)->contains(
            fn($m) => preg_match('/^(role|permission|can|ability|authorize)[.:\-]/i', $m)
            || in_array(strtolower($m), ['admin', 'superadmin', 'is-admin', 'isadmin'])
        );

        if ($adminSorted === $defaultSorted) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'Security', 'message' =>
                'The admin dashboard is only protected by the default middleware [web, auth] — any authenticated user can access it. '
                . 'Add a role or permission middleware to admin_middleware in your config, e.g. "role:admin" (Spatie), "can:manage-chatbox" (Laravel Gates), or a custom middleware.'];
        } elseif (!$hasRoleCheck($adminMiddleware)) {
            $diagnostics[] = ['level' => 'info', 'group' => 'Security', 'message' =>
                'admin_middleware has been customised but no role/permission middleware was detected. Ensure dashboard access is restricted to trusted users only.'];
        }

        if ($ragSorted === $defaultSorted) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'Security', 'message' =>
                'The Knowledge Base (RAG) pages are only protected by the default middleware [web, auth] — any authenticated user can upload or delete documents. '
                . 'Add a role or permission middleware to rag_admin_middleware in your config.'];
        } elseif (!$hasRoleCheck($ragMiddleware)) {
            $diagnostics[] = ['level' => 'info', 'group' => 'Security', 'message' =>
                'rag_admin_middleware has been customised but no role/permission middleware was detected. Ensure Knowledge Base access is restricted to trusted users only.'];
        }
    }

    private function checkStreaming(array &$diagnostics, array $cfg): void
    {
        if (!($cfg['stream'] ?? false)) {
            return;
        }

        if (!function_exists('ob_flush')) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'Streaming', 'message' =>
                'stream is enabled but ob_flush() is unavailable. SSE tokens may not flush to the client correctly.'];
        }

        $obSetting = ini_get('output_buffering');
        $obEnabled = $obSetting !== false
        && $obSetting !== ''
        && $obSetting !== '0'
        && strtolower((string) $obSetting) !== 'off';
        if ($obEnabled) {
            $diagnostics[] = ['level' => 'warning', 'group' => 'Streaming', 'message' =>
                "PHP output_buffering is enabled (php.ini value: \"{$obSetting}\"). PHP will accumulate the entire response before sending it, silently breaking SSE. "
                . 'Set output_buffering=Off in php.ini or your PHP-FPM pool config (e.g. php_admin_value[output_buffering] = Off).'];
        }

        $serverSoftware = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));
        if (str_contains($serverSoftware, 'nginx')) {
            $diagnostics[] = ['level' => 'info', 'group' => 'Streaming', 'message' =>
                'NginX detected. The package already sends X-Accel-Buffering: no on stream responses to disable NginX proxy buffering. '
                . 'If tokens still arrive in batches, also add "proxy_buffering off;" to the NginX location block for your chatbox routes.'];
        } elseif (str_contains($serverSoftware, 'apache')) {
            $routePrefix = $cfg['route_prefix'] ?? 'ai-chatbox';
            $diagnostics[] = ['level' => 'warning', 'group' => 'Streaming', 'message' =>
                'Apache detected. If mod_deflate or mod_gzip is active it buffers output before compression, breaking SSE. '
                . "Disable compression for chatbox routes by adding to your VirtualHost: SetEnvIf Request_URI \"{$routePrefix}\" no-gzip dont-vary"];
        }

        $behindProxy = isset($_SERVER['HTTP_X_FORWARDED_FOR'])
        || isset($_SERVER['HTTP_X_FORWARDED_HOST'])
        || isset($_SERVER['HTTP_X_FORWARDED_PROTO']);
        if ($behindProxy) {
            $diagnostics[] = ['level' => 'info', 'group' => 'Streaming', 'message' =>
                'A reverse proxy or CDN is detected (X-Forwarded-* headers present). '
                . 'Ensure it passes event-stream responses through without buffering. '
                . 'Cloudflare, AWS ALB, and similar services may require explicit configuration to honour SSE (e.g. Cloudflare disables buffering for text/event-stream by default, but custom proxy rules or enterprise WAF policies can re-enable it).'];
        }
    }

    // ── Shared utilities ──────────────────────────────────────────────────────

    private function isLocalHost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'])
        || str_starts_with($host, '192.168.')
        || str_starts_with($host, '10.')
        || str_starts_with($host, '172.');
    }

    private function resolveUserNames(\Illuminate\Support\Collection $userIds): array
    {
        $userNames = [];
        try {
            $userModel = config('auth.providers.users.model', 'App\\Models\\User');
            $ids = $userIds->filter()->unique()->values();
            if ($ids->isNotEmpty()) {
                $userModel::whereIn('id', $ids)
                    ->get()
                    ->each(function ($u) use (&$userNames) {
                        $userNames[$u->id] = $u->name ?? $u->username ?? $u->email ?? null;
                    });
            }
        } catch (\Throwable) {
            // User model unavailable — degrade gracefully
        }
        return $userNames;
    }
}

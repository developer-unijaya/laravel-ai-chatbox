<?php

return [

/*
|--------------------------------------------------------------------------
| Active Provider
|--------------------------------------------------------------------------
| The provider the chatbox widget and AI facade use. Must match a key
| under 'providers' below. The provider's api_url, api_token, and
| api_model are the authoritative values — configure them via the
| provider's own env vars (e.g. OLLAMA_URL, OPENAI_URL) rather than
| separate top-level AI_CHATBOX_API_* variables.
|
| Examples:
|   AI_CHATBOX_ACTIVE_PROVIDER=ollama      → uses providers.ollama
|   AI_CHATBOX_ACTIVE_PROVIDER=openai      → uses providers.openai
|   AI_CHATBOX_ACTIVE_PROVIDER=lmstudio    → uses providers.lmstudio
*/

    'active_provider' => env('AI_CHATBOX_ACTIVE_PROVIDER', 'ollama'),

/*
|--------------------------------------------------------------------------
| Response Language
|--------------------------------------------------------------------------
| The language the AI must always reply in, regardless of what language
| the user writes in. Uses the full language name (e.g. 'English',
| 'Bahasa Malaysia', 'French', 'Arabic').
|
| Set to empty string to let the AI reply in whatever language it chooses.
| Publish the config to change: php artisan vendor:publish --tag=ai-chatbox-config
*/

    'language' => 'English',

/*
|--------------------------------------------------------------------------
| System Prompt
|--------------------------------------------------------------------------
| An optional system message sent to the AI on every request.
| The {language} placeholder is automatically replaced with the value
| of the 'language' config above.
|
| Publish the config to customise:
|   php artisan vendor:publish --tag=ai-chatbox-config
|
| Note: avoid setting this via .env — multi-line strings and special
| characters are error-prone in .env files.
*/

    'system_prompt' => 'You are a helpful assistant. You must always respond in {language} only, no matter what language the user writes in. Do not switch to any other language under any circumstances.',

/*
|--------------------------------------------------------------------------
| Route Configuration
|--------------------------------------------------------------------------
| The URL prefix and middleware applied to the chatbox route.
| Change the prefix to avoid collisions with existing routes.
*/

    'route_prefix' => 'ai-chatbox',

/*
|--------------------------------------------------------------------------
| Health Check
|--------------------------------------------------------------------------
| When enabled, clicking the chat button first pings the AI service.
| The window only opens if the service is reachable.
| Set to false to skip the check and open immediately.
*/

    'health_check' => env('AI_CHATBOX_HEALTH_CHECK', true),
    'offline_message' => 'AI service is currently unreachable.',

/*
|--------------------------------------------------------------------------
| SSRF Protection
|--------------------------------------------------------------------------
| When enabled, the health check blocks requests to private/reserved IP
| ranges (localhost, 10.x, 172.16.x, 192.168.x, 169.254.x) to prevent
| Server-Side Request Forgery attacks.
|
| Disable this only in local development where your AI service runs on
| localhost or a private network (e.g. local Ollama).
|
| AI_CHATBOX_SSRF_PROTECTION=false
*/

    'ssrf_protection' => env('AI_CHATBOX_SSRF_PROTECTION', true),
    'middleware' => ['web', 'throttle:20,1', 'ai-chatbox.cors'],

/*
|--------------------------------------------------------------------------
| CORS — Allowed Origins
|--------------------------------------------------------------------------
| Origins permitted to call the chatbox endpoints. Defaults to the app's
| own URL so cross-origin requests from other domains are rejected.
| Add additional origins as needed, e.g. ['https://app.example.com'].
*/

    'allowed_origins' => [env('APP_URL', 'http://localhost')],

/*
|--------------------------------------------------------------------------
| Rate Limiting
|--------------------------------------------------------------------------
| Controls the throttle middleware above.
| 'rate_limit'  — max requests allowed per window (default: 20)
| 'rate_window' — window size in minutes (default: 1)
|
| To change, update the middleware entry above or publish the config.
| Example: 'throttle:10,1' = 10 requests per minute per IP.
*/

    'rate_limit' => env('AI_CHATBOX_RATE_LIMIT', 20),
    'rate_window' => env('AI_CHATBOX_RATE_WINDOW', 1),

/*
|--------------------------------------------------------------------------
| Widget Appearance
|--------------------------------------------------------------------------
| Publish the config to customise these values:
|   php artisan vendor:publish --tag=ai-chatbox-config
*/

    'title' => env('AI_CHATBOX_TITLE', 'AI Assistant'),
    'placeholder' => 'Type your message...',
    'theme_color' => '#0dad35',
    'greeting' => 'Hi! How can I help you today?',

/*
|--------------------------------------------------------------------------
| Toggle Button Icon
|--------------------------------------------------------------------------
| The icon displayed on the floating toggle button when the chat is closed.
|
| null                        — use the default built-in SVG chat bubble icon
| 'images/chatbot.png'        — path relative to your public folder (asset())
| 'https://example.com/b.svg' — any absolute URL, used as-is
|
| Do NOT call asset() here — config files load before the app is booted.
| The package resolves relative paths through asset() automatically.
|
| The image is displayed at 26×26 px inside the 56×56 px circular button.
| The close (✕) and loading spinner icons are never replaced.
*/

    'toggle_icon' => null,

/*
|--------------------------------------------------------------------------
| Color Scheme
|--------------------------------------------------------------------------
| Controls the light/dark mode for both the chat widget and all admin pages
| (/ai-chatbox/admin, /ai-chatbox/admin/conversations, /ai-chatbox/rag).
|
| 'auto'  — follows the user's OS / browser preference (default)
| 'light' — always light, regardless of OS preference
| 'dark'  — always dark, regardless of OS preference
*/

    'color_scheme' => 'auto',

/*
|--------------------------------------------------------------------------
| Frontend Driver
|--------------------------------------------------------------------------
| Controls which UI the @aichatbox directive renders.
|
| 'vue'      — Pre-built Vue 3 widget (default, zero-config)
| 'blade'    — Vanilla JS widget, no framework required
| 'livewire' — Alpine.js widget mounted via Livewire (requires livewire/livewire)
| 'none'     — Only outputs window.AiChatboxConfig; bring your own frontend
*/

    'frontend' => 'vue',

/*
|--------------------------------------------------------------------------
| Markdown Rendering
|--------------------------------------------------------------------------
| When enabled, AI replies are rendered as Markdown (bold, italics, lists,
| code blocks, etc.) using marked.js + DOMPurify (loaded from CDN).
|
| Set to false to display replies as plain text.
*/

    'markdown' => true,

/*
|--------------------------------------------------------------------------
| Sound Notification
|--------------------------------------------------------------------------
| Play a soft ping when the AI replies.
| Uses the Web Audio API — no sound file required.
|
| 'sound'        — true/false to enable/disable
| 'sound_volume' — float between 0.0 (silent) and 1.0 (full)
*/

    'sound' => true,
    'sound_volume' => 0.3,

/*
|--------------------------------------------------------------------------
| Widget Position
|--------------------------------------------------------------------------
| Where the chatbox appears on screen.
| Supported: 'bottom-right', 'bottom-left', 'top-right', 'top-left'
*/

    'position' => 'bottom-right',

/*
|--------------------------------------------------------------------------
| Conversation History
|--------------------------------------------------------------------------
| When enabled, previous messages are stored in the session and sent to
| the AI on every request, giving it memory of the current conversation.
|
| 'history_enabled' — set to false to disable (each message is standalone)
| 'history_limit'   — max number of user+assistant message PAIRS to keep.
|                     Older messages are dropped to stay within token budgets.
*/

    'history_enabled' => env('AI_CHATBOX_HISTORY', true),
    'history_limit' => 50,

/*
|--------------------------------------------------------------------------
| Context Token Limit
|--------------------------------------------------------------------------
| Approximate maximum number of tokens to include from conversation history
| in each API request. History is trimmed oldest-first (by message pair)
| until the estimated token count falls below this threshold.
|
| Uses a ~4 characters per token estimate. Tune this to stay within your
| model's context window. Common values:
|   phi3:mini   → 4 000   (default)
|   llama3 8B   → 8 000
|   GPT-4o      → 32 000
|
| Set to 0 to disable token-based trimming (rely on history_limit only).
*/

    'context_token_limit' => 4000,

/*
|--------------------------------------------------------------------------
| Token Streaming (Server-Sent Events)
|--------------------------------------------------------------------------
| When enabled, AI replies are streamed token-by-token to the browser via
| Server-Sent Events (SSE). The user sees the response being written in
| real time rather than waiting for the full reply.
|
| Set to false to fall back to the standard POST request/response cycle.
|
| Requires your AI provider to support stream: true (all OpenAI-compatible
| APIs and Ollama do). Ensure your web server does not buffer responses:
|   Nginx → proxy_buffering off  (set automatically via X-Accel-Buffering)
|   PHP   → disable output_buffering in php.ini for best results
*/

    'stream' => env('AI_CHATBOX_STREAM', true),

/*
|--------------------------------------------------------------------------
| Client-side Storage Driver
|--------------------------------------------------------------------------
| Controls where chat history is persisted in the browser.
|
| 'local'   — localStorage: survives tab/browser close (default)
| 'session' — sessionStorage: cleared when the tab is closed (more private)
|
| Use 'session' for apps where users may discuss sensitive information.
*/

    'storage' => 'local',

/*
|--------------------------------------------------------------------------
| AI Response Tuning
|--------------------------------------------------------------------------
| 'max_tokens'  — maximum tokens in the AI reply. Lower = shorter/cheaper.
|                 Default: 300. Set to null to let the model decide (not
|                 supported by Anthropic — its engine always requires a number).
|
| 'temperature' — creativity/randomness of replies.
|                 0.0 = deterministic, 1.0 = creative. Default: 0.5.
*/

    'max_tokens' => 300,
    'temperature' => 0.5,

/*
|--------------------------------------------------------------------------
| Request Timeout
|--------------------------------------------------------------------------
| Seconds to wait for a response from the AI API before timing out.
| Increase for slow local models (e.g. Ollama on consumer hardware).
*/

    'timeout' => env('AI_CHATBOX_TIMEOUT', 30),

/*
|--------------------------------------------------------------------------
| RAG — Retrieval-Augmented Generation
|--------------------------------------------------------------------------
| When enabled, the chatbox retrieves relevant context from your uploaded
| knowledge-base documents and injects it into every AI request.
|
| 'rag_enabled'              — master switch (default: false)
| 'rag_embedding_timeout'    — timeout in seconds for every embedding HTTP request (default: 10).
|                              Applies to all providers. Env: AI_CHATBOX_EMBEDDING_TIMEOUT
| 'rag_top_k'                — number of chunks to retrieve per query (default: 10)
| 'rag_chunk_size'           — target chunk size in tokens (~4 chars/token, default: 500)
| 'rag_chunk_overlap'        — overlap between chunks in tokens (default: 50)
| 'rag_similarity_threshold' — minimum cosine similarity score 0.0–1.0 (default: 0.2)
| 'rag_admin_middleware'     — middleware for the Knowledge Base (RAG) document-management UI.
|                              Default: ['web', 'auth'] — requires an authenticated user.
|                              Change to ['web'] to make it publicly accessible (not recommended).
| 'admin_middleware'         — middleware for the Admin dashboard (diagnostics, config viewer,
|                              conversations). Defaults to the same value as rag_admin_middleware
|                              when not set, so existing deployments are unaffected. Set this to a
|                              stricter middleware (e.g. 'role:superadmin') to give read-only
|                              operators dashboard access without granting document upload/delete.
*/

    'rag_enabled' => env('AI_CHATBOX_RAG', false),
    'rag_embedding_timeout' => (int) env('AI_CHATBOX_EMBEDDING_TIMEOUT', 10),
    'rag_top_k' => 10,
    'rag_chunk_size' => 500,
    'rag_chunk_overlap' => 50,
    'rag_similarity_threshold' => 0.2,
    // When true (default), RAG falls back to a simple keyword search when the
    // embedding service is unavailable or rag_embedding_url is not configured.
    // Set to false to return no context instead of the keyword fallback.
    'rag_keyword_fallback' => env('AI_CHATBOX_RAG_KEYWORD_FALLBACK', true),

/*
|--------------------------------------------------------------------------
| RAG Keyword Fallback — Stop Words
|--------------------------------------------------------------------------
| Words stripped from the user's query before keyword search so that
| common interrogative and function words (e.g. "what", "how", "the")
| do not flood the results with irrelevant chunks.
|
| Extend this list with domain-specific noise words for your knowledge base.
| Set to an empty array [] to disable stop-word filtering entirely.
*/

    'rag_keyword_stop_words' => [
        'what', 'which', 'where', 'when', 'how', 'why', 'who',
        'the', 'this', 'that', 'these', 'those',
        'are', 'was', 'were', 'will', 'would', 'can', 'could',
        'should', 'shall', 'may', 'might', 'must',
        'have', 'has', 'had', 'does', 'did',
        'for', 'and', 'but', 'not', 'you', 'your',
    ],
/*
|--------------------------------------------------------------------------
| Admin & Knowledge Base access
|--------------------------------------------------------------------------
| Middleware protecting the admin dashboard and the RAG (Knowledge Base)
| pages. The default [web, auth] means "any logged-in user" — fine for local
| testing, but NOT authorization. For that reason the package FAILS CLOSED:
| outside the local/testing environment, if either gate is left at the exact
| bare default [web, auth], the routes return 403 until you configure a real
| gate here. Set a role/permission/policy middleware appropriate to your app,
| e.g. 'role:admin' (Spatie), 'can:manage-ai-chatbox' (Laravel Gate), or a
| custom middleware. Any customisation disables the tripwire.
|
| admin_middleware = null inherits rag_admin_middleware.
*/

    'rag_admin_middleware' => ['web', 'auth'],
    'admin_middleware' => null, // null = inherit rag_admin_middleware

    // Rate limit for the RAG routes (upload, reprocess, and the test-chat endpoint,
    // which each hit the live AI/embedding provider). Applied on top of the
    // rag_admin_middleware above. Tune down for shared/multi-user deployments.
    'rag_rate_limit' => (int) env('AI_CHATBOX_RAG_RATE_LIMIT', 30),
    'rag_rate_window' => (int) env('AI_CHATBOX_RAG_RATE_WINDOW', 1),

/*
|--------------------------------------------------------------------------
| RAG Context Prompt
|--------------------------------------------------------------------------
| Instruction prepended to the retrieved context block sent to the AI.
| This tells the model to prioritize the knowledge-base content over its
| general training data. Tune this for your model or use case.
|
| Use {chunks} as the placeholder where the retrieved text will be inserted.
| If {chunks} is absent, the retrieved text is appended after the prompt.
|
| Set to empty string to send the raw chunks with no additional instruction.
|
| Publish the config to customise:
|   php artisan vendor:publish --tag=ai-chatbox-config
*/

    'rag_context_prompt' => "Answer the user's question using ONLY the knowledge-base excerpts below. "
    . "Do not use any prior or general knowledge, and do not add facts that are not present in these excerpts. "
    . "If the excerpts do not contain the answer, reply exactly: \"I don't have that information in my knowledge base.\" "
    . "You may still respond naturally to greetings, thanks, and small talk.\n\n"
    . "Context:\n{chunks}",

/*
|--------------------------------------------------------------------------
| RAG No-Context Prompt (grounding guard)
|--------------------------------------------------------------------------
| Injected when RAG is enabled but NO knowledge-base chunk is relevant to
| the user's question — nothing cleared rag_similarity_threshold, there are
| no indexed documents, or the embedding/retrieval call failed.
|
| Without this guard the model receives no grounding instruction at all on
| unmatched questions, so it answers freely from its training data. That is
| the usual cause of "the bot answered outside the knowledge base".
|
| This default refuses factual questions while still allowing greetings and
| small talk. Set to an empty string to disable the guard entirely (the
| model answers unconstrained when nothing matches).
|
| Publish the config to customise:
|   php artisan vendor:publish --tag=ai-chatbox-config
*/

    'rag_no_context_prompt' => "No relevant knowledge-base entries were found for this question. "
    . "If the user is asking for information or a factual answer, do not answer from general knowledge — "
    . "reply exactly: \"I don't have that information in my knowledge base.\" "
    . "You may still respond naturally to greetings, thanks, and small talk.",

/*
|--------------------------------------------------------------------------
| Memory Driver
|--------------------------------------------------------------------------
| Controls where conversation history (chats and messages) is persisted.
|
| 'session'  — PHP session storage. Zero-config, default.
| 'database' — Eloquent models stored in ai_chatbox_conversations /
|              ai_chatbox_messages tables. History survives browser sessions
|              and is queryable. Run `php artisan migrate` after switching.
*/

    'memory_driver' => env('AI_CHATBOX_MEMORY_DRIVER', 'session'),

/*
|--------------------------------------------------------------------------
| Conversation Pruning
|--------------------------------------------------------------------------
| Default retention period used by the `ai-chatbox:prune-conversations`
| Artisan command. Conversations with no activity beyond this many days
| will be permanently deleted along with all their messages.
|
| Override at runtime with the --days option:
|   php artisan ai-chatbox:prune-conversations --days=60
|
| Only applies when memory_driver=database.
*/

    'conversation_prune_days' => 30,

/*
|--------------------------------------------------------------------------
| RAG Processing Time Limit
|--------------------------------------------------------------------------
| Maximum seconds PHP is allowed to spend on a single document upload
| (chunking + embedding all chunks). Embedding each chunk makes one HTTP
| call to the embedding API, so large documents on slow local models can
| easily exceed PHP's default 30-second limit.
|
| 0 = no limit (recommended for local models, default)
| 300 = 5 minutes (a safe upper bound for most use cases)
|
| This only affects the RAG admin upload/reprocess request — all other
| requests use the normal PHP max_execution_time.
*/

    'rag_processing_timeout' => (int) env('AI_CHATBOX_RAG_PROCESSING_TIMEOUT', 0),

/*
|--------------------------------------------------------------------------
| AI Orchestrator (agentic tool calling)
|--------------------------------------------------------------------------
| When enabled, the chatbox becomes a multi-step agent: the model can call the
| tools you allow-list below, you execute them, and the result is fed back until
| the model produces a final answer. OFF by default — when disabled (or when the
| active provider's engine cannot do tool calling, or no tools are allow-listed),
| the chatbox behaves exactly as before: a single model call per message.
|
| 'orchestrator_enabled'   — master switch. Env: AI_CHATBOX_ORCHESTRATOR
| 'orchestrator_max_steps'  — hard cap on tool-call loop iterations (runaway guard)
| 'orchestrator_timeout'    — wall-clock seconds for the whole run
| 'orchestrator_max_tokens' — token cap for agentic (tool-calling) turns. Kept
|                             separate from (and >= 1024, regardless of) the chat
|                             'max_tokens' so tool-call argument JSON is never truncated
| 'orchestrator_tools'     — allow-list of ToolInterface class names the model may
|                            use. Empty = no tools (safe default). A tool runs only
|                            if it is listed here AND its authorize() returns true.
|
| Tool calling requires a provider that supports it (OpenAI-compatible or Anthropic).
| Register your own tools by implementing
| \DeveloperUnijaya\AiChatbox\Orchestration\Contracts\ToolInterface and adding the
| class name here (or calling ToolRegistry::register() in a service provider).
*/

    'orchestrator_enabled' => env('AI_CHATBOX_ORCHESTRATOR', false),
    'orchestrator_max_steps' => (int) env('AI_CHATBOX_ORCHESTRATOR_MAX_STEPS', 5),
    'orchestrator_timeout' => (int) env('AI_CHATBOX_ORCHESTRATOR_TIMEOUT', 60),
    'orchestrator_max_tokens' => (int) env('AI_CHATBOX_ORCHESTRATOR_MAX_TOKENS', 1024),
    'orchestrator_tools' => [
        // Built-in demo tools (safe, read-only) — uncomment to enable:
        // \DeveloperUnijaya\AiChatbox\Orchestration\Tools\CurrentDateTimeTool::class,
        // \DeveloperUnijaya\AiChatbox\Orchestration\Tools\KnowledgeBaseSearchTool::class,

        // Your own tools:
        // \App\AiTools\GetOrderStatusTool::class,
    ],

/*
|--------------------------------------------------------------------------
| Named AI Providers
|--------------------------------------------------------------------------
| Define additional named providers for use with the AI facade:
|
|   AI::provider('ollama')->chat('Hello');
|   AI::provider('openai')->withTemperature(0.2)->chat('Hello');
|   AI::chat('Hello');   // uses the 'default' provider (top-level config above)
|
| Each provider must define api_url, api_token, and api_model via its own
| env vars. All other settings (temperature, system_prompt, language,
| history_limit, etc.) are inherited from the global defaults above.
|
| You can add as many named providers as you like. Custom provider names
| (e.g. 'lmstudio', 'mistral', 'azure') are fully supported.
*/

    'providers' => [

        'lmstudio' => [
            'api_url' => env('LMSTUDIO_URL', 'http://127.0.0.1:1234/v1/chat/completions'),
            'api_token' => env('LMSTUDIO_TOKEN', 'lmstudio'),
            'api_model' => env('LMSTUDIO_MODEL', 'phi-3.5-mini-instruct'),
            'rag_embedding_url' => env('LMSTUDIO_EMBEDDING_URL', 'http://127.0.0.1:1234/v1/embeddings'),
            'rag_embedding_model' => env('LMSTUDIO_EMBEDDING_MODEL', 'text-embedding-nomic-embed-text-v1.5'),
        ],

        'ollama' => [
            'api_url' => env('OLLAMA_URL', 'http://localhost:11434/v1/chat/completions'),
            'api_token' => env('OLLAMA_TOKEN', 'your-ollama-token'),
            'api_model' => env('OLLAMA_MODEL', 'gpt-oss:120b'),
            'rag_embedding_url' => env('OLLAMA_EMBEDDING_URL', 'http://localhost:11434/v1/embeddings'),
            'rag_embedding_model' => env('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text'),
        ],

        'openai' => [
            'api_url' => env('OPENAI_URL', ''),
            'api_token' => env('OPENAI_API_KEY', ''),
            'api_model' => env('OPENAI_MODEL', ''),
            'rag_embedding_url' => env('OPENAI_EMBEDDING_URL', ''),
            'rag_embedding_model' => env('OPENAI_EMBEDDING_MODEL', ''),
        ],

        'anthropic' => [
            'engine' => 'anthropic', // required — selects AnthropicEngine regardless of api_url
            'api_url' => env('ANTH_URL', 'https://api.anthropic.com/v1/messages'),
            'api_token' => env('ANTH_API_KEY', ''),
            'api_model' => env('ANTH_MODEL', 'claude-sonnet-4-6'),
            'anthropic_version' => env('ANTH_VERSION', '2023-06-01'),
            // Anthropic does not offer a public embedding API.
            // Point rag_embedding_url at an OpenAI-compatible embedding service
            // (e.g. OpenAI, LM Studio, or Ollama /v1/embeddings) and supply its
            // own token via rag_embedding_token when it differs from api_token.
            'rag_embedding_url' => env('ANTH_EMBEDDING_URL', ''),
            'rag_embedding_model' => env('ANTH_EMBEDDING_MODEL', ''),
            'rag_embedding_token' => env('ANTH_EMBEDDING_TOKEN', ''),
        ],
    ],

];

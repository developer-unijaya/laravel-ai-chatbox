Flow Diagram:

┌──────────────────────────────────────────────────┐
│                 CLIENT (Browser)                 │
│                                                  │
│   Chat widget — one of three drivers:            │
│     • Vue   • Blade (vanilla JS)   • Livewire    │
│   - User input                                   │
│   - Chat history (session/localStorage)          │
└───────────────────────┬──────────────────────────┘
                        │ HTTP  (POST /message · SSE /stream · /clear · /health)
                        ▼
┌──────────────────────────────────────────────────┐
│              APPLICATION SERVER                  │
│           (Laravel 10 / 11 / 12)                 │
│                                                  │
│  Layer 3 — UI                                    │
│  ┌─────────────────────────────────────────┐     │
│  │ ChatboxController                       │     │
│  │  validate → load history → trim context │     │
│  └────────────────────┬────────────────────┘     │
│                       ▼                          │
│  Layer 1.5 — Orchestration                       │
│  ┌─────────────────────────────────────────┐     │
│  │ Orchestrator  (agentic tool loop)       │     │
│  │  OFF by default → collapses to one call.│     │
│  │  ON → model calls allow-listed Tools,   │     │
│  │  results fed back, repeat to an answer. │     │
│  └──────┬────────────────────────┬─────────┘     │
│         │ prompt assembly        │ engine pick   │
│         ▼                        ▼               │
│  ┌───────────────┐      ┌──────────────────┐     │
│  │ PromptBuilder │      │ AiManager        │     │
│  │  + RAG inject │      │  resolveEngine() │     │
│  └──────┬────────┘      └────────┬─────────┘     │
│         │                        │               │
│         ▼                        ▼               │
│  Services / RAG           Layer 1 — Engine       │
│  ┌───────────────┐      ┌──────────────────┐     │
│  │ RagRetriever  │      │ OpenAiCompatible │     │
│  │ EmbeddingSvc  │      │  / Anthropic     │     │
│  │ DocumentChunk │      │  (retry + SSRF   │     │
│  └──────┬────────┘      │   guard, no      │     │
│         │               │   redirects)     │     │
│         ▼               └────────┬─────────┘     │
│  Layer 2 — Memory                │               │
│  ┌───────────────┐               │               │
│  │ Conversation  │               │               │
│  │  session | DB │               │               │
│  └───────────────┘               │               │
│                                  │               │
│  Internal storage:               │               │
│   • MySQL — chat logs (DB driver)│               │
│   • RAG tables — documents+chunks│               │
└──────────────────────────────────┬───────────────┘
                                   │ HTTPS (REST) — no redirects followed
                                   ▼
┌──────────────────────────────────────────────────┐
│                   AI PROVIDER                    │
│   (whichever AI_CHATBOX_ACTIVE_PROVIDER names)   │
│                                                  │
│   ┌────────────────────┐  ┌─────────────────┐    │
│   │ OpenAI-compatible  │  │ Anthropic       │    │
│   │  Ollama / OpenAI / │  │  Messages API   │    │
│   │  Groq / LM Studio /│  │  (Claude)       │    │
│   │  OpenRouter …      │  │                 │    │
│   └────────────────────┘  └─────────────────┘    │
│   Chat completion  +  (optional) /embeddings     │
└──────────────────────────────────────────────────┘

---

## Request lifecycle

1. **UI (Layer 3) — `ChatboxController`.** Validates the request, loads the
   thread's history from the Memory layer, and trims a copy to the context-token
   budget for the outgoing prompt. The stored history is written **append-only**
   after a successful reply (each turn persists only its own user+assistant pair,
   so concurrent writes can't drop each other's messages).

2. **Orchestration (Layer 1.5) — `Orchestrator`.** OFF by default: it collapses
   to exactly one plain `complete()` call, i.e. the original non-agentic
   behaviour. ON (`orchestrator_enabled` + tools allow-listed + a tool-capable
   engine): the model may call allow-listed `ToolInterface` tools, the package
   executes them and feeds the results back, and the loop repeats until the model
   returns a text answer or a safety limit trips. At the step limit it makes one
   final tool-less call so the user still gets a best-effort answer rather than an
   error.

3. **Prompt assembly — `PromptBuilder` (+ RAG).** Builds the message array:
   system prompt → history → RAG grounding instruction (system role) → the user
   turn. Retrieved knowledge-base chunks are folded into the **user** turn inside
   `<reference_material>` delimiters (never the system role), so a poisoned
   document can't override the system prompt.

4. **RAG / Services.** `RagRetriever` embeds the query via `EmbeddingService`
   (batched; falls back to keyword search when no embedding endpoint is set),
   streams candidate chunk vectors from the DB, scores them, and returns the
   top-K. Ingestion (`DocumentChunker` → `EmbeddingService` → store) is
   multibyte-safe, atomic (old chunks swapped in one transaction), and bounded by
   `rag_processing_timeout` / `rag_max_chunks_per_document`.

5. **Engine selection — `AiManager::resolveEngine()`.** Picks the engine from the
   active provider's config: `AnthropicEngine` when the provider is Anthropic,
   otherwise `OpenAiCompatibleEngine`. Prompt assembly, RAG, and Memory are
   identical regardless of backend.

6. **Engine (Layer 1).** Sends the chat completion (streamed or not). Hardened:
   transient failures (`408/429/5xx/529` + connection errors) are retried with
   backoff / `Retry-After`; redirects are never followed (SSRF + auth-header-leak
   guard); the health check's SSRF guard blocks private/reserved IPs but exempts
   loopback so a local provider works out of the box; `temperature` is omitted for
   Anthropic models that reject it; and the provider's real error body is logged.

---

## Providers

The "AI PROVIDER" box is whichever named provider is active
(`AI_CHATBOX_ACTIVE_PROVIDER`):

- Any **OpenAI-compatible** endpoint — Ollama, OpenAI, Groq, LM Studio,
  OpenRouter — handled by `OpenAiCompatibleEngine`.
- **Anthropic** (Claude) via the native Messages API — handled by
  `AnthropicEngine`, auto-selected when the provider's `engine` is `anthropic`
  (or the URL is `api.anthropic.com`).

Tool calling (Layer 1.5) requires a tool-capable engine (both of the above
implement `SupportsToolCalling`); other engines degrade gracefully to a single
plain completion. RAG embeddings use the provider's `rag_embedding_url` when set
(the chat token is reused only when the embedding host matches the chat host,
never sent to a different host).

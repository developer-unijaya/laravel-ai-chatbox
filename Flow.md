Flow Diagram:

┌──────────────────────────────┐
│          CLIENT (Browser)    │
│                              │
│   Vue Chat UI (Chatbox)      │
│   - User input               │
│   - Chat history display     │
└──────────────┬───────────────┘
               │ HTTP (AJAX / API)
               ▼
┌──────────────────────────────┐
│     APPLICATION SERVER       │
│   (Laravel 10 + Chatbox)     │
│                              │
│  ┌────────────────────────┐  │
│  │ laravel-ai-chatbox     │  │
│  │                        │  │
│  │ - Chat Controller      │  │
│  │ - Prompt Builder       │  │
│  │ - RAG (DB knowledge)   │  │
│  │ - Conversation Memory  │  │
│  └──────────┬─────────────┘  │
│             │                │
│             ▼                │
│     Internal Services        │
│  - MySQL (chat logs)         │
│  - Knowledge DB (RAG files)  │
└─────────────┬────────────────┘
              │ HTTP API (REST)
              ▼
┌──────────────────────────────┐
│         AI SERVER            │
│      (Self-hosted LLM)       │
│                              │
│     ┌──────────────────┐     │
│     │ Ollama API       │     │
│     │                  │     │
│     │ - Model (LLM)    │     │
│     │ - Inference      │     │
│     └────────┬─────────┘     │
│              │               │
│              ▼               │
│        Local Models          │
│     (Llama / Mistral etc.)   │
└──────────────────────────────┘

---

Note: the "AI SERVER" box above shows a self-hosted Ollama LLM, but it is only one
option. The chatbox proxies to whichever named provider is active
(`AI_CHATBOX_ACTIVE_PROVIDER`):

- Any OpenAI-compatible endpoint — Ollama, OpenAI, Groq, LM Studio, OpenRouter —
  handled by `OpenAiCompatibleEngine`.
- Anthropic (Claude) via the native Messages API — handled by `AnthropicEngine`,
  auto-selected when the provider URL contains `api.anthropic.com`.

The engine is chosen per request by `AiManager::resolveEngine()` from the active
provider's config, so the Prompt Builder, RAG, and Conversation Memory stages are
identical regardless of which AI backend is used.
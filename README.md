# laravel-ai-chatbox

[![Tests](https://github.com/developer-unijaya/laravel-ai-chatbox/actions/workflows/tests.yml/badge.svg)](https://github.com/developer-unijaya/laravel-ai-chatbox/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/developer-unijaya/laravel-ai-chatbox.svg?label=packagist)](https://packagist.org/packages/developer-unijaya/laravel-ai-chatbox)
[![Total Downloads](https://img.shields.io/packagist/dt/developer-unijaya/laravel-ai-chatbox.svg)](https://packagist.org/packages/developer-unijaya/laravel-ai-chatbox)
[![PHP](https://img.shields.io/packagist/php-v/developer-unijaya/laravel-ai-chatbox.svg)](https://packagist.org/packages/developer-unijaya/laravel-ai-chatbox)
[![Laravel](https://img.shields.io/badge/Laravel-10%20|%2011%20|%2012-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![Vue.js](https://img.shields.io/badge/Vue.js-3-42b883?logo=vue.js&logoColor=white)](https://vuejs.org)
[![License](https://img.shields.io/packagist/l/developer-unijaya/laravel-ai-chatbox.svg)](LICENSE)

A drop-in AI chatbox widget for Laravel. One Blade directive — no build tools required in your application.

Connect to any **OpenAI-compatible API** including Ollama, OpenAI, Groq, LM Studio, and OpenRouter — plus native **Anthropic (Claude)** support via the Messages API. Includes real-time token streaming, conversation memory, a full **RAG (Retrieval-Augmented Generation)** system, an admin dashboard, and a PHP facade for calling AI from anywhere in your codebase.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration Reference](#configuration-reference)
- [AI Providers](#ai-providers)
- [Frontend Drivers](#frontend-drivers)
- [AI Provider Facade](#ai-provider-facade)
- [Conversation Threads & Memory](#conversation-threads--memory)
- [Pruning Old Conversations](#pruning-old-conversations)
- [Token Control](#token-control)
- [Real-Time Streaming](#real-time-streaming)
- [RAG — Retrieval-Augmented Generation](#rag--retrieval-augmented-generation)
- [AI Orchestrator (Tools & Agents)](#ai-orchestrator-tools--agents)
- [Admin Dashboard](#admin-dashboard)
- [Security](#security)
- [Dark Mode](#dark-mode)
- [Customising the Widget](#customising-the-widget)
- [Architecture](#architecture)
- [Extending the Package](#extending-the-package)
- [Troubleshooting](#troubleshooting)
- [Testing](#testing)
- [License](#license)
- [Complete .env Reference](#complete-env-reference)

---

## Features

**Widget & Frontend**
- Drop `@aichatbox` into any Blade layout — nothing else required
- Four frontend drivers: **Vue 3** (default), **vanilla JS** (Blade), **Livewire + Alpine.js**, or **API-only** for React/Svelte/custom builds
- Floating button in any of four corners; dark mode follows OS preference
- Resizable chat window — cycle **1× / 2× / 3×** from a header button; the preferred size is remembered per user in the browser and restored on the next visit
- Markdown rendering with syntax-highlighted code blocks (bundled in Vue, CDN in Blade/Livewire)
- Sound notification on AI reply (Web Audio API, no audio file needed)
- Messages persist across page refresh via `localStorage` or `sessionStorage`

**AI & Streaming**
- Supports Ollama, OpenAI, Groq, LM Studio, OpenRouter, and any OpenAI-compatible endpoint
- Native **Anthropic (Claude)** support via the Messages API — auto-selected when the provider URL points at `api.anthropic.com`
- Real-time token streaming via Server-Sent Events (SSE) with a blinking cursor
- Configurable system prompt, language enforcement, temperature, and max tokens
- `AI` facade for calling providers directly from controllers, jobs, or commands

**Conversation Memory**
- Server-side history per thread — context sent back to the AI on every message
- Two memory drivers: **session** (default) or **database** (persists across session expiry)
- Configurable turn limit and token-based context trimming (oldest pairs pruned first)
- Isolated conversation threads with UUID thread IDs; start a new thread without losing others

**RAG — Retrieval-Augmented Generation**
- Upload `.md` and `.txt` documents; the chatbox retrieves relevant context automatically
- Document chunking with configurable size and overlap; per-provider embedding configuration
- **Two retrieval modes:** vector search (cosine similarity in PHP, no external vector database) and keyword search (SQL `LIKE`, no embedding service required) — either alone or as automatic fallback
- Knowledge Base UI at `/ai-chatbox/rag` with upload, reprocess, and delete actions

**AI Orchestrator (agentic tool calling)**
- Give the chatbox real abilities: the model can call **tools** you define — query a table, look up a record, hit an internal API — then answer from the result
- Works with OpenAI-compatible **and** Anthropic (Claude) providers; tool calling built into both engines
- Explicit allow-list, per-request authorization, step/time caps — off by default, safe by design
- Two safe read-only demo tools included (`current_datetime`, `knowledge_base_search`)
- Scaffold tools from a model in one command: `php artisan ai-chatbox:make-tool --model=Car`

**Admin & Operations**
- Admin dashboard at `/ai-chatbox/admin` with config diagnostics, live error/warning/notice checks, and provider details
- Conversations viewer at `/ai-chatbox/admin/conversations` (requires database memory driver)
- `ai-chatbox:prune-conversations` Artisan command — bulk-delete inactive conversations with `--days`, `--dry-run`, and `--force` options; schedulable via Laravel's task scheduler
- Health check endpoint pings the AI service before the widget opens
- SSRF protection, CORS origin whitelist, configurable rate limiting

---

## Requirements

| | Version |
|---|---|
| PHP | 8.2 or higher |
| Laravel | 10, 11, or 12 |

> No Node.js or npm is required in your application. The Vue bundle is pre-compiled and shipped as a static asset. The `blade` and `livewire` drivers need no compiled assets at all.

---

## Installation

### 1. Require the package

**From Packagist:**

```bash
composer require developer-unijaya/laravel-ai-chatbox
```

---

### 2. Publish assets

Publish CSS + JS to public/vendor/ai-chatbox/
```bash
php artisan vendor:publish --tag=ai-chatbox-assets
```

Publish the config file to customise defaults
```bash
php artisan vendor:publish --tag=ai-chatbox-config
```

If you plan to use **RAG** or the **database memory driver**, run the migrations:

Publish the migration files
```bash
php artisan vendor:publish --tag=ai-chatbox-migrations &&   
php artisan migrate
```

---

### 3. Configure your AI provider

The package defaults to the `ollama` provider on `localhost:11434`. Set your active provider and its credentials in `.env`:

```env
AI_CHATBOX_ACTIVE_PROVIDER=ollama
OLLAMA_URL=http://localhost:11434/v1/chat/completions
OLLAMA_TOKEN=your-ollama-token
OLLAMA_MODEL=gpt-oss:120b
AI_CHATBOX_LANGUAGE=English
```

See [AI Providers](#ai-providers) for examples covering OpenAI, Groq, LM Studio, and more.

> **Running Ollama in WSL?** `localhost` from a Windows host may not reach WSL. Find your WSL IP and use it:
> ```bash
> # run inside WSL
> ip addr show eth0 | grep 'inet '
> ```
> ```env
> OLLAMA_URL=http://172.x.x.x:11434/v1/chat/completions
> AI_CHATBOX_SSRF_PROTECTION=false
> ```

> **Local Ollama on macOS/Linux?** SSRF protection is on by default and blocks `localhost`. Disable it for local development:
> ```env
> AI_CHATBOX_SSRF_PROTECTION=false
> ```

---

### 4. Add the widget to a Blade layout

```blade
{{-- e.g. resources/views/layouts/app.blade.php --}}
@aichatbox
```

The chatbox appears as a floating button on every page that uses the layout. Done.

> Use `@aichatboxConfig` instead if you are building your own frontend (React, Svelte, etc.) — it outputs only `window.AiChatboxConfig` without any widget HTML or scripts.

---

## Configuration Reference

Publish and edit `config/ai-chatbox.php` to change any default.

### Active Provider

| Key | Env var | Default | Description |
|---|---|---|---|
| `active_provider` | `AI_CHATBOX_ACTIVE_PROVIDER` | `ollama` | Provider to use — must match a key under `providers`. The provider's `api_url`, `api_token`, and `api_model` are always the authoritative values. |

> `api_url`, `api_token`, and `api_model` are **not** top-level env vars. They are always sourced from the active named provider. See [AI Providers](#ai-providers).

---

### Response & Language

| Key | Env var | Default | Description |
|---|---|---|---|
| `language` | - | `English` | Language the AI must reply in — leave empty to let the model decide |
| `system_prompt` | - | `You are a helpful assistant...` | System message sent on every request — use `{language}` as a placeholder |

The `language` value is enforced at two points per request:

1. The `{language}` placeholder in `system_prompt` is substituted at runtime
2. `[Important: Reply in {language} only.]` is appended to every user message, which improves compliance on smaller models

These are project-level settings best changed by publishing the config file:

```bash
php artisan vendor:publish --tag=ai-chatbox-config
```

Then edit `config/ai-chatbox.php` directly.

---

### Response Tuning

| Key | Env var | Default | Description |
|---|---|---|---|
| `temperature` | - | `0.5` | Creativity — `0.0` deterministic, `1.0` creative |
| `max_tokens` | - | `300` | Max reply length — set to `null` to let the model decide (not supported by Anthropic) |
| `timeout` | `AI_CHATBOX_TIMEOUT` | `30` | Request timeout in seconds — increase for slow local models |

---

### Widget Appearance

| Key | Env var | Default | Description |
|---|---|---|---|
| `frontend` | - | `vue` | UI driver — `vue`, `blade`, `livewire`, or `none` |
| `title` | `AI_CHATBOX_TITLE` | `AI Assistant` | Widget header title |
| `greeting` | - | `Hi! How can I help you today?` | Opening message — leave empty to disable |
| `placeholder` | - | `Type your message...` | Input placeholder text |
| `theme_color` | - | `#0dad35` | Primary colour (hex) |
| `color_scheme` | - | `auto` | Colour scheme for the widget and admin pages — `auto` (OS preference), `light`, or `dark` |
| `position` | - | `bottom-right` | Widget position — `bottom-right`, `bottom-left`, `top-right`, `top-left` |
| `toggle_icon` | - | `null` | Custom image for the floating toggle button — URL or asset path; `null` uses the built-in chat bubble SVG |
| `markdown` | - | `true` | Render AI replies as Markdown |
| `sound` | - | `true` | Play a ping when the AI replies |
| `sound_volume` | - | `0.3` | Volume — `0.0` silent, `1.0` full |
| `stream` | `AI_CHATBOX_STREAM` | `true` | Stream replies token-by-token via SSE |

---

### Conversation History & Memory

| Key | Env var | Default | Description |
|---|---|---|---|
| `history_enabled` | `AI_CHATBOX_HISTORY` | `true` | Include previous messages for context |
| `history_limit` | - | `50` | Max user+assistant pairs kept per thread |
| `context_token_limit` | - | `4000` | Max estimated tokens of history per request — trims oldest pairs first (`0` = rely on `history_limit` only) |
| `memory_driver` | `AI_CHATBOX_MEMORY_DRIVER` | `session` | Server-side history driver — `session` or `database` |
| `storage` | `AI_CHATBOX_STORAGE` | `session` | Browser storage — `session` (clears on tab close, default) or `local` (persists across sessions) |

---

### Routes & Security

| Key | Env var | Default | Description |
|---|---|---|---|
| `route_prefix` | - | `ai-chatbox` | URL prefix for all chatbox routes |
| `middleware` | - | `['web', 'throttle:20,1', 'ai-chatbox.cors']` | Middleware stack for chatbox API routes |
| `rate_limit` | `AI_CHATBOX_RATE_LIMIT` | `20` | Max requests per window per IP |
| `rate_window` | `AI_CHATBOX_RATE_WINDOW` | `1` | Rate limit window in minutes |
| `health_check` | `AI_CHATBOX_HEALTH_CHECK` | `true` | Ping the AI service before opening the widget |
| `offline_message` | - | `AI service is currently unreachable.` | Toast shown when the service is unreachable |
| `ssrf_protection` | `AI_CHATBOX_SSRF_PROTECTION` | `true` | Block requests to private/reserved IP ranges |
| `allowed_origins` | - | `[env('APP_URL')]` | Origins allowed to call chatbox endpoints (CORS) |
| `rag_admin_middleware` | - | `['web', 'auth']` | Middleware for the Knowledge Base (`/ai-chatbox/rag`) pages |
| `admin_middleware` | - | `null` | Middleware for the Admin dashboard — inherits `rag_admin_middleware` when `null` |

---

### RAG

| Key | Env var | Default | Description |
|---|---|---|---|
| `rag_enabled` | `AI_CHATBOX_RAG` | `false` | Master switch — enable RAG context injection |
| `rag_embedding_timeout` | `AI_CHATBOX_EMBEDDING_TIMEOUT` | `10` | Timeout in seconds for every embedding HTTP request — applies to all providers |
| `rag_keyword_fallback` | `AI_CHATBOX_RAG_KEYWORD_FALLBACK` | `true` | When the embedding URL is absent or the embedding call fails, fall back to SQL keyword search instead of injecting the no-context guard. Words shorter than 3 characters are ignored. Set `false` to disable |
| `rag_top_k` | - | `10` | Number of chunks retrieved per query |
| `rag_chunk_size` | - | `500` | Target chunk size in tokens (~4 chars/token) |
| `rag_chunk_overlap` | - | `50` | Overlap between consecutive chunks in tokens |
| `rag_similarity_threshold` | - | `0.2` | Minimum cosine similarity score (`0.0`–`1.0`) |
| `rag_context_prompt` | - | *(see below)* | Instruction prepended to retrieved chunks when a match is found — use `{chunks}` as placeholder |
| `rag_no_context_prompt` | - | *(see below)* | Grounding guard injected when RAG is on but **no** chunk matches — keeps the model from answering off-context. Empty string disables it |
| `rag_processing_timeout` | `AI_CHATBOX_RAG_PROCESSING_TIMEOUT` | `0` | Max seconds for a single upload or reprocess — `0` = no limit |

> `rag_embedding_url`, `rag_embedding_model`, and `rag_embedding_token` are per-provider settings defined inside the `providers` block and resolved through the active named provider. See [AI Providers](#ai-providers).

---

## AI Providers

Every API connection is configured through a **named provider**. Set `AI_CHATBOX_ACTIVE_PROVIDER` to the provider name, then configure that provider's own env vars.

### Named providers — configuration

Named providers are defined under the `providers` key in `config/ai-chatbox.php`. Each entry can override any global setting; everything else is inherited.

```php
// config/ai-chatbox.php
'providers' => [
    'ollama'   => [
        'api_url'             => env('OLLAMA_URL',               'http://localhost:11434/v1/chat/completions'),
        'api_token'           => env('OLLAMA_TOKEN',             'your-ollama-token'),
        'api_model'           => env('OLLAMA_MODEL',             'gpt-oss:120b'),
        'rag_embedding_url'   => env('OLLAMA_EMBEDDING_URL',     'http://localhost:11434/v1/embeddings'),
        'rag_embedding_model' => env('OLLAMA_EMBEDDING_MODEL',   'nomic-embed-text'),
    ],
    'openai'   => [
        'api_url'             => env('OPENAI_URL',               ''),
        'api_token'           => env('OPENAI_API_KEY',           ''),
        'api_model'           => env('OPENAI_MODEL',             ''),
        'rag_embedding_url'   => env('OPENAI_EMBEDDING_URL',     ''),
        'rag_embedding_model' => env('OPENAI_EMBEDDING_MODEL',   ''),
    ],
    'lmstudio' => [
        'api_url'             => env('LMSTUDIO_URL',             'http://127.0.0.1:1234/v1/chat/completions'),
        'api_token'           => env('LMSTUDIO_TOKEN',           'lmstudio'),
        'api_model'           => env('LMSTUDIO_MODEL',           'phi-3.5-mini-instruct'),
        'rag_embedding_url'   => env('LMSTUDIO_EMBEDDING_URL',   'http://127.0.0.1:1234/v1/embeddings'),
        'rag_embedding_model' => env('LMSTUDIO_EMBEDDING_MODEL', 'text-embedding-nomic-embed-text-v1.5'),
    ],
    'anthropic' => [
        'engine'              => 'anthropic',                    // explicit engine selection
        'api_url'             => env('ANTH_URL',                 'https://api.anthropic.com/v1/messages'),
        'api_token'           => env('ANTH_API_KEY',             ''),
        'api_model'           => env('ANTH_MODEL',               'claude-sonnet-4-6'),
        'anthropic_version'   => env('ANTH_VERSION',             '2023-06-01'),
        'rag_embedding_url'   => env('ANTH_EMBEDDING_URL',       ''),
        'rag_embedding_model' => env('ANTH_EMBEDDING_MODEL',     ''),
        'rag_embedding_token' => env('ANTH_EMBEDDING_TOKEN',     ''), // separate token for the embedding service
    ],
],
```

> `groq` and other providers are not in the default config — add them as custom entries after publishing (see [OpenRouter example](#openrouter-custom-provider) below for the pattern).

> **Anthropic is not OpenAI-compatible.** The package selects `AnthropicEngine` when a provider has `engine: 'anthropic'` **or** its `api_url` contains `anthropic.com` — no manual binding required. Anthropic has no embeddings endpoint; set `ANTH_EMBEDDING_URL` and `ANTH_EMBEDDING_TOKEN` to point at a separate OpenAI-compatible embeddings service (Ollama, LM Studio, OpenAI), or leave them empty to use keyword-only RAG retrieval.

**Env var reference per provider:**

| Provider | URL | Token | Model | Embedding URL | Embedding model | Embedding token |
|---|---|---|---|---|---|---|
| `ollama` | `OLLAMA_URL` | `OLLAMA_TOKEN` | `OLLAMA_MODEL` | `OLLAMA_EMBEDDING_URL` | `OLLAMA_EMBEDDING_MODEL` | — |
| `openai` | `OPENAI_URL` | `OPENAI_API_KEY` | `OPENAI_MODEL` | `OPENAI_EMBEDDING_URL` | `OPENAI_EMBEDDING_MODEL` | — |
| `lmstudio` | `LMSTUDIO_URL` | `LMSTUDIO_TOKEN` | `LMSTUDIO_MODEL` | `LMSTUDIO_EMBEDDING_URL` | `LMSTUDIO_EMBEDDING_MODEL` | — |
| `anthropic` | `ANTH_URL` | `ANTH_API_KEY` | `ANTH_MODEL` | `ANTH_EMBEDDING_URL` | `ANTH_EMBEDDING_MODEL` | `ANTH_EMBEDDING_TOKEN` |

> `rag_embedding_timeout` (`AI_CHATBOX_EMBEDDING_TIMEOUT`) is a universal setting — it applies to all providers and is not overridable per provider. Configure it once in the RAG section.

> **`rag_embedding_token`** — only needed when the embedding service uses a different API key than the chat provider. Useful for Anthropic users who point `ANTH_EMBEDDING_URL` at OpenAI or another separate service.

> The chatbox widget and the `AI` facade both resolve through the same named provider. `AI_CHATBOX_ACTIVE_PROVIDER` controls which provider is active for both.

---

### Provider examples

#### Ollama (local)

```env
AI_CHATBOX_ACTIVE_PROVIDER=ollama
OLLAMA_URL=http://localhost:11434/v1/chat/completions
OLLAMA_TOKEN=your-ollama-token
OLLAMA_MODEL=gpt-oss:120b
AI_CHATBOX_SSRF_PROTECTION=false
```

#### Ollama Cloud

```env
AI_CHATBOX_ACTIVE_PROVIDER=ollama
OLLAMA_URL=https://ollama.com/api/chat
OLLAMA_TOKEN=your_ollama_api_key
OLLAMA_MODEL=gpt-oss:120b
```

#### OpenAI

```env
AI_CHATBOX_ACTIVE_PROVIDER=openai
OPENAI_URL=https://api.openai.com/v1/chat/completions
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4o
```

#### Anthropic (Claude)

Anthropic uses its own Messages API, not the OpenAI-compatible format. The package detects `anthropic.com` in the URL (or the explicit `engine: 'anthropic'` key) and switches to the native `AnthropicEngine` automatically — chat and streaming both work out of the box.

```env
AI_CHATBOX_ACTIVE_PROVIDER=anthropic
ANTH_URL=https://api.anthropic.com/v1/messages
ANTH_API_KEY=sk-ant-...
ANTH_MODEL=claude-sonnet-4-6
# Optional: pin the API version header (default 2023-06-01)
ANTH_VERSION=2023-06-01
```

Anthropic does not expose an embeddings endpoint. RAG still works in **keyword-only mode** (no extra configuration needed). To enable vector/semantic search, point the embedding vars at a compatible service and provide its token separately:

```env
# RAG via a separate OpenAI-compatible embedding service
ANTH_EMBEDDING_URL=https://api.openai.com/v1/embeddings
ANTH_EMBEDDING_MODEL=text-embedding-3-small
ANTH_EMBEDDING_TOKEN=sk-openai-key-here
```

Or use a local model (Ollama, LM Studio) — just set `ANTH_EMBEDDING_TOKEN` to whatever token that service expects (or leave it empty for Ollama).

> When `ANTH_EMBEDDING_URL` is empty, the chatbox falls back to keyword search automatically (controlled by `AI_CHATBOX_RAG_KEYWORD_FALLBACK`). Documents can be uploaded and searched by keyword without any embedding service configured.

#### Groq (custom provider)

Groq is not in the default config — add it after publishing `config/ai-chatbox.php`:

```php
'providers' => [
    'groq' => [
        'api_url'   => env('GROQ_URL',     'https://api.groq.com/openai/v1/chat/completions'),
        'api_token' => env('GROQ_API_KEY', ''),
        'api_model' => env('GROQ_MODEL',   ''),
    ],
    // ... other providers
],
```

```env
AI_CHATBOX_ACTIVE_PROVIDER=groq
GROQ_URL=https://api.groq.com/openai/v1/chat/completions
GROQ_API_KEY=gsk_...
GROQ_MODEL=llama-3.3-70b-versatile
```

#### LM Studio (local)

```env
AI_CHATBOX_ACTIVE_PROVIDER=lmstudio
LMSTUDIO_URL=http://localhost:1234/v1/chat/completions
LMSTUDIO_TOKEN=lmstudio
LMSTUDIO_MODEL=your-loaded-model-name
AI_CHATBOX_SSRF_PROTECTION=false
```

> Start LM Studio, load a model, and enable the **Local Server** tab. The model name must match exactly what LM Studio displays.

#### OpenRouter (custom provider)

Add a custom entry to your published `config/ai-chatbox.php`:

```php
'providers' => [
    'openrouter' => [
        'api_url'   => env('OPENROUTER_URL',     'https://openrouter.ai/api/v1/chat/completions'),
        'api_token' => env('OPENROUTER_API_KEY',  ''),
        'api_model' => env('OPENROUTER_MODEL',    'mistralai/mistral-7b-instruct'),
    ],
    // ... other providers
],
```

```env
AI_CHATBOX_ACTIVE_PROVIDER=openrouter
OPENROUTER_URL=https://openrouter.ai/api/v1/chat/completions
OPENROUTER_API_KEY=sk-or-...
OPENROUTER_MODEL=mistralai/mistral-7b-instruct
```

---

## Frontend Drivers

The `frontend` setting controls how `@aichatbox` renders. All drivers share the same backend API routes and `window.AiChatboxConfig` — only the widget layer differs.

| Driver | Widget | Streaming | JS dependency | Assets required |
|---|---|---|---|---|
| `vue` | Vue 3 SFC | SSE | Bundled `chatbox.js` | `vendor:publish --tag=ai-chatbox-assets` |
| `blade` | Vanilla JS | SSE | None (marked.js from CDN if Markdown on) | Same |
| `livewire` | Alpine.js | SSE | Alpine.js (bundled with Livewire 3) | Same |
| `none` | - | Your choice | None | Not required |

> **No env var.** Publish the config and set `frontend` directly in `config/ai-chatbox.php`:

```php
// config/ai-chatbox.php
'frontend' => 'vue',      // Vue 3 widget (default)
'frontend' => 'blade',    // Vanilla JS, no framework
'frontend' => 'livewire', // Alpine.js via Livewire
'frontend' => 'none',     // API + config only
```

---

### `vue` — Vue 3 (default)

No extra setup. The pre-compiled bundle mounts to `#ai-chatbox-app` and reads `window.AiChatboxConfig`.

---

### `blade` — Vanilla JS

A self-contained widget with no framework dependency. Uses the same CSS as the Vue driver (identical HTML class names), so all appearance options apply equally.

If `AI_CHATBOX_MARKDOWN=true`, `marked.js` and `DOMPurify` are loaded from jsDelivr at runtime. Set `AI_CHATBOX_MARKDOWN=false` to remove the CDN dependency entirely.

---

### `livewire` — Livewire + Alpine.js

Renders an Alpine.js widget. Livewire 3 bundles Alpine.js automatically — no additional scripts needed.

The package registers a Livewire component, so you can also mount the widget independently:

```blade
<livewire:ai-chatbox />
```

> If you use `<livewire:ai-chatbox />` without `@aichatbox`, add `@aichatboxConfig` to your layout so the widget has access to its configuration.

```blade
{{-- layout --}}
@aichatboxConfig
...
{{-- anywhere on the page --}}
<livewire:ai-chatbox />
```

---

### `none` — API-only / custom frontend

Outputs only `window.AiChatboxConfig`. Use this when building your own React, Svelte, or other framework frontend.

`@aichatboxConfig` produces the same output regardless of the `frontend` setting:

```blade
@aichatboxConfig
```

**`window.AiChatboxConfig` reference:**

```js
window.AiChatboxConfig = {
    url,            // POST /ai-chatbox/message  — full JSON reply
    streamUrl,      // POST /ai-chatbox/stream   — SSE token stream
    clearUrl,       // POST /ai-chatbox/clear    — clear session history
    healthUrl,      // GET  /ai-chatbox/health   — liveness check
    token,          // CSRF token
    stream,         // boolean
    healthCheck,    // boolean
    title,
    placeholder,
    greeting,
    markdown,       // boolean
    sound,          // boolean
    soundVolume,    // 0.0–1.0
    position,       // 'bottom-right' | 'bottom-left' | 'top-right' | 'top-left'
    storageKey,     // localStorage/sessionStorage key (scoped per app + user)
    storageType,    // 'local' | 'session'
    offlineMessage,
    themeColor,
};
```

All API endpoints accept `{ message, thread_id }` as JSON. Responses:

| Endpoint | Response |
|---|---|
| `POST /ai-chatbox/message` | `{ "reply": "..." }` |
| `POST /ai-chatbox/stream` | SSE events: `data: {"token":"..."}` ending with `data: [DONE]` |
| `POST /ai-chatbox/clear` | `{ "status": "ok" }` |
| `GET /ai-chatbox/health` | `{ "status": "online" }` or `{ "status": "offline", "message": "...", "code": "E##" }` |

---

## AI Provider Facade

The `AI` facade lets you call any configured AI provider directly from controllers, jobs, Artisan commands, or services — without touching the chatbox widget.

### Basic usage

```php
use DeveloperUnijaya\AiChatbox\AI;

// Use the active provider (resolves to AI_CHATBOX_ACTIVE_PROVIDER)
$reply = AI::chat('Summarise this document: ...');

// Use a specific named provider
$reply = AI::provider('openai')->chat('Translate to French: ...');
$reply = AI::provider('lmstudio')->chat('Write a test for this function...');
$reply = AI::provider('ollama')->chat('What is the capital of France?');
```

### Fluent modifiers

Every modifier returns a **new immutable instance** — the original provider is never mutated.

```php
$reply = AI::provider('openai')
    ->withModel('gpt-4o-mini')
    ->withTemperature(0.2)
    ->withSystemPrompt('You are a JSON-only responder. Return only valid JSON.')
    ->withMaxTokens(512)
    ->withTimeout(60)
    ->chat($prompt);
```

| Method | Description |
|---|---|
| `->withModel(string $model)` | Override the model for this call |
| `->withSystemPrompt(string $prompt)` | Override the system prompt |
| `->withLanguage(string $lang)` | Override the reply language |
| `->withTemperature(float $temp)` | Override creativity (`0.0`–`1.0`) |
| `->withMaxTokens(?int $tokens)` | Override max reply length (`null` = model default) |
| `->withTimeout(int $seconds)` | Override the HTTP timeout |
| `->withConfig(array $overrides)` | Merge arbitrary config overrides |

### Streaming via facade

```php
// Pass a callback — tokens are emitted synchronously
AI::provider('openai')->stream($prompt, [], function (string $token) {
    echo $token;
    ob_flush(); flush();
});

// Or receive a Closure to invoke later
$reader = AI::provider('default')->stream($prompt);
$reader(fn(string $token) => print($token));
```

### Chat with history

```php
$history = [
    ['role' => 'user',      'content' => 'Previous question'],
    ['role' => 'assistant', 'content' => 'Previous answer'],
];

$reply = AI::provider('default')->chat('Follow-up question', $history);
```

### How provider resolution works

| Usage | Resolves to |
|---|---|
| **Chatbox widget** | Provider named by `AI_CHATBOX_ACTIVE_PROVIDER` |
| `AI::chat()` / `AI::provider('default')` | Provider named by `AI_CHATBOX_ACTIVE_PROVIDER` |
| `AI::provider('openai')` | `openai` entry in `config/ai-chatbox.php` |
| `AI::provider('ollama')` | `ollama` entry in `config/ai-chatbox.php` |
| `AI::provider('anthropic')` | `anthropic` entry — engine auto-switches to `AnthropicEngine` |

---

## Conversation Threads & Memory

### Thread IDs

Each time a user first opens the chatbox, a **UUID v4 thread ID** is generated in the browser and stored in `localStorage` (or `sessionStorage`). This ID is sent with every message and scopes the server-side history — so multiple independent conversations never share context.

```
Thread A (UUID: 550e8400...)  →  session key: ai_chatbox_history_550e8400...
Thread B (UUID: 6ba7b810...)  →  session key: ai_chatbox_history_6ba7b810...
```

The **pencil icon** in the widget header starts a fresh thread. The **trash icon** clears the current thread's history. Thread IDs survive page refresh — the same conversation context is restored on return.

---

### Session memory driver (default)

History is stored in the PHP session and sent to the AI on every subsequent message.

```env
AI_CHATBOX_MEMORY_DRIVER=session
AI_CHATBOX_HISTORY=true
AI_CHATBOX_HISTORY_LIMIT=50
```

Set `AI_CHATBOX_HISTORY=false` to make every message standalone (no context sent):

```env
AI_CHATBOX_HISTORY=false
```

---

### Database memory driver

Switch to the `database` driver to persist history in Eloquent models. History survives PHP session expiry, is shared across all PHP workers, and can be queried or exported.

```env
AI_CHATBOX_MEMORY_DRIVER=database
```

Run the migration if you haven't already:

```bash
php artisan migrate
```

This creates:

| Table | Purpose |
|---|---|
| `ai_chatbox_conversations` | One row per thread, keyed by UUID |
| `ai_chatbox_messages` | All messages per thread (role + content) |

The [Conversations Viewer](#conversations-viewer) in the admin dashboard requires this driver.

To revert, set `AI_CHATBOX_MEMORY_DRIVER=session`. Existing database records are preserved but ignored until you switch back.

---

### Browser storage

Chat bubbles are persisted in the browser, automatically scoped to prevent history leaking between different apps or different authenticated users on the same device.

| Setting | Behaviour |
|---|---|
| `AI_CHATBOX_STORAGE=session` | Cleared when the tab is closed (default) |
| `AI_CHATBOX_STORAGE=local` | Persists across browser sessions |

---

## Pruning Old Conversations

When using the `database` memory driver, conversation records accumulate over time. The `ai-chatbox:prune-conversations` command permanently deletes conversations (and their messages via cascade) that have had no activity beyond the configured retention period.

### Running the command

```bash
# Use the default from config (AI_CHATBOX_PRUNE_DAYS, default 30 days)
php artisan ai-chatbox:prune-conversations

# Override the retention period at runtime
php artisan ai-chatbox:prune-conversations --days=60

# Preview what would be deleted without making any changes
php artisan ai-chatbox:prune-conversations --dry-run

# Run even when memory_driver is not set to 'database' (e.g. cleanup after switching drivers)
php artisan ai-chatbox:prune-conversations --force
```

### Scheduling automatic pruning

Register the command in your application's `routes/console.php` (Laravel 11+) or `app/Console/Kernel.php` (Laravel 10):

**Laravel 11+ (`routes/console.php`):**

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('ai-chatbox:prune-conversations')->daily();
```

**Laravel 10 (`app/Console/Kernel.php`):**

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('ai-chatbox:prune-conversations')->daily();
}
```

### Configuration

| Key | Env var | Default | Description |
|---|---|---|---|
| `conversation_prune_days` | `AI_CHATBOX_PRUNE_DAYS` | `30` | Conversations inactive for longer than this many days are deleted |

```env
AI_CHATBOX_PRUNE_DAYS=30   # default — 30 days retention
AI_CHATBOX_PRUNE_DAYS=90   # longer retention
AI_CHATBOX_PRUNE_DAYS=7    # aggressive cleanup
```

### Error handling

The command performs the following checks before deleting anything:

| Condition | Behaviour |
|---|---|
| `memory_driver` is not `database` | Exits with an error — use `--force` to override |
| `ai_chatbox_conversations` table missing | Exits with an error and prints migration instructions |
| `ai_chatbox_messages` table missing | Warns but continues (cascade may not apply) |
| `--days` value is less than 1 | Exits with an error |
| No matching conversations found | Exits cleanly with an informational message |

> **How "last activity" is determined:** `updated_at` on the conversation row is updated every time `saveHistory` is called — i.e., every time the user sends a message. Conversations that have genuinely had no activity for `--days` days are safe to remove.

---

## Token Control

Two independent limits control how much is sent to the AI per request.

> **These settings have no env var.** Publish the config file and edit `config/ai-chatbox.php` directly:
> ```bash
> php artisan vendor:publish --tag=ai-chatbox-config
> ```

### Context token limit

Limits the amount of conversation history included per request. History is trimmed oldest-pair-first using a ~4 chars/token heuristic.

```php
// config/ai-chatbox.php
'context_token_limit' => 4000,   // phi3:mini, llama3 8B (default)
'context_token_limit' => 8000,   // llama3 70B, Mixtral
'context_token_limit' => 32000,  // GPT-4o, Claude
'context_token_limit' => 0,      // disabled — rely on history_limit only
```

### Max reply tokens

Limits how long the AI's reply can be. Leave `null` to let the model decide.

```php
// config/ai-chatbox.php
'max_tokens' => 300,   // default — short replies
'max_tokens' => 512,   // medium replies
'max_tokens' => 2048,  // longer replies
'max_tokens' => null,  // let the model decide (not supported by Anthropic)
```

Both limits work together: `history_limit` caps by message count, `context_token_limit` caps by estimated tokens. Whichever is reached first applies.

### Temperature

```php
// config/ai-chatbox.php
'temperature' => 0.2,  // focused, deterministic
'temperature' => 0.5,  // balanced (default)
'temperature' => 1.0,  // creative, unpredictable
```

---

## Real-Time Streaming

When `AI_CHATBOX_STREAM=true` (default), AI replies are streamed token-by-token via **Server-Sent Events**. Each word appears as it is generated, with a blinking `▋` cursor while in progress.

```env
AI_CHATBOX_STREAM=true    # stream token-by-token (default)
AI_CHATBOX_STREAM=false   # wait for the full reply before displaying
```

**How it works:**

1. The frontend calls `POST /ai-chatbox/stream` using the Fetch API and `ReadableStream`
2. The server proxies the AI response using Guzzle's `'stream' => true` and reads 1024-byte chunks
3. Each token is emitted as: `data: {"token":"word"}\n\n`
4. The stream ends with: `data: [DONE]\n\n`
5. Markdown rendering is applied to the completed reply, not during streaming

**Server configuration:**

```
Nginx   proxy_buffering off;  (the package sets X-Accel-Buffering: no automatically)
PHP     output_buffering = Off  in php.ini
```

---

## RAG — Retrieval-Augmented Generation

RAG lets the chatbox answer questions about **your own data** — documents, FAQs, knowledge bases — without fine-tuning any model.

```
User sends a message
     ↓
Message is embedded → cosine similarity search across all indexed chunks
     ↓
Top-K most relevant chunks are injected as a system message
     ↓
AI answers using your knowledge-base context
```

### Quick start

**1. Enable RAG and set embedding config on your active provider:**

```env
AI_CHATBOX_RAG=true

# Set embedding settings for your active provider (example: ollama)
OLLAMA_EMBEDDING_URL=http://localhost:11434/v1/embeddings
OLLAMA_EMBEDDING_MODEL=nomic-embed-text
```

**2. Run the migration:**

```bash
php artisan migrate
```

**3. Upload documents** at `/ai-chatbox/rag` (requires an authenticated user by default).

Every subsequent chat message will automatically retrieve and inject relevant context.

---

### Embedding providers

RAG uses a **separate embedding API** — distinct from the chat API. Any provider with an `/embeddings` endpoint works.

Embedding settings are configured **per named provider** via `rag_embedding_url`, `rag_embedding_model`, and optionally `rag_embedding_token`. They are resolved through the active provider.

> **No embedding URL?** RAG still works. When `rag_embedding_url` is not set, the package falls back to keyword search automatically (see [`rag_keyword_fallback`](#rag) in the config reference). You can upload documents and retrieve relevant chunks by keyword matching — no embedding service required.

| Provider | URL env var | Model env var | Token env var |
|---|---|---|---|
| Ollama | `OLLAMA_EMBEDDING_URL` | `OLLAMA_EMBEDDING_MODEL` | — |
| LM Studio | `LMSTUDIO_EMBEDDING_URL` | `LMSTUDIO_EMBEDDING_MODEL` | — |
| OpenAI | `OPENAI_EMBEDDING_URL` | `OPENAI_EMBEDDING_MODEL` | — |
| Anthropic (via OpenAI) | `ANTH_EMBEDDING_URL` | `ANTH_EMBEDDING_MODEL` | `ANTH_EMBEDDING_TOKEN` |

| Provider | Example URL | Example model |
|---|---|---|
| Ollama | `http://localhost:11434/v1/embeddings` | `nomic-embed-text`, `mxbai-embed-large` |
| LM Studio | `http://127.0.0.1:1234/v1/embeddings` | your loaded embedding model name |
| OpenAI | `https://api.openai.com/v1/embeddings` | `text-embedding-3-small`, `text-embedding-3-large` |

> **`rag_embedding_token`** — only set this when the embedding service requires a different API key from the chat provider (e.g. Anthropic chat + OpenAI embeddings). For Ollama/LM Studio this can be left empty.

> **Ollama:** Pull the embedding model first:
> ```bash
> ollama pull nomic-embed-text
> ```

---

### Document formats

| Format | Extension | Notes |
|---|---|---|
| Plain text | `.txt` | Chunked directly |
| Markdown | `.md` | Heading structure is preserved across chunks |

Maximum upload size: **10 MB** per file.

---

### How chunking works

Documents are split into overlapping text chunks before embedding:

```
rag_chunk_size    = 500 tokens ≈ 2 000 chars (default)
rag_chunk_overlap =  50 tokens ≈   200 chars (default)
```

The chunker:

1. Splits on paragraph boundaries (two or more blank lines)
2. Falls back to sentence boundaries (`. ! ?`) for oversized paragraphs
3. Carries `chunk_overlap` characters into the next chunk so context is not lost at boundaries

---

### Retrieval modes

The package supports two retrieval strategies. They work together: vector search runs first when an embedding URL is configured, and keyword search runs as an automatic fallback when it is not — or when the embedding call fails.

| | Vector search | Keyword search |
|---|---|---|
| **Requires** | Embedding URL + model configured for the active provider | Nothing — works with any provider |
| **How it works** | The user's query is embedded; cosine similarity is computed in PHP against every stored chunk | The query is split into words ≥ 3 characters; a SQL `LIKE` search finds chunks containing any of those words, ranked by hit count |
| **Precision** | High — finds semantically related content even when exact words differ | Moderate — matches on exact words only |
| **Setup** | Set `rag_embedding_url` and `rag_embedding_model` on the active provider | No extra setup — enabled by default via `rag_keyword_fallback=true` |

**Which mode runs on each request:**

| Condition | Mode used |
|---|---|
| Embedding URL is configured and the call succeeds | Vector search |
| Embedding URL is absent **and** `rag_keyword_fallback=true` (default) | Keyword-only |
| Embedding URL is configured but the call fails **and** `rag_keyword_fallback=true` | Keyword fallback |
| No embedding available **and** `rag_keyword_fallback=false` | No context — `rag_no_context_prompt` guard fires instead |

> **Keyword mode is not a degraded fallback — it is a fully usable mode.** Documents uploaded without an embedding URL are indexed for keyword retrieval immediately after chunking. The Knowledge Base UI at `/ai-chatbox/rag` labels which mode is active and what is needed to upgrade to vector search.

---

### How retrieval works

On every chat message:

1. **Vector path** — the user's query is embedded using the configured model; cosine similarity is computed in PHP against all stored chunks; chunks below `rag_similarity_threshold` (default `0.2`) are discarded
2. **Keyword path** (when `rag_keyword_fallback=true` and step 1 produced no results) — the query is split into words ≥ 3 characters; a SQL `LIKE` search is run across all chunks from `ready` documents; results are ranked by number of matching terms
3. The top `rag_top_k` (default `10`) chunks from whichever path ran are injected as a system message using `rag_context_prompt`, replacing the `{chunks}` placeholder

The default prompt instructs the model to answer **only** from the retrieved chunks and reply "I don't have that information in my knowledge base" when the answer is not found there. Edit `rag_context_prompt` in the published config to customise it.

#### Grounding when nothing matches

A common complaint is *"the bot answered from its own knowledge instead of my documents."* This happens on questions the knowledge base doesn't cover: when **no chunk clears `rag_similarity_threshold`** (or the embedding call fails, or there are no indexed documents), there is nothing to inject — so without a guard the model is left completely unconstrained and falls back to its training data.

To prevent this, when RAG is enabled but no chunk matches, the package injects `rag_no_context_prompt` instead. The default refuses factual answers while still allowing greetings and small talk:

```php
// config/ai-chatbox.php — default
'rag_no_context_prompt' => "No relevant knowledge-base entries were found for this question. "
    . "If the user is asking for information or a factual answer, do not answer from general knowledge — "
    . "reply exactly: \"I don't have that information in my knowledge base.\" "
    . "You may still respond naturally to greetings, thanks, and small talk.",
```

Set it to an empty string to disable the guard (the model answers unconstrained when nothing matches). If your bot is *still* going off-context even when chunks **are** retrieved, tighten `rag_context_prompt` to forbid outside knowledge outright, and consider raising `rag_similarity_threshold` so weak/irrelevant chunks aren't injected.

> **Scale:** Similarity is computed in PHP and works well up to a few thousand chunks. For larger knowledge bases, consider switching to a database with native vector support such as `pgvector` for PostgreSQL.

---

### Knowledge Base UI

Visit **`/ai-chatbox/rag`** (authenticated users only).

| Action | Description |
|---|---|
| **Upload** | Select a `.md` or `.txt` file, optionally set a title, click *Upload & Index* (or *Save & Chunk* in keyword-only mode) |
| **Reprocess** | Re-chunk and re-embed an existing document after changing chunk or embedding settings |
| **Delete** | Remove the document and all its chunks permanently (confirmation required) |

Each document shows its status (`Pending` → `Processing` → `Ready` / `Failed`), chunk count, and expandable error details on failure.

**Banners:**

| Condition | Banner |
|---|---|
| Embedding URL and model are both set | No banner — full vector search mode |
| Embedding URL is **not** set | Amber warning — keyword-only mode; uploads are still enabled |
| Embedding URL is set but model is **missing** | Red error — misconfigured; uploads are disabled until corrected |

---

### Rebuilding the Knowledge Base from a graphify knowledge graph

Instead of uploading files by hand, you can populate the knowledge base directly from your codebase using [graphify](https://github.com/safishamsi/graphify) — a tool that turns a codebase, docs, and media into a queryable knowledge graph and exports it as markdown.

The intended workflow is **generate once, commit, rebuild anywhere**:

1. **Generate the graph** (once, by a developer) — run graphify against your application and commit the resulting `graphify-out/` folder to your repository:

   ```bash
   graphify . --wiki      # code structure via tree-sitter (no LLM cost); --wiki adds one article per community
   ```

   graphify always writes `GRAPH_REPORT.md`; `--wiki` and `--obsidian` add per-community articles that make richer RAG context.

2. **Rebuild the knowledge base** (on any machine where the app runs):

   ```bash
   php artisan ai-chatbox:graphify
   ```

   This reads every markdown file under `graphify-out/` (recursively) and imports each as a knowledge-base document through the same chunking pipeline as manual uploads. With no embedding endpoint configured (e.g. the Anthropic provider) the documents are indexed for keyword retrieval; with an embedding endpoint they are embedded for vector search.

| Option | Description |
|---|---|
| `--path=<dir>` | Import from a directory other than `base_path('graphify-out')` |
| `--dry-run` | List the markdown files that would be imported without writing anything |
| `--keep` | Append to the knowledge base instead of replacing previously imported graphify documents |

Each run **replaces** the documents it imported previously (matched by the `graphify-out/` marker), so the knowledge base always mirrors the committed graph. Documents you uploaded manually through the Knowledge Base UI are never touched.

> graphify is only needed on the machine that **generates** the graph. Rebuilding the knowledge base from a committed `graphify-out/` folder needs nothing but PHP — no Python and no graphify install on your app servers.

---

## AI Orchestrator (Tools & Agents)

The orchestrator turns the chatbox from a single *"prompt in → text out"* call into a multi-step **agent**. Instead of only talking, the model can call **tools** you define — look up a record, query a table, hit an internal API — and use the results to answer.

It is **off by default**. When disabled, every message is a single model call exactly as before — no behaviour change, no new tables, no new dependencies.

> **Provider support.** Tool calling requires a provider that supports it: any OpenAI-compatible endpoint (OpenAI, Groq, LM Studio, …) **or** Anthropic (Claude). Both engines ship with tool-calling built in. If the active provider's engine cannot do tool calling, the orchestrator transparently falls back to a single plain completion.

### How it works

```
1. Your message is sent to the model together with the list of allow-listed tools.
2. The model either answers directly (done), or asks to call one or more tools.
3. The orchestrator authorizes + validates + runs each tool, and feeds the result back.
4. Steps 2–3 repeat until the model produces a final answer — or a safety limit trips.
```

The orchestrator owns the loop: it enforces the step limit, the wall-clock timeout, per-tool authorization, argument validation, and error handling, so a runaway model can neither loop forever nor call something it shouldn't.

### Enabling

```env
AI_CHATBOX_ORCHESTRATOR=true
```

| Key | Env var | Default | Description |
|---|---|---|---|
| `orchestrator_enabled` | `AI_CHATBOX_ORCHESTRATOR` | `false` | Master switch |
| `orchestrator_max_steps` | `AI_CHATBOX_ORCHESTRATOR_MAX_STEPS` | `5` | Max tool-call loop iterations (runaway guard) |
| `orchestrator_max_tokens` | `AI_CHATBOX_ORCHESTRATOR_MAX_TOKENS` | `1024` | Max tokens for agentic tool turns (independent of the chat `max_tokens`) |
| `orchestrator_timeout` | `AI_CHATBOX_ORCHESTRATOR_TIMEOUT` | `60` | Wall-clock seconds for the whole run |
| `orchestrator_tools` | — | `[]` | Allow-list of tool class names the model may use |

> A tool runs **only** if its class is in `orchestrator_tools` **and** its `authorize()` returns true for the current request. An empty list means no tools — the safe default.

### Writing a tool

Implement `ToolInterface` — the same "implement an interface, register it" pattern used for [custom engines](#custom-ai-engine) and [custom memory drivers](#custom-memory-driver):

```php
use DeveloperUnijaya\AiChatbox\Orchestration\Contracts\ToolInterface;
use Illuminate\Http\Request;

class GetOrderStatusTool implements ToolInterface
{
    public function name(): string
    {
        return 'get_order_status';
    }

    public function description(): string
    {
        return 'Look up the current status of a customer order by its order number.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'string', 'description' => 'The order number, e.g. "A-10234".'],
            ],
            'required' => ['order_id'],
        ];
    }

    public function authorize(?Request $request = null): bool
    {
        return $request?->user() !== null;   // logged-in users only
    }

    public function handle(array $arguments): mixed
    {
        $order = \App\Models\Order::where('number', $arguments['order_id'])
            ->where('user_id', auth()->id())        // scope to the caller — never trust the model
            ->first();

        return $order
            ? ['status' => $order->status, 'eta' => $order->eta?->toDateString()]
            : ['error' => 'Order not found'];
    }
}
```

| Method | Purpose |
|---|---|
| `name()` | Unique tool name the model calls (`[a-zA-Z0-9_-]`) |
| `description()` | One line the model reads to decide *when* to use the tool |
| `parameters()` | JSON-Schema object describing the arguments |
| `authorize(?Request)` | Return `false` to hide/deny the tool for this request |
| `handle(array $arguments)` | Execute and return any JSON-serialisable value |

Then allow-list it:

```php
// config/ai-chatbox.php
'orchestrator_enabled' => true,
'orchestrator_tools'   => [
    \App\AiTools\GetOrderStatusTool::class,
],
```

Now the chatbox can act on it:

> **User:** "Where's my order A-10234?"
> *(model calls `get_order_status({order_id: "A-10234"})` → `{status: "shipped", eta: "2026-07-20"}`)*
> **Assistant:** "Your order A-10234 has shipped and should arrive around 20 July 2026."

### Generating a tool with `artisan`

Scaffold a tool instead of writing it by hand:

```bash
# Blank skeleton — you fill in handle()
php artisan ai-chatbox:make-tool GetWeatherTool

# Model-backed read tool — introspects the table for typed filter arguments + a query
php artisan ai-chatbox:make-tool --model=Car

# Restrict which columns become filter arguments
php artisan ai-chatbox:make-tool --model=Car --filterable=brand,year
```

Model mode reads the model's table and pre-fills `parameters()` (one optional, **typed** filter per column) and `handle()` (a read query returning the selected columns). It writes to `app/AiTools/`, excludes `id` from filters and timestamps entirely, and prints the exact allow-list line to add.

| Option | Purpose |
|---|---|
| `--model=` | Eloquent model to build a read tool from (`Car` or `App\Models\Car`) |
| `--tool-name=` | Override the tool's snake_case `name()` (default derived) |
| `--columns=` | Comma list of columns the tool returns (default: all non-timestamp) |
| `--filterable=` | Comma list of columns exposed as filter arguments |
| `--namespace=` | Namespace for the generated class (default `App\AiTools`) |
| `--force` | Overwrite an existing tool |

> Generated tools are **read-only** and default to authenticated-users-only — they are a *starting point*. Add per-user scoping (a `// TODO` marks exactly where) and allow-list the class before it does anything. Customise the generated shape with `php artisan vendor:publish --tag=ai-chatbox-stubs`.

### Registering tools at runtime

Instead of (or in addition to) the config allow-list, register tools programmatically in a service provider:

```php
use DeveloperUnijaya\AiChatbox\Orchestration\ToolRegistry;

public function boot(): void
{
    $this->app->make(ToolRegistry::class)->register(new GetOrderStatusTool());
}
```

### Built-in demo tools

Two safe, read-only tools ship with the package — both **disabled** until you allow-list them:

| Tool name | Class | Purpose |
|---|---|---|
| `current_datetime` | `Orchestration\Tools\CurrentDateTimeTool` | Returns the current server date/time (optional timezone) |
| `knowledge_base_search` | `Orchestration\Tools\KnowledgeBaseSearchTool` | Lets the model search the RAG knowledge base on demand (pull, instead of always-on injection) |

```php
'orchestrator_tools' => [
    \DeveloperUnijaya\AiChatbox\Orchestration\Tools\CurrentDateTimeTool::class,
    \DeveloperUnijaya\AiChatbox\Orchestration\Tools\KnowledgeBaseSearchTool::class,
],
```

### Streaming behaviour

When streaming is on (`AI_CHATBOX_STREAM=true`) and the orchestrator runs a tool loop, the tool-calling turns run **non-streamed**; the **final answer** is then streamed to the widget token-by-token as usual. No frontend change is required — the Vue, Blade, and Livewire widgets work unchanged.

### Safety & limits

- **Explicit allow-list only** — no auto-discovery. Empty list = no tools.
- **Per-request authorization** via `authorize()` — scope tools to admins, owners, or logged-in users.
- **Argument validation** — required arguments are checked before the tool runs.
- **Step & time caps** (`orchestrator_max_steps`, `orchestrator_timeout`) prevent runaway loops and cost blow-ups.
- **Failures never 500 the request.** A tool that is unknown, unauthorized, gets bad arguments, or throws is reported back to the model as an error result so it can recover — see the codes below.

> **Cost note.** An orchestrated message can make several model + tool calls, not one. Keep `orchestrator_max_steps` conservative and consider a stricter throttle on high-traffic apps.

### Orchestration error codes

Distinct from the engine's `E01`–`E19` (which are logged to `storage/logs/laravel.log`):

| Code | Meaning | Fatal to the request? |
|---|---|---|
| `O01` | Maximum steps reached without a final answer | Yes |
| `O02` | Wall-clock timeout exceeded | Yes |
| `O03` | Unknown tool requested | No — reported back to the model |
| `O04` | Tool not authorized for this request | No — reported back to the model |
| `O05` | Missing required argument | No — reported back to the model |
| `O06` | Tool threw during execution | No — reported back to the model |

---

## Admin Dashboard

Visit **`/ai-chatbox/admin`** (authenticated users only).

### Dashboard overview

| Section | Content |
|---|---|
| **Stat cards** | RAG document/chunk counts; conversation and message counts (database driver) |
| **Configuration diagnostics** | Live validation of every config group at page load — errors (red), warnings (amber), notices (blue). All clear → green banner |
| **Config values** | All resolved settings grouped by section |
| **Named providers** | All configured providers and their settings |
| **Environment** | Laravel version, PHP version, app environment, debug mode |

Diagnostic checks include: missing API URL/token/model, placeholder tokens left in place, `APP_DEBUG` on in production, SSRF conflicts with local URLs, open CORS origins, missing database tables, invalid RAG chunk settings, failed or unembedded documents, weak admin middleware, and selected frontend driver not installed.

---

### Conversations viewer

Visit **`/ai-chatbox/admin/conversations`** (requires `memory_driver=database`).

- **Async-paginated table** — loads conversation rows via JSON; shows thread ID, user name, message count, last message preview, and last active time
- **Click a row** to open a modal with full message history in a chat-bubble layout
- **Guest sessions** — rows show "Guest" when no user is associated

---

### Protecting the admin UI

Two separate middleware keys control access:

| Key | Controls | Default |
|---|---|---|
| `rag_admin_middleware` | Knowledge Base — `/ai-chatbox/rag` (document upload/delete) | `['web', 'auth']` |
| `admin_middleware` | Admin dashboard — `/ai-chatbox/admin` and conversations viewer | inherits `rag_admin_middleware` when `null` |

By default both require an authenticated user. For production, publish the config and tighten each independently:

```php
// config/ai-chatbox.php

// Restrict document management to admins only
'rag_admin_middleware' => ['web', 'auth', 'role:admin'],

// Allow all authenticated users to view the dashboard (read-only diagnostics)
'admin_middleware' => ['web', 'auth'],
```

Common middleware examples:

```php
'rag_admin_middleware' => ['web', 'auth', 'role:admin'],          // Spatie roles
'rag_admin_middleware' => ['web', 'auth', 'can:manage-chatbox'],  // Laravel Gates
'admin_middleware'     => ['web', 'auth:sanctum'],                // Sanctum
```

---

## Routes

All routes are registered under the configured prefix (`ai-chatbox` by default).

```
GET    /ai-chatbox/health                               Health check — ping the AI service
POST   /ai-chatbox/message                              Send a message, receive a full JSON reply
POST   /ai-chatbox/stream                               Send a message, stream SSE tokens
POST   /ai-chatbox/clear                                Clear server-side history for a thread

GET    /ai-chatbox/rag                                  Knowledge Base — list indexed documents  [auth]
POST   /ai-chatbox/rag                                  Knowledge Base — upload a document       [auth]
DELETE /ai-chatbox/rag/{id}                             Knowledge Base — delete a document       [auth]
POST   /ai-chatbox/rag/{id}/reprocess                   Knowledge Base — re-chunk and re-embed   [auth]

GET    /ai-chatbox/admin                                Admin dashboard                          [auth]
GET    /ai-chatbox/admin/conversations                  Conversations list                       [auth]
GET    /ai-chatbox/admin/conversations/data             Conversations JSON (paginated)           [auth]
GET    /ai-chatbox/admin/conversations/{id}/messages    Messages for a conversation (JSON)       [auth]
```

---

## Security

### SSRF protection

The health check pings the configured `api_url` to verify the AI service is reachable. To prevent Server-Side Request Forgery (SSRF), requests to private and reserved IP ranges are blocked by default: `localhost`, `10.x`, `172.16.x`, `192.168.x`, `169.254.x`.

```env
AI_CHATBOX_SSRF_PROTECTION=true   # production — keep enabled (default)
AI_CHATBOX_SSRF_PROTECTION=false  # local Ollama / LM Studio — disable
```

### CORS

The package registers an `ai-chatbox.cors` middleware that restricts chatbox endpoints to requests originating from your application's URL. Cross-origin requests from other domains are rejected with `403`.

To permit additional origins, publish the config:

```php
'allowed_origins' => [
    env('APP_URL', 'http://localhost'),
    'https://other-allowed-origin.example.com',
],
```

### Authentication

By default the chatbox is accessible to guests. To restrict it to authenticated users:

```php
// config/ai-chatbox.php
'middleware' => ['web', 'throttle:20,1', 'ai-chatbox.cors', 'auth'],
// or with Sanctum:
'middleware' => ['web', 'throttle:20,1', 'ai-chatbox.cors', 'auth:sanctum'],
```

### Browser storage & sensitive data

Conversation history is stored in `localStorage` by default. For privacy-sensitive applications, switch to `sessionStorage`:

```env
AI_CHATBOX_STORAGE=session
```

> Do not enter passwords, tokens, or other secrets into the chatbox regardless of the storage driver — any script running on the page can read browser storage.

---

## Dark Mode

### Chat widget and admin pages

The `color_scheme` setting controls both the chat widget and all admin pages (`/ai-chatbox/admin`, `/ai-chatbox/admin/conversations`, `/ai-chatbox/rag`):

| Value | Behaviour |
|---|---|
| `auto` *(default)* | Follows the OS/browser `prefers-color-scheme` preference |
| `light` | Always light, regardless of OS preference |
| `dark` | Always dark, regardless of OS preference |

> **No env var.** Publish the config and set `color_scheme` directly in `config/ai-chatbox.php`:

```php
// config/ai-chatbox.php
'color_scheme' => 'auto',  // OS preference (default)
'color_scheme' => 'light',
'color_scheme' => 'dark',
```

> Run `php artisan config:clear` after changing config values.

---

## Customising the Widget

Publish views to override any Blade template:

```bash
php artisan vendor:publish --tag=ai-chatbox-views
```

Published to `resources/views/vendor/ai-chatbox/`:

| File | Driver | Purpose |
|---|---|---|
| `chatbox.blade.php` | all | Main dispatcher — routes to the active driver |
| `chatbox-config.blade.php` | all | Outputs `window.AiChatboxConfig` |
| `chatbox-vue.blade.php` | `vue` | CSS link + Vue mount point + JS bundle |
| `chatbox-blade.blade.php` | `blade` | Full vanilla JS widget |
| `livewire/chatbox.blade.php` | `livewire` | Alpine.js widget |

### Chat Window Size

End users can resize the chat window with the **resize button** in the widget header. Each click cycles through three sizes:

| Size | Dimensions | CSS class (on `#ai-chatbox-wrapper`) |
|---|---|---|
| **1×** (default) | 360 × 480 | *(none)* |
| **2×** | 720 × 760 | `ai-chatbox--size-2x` |
| **3×** | 1040 × 980 | `ai-chatbox--size-3x` |

The window is capped to the viewport (`min(…, calc(100vw - 48px))` for width and height), so the largest size never overflows small screens.

The chosen size is **remembered in the browser** — it uses the same `localStorage` / `sessionStorage` driver configured by [`storage`](#configuration-reference), under the storage key suffix `_size` — and is restored on the next visit. A first-time visitor (or after the size key is cleared) starts at **1×**.

To change the 2× / 3× dimensions, override the CSS variables. Publish the assets (`--tag=ai-chatbox-assets`) and add your own rule after the stylesheet, or edit `public/vendor/ai-chatbox/css/chatbox.css`:

```css
#ai-chatbox-wrapper.ai-chatbox--size-2x { --chatbox-width: 640px;  --chatbox-height: 720px; }
#ai-chatbox-wrapper.ai-chatbox--size-3x { --chatbox-width: 900px;  --chatbox-height: 900px; }
```

---

## Architecture

The package is organised into four explicit layers. Each layer communicates only with the layer directly above or below it; controllers contain no business logic. An optional **Orchestration** layer sits between the UI and the Engine when [agentic tool calling](#ai-orchestrator-tools--agents) is enabled; when disabled it is bypassed entirely and behaviour is unchanged.

```
┌──────────────────────────────────────────────────────┐
│  Layer 4 — UI                                        │
│  ChatboxController · RagController · AdminController │
│  Blade views · Vue 3 · Blade · Livewire drivers      │
│  HTTP request / response only                        │
├──────────────────────────────────────────────────────┤
│  Layer 3 — RAG                                       │
│  RagRetriever · EmbeddingService · DocumentChunker   │
│  RagDocument + RagChunk models                       │
│  Document upload, chunking, embedding, retrieval     │
├──────────────────────────────────────────────────────┤
│  Layer 2 — Memory                                    │
│  ContextManager                                      │
│  SessionConversationRepository                       │
│  DatabaseConversationRepository                      │
│  Conversation + Message models                       │
│  History persistence and context trimming            │
├──────────────────────────────────────────────────────┤
│  Layer 1 — AI Engine                                 │
│  OpenAiCompatibleEngine · AnthropicEngine            │
│  AiEngineInterface · PromptBuilder · HealthChecker   │
│  AiEngineException (error codes E01–E19)             │
│  HTTP calls, prompt assembly, error handling         │
└──────────────────────────────────────────────────────┘
```

**Source layout:**

```
src/
├── Config/
│   └── ai-chatbox.php
├── Console/
│   ├── Commands/
│   │   ├── PruneConversations.php # ai-chatbox:prune-conversations
│   │   ├── GraphifyImport.php     # ai-chatbox:graphify
│   │   └── MakeAiTool.php         # ai-chatbox:make-tool (orchestrator tool generator)
│   └── stubs/                     # publishable — tag: ai-chatbox-stubs
├── Database/
│   └── Migrations/
├── Engine/
│   ├── Contracts/AiEngineInterface.php
│   ├── Contracts/SupportsToolCalling.php   # optional tool-calling capability
│   ├── EngineResult.php             # text-or-tool-calls result value object
│   ├── OpenAiCompatibleEngine.php
│   ├── AnthropicEngine.php          # native Anthropic Messages API (extends OpenAiCompatibleEngine)
│   ├── HealthChecker.php
│   └── PromptBuilder.php
├── Http/
│   ├── Controllers/
│   └── Middleware/CorsMiddleware.php
├── Memory/
│   ├── Contracts/ConversationRepositoryInterface.php
│   ├── SessionConversationRepository.php
│   ├── DatabaseConversationRepository.php
│   ├── ContextManager.php
│   └── Models/
│       ├── Conversation.php
│       └── Message.php
├── Models/
│   ├── RagDocument.php
│   └── RagChunk.php
├── Orchestration/                    # optional agentic tool-calling layer (off by default)
│   ├── Contracts/ToolInterface.php
│   ├── Orchestrator.php              # the tool-calling loop (step/time caps, dispatch)
│   ├── ToolRegistry.php              # allow-list + authorization + schemas
│   ├── ToolCall.php · OrchestratorResult.php
│   ├── Exceptions/OrchestrationException.php   # codes O01–O06
│   └── Tools/                        # built-in demo tools (disabled until allow-listed)
│       ├── CurrentDateTimeTool.php
│       └── KnowledgeBaseSearchTool.php
├── Services/
│   ├── RagRetriever.php
│   ├── EmbeddingService.php
│   └── DocumentChunker.php
├── resources/
│   └── views/
│       ├── chatbox.blade.php
│       ├── chatbox-config.blade.php
│       ├── chatbox-vue.blade.php
│       ├── chatbox-blade.blade.php
│       ├── admin.blade.php
│       ├── admin-conversations.blade.php
│       ├── rag.blade.php
│       └── livewire/chatbox.blade.php
├── AI.php                         # Facade
├── AiManager.php                  # Provider registry + singleton
└── AiChatboxServiceProvider.php
```

---

## Extending the Package

### Custom AI engine

> **Anthropic (Claude) ships built in.** The package includes `AnthropicEngine` and auto-selects it when a provider's `api_url` contains `api.anthropic.com` — see [Anthropic (Claude)](#anthropic-claude) under provider examples. You only need a custom engine for *other* non-OpenAI-compatible providers.

Implement `AiEngineInterface` to support a provider that is not OpenAI-compatible (Gemini, Cohere, etc.):

```php
use DeveloperUnijaya\AiChatbox\Engine\Contracts\AiEngineInterface;
use DeveloperUnijaya\AiChatbox\Engine\Exceptions\AiEngineException;

class AnthropicEngine implements AiEngineInterface
{
    public function validateConfig(array $options): void
    {
        if (empty($options['api_token'])) {
            throw new AiEngineException('E03', 'API token missing', 500);
        }
    }

    public function complete(array $messages, array $options = []): string
    {
        // Call Anthropic Messages API, return the reply as a plain string
    }

    public function stream(array $messages, array $options, callable $onToken): string
    {
        // Call $onToken('word') per token, return the full assembled reply
    }

    public function beginStream(array $messages, array $options): \Closure
    {
        // Open the HTTP connection before response()->stream() starts
        // Return a closure: fn(callable $onToken): string
        $this->validateConfig($options);
        return function (callable $onToken): string {
            // read stream, call $onToken per token, return full reply
        };
    }
}
```

Bind in a service provider:

```php
use DeveloperUnijaya\AiChatbox\Engine\Contracts\AiEngineInterface;

$this->app->bind(AiEngineInterface::class, AnthropicEngine::class);
```

---

### Custom memory driver

Implement `ConversationRepositoryInterface` to store history in Redis, MongoDB, or any other backend:

```php
use DeveloperUnijaya\AiChatbox\Memory\Contracts\ConversationRepositoryInterface;

class RedisConversationRepository implements ConversationRepositoryInterface
{
    public function getHistory(string $threadId): array
    {
        return json_decode(Redis::get("chat:{$threadId}") ?? '[]', true);
    }

    public function saveHistory(string $threadId, array $history): void
    {
        Redis::set("chat:{$threadId}", json_encode($history));
    }

    public function trimToLimit(string $threadId, int $maxPairs): void
    {
        $history = $this->getHistory($threadId);
        $this->saveHistory($threadId, array_slice($history, -($maxPairs * 2)));
    }

    public function clear(string $threadId): void
    {
        Redis::del("chat:{$threadId}");
    }
}
```

Bind in a service provider:

```php
use DeveloperUnijaya\AiChatbox\Memory\Contracts\ConversationRepositoryInterface;

$this->app->bind(ConversationRepositoryInterface::class, RedisConversationRepository::class);
```

> Binding a custom implementation directly overrides the `memory_driver` config key selection.

---

### Custom orchestrator tool

Give the chatbox a new ability by implementing `ToolInterface` and allow-listing the class. See [AI Orchestrator (Tools & Agents)](#ai-orchestrator-tools--agents) for the full contract, a worked example, and the safety model.

```php
use DeveloperUnijaya\AiChatbox\Orchestration\Contracts\ToolInterface;

class GetOrderStatusTool implements ToolInterface { /* name/description/parameters/authorize/handle */ }
```

```php
// config/ai-chatbox.php
'orchestrator_enabled' => true,
'orchestrator_tools'   => [ \App\AiTools\GetOrderStatusTool::class ],
```

---

## Troubleshooting

If the widget shows an offline toast or requests fail, check `storage/logs/laravel.log` for an error code (`E01`–`E19`). Full reference: [TROUBLESHOOTING.md](TROUBLESHOOTING.md)

Orchestrator runs add their own codes (`O01`–`O06`) — see [Orchestration error codes](#orchestration-error-codes).

---

## Testing

```bash
composer test
```

The test suite covers: controller responses, error classification, session history, conversation thread isolation, token-based context trimming, SSE streaming, RAG document upload/delete/reprocess, RAG context injection (vector and keyword fallback), CORS middleware, SSRF protection, health check logic, `AiManager` named provider resolution and engine selection, `AiProvider` fluent modifiers and immutability, the `AI` facade, the `AnthropicEngine` (complete/stream/headers/system message extraction), admin diagnostics (all config groups, keyword-only mode notices), and the `ai-chatbox:prune-conversations` command (pre-flight checks, deletion, boundary conditions, cascade, `--dry-run`, `--force`, config key precedence); and the **AI orchestrator** — engine tool-calling for both the OpenAI-compatible and Anthropic engines (payload shapes, tool-call parsing, tool-result messages), the orchestration loop (tool execution, step limit, timeout, and `O03`–`O06` recoverable failures), the tool registry (allow-list, authorization, schema output), the `ai-chatbox:make-tool` generator (blank + model-backed scaffolding, typed filters, overwrite protection), and backward compatibility (identical behaviour when the orchestrator is disabled) — using PHPUnit 11 and Orchestra Testbench.

---

## License

MIT — see [LICENSE](LICENSE) for details.

---

## Complete `.env` Reference

All available settings with their default values.

```env
# ── Active Provider ────────────────────────────────────────────────────────────
# api_url, api_token, and api_model are always sourced from the active provider.
AI_CHATBOX_ACTIVE_PROVIDER=ollama

# ── Response Tuning ────────────────────────────────────────────────────────────
AI_CHATBOX_TIMEOUT=30

# ── Conversation History ───────────────────────────────────────────────────────
AI_CHATBOX_HISTORY=true

# ── Streaming ─────────────────────────────────────────────────────────────────
AI_CHATBOX_STREAM=true

# ── Health Check ──────────────────────────────────────────────────────────────
AI_CHATBOX_HEALTH_CHECK=true

# ── Security ──────────────────────────────────────────────────────────────────
AI_CHATBOX_SSRF_PROTECTION=true  # disable for local Ollama / LM Studio
AI_CHATBOX_RATE_LIMIT=20
AI_CHATBOX_RATE_WINDOW=1

# ── Widget Appearance ─────────────────────────────────────────────────────────
AI_CHATBOX_TITLE="AI Assistant"

# ── Memory ────────────────────────────────────────────────────────────────────
AI_CHATBOX_MEMORY_DRIVER=session  # session | database

# ── RAG ───────────────────────────────────────────────────────────────────────
# Embedding URL and model are per-provider — set them in the provider block below.
# Tuning values (top_k, chunk_size, etc.) are set in the published config file.
AI_CHATBOX_RAG=false
AI_CHATBOX_EMBEDDING_TIMEOUT=10       # universal — applies to all providers
AI_CHATBOX_RAG_PROCESSING_TIMEOUT=0  # 0 = no limit
AI_CHATBOX_RAG_KEYWORD_FALLBACK=true  # keyword search when embedding URL is absent

# ── AI Orchestrator (agentic tool calling) ────────────────────────────────────
# Off by default. When enabled, the model can call allow-listed tools. Requires a
# provider that supports tool calling (OpenAI-compatible or Anthropic). The tool
# allow-list (orchestrator_tools) is set in the published config, not via env.
AI_CHATBOX_ORCHESTRATOR=false
AI_CHATBOX_ORCHESTRATOR_MAX_STEPS=5    # max tool-call loop iterations (runaway guard)
AI_CHATBOX_ORCHESTRATOR_TIMEOUT=60    # wall-clock seconds for the whole run

# ── Named Provider Credentials ────────────────────────────────────────────────
# The chatbox widget and AI facade both resolve through these env vars.
# AI_CHATBOX_ACTIVE_PROVIDER selects which block is used.

# Ollama
OLLAMA_URL=http://localhost:11434/v1/chat/completions
OLLAMA_TOKEN=your-ollama-token
OLLAMA_MODEL=gpt-oss:120b
OLLAMA_EMBEDDING_URL=http://localhost:11434/v1/embeddings
OLLAMA_EMBEDDING_MODEL=nomic-embed-text

# LM Studio
LMSTUDIO_URL=http://127.0.0.1:1234/v1/chat/completions
LMSTUDIO_TOKEN=lmstudio
LMSTUDIO_MODEL=phi-3.5-mini-instruct
LMSTUDIO_EMBEDDING_URL=http://127.0.0.1:1234/v1/embeddings
LMSTUDIO_EMBEDDING_MODEL=text-embedding-nomic-embed-text-v1.5

# OpenAI
OPENAI_URL=https://api.openai.com/v1/chat/completions
OPENAI_API_KEY=
OPENAI_MODEL=gpt-4o
OPENAI_EMBEDDING_URL=https://api.openai.com/v1/embeddings
OPENAI_EMBEDDING_MODEL=text-embedding-3-small

# Anthropic (Claude) — native Messages API, auto-detected via anthropic.com in URL
ANTH_URL=https://api.anthropic.com/v1/messages
ANTH_API_KEY=
ANTH_MODEL=claude-sonnet-4-6
ANTH_VERSION=2023-06-01              # anthropic-version header; update when Anthropic releases a new version
# Anthropic has no embeddings endpoint.
# Leave empty for keyword-only RAG, or point at a compatible service (OpenAI, Ollama, LM Studio):
ANTH_EMBEDDING_URL=
ANTH_EMBEDDING_MODEL=
ANTH_EMBEDDING_TOKEN=                # separate token for the embedding service (if different from ANTH_API_KEY)

# Groq / OpenRouter / custom providers — add a 'providers' entry in config/ai-chatbox.php
# (see Provider examples in the docs above)
```

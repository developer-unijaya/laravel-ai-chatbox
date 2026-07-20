# AI Chatbox — Troubleshooting & Error Code Reference

When the AI service is offline or a request fails, the package returns a JSON response containing a `code` field:

```json
{ "status": "offline", "code": "E07", "message": "AI service is currently unreachable." }
```

The same code is written to `storage/logs/laravel.log`. Search for it:

```bash
grep "E07" storage/logs/laravel.log
```

> **How URLs and tokens are configured:** `api_url`, `api_token`, and `api_model` are always sourced from the **active named provider**, not from top-level env vars. Set `AI_CHATBOX_ACTIVE_PROVIDER` to select the provider, then configure that provider's own variables (e.g. `OLLAMA_URL`, `OPENAI_API_KEY`). See the [Configuration Reference](README.md#ai-providers) in the README.

### Contents

- **[How to debug — start here](#how-to-debug--start-here)** — the 3-tool workflow (read this first)
- [The Admin Diagnostics panel](#the-admin-diagnostics-panel) · [The health endpoint](#the-health-endpoint)
- [Where to look — a map](#where-to-look--a-map)
- [Error Code Reference](#error-code-reference) — `E01`–`E19` (config, security, network, HTTP, response) and `O01`–`O06` (orchestration)
- [HTTP status quick reference](#http-status-quick-reference) — the package route's own 400/403/419/422/429/500/502
- [What the widget shows the user](#what-the-widget-shows-the-user) — bubble text → real cause
- [Reading the Log](#reading-the-log) · [Log message index](#log-message-index)
- [Common Scenarios](#common-scenarios) — per-provider setup, [widget doesn't appear](#widget-does-not-appear-at-all), [session expired](#the-widget-says-your-session-has-expired), [silent fallbacks](#silent-fallbacks-no-error-but-not-what-you-expected), [console commands](#console-command-errors)

---

## How to debug — start here

Work through these three tools **in order**. Most issues are identified at step 1 without ever reading a log.

1. **Open the built-in Diagnostics panel** at **`/ai-chatbox/admin`**. It runs ~11 automated checks against your live config and lists every problem as an **error** (red — will break), **warning** (amber — may misbehave), or **info** (blue — worth knowing). This is the fastest way to find a misconfiguration. See [The Admin Diagnostics panel](#the-admin-diagnostics-panel) below.
2. **Open your browser DevTools → Network tab**, reproduce the issue, and click the failed `message` / `stream` / `rag` request. The **HTTP status** and the JSON body (which usually contains a `code` field) tell you what the *server* returned. See [HTTP status quick reference](#http-status-quick-reference) and [What the widget shows the user](#what-the-widget-shows-the-user).
3. **Read the server log** — `storage/logs/laravel.log`. Every error the package handles is logged with a searchable `AI Chatbox` prefix and, for provider failures, the provider's **real error body**. See [Reading the Log](#reading-the-log) and the [Log message index](#log-message-index).

> **Golden rule:** the message shown to the end-user is always generic ("Unable to reach AI service", "Something went wrong") on purpose — the *specific* cause is in the Diagnostics panel, the HTTP response `code`, or the log. Never debug from the chat bubble text alone.

---

## The Admin Diagnostics panel

Visit **`/ai-chatbox/admin`** (the `route_prefix` defaults to `ai-chatbox`). The dashboard runs these checks live and shows the results grouped by area:

| Group | Catches (examples) |
|---|---|
| **PHP** | `curl` extension missing (all AI calls fail); `mbstring` missing |
| **Active Provider** | `AI_CHATBOX_ACTIVE_PROVIDER` unset / points at an undefined provider; missing `api_url` / `api_token` / `api_model`; placeholder values still in place |
| **Providers** | each named provider's URL/token/model completeness ("complete" vs "incomplete" badge) |
| **Security** | `APP_DEBUG=true` in production; `allowed_origins` wildcard; SSRF disabled; token exposure |
| **Response** | `system_prompt`, `temperature`, `max_tokens` sanity (e.g. `max_tokens` very low → truncated replies) |
| **History** | `memory_driver` / `history_limit` / `context_token_limit` sanity |
| **Frontend / Widget** | invalid `frontend` driver, `position`, `color_scheme`, `storage`; markdown/CDN notes |
| **RAG** | `rag_embedding_url` set but `rag_embedding_model` empty; external embedding host with no `rag_embedding_token`; documents in `failed` state; zero indexed chunks |
| **Memory** | `memory_driver=database` but the tables aren't migrated |
| **Admin Protection** | admin / RAG routes still on the bare `[web, auth]` gate (any logged-in user gets in) |
| **Streaming** | `stream=true` combined with settings/servers that buffer SSE |

Each item is one of:

- 🔴 **error** — will break the feature; fix before expecting it to work.
- 🟠 **warning** — works but likely misconfigured or insecure.
- 🔵 **info** — informational (a fallback is active, a default is in play).

The dashboard also shows the resolved config (secrets masked), the RAG/knowledge-base stats, and a **provider "Test" button** that runs the [health check](#the-health-endpoint) live.

> **Can't reach `/ai-chatbox/admin`?** In production it returns **403** by default until you configure a real admin gate — see [Admin dashboard or Knowledge Base returns 403](#admin-dashboard-or-knowledge-base-returns-403-in-production).

### The health endpoint

`GET /ai-chatbox/health` (optionally `?provider=<name>`) pings the configured provider's base URL and returns `{"status":"online"|"offline","code":"E##","message":...}`. The widget calls it before opening. Hit it directly (or use the admin "Test" button) to check connectivity in isolation from the chat flow.

---

## Where to look — a map

| Symptom source | Where to look |
|---|---|
| Configuration / setup problems | **`/ai-chatbox/admin`** diagnostics panel (start here) |
| A request that returned an error | Browser **DevTools → Network** → the request's status + JSON `code` |
| The *real* provider error behind a generic message | `storage/logs/laravel.log` → `AI Chatbox: provider request failed` (`provider_response`) |
| Any handled error, with its code | `storage/logs/laravel.log` → search `AI Chatbox` |
| RAG document state (ready / failed / processing) | Admin → Knowledge Base (`/ai-chatbox/rag`), each doc's status + `error_message` |
| Widget not appearing at all | Browser **Console** for JS errors; page source for the widget markup; see [Widget doesn't appear](#widget-does-not-appear-at-all) |
| Console command failures | The command's stdout (`ai-chatbox:graphify`, `:make-tool`, `:prune-conversations`) |
| Effective config values | Admin panel (masked), or `php artisan config:show ai-chatbox` |

---

## Error Code Reference

### Configuration Errors

These are resolved by fixing your `.env` or published `config/ai-chatbox.php`.

| Code | Meaning | How to fix |
|------|---------|------------|
| `E01` | The active provider's `api_url` is missing or empty | Set the URL env var for your active provider — e.g. `OLLAMA_URL`, `OPENAI_URL`, `GROQ_URL` |
| `E02` | The active provider's `api_url` is malformed — no host could be parsed | Check the URL format, e.g. `http://localhost:11434/v1/chat/completions` |
| `E03` | The active provider's `api_token` is missing or empty | Set the token env var for your active provider — e.g. `OLLAMA_TOKEN`, `OPENAI_API_KEY`, `GROQ_API_KEY` |
| `E04` | The active provider's `api_model` contains invalid characters | Model name must match `[a-zA-Z0-9_:.-]`, e.g. `gpt-oss:120b`, `llama3`, `gpt-4o` |

---

### Security Errors

| Code | Meaning | How to fix |
|------|---------|------------|
| `E05` | SSRF protection blocked the request — the configured URL resolves to a private or reserved IP | **Loopback (`127.0.0.1`, `localhost`, `::1`) is exempt** and always allowed, so a local Ollama/LM Studio on `localhost` works with SSRF protection **on**. `E05` now only fires for *other* private/reserved ranges — e.g. a WSL/LAN IP like `172.x` / `192.168.x`, or cloud-metadata `169.254.x`. If that host is intentional, set `AI_CHATBOX_SSRF_PROTECTION=false`. Do **not** disable in production for an external provider. |

> **Redirects are never followed.** For security (SSRF + auth-header replay), the package sends `allow_redirects=false` on every provider/embedding/health request. A provider URL that returns a `3xx` redirect will **not** be followed — point the URL at the final endpoint. A redirecting or wrong URL now typically surfaces as `E18` (unparseable reply) rather than `E10`.

---

### Network / Connectivity Errors

These indicate the AI service cannot be reached from the server running Laravel.

| Code | Meaning | How to fix |
|------|---------|------------|
| `E06` | DNS resolution failed — hostname not found | Check that the hostname in your provider's URL env var is correct and resolvable from the server. Run `nslookup <host>` or `dig <host>` to verify. |
| `E07` | Connection refused — the host is reachable but nothing is listening on that port | The AI service is not running. Start Ollama (`ollama serve`) or your AI provider. Check the port number in the URL. |
| `E08` | Connection timed out — the host did not respond within the timeout window | The service may be overloaded or a firewall is silently dropping packets. Try increasing `AI_CHATBOX_TIMEOUT`. Check firewall rules between your server and the AI host. |
| `E09` | SSL/TLS error — certificate validation failed or handshake error | The AI service's SSL certificate is invalid, self-signed, or expired. Use a valid certificate, or configure your HTTP client to trust a self-signed one. |
| `E10` | Too many redirects | **Rare now** — the package disables redirect following, so this only appears from an unusual transport error. Point your provider's URL env var at the correct final endpoint (a redirecting URL is no longer followed; see the redirect note above). |
| `E11` | Generic connection error (unclassified) | Check `storage/logs/laravel.log` for the full exception message to diagnose further. |

---

### API / HTTP Errors

These occur when the AI service is reachable but returns an error HTTP status.

| Code | HTTP Status | Meaning | How to fix |
|------|-------------|---------|------------|
| `E12` | 401 | Unauthorized — the token was rejected | Check the token env var for your active provider (e.g. `OPENAI_API_KEY`). Regenerate the API key from your provider's dashboard. |
| `E13` | 403 | Forbidden — the token does not have permission | Your token may be scoped incorrectly. Check API key permissions with your provider. |
| `E14` | 404 | Not Found — the endpoint URL is wrong | Verify your provider's URL env var. For Ollama the path should be `/v1/chat/completions` (OpenAI-compatible) or `/api/chat` (native). |
| `E15` | 429 | Too Many Requests — rate limited by the AI provider | You are sending too many requests. Reduce `AI_CHATBOX_RATE_LIMIT` or upgrade your API plan. Transient `429`s are retried automatically first (see *Automatic retries* below) — a persistent `E15` means the retries were exhausted. |
| `E16` | 408 / 500 / 502 / 503 / 504 / 529 | Retryable server-side / overload error from the AI service (includes Anthropic `529 overloaded_error` and `408` request-timeout) | The AI service is overloaded or erroring. These are **retried automatically** before surfacing; a persistent `E16` means the service is down — check its status page. For Ollama, check `journalctl -u ollama`. |
| `E17` | Other | Unexpected HTTP status (e.g. a `400 bad request`) | Check `storage/logs/laravel.log` — the log now includes the provider's **actual error body** under `provider_response` (e.g. `"temperature is not supported"`, `"model not found"`, `"max_tokens: required"`). That message tells you exactly what the provider rejected. |

---

### Response Errors

| Code | Meaning | How to fix |
|------|---------|------------|
| `E18` | The AI API returned an empty or unparseable reply | Common causes: (1) a **wrong or redirecting URL** — since redirects aren't followed, a `3xx`/HTML response parses to nothing (verify the URL points at the real completions endpoint); (2) the model genuinely returned an empty completion — try a different model. The Anthropic engine reads **all** text blocks, so a `thinking`-first reply is handled correctly; and `temperature` is omitted automatically for models that reject it (Opus 4.7+/Sonnet 5/Fable 5), so that no longer masks a real reply as `E18`. Check `provider_response` in the log for the raw payload. |
| `E19` | Unknown / unclassified error | An unexpected exception was thrown. Check `storage/logs/laravel.log` for the full stack trace. |

---

### Automatic retries

Transient provider failures — HTTP `408`/`429`/`500`/`502`/`503`/`504`/`529` and connection errors — are **retried automatically** before an error is surfaced. Retries honour a `Retry-After` response header when present, otherwise use exponential backoff. This means an occasional blip no longer fails the user's message, but it also means a request to a truly-down provider takes longer to fail (each attempt waits).

| Setting | Env var | Default | Meaning |
|---|---|---|---|
| `max_retries` | `AI_CHATBOX_MAX_RETRIES` | `2` | Retry attempts after the first try. Set `0` to disable retries entirely. |
| `retry_base_delay_ms` | `AI_CHATBOX_RETRY_BASE_DELAY_MS` | `500` | Base backoff in ms (doubles each attempt) when there's no `Retry-After`. |

Only the non-streaming completion path is retried; streaming connections are single-attempt.

---

### Orchestration Errors (AI Orchestrator)

These `O##` codes come from the **AI Orchestrator** (agentic tool calling) and only occur when `orchestrator_enabled=true` with tools allow-listed in `orchestrator_tools`. They are unrelated to the `E##` engine/HTTP codes above.

There are two categories:

- **`O02` is fatal** — it aborts the run and is returned to the client as a `500` JSON error with the `code` field (a generic message is shown to the user; the code is logged). *(`O01` is no longer thrown — see below.)*
- **`O03`–`O06` are recoverable** — they are **not** returned to the user or thrown. The failure is captured on the tool step and fed back to the model as a tool-error result so it can recover (retry, pick another tool, or explain). You will see these while inspecting an orchestration run rather than as an HTTP error (`O06` is also logged as a warning).

| Code | Meaning | How to fix |
|------|---------|------------|
| `O01` | **No longer thrown.** At `orchestrator_max_steps` the orchestrator now makes one final model call with **no tools offered**, forcing a text answer from the results gathered so far — so the user gets a best-effort reply instead of an error. | If replies feel cut off or the model clearly needed more tool turns, raise `AI_CHATBOX_ORCHESTRATOR_MAX_STEPS`, or improve tool descriptions so it converges sooner. A tool returning unhelpful/empty data still causes wasted steps — inspect the tool results. |
| `O02` | Orchestration timed out — the whole run exceeded `orchestrator_timeout` (wall-clock) | Raise `AI_CHATBOX_ORCHESTRATOR_TIMEOUT`, or speed up slow tools (`handle()` doing slow DB/HTTP work). Note each individual model call also has the provider `timeout` (and provider calls are retried on transient failures, which counts against the wall-clock budget). |
| `O03` | Unknown tool — the model called a tool name that is not registered/allow-listed | The model hallucinated a tool name, or a tool failed to load. Verify the class is in `orchestrator_tools` and check the log for "could not resolve tool class" / "does not implement ToolInterface" warnings. |
| `O04` | Not authorized — the tool's `authorize()` returned `false` (or threw) for this request | Expected when a tool is user/role-scoped and the current request isn't permitted. If unexpected, review the tool's `authorize(?Request $request)` logic (note it receives `null` in console/queue contexts). |
| `O05` | Missing required argument — the model's tool call omitted a key listed in the tool's `parameters().required` | Usually the model self-corrects on the next step. If it persists, clarify the argument in the tool's `description()` / parameter description so the model supplies it. |
| `O06` | Tool threw during `handle()` — the tool's own code raised an exception | Check `storage/logs/laravel.log` for "tool threw during handle()" with the tool name and the **real exception message** — that detail is only in the log. The model is fed a **generic** "tool failed to execute" message (the raw exception can contain SQL, table names, or credentials, which the model would otherwise echo to the user), so don't expect the specifics in the chat reply. |

> **Provider capability:** tool calling only works with a provider whose engine supports it (OpenAI-compatible or Anthropic). If the active provider's engine cannot do tool calling, or no tools are allow-listed, the orchestrator silently falls back to a single plain completion — no `O##` error, just non-agentic behaviour.

---

## HTTP status quick reference

This maps the **HTTP status of the package's own route** (what you see in DevTools → Network for `/message`, `/stream`, `/rag*`, `/admin*`) to its cause. This is distinct from the `E##` codes above, which describe the *upstream provider's* status.

| Status | Route(s) | Cause | Where to fix |
|---|---|---|---|
| **400** | `/message`, `/stream` | Unknown / unresolvable provider (`{"error":"Unknown provider."}`), or a malformed request | Check `AI_CHATBOX_ACTIVE_PROVIDER` and the `?provider=` param; see Diagnostics → Active Provider |
| **403** | `/admin*`, `/rag*` | Admin gate not configured for production, **or** a cross-origin request blocked by CORS (`Cross-origin request blocked.`) | [Admin 403](#admin-dashboard-or-knowledge-base-returns-403-in-production) / add the origin to `allowed_origins` |
| **419** | `/message`, `/stream`, `/clear`, `/rag*` | CSRF token missing/expired (`Page Expired`) | Ensure the CSRF cookie/meta is present; the widget sends `X-XSRF-TOKEN`. See [session expired](#the-widget-says-your-session-has-expired) |
| **422** | `/message`, `/rag*` | Validation failed — message > 2000 chars, bad `thread_id` (> 36 chars), or a RAG document with **no chunks** (`Document has no chunks`) | Fix the input; for RAG, re-check the source document/chunking |
| **429** | `/message`, `/stream` | The package's own **rate limiter** (not the provider's) throttled the client | Raise `AI_CHATBOX_RATE_LIMIT`, or back off |
| **500** | `/message`, `/stream` | Unhandled server exception, or a **fatal** orchestration error (`O02` timeout) | Read `storage/logs/laravel.log` for the trace / `O02` |
| **502** | `/rag/{id}/chat` | The RAG "chat with document" call to the provider failed (`AI call failed. Check your provider config in the dashboard.`) | Fix provider config (same as an `E##` on the main chat) |

> The **200-with-an-error-body** case: the main chat endpoints return HTTP **200** even when the provider is offline, carrying `{"status":"offline","code":"E##"}` in the JSON so the widget can render a friendly bubble. So a "200" in Network is **not** proof the AI answered — check the JSON body for a `code`.

---

## What the widget shows the user

End-users only ever see generic text. Use this table to translate the **bubble/toast the user reports** into where the real cause lives.

| What the user sees | What actually happened | Where to look |
|---|---|---|
| *"Network error. Please check your connection."* | The `fetch` to the package route never completed — server unreachable, CORS block, or a dropped connection | DevTools → Network (is the request red/failed?); [CORS 403](#admin-dashboard-or-knowledge-base-returns-403-in-production) |
| *"Your session has expired. Please refresh the page."* | **419** — CSRF token invalid/expired | [session expired](#the-widget-says-your-session-has-expired) |
| *"No response received. Please try again."* / *"No response."* | The request succeeded but the reply body was empty — usually an `E18`, or an offline provider (`status:offline`) | Log → `provider_response`; run the health check |
| *"Something went wrong. Please try again."* | A non-OK HTTP status (4xx/5xx) from the package route, or a stream error | DevTools → Network status → [HTTP status quick reference](#http-status-quick-reference); log |
| The friendly "AI service is currently unreachable" bubble | `status:offline` with an `E##` code (health check or request failed) | Match the `E##` in the [Error Code Reference](#error-code-reference) |

---

## Reading the Log

Every error is logged to Laravel's default log channel with its code:

```
[2025-01-01 12:00:00] production.WARNING: AI Chatbox health check failed {"code":"E07","message":"cURL error 7: Failed to connect to localhost port 11434"}
[2025-01-01 12:00:00] production.ERROR: AI Chatbox error {"code":"E12","status":401,"message":"Client error: 401 Unauthorized"}
```

**The real provider error is logged.** Because the client-facing message is deliberately generic ("Unable to reach AI service"), the provider's actual response body is written to the log at `warning` level so you can diagnose the cause — look for `AI Chatbox: provider request failed` with a `provider_response` field:

```
[2025-01-01 12:00:00] production.WARNING: AI Chatbox: provider request failed {"engine":"...AnthropicEngine","model":"claude-sonnet-5","status":400,"error_code":"E17","provider_response":"{\"type\":\"error\",\"error\":{\"message\":\"temperature: unsupported parameter\"}}"}
```

This is the fastest way to diagnose an `E17`/`E12`–`E16`: the `provider_response` names exactly what the provider rejected. (Only the response *body* is logged — never the request's `Authorization`/`x-api-key` header, so no token is written to the log.)

To tail the log in real time:

```bash
tail -f storage/logs/laravel.log | grep "AI Chatbox"
```

### Log message index

Every line the package writes is prefixed `AI Chatbox`. Search for the exact phrase to jump to the cause. Levels: 🔴 `error` · 🟠 `warning` · 🔵 `info`.

| Log message (grep this) | Level | What it means / where to fix |
|---|---|---|
| `AI Chatbox: provider request failed` | 🟠 | A chat completion failed. The `provider_response` field holds the **provider's real error body** — the single most useful line for `E12`–`E18`. |
| `AI Chatbox error` | 🔴 | A handled `AiServiceException` reached the controller — has `code` + `status`. |
| `AI Chatbox orchestration error` | 🔴 | Fatal orchestration failure (`O02`) — the run was aborted. |
| `AI Chatbox stream error` | 🔴 | The SSE `/stream` request failed mid-stream (has `code` if it was an `AiServiceException`). |
| `AI Chatbox: failed to save AI reply` | 🔴 | The reply was generated but couldn't be persisted (DB driver) — check the `memory` DB connection/migrations. |
| `AI Chatbox health check failed` | 🟠 | `/health` (or the admin "Test") couldn't reach the provider — has the `E##` code. |
| `AI Chatbox: APP_DEBUG is enabled while a real API token is configured` | 🟠 | Security: turn off `APP_DEBUG` in production (would leak the token in stack traces). |
| `AI Chatbox orchestrator: tool threw during handle().` | 🟠 | A tool's own code threw (`O06`) — the **real exception** is here (the model only got a generic message). Has the `tool` name. |
| `AI Chatbox orchestrator: could not resolve tool class.` | 🟠 | A class in `orchestrator_tools` doesn't exist / can't be built — fix the FQCN. |
| `AI Chatbox orchestrator: tool class does not implement ToolInterface — skipped.` | 🟠 | An allow-listed class isn't a valid tool — it's ignored. |
| `AI Chatbox orchestrator: tool authorize() threw — treating as denied.` | 🟠 | A tool's `authorize()` raised (treated as `O04` denied) — review that method. |
| `AI Chatbox orchestrator: knowledge_base_search failed.` | 🟠 | The built-in KB-search tool errored (RAG query failed under the orchestrator). |
| `AI Chatbox RAG retrieval failed` | 🟠 | RAG lookup threw during prompt building — the message is sent **without** grounding rather than failing. |
| `AI Chatbox RAG: embedding unavailable — falling back to keyword search.` | 🔵 | No embedding endpoint configured → RAG is using keyword search (works, but lower quality). See [silent fallbacks](#silent-fallbacks-no-error-but-not-what-you-expected). |
| `AI Chatbox RAG: Query embedding failed` | 🟠 | The query couldn't be embedded — no RAG context this message. Check `rag_embedding_url`/token. |
| `AI Chatbox RAG: rag_embedding_url is not configured.` | 🟠 | Embedding requested but no URL set. |
| `AI Chatbox RAG: Embedding API call failed.` / `Batch embedding API call failed` | 🔴/🟠 | The embedding provider itself errored — check its URL/token/model. |
| `AI Chatbox RAG: ... returned an unrecognised format.` | 🟠 | The embedding endpoint replied with an unexpected shape — wrong endpoint/model. |
| `AI Chatbox RAG: Chunk embedding dimension mismatch — skipping chunk.` | 🟠 | Stored chunks use a different embedding dimension than the current model — **reprocess the document** after changing the embedding model. |
| `AI Chatbox RAG: Skipped chunks with no stored embedding — reprocess the document to fix.` | 🟠 | Some chunks were stored without a vector — reprocess the document. |
| `AI Chatbox RAG: All chunk embeddings failed — document marked as failed.` | 🔴 | Ingestion failed for a whole document → it shows `failed` in the Knowledge Base. Fix embeddings, then reprocess. |
| `AI Chatbox RAG: document exceeded rag_max_chunks_per_document — truncating.` | 🟠 | The document was too large and was cut off — raise the cap or split the source. |
| `AI Chatbox RAG: No chunks met the similarity threshold` | 🔵 | Retrieval found nothing similar enough — lower `rag_similarity_threshold`. |

---

## Common Scenarios

### Local Ollama not reachable

**Symptom:** `E07` (connection refused)

Start Ollama first (`ollama serve`), then configure:

```env
AI_CHATBOX_ACTIVE_PROVIDER=ollama
OLLAMA_URL=http://localhost:11434/v1/chat/completions
OLLAMA_TOKEN=your-ollama-token
OLLAMA_MODEL=gpt-oss:120b
# No AI_CHATBOX_SSRF_PROTECTION change needed — loopback (localhost / 127.0.0.1)
# is exempt from the SSRF guard, so the default stack works out of the box.
```

---

### Ollama running in WSL, accessed from Windows

**Symptom:** `E06` (DNS failure) or `E07` (connection refused)

```bash
# Get your WSL IP (run inside WSL)
ip addr show eth0 | grep 'inet '
```

```env
AI_CHATBOX_ACTIVE_PROVIDER=ollama
OLLAMA_URL=http://172.x.x.x:11434/v1/chat/completions
AI_CHATBOX_SSRF_PROTECTION=false   # still required — a WSL/LAN IP (172.x) is a private range, NOT loopback
```

> Only **loopback** (`127.0.0.1` / `localhost` / `::1`) is exempt from the SSRF guard. A WSL, Docker, or LAN IP (`172.x`, `192.168.x`, `10.x`) still trips `E05` unless you set `AI_CHATBOX_SSRF_PROTECTION=false`.

---

### OpenAI / cloud provider — invalid token

**Symptom:** `E12` (401 Unauthorized)

```env
AI_CHATBOX_ACTIVE_PROVIDER=openai
OPENAI_API_KEY=sk-...   # paste your key, no quotes
```

---

### Request times out on large models

**Symptom:** `E08` (timeout)

```env
AI_CHATBOX_TIMEOUT=120   # increase to 120 seconds
```

---

### Wrong endpoint URL for provider

**Symptom:** `E14` (404 Not Found)

| Provider | Correct URL env var | Correct value |
|---|---|---|
| Ollama (OpenAI-compatible) | `OLLAMA_URL` | `http://localhost:11434/v1/chat/completions` |
| Ollama (native) | `OLLAMA_URL` | `http://localhost:11434/api/chat` |
| Ollama cloud | `OLLAMA_URL` | `https://ollama.com/api/chat` |
| LM Studio | `LMSTUDIO_URL` | `http://localhost:1234/v1/chat/completions` |
| OpenAI | `OPENAI_URL` | `https://api.openai.com/v1/chat/completions` |
| Groq | `GROQ_URL` | `https://api.groq.com/openai/v1/chat/completions` |
| OpenRouter | *(custom provider)* | `https://openrouter.ai/api/v1/chat/completions` |

---

### LM Studio not connecting

**Symptom:** `E07` (connection refused)

```env
AI_CHATBOX_ACTIVE_PROVIDER=lmstudio
LMSTUDIO_URL=http://localhost:1234/v1/chat/completions
LMSTUDIO_TOKEN=lmstudio
LMSTUDIO_MODEL=your-loaded-model-name   # must match exactly what LM Studio shows
# localhost is loopback → exempt from the SSRF guard, no SSRF change needed.
```

Make sure the **Local Server** is started inside LM Studio and a model is loaded before sending requests.

---

### AI Orchestrator enabled but tools never fire

**Symptom:** `orchestrator_enabled=true` but the model never calls your tool — it just answers normally, with no `O##` error.

The orchestrator silently falls back to a single plain completion (non-agentic) when any of these is true. Check each:

```env
AI_CHATBOX_ORCHESTRATOR=true
```

- **No tools allow-listed.** `orchestrator_tools` must contain your tool's class name — an empty list means no tools (the safe default). Add it in `config/ai-chatbox.php`:
  ```php
  'orchestrator_tools' => [
      \App\AiTools\GetOrderStatusTool::class,
  ],
  ```
- **Provider can't do tool calling.** Only OpenAI-compatible and Anthropic engines support it. Point `AI_CHATBOX_ACTIVE_PROVIDER` at a capable provider (e.g. `openai`, `groq`, `anthropic`). Some local/OpenAI-compatible endpoints don't implement `tools`.
- **`authorize()` returns false.** A tool is hidden from a request when its `authorize(?Request)` returns `false` — it won't even be offered to the model. Verify the current request satisfies it.
- **The model chose not to.** Even when offered, the model decides when a tool is relevant. Improve the tool's `description()` and parameter descriptions so it's clear when to use it.

Scaffold a tool quickly with `php artisan ai-chatbox:make-tool --model=YourModel`, then add the printed class name to `orchestrator_tools`.

---

### Admin dashboard or Knowledge Base returns 403 (in production)

**Symptom:** `/ai-chatbox/admin`, `/ai-chatbox/admin/conversations`, or `/ai-chatbox/rag` return **403** in a non-`local` environment, with a message about configuring `admin_middleware` / `rag_admin_middleware`.

This is **intentional, fail-closed** behaviour. The shipped default gate is `['web', 'auth']`, which only means "logged in" — **not** "is an admin". To avoid exposing every user's transcripts and the knowledge base to any registered account, the package refuses these routes outside `local`/`testing` until you configure a real gate:

```php
// config/ai-chatbox.php
'rag_admin_middleware' => ['web', 'auth', 'role:admin'],   // Spatie
// or 'can:manage-ai-chatbox' (a Laravel Gate you define), or your own middleware
'admin_middleware'     => null,   // null = inherit rag_admin_middleware
```

Any customisation of the gate disables the tripwire. In `local`/`testing` the routes stay open for zero-config development, so you'll only hit the 403 after deploying.

---

### RAG / Knowledge Base issues

**Document stuck on `processing`.** Ingestion runs synchronously in the upload request. If the PHP process was killed mid-run (deploy, OOM, fatal), the row can be left at `processing` — but its **previous** chunks are still intact (ingestion is atomic: new chunks are embedded first, then swapped in one transaction). Re-run **Reprocess** on the document to finish it.

**Document marked `failed`.** Every embedding call failed. Check the log for `All chunk embeddings failed`, then verify `rag_embedding_url` / `rag_embedding_model` and that the embedding service is reachable. The document's `error_message` (shown in the admin) has the details.

**Some chunks have no vector / retrieval is weak.** Individual chunk embeddings failed but not all — those chunks are stored without a vector and skipped during vector search (the `error_message` reads "*N of M chunks failed to embed*"). Fix the embedding service and Reprocess.

**Embeddings fail only when using a separate embedding host.** The chat provider's `api_token` is **no longer** reused for a *different* embedding host (that would leak the key). If `rag_embedding_url` points at a different host than the chat `api_url` and needs auth, set an explicit `rag_embedding_token` (e.g. `OPENAI_EMBEDDING_TOKEN`) — otherwise the embedding request is sent with no auth header and the provider rejects it.

**Large document only partially indexed.** A single document is capped at `rag_max_chunks_per_document` (default **5000**; `AI_CHATBOX_RAG_MAX_CHUNKS`, `0` = no cap). Beyond the cap, extra chunks are dropped and the `error_message` notes "*Only the first N chunks were indexed*". Raise the cap or split the document.

**Upload/reprocess hangs or ties up a worker.** Ingestion is synchronous with a `rag_processing_timeout` — now defaulting to **300s** (was previously unlimited). For very slow local embedding models set `AI_CHATBOX_RAG_PROCESSING_TIMEOUT` higher, or `0` for no limit. Embeddings are batched (`rag_embedding_batch_size`, default 32) to cut round-trips.

**Non-ASCII documents (CJK, accents, emoji) failed to ingest.** Fixed — chunking is now multibyte-safe. If an older upload failed with a DB "Incorrect string value" error, just re-upload it.

**`ai-chatbox:graphify` re-embeds everything every run.** Fixed — the importer now stores a content hash and **skips unchanged files**, re-ingesting only changed/new ones (and removing docs whose source file is gone). Output reports "*N indexed, M unchanged*".

---

### Chat history disappears when the tab is closed or on refresh

**Symptom:** the conversation resets when the browser tab is closed (or, depending on browser, on reload).

The browser storage default is now **`session`** (`sessionStorage`, cleared on tab close) rather than `local`, so a transcript isn't left behind on a shared machine. To persist chat history across browser sessions again:

```env
AI_CHATBOX_STORAGE=local
```

(This is separate from server-side conversation memory, which is controlled by `AI_CHATBOX_MEMORY_DRIVER=session|database`.)

---

### After upgrading the package

Two steps are easy to forget after `composer update`:

1. **Run migrations.** New releases may add columns (e.g. `content_hash` on RAG documents, and `content` widened to `longText`). Run:
   ```bash
   php artisan migrate
   ```
   On an existing MySQL install where `content` is still `TEXT`, large documents were truncated at 64 KB before this change; after migrating, re-upload any affected document.
2. **Re-publish assets.** The widget CSS/JS are versioned and cache-busted by the installed package version, but you must copy the new build into `public/`:
   ```bash
   php artisan vendor:publish --tag=ai-chatbox-assets --force
   php artisan config:clear
   ```
   If the chat widget looks stale or behaves like the old version after an upgrade, this is why.

---

### Stale styling / old widget behaviour after an upgrade

**Symptom:** the widget shows old styling or an old bug after upgrading.

The `?v=` cache-buster on the published assets is derived from the installed package version, so browsers pick up new files per release — but only if you re-published the assets (see *After upgrading the package* above). Force-refresh the browser (Ctrl/Cmd-Shift-R) once after re-publishing.

---

### Widget does not appear at all

**Symptom:** no chat bubble renders on the page — not an error bubble, *nothing*.

This is a front-end / wiring issue, not a provider issue, so the `E##` codes don't apply. Check in order:

1. **Is the directive/component on the page?** The widget only renders where you place it — `@aichatbox` (Blade), `<ai-chatbox>` (Vue mount point), or `<livewire:ai-chatbox />`, depending on your `frontend` driver. View the page **source** and confirm the markup is present.
2. **Does the `frontend` driver match how you embedded it?** `AI_CHATBOX_FRONTEND` must be `vue`, `blade`, or `livewire` and match the tag you used. A mismatch (e.g. `frontend=blade` but you placed the Vue mount point) renders nothing. The Diagnostics panel flags an invalid driver.
3. **Were the assets published?** `php artisan vendor:publish --tag=ai-chatbox-assets --force`. If `public/vendor/ai-chatbox/...` is missing, the CSS/JS 404 (check DevTools → Network) and nothing mounts.
4. **JS errors in the Console?** Open DevTools → **Console**. A Vue mount error, a missing CSRF meta tag, or a CSP that blocks the inline/bundled script will abort rendering. For Vue, ensure the bundle is loaded on the page.
5. **`enabled` / route prefix.** If `AI_CHATBOX_ENABLED=false` the widget and routes are off. Confirm it's `true`.

---

### The widget says "Your session has expired"

**Symptom:** the bubble shows *"Your session has expired. Please refresh the page."* — the request returned **419**.

This is Laravel CSRF, not the AI. The package sends the `X-XSRF-TOKEN` header from the `XSRF-TOKEN` cookie. It breaks when:

- The `XSRF-TOKEN` cookie is missing — the page must be served through Laravel's `web` middleware (which sets it), and the widget's route prefix must be on the same domain.
- The session expired (the user left the tab open past the session lifetime) — refreshing fixes it.
- A reverse proxy/CDN is stripping cookies or the `X-XSRF-TOKEN` header — allow them through.
- For SPA/cross-subdomain setups, configure `SANCTUM_STATEFUL_DOMAINS` / `session.domain` so the cookie is readable by the front-end origin.

---

### Silent fallbacks (no error, but not what you expected)

Some features **degrade quietly** by design — you get an answer, but not the behaviour you configured. These produce **no error code**; the only signal is a log line (see the [Log message index](#log-message-index)).

| You configured… | …but it silently does this | Tell-tale log line / signal |
|---|---|---|
| RAG with no embedding endpoint | Uses **keyword** search instead of vector similarity | `embedding unavailable — falling back to keyword search` |
| RAG, but nothing scores high enough | Injects **no** grounding for that message (answers from base knowledge) | `No chunks met the similarity threshold` — lower `rag_similarity_threshold` |
| Orchestrator ON, but no capable provider / no tools / model declined | Falls back to a **single plain completion** (non-agentic) | No `O##` code; see [orchestrator tools never fire](#ai-orchestrator-enabled-but-tools-never-fire) |
| Orchestrator hit `orchestrator_max_steps` | Makes one final **tool-less** call for a best-effort answer | No `O01` any more; raise `orchestrator_max_steps` if replies feel cut off |
| Separate embedding host needing auth, no `rag_embedding_token` | Sends the embedding request **without** an auth header (then the provider rejects it) | Embedding API call failed |

> If a feature "seems off but throws nothing," this table is where to look — then confirm with the matching log line.

---

### Console command errors

The package's Artisan commands print their own diagnostics to stdout.

**`php artisan ai-chatbox:graphify`** (import `graphify-out/` markdown into the knowledge base):

- `graphify-out directory not found` — run graphify first, or pass the correct `--dir`.
- `No markdown files found under …` — the directory has no `.md` files.
- `skipped (empty/unreadable): …` — that file was empty or unreadable (warning, continues).
- `failed: … — <error>` — that file threw during ingestion (embedding/DB error).
- `RAG is currently disabled — set AI_CHATBOX_RAG=true` — documents were imported but won't be used until RAG is enabled.

**`php artisan ai-chatbox:make-tool`** (scaffold an orchestrator tool):

- `Provide a tool class name or --model=` — pass one, e.g. `--model=Order`.
- `Model [X] could not be resolved — generating a blank tool instead` — the model class wasn't found; edit the generated stub by hand.
- `Could not read the schema for [X]` / `Table for [X] has no columns` — the model's table isn't migrated/reachable; a blank tool is generated.
- `Skipping invalid column identifier: '…'` — a column name wasn't a safe identifier and was left out.

**`php artisan ai-chatbox:prune-conversations`** (delete old DB-driver conversations):

- `Days must be 1 or greater.` — fix the `--days` value.
- `memory_driver is set to '…', not 'database'.` — pruning only applies to the database memory driver; pass `--force` to run anyway.
- `Table 'ai_chatbox_conversations' does not exist.` — run `php artisan migrate`.
- `Table 'ai_chatbox_messages' does not exist … Run 'php artisan migrate'` — same fix; message cascade is skipped until then.

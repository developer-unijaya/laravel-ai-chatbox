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
| `E05` | SSRF protection blocked the request — the configured URL resolves to a private or reserved IP | If this is intentional (local Ollama, LM Studio), set `AI_CHATBOX_SSRF_PROTECTION=false` in `.env`. Do **not** disable in production. |

---

### Network / Connectivity Errors

These indicate the AI service cannot be reached from the server running Laravel.

| Code | Meaning | How to fix |
|------|---------|------------|
| `E06` | DNS resolution failed — hostname not found | Check that the hostname in your provider's URL env var is correct and resolvable from the server. Run `nslookup <host>` or `dig <host>` to verify. |
| `E07` | Connection refused — the host is reachable but nothing is listening on that port | The AI service is not running. Start Ollama (`ollama serve`) or your AI provider. Check the port number in the URL. |
| `E08` | Connection timed out — the host did not respond within the timeout window | The service may be overloaded or a firewall is silently dropping packets. Try increasing `AI_CHATBOX_TIMEOUT`. Check firewall rules between your server and the AI host. |
| `E09` | SSL/TLS error — certificate validation failed or handshake error | The AI service's SSL certificate is invalid, self-signed, or expired. Use a valid certificate, or configure your HTTP client to trust a self-signed one. |
| `E10` | Too many redirects | The endpoint URL is redirecting in a loop. Verify your provider's URL env var points to the correct final endpoint. |
| `E11` | Generic connection error (unclassified) | Check `storage/logs/laravel.log` for the full exception message to diagnose further. |

---

### API / HTTP Errors

These occur when the AI service is reachable but returns an error HTTP status.

| Code | HTTP Status | Meaning | How to fix |
|------|-------------|---------|------------|
| `E12` | 401 | Unauthorized — the token was rejected | Check the token env var for your active provider (e.g. `OPENAI_API_KEY`). Regenerate the API key from your provider's dashboard. |
| `E13` | 403 | Forbidden — the token does not have permission | Your token may be scoped incorrectly. Check API key permissions with your provider. |
| `E14` | 404 | Not Found — the endpoint URL is wrong | Verify your provider's URL env var. For Ollama the path should be `/v1/chat/completions` (OpenAI-compatible) or `/api/chat` (native). |
| `E15` | 429 | Too Many Requests — rate limited by the AI provider | You are sending too many requests. Reduce `AI_CHATBOX_RATE_LIMIT` or upgrade your API plan. |
| `E16` | 500 / 502 / 503 / 504 | Server-side error from the AI service | The AI service itself is experiencing issues. Check the service's status page or logs. For Ollama, check `journalctl -u ollama`. |
| `E17` | Other | Unexpected HTTP status | Check `storage/logs/laravel.log` for the full response details. |

---

### Response Errors

| Code | Meaning | How to fix |
|------|---------|------------|
| `E18` | The AI API returned an empty or unparseable reply | The model may have returned an empty completion. Try a different model (update your provider's model env var) or adjust `AI_CHATBOX_TEMPERATURE`. Verify the API response format matches OpenAI-compatible or Ollama native format. |
| `E19` | Unknown / unclassified error | An unexpected exception was thrown. Check `storage/logs/laravel.log` for the full stack trace. |

---

### Orchestration Errors (AI Orchestrator)

These `O##` codes come from the **AI Orchestrator** (agentic tool calling) and only occur when `orchestrator_enabled=true` with tools allow-listed in `orchestrator_tools`. They are unrelated to the `E##` engine/HTTP codes above.

There are two categories:

- **`O01` / `O02` are fatal** — they abort the run and are returned to the client as a `500` JSON error with the `code` field (a generic message is shown to the user; the code is logged).
- **`O03`–`O06` are recoverable** — they are **not** returned to the user or thrown. The failure is captured on the tool step and fed back to the model as a tool-error result so it can recover (retry, pick another tool, or explain). You will see these while inspecting an orchestration run rather than as an HTTP error (`O06` is also logged as a warning).

| Code | Meaning | How to fix |
|------|---------|------------|
| `O01` | Maximum orchestration steps reached without a final answer — the model kept requesting tools past `orchestrator_max_steps` | The model may be stuck in a tool loop. Raise `AI_CHATBOX_ORCHESTRATOR_MAX_STEPS` if the task legitimately needs more steps, or improve the tool descriptions so the model converges. Check the tool results being returned — a tool returning unhelpful/empty data can cause the model to keep retrying. |
| `O02` | Orchestration timed out — the whole run exceeded `orchestrator_timeout` (wall-clock) | Raise `AI_CHATBOX_ORCHESTRATOR_TIMEOUT`, or speed up slow tools (`handle()` doing slow DB/HTTP work). Note each individual model call also has the provider `timeout`. |
| `O03` | Unknown tool — the model called a tool name that is not registered/allow-listed | The model hallucinated a tool name, or a tool failed to load. Verify the class is in `orchestrator_tools` and check the log for "could not resolve tool class" / "does not implement ToolInterface" warnings. |
| `O04` | Not authorized — the tool's `authorize()` returned `false` (or threw) for this request | Expected when a tool is user/role-scoped and the current request isn't permitted. If unexpected, review the tool's `authorize(?Request $request)` logic (note it receives `null` in console/queue contexts). |
| `O05` | Missing required argument — the model's tool call omitted a key listed in the tool's `parameters().required` | Usually the model self-corrects on the next step. If it persists, clarify the argument in the tool's `description()` / parameter description so the model supplies it. |
| `O06` | Tool threw during `handle()` — the tool's own code raised an exception | Check `storage/logs/laravel.log` for "tool threw during handle()" with the tool name and exception message, then fix the tool. The exception message is also fed back to the model. |

> **Provider capability:** tool calling only works with a provider whose engine supports it (OpenAI-compatible or Anthropic). If the active provider's engine cannot do tool calling, or no tools are allow-listed, the orchestrator silently falls back to a single plain completion — no `O##` error, just non-agentic behaviour.

---

## Reading the Log

Every error is logged to Laravel's default log channel with its code:

```
[2025-01-01 12:00:00] production.WARNING: AI Chatbox health check failed {"code":"E07","message":"cURL error 7: Failed to connect to localhost port 11434"}
[2025-01-01 12:00:00] production.ERROR: AI Chatbox error {"code":"E12","status":401,"message":"Client error: 401 Unauthorized"}
```

To tail the log in real time:

```bash
tail -f storage/logs/laravel.log | grep "AI Chatbox"
```

---

## Common Scenarios

### Local Ollama not reachable

**Symptom:** `E07` (connection refused) or `E05` (SSRF blocked)

Start Ollama first (`ollama serve`), then configure:

```env
AI_CHATBOX_ACTIVE_PROVIDER=ollama
OLLAMA_URL=http://localhost:11434/v1/chat/completions
OLLAMA_TOKEN=your-ollama-token
OLLAMA_MODEL=gpt-oss:120b
AI_CHATBOX_SSRF_PROTECTION=false   # required — localhost is a private IP
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
AI_CHATBOX_SSRF_PROTECTION=false   # WSL IP is in a private range
```

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

**Symptom:** `E07` (connection refused) or `E05` (SSRF blocked)

```env
AI_CHATBOX_ACTIVE_PROVIDER=lmstudio
LMSTUDIO_URL=http://localhost:1234/v1/chat/completions
LMSTUDIO_TOKEN=lmstudio
LMSTUDIO_MODEL=your-loaded-model-name   # must match exactly what LM Studio shows
AI_CHATBOX_SSRF_PROTECTION=false        # required — localhost is a private IP
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

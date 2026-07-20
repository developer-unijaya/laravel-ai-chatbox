<?php
namespace DeveloperUnijaya\AiChatbox\Engine;

use DeveloperUnijaya\AiChatbox\Engine\Contracts\AiEngineInterface;
use DeveloperUnijaya\AiChatbox\Engine\Contracts\SupportsToolCalling;
use DeveloperUnijaya\AiChatbox\Engine\EngineResult;
use DeveloperUnijaya\AiChatbox\Engine\Exceptions\AiEngineException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\StreamInterface;

class OpenAiCompatibleEngine implements AiEngineInterface, SupportsToolCalling
{
    // ── Error codes ───────────────────────────────────────────────────────────

    public const E01 = 'E01'; // api_url is missing or empty
    public const E03 = 'E03'; // api_token is missing or empty
    public const E04 = 'E04'; // api_model contains invalid characters

    public const E06 = 'E06'; // DNS resolution failed
    public const E07 = 'E07'; // Connection refused
    public const E08 = 'E08'; // Connection timed out
    public const E09 = 'E09'; // SSL/TLS error
    public const E10 = 'E10'; // Too many redirects
    public const E11 = 'E11'; // Generic connection error

    public const E12 = 'E12'; // HTTP 401 Unauthorized
    public const E13 = 'E13'; // HTTP 403 Forbidden
    public const E14 = 'E14'; // HTTP 404 Not Found
    public const E15 = 'E15'; // HTTP 429 Rate limited
    public const E16 = 'E16'; // HTTP 5xx server error
    public const E17 = 'E17'; // Unexpected HTTP status

    public const E18 = 'E18'; // Empty or unparseable response
    public const E19 = 'E19'; // Unknown / unclassified error

    // ── AiEngineInterface ─────────────────────────────────────────────────────

    public function validateConfig(array $options): void
    {
        $this->assertConfig(
            $options['api_url'] ?? '',
            $options['api_token'] ?? '',
            $options['api_model'] ?? ''
        );
    }

    public function complete(array $messages, array $options = []): string
    {
        $apiUrl = $options['api_url'] ?? '';
        $apiToken = $options['api_token'] ?? '';
        $model = $options['api_model'] ?? '';
        $timeout = $options['timeout'] ?? 30;
        $temp = (float) ($options['temperature'] ?? 0.7);
        $maxTokens = $options['max_tokens'] ?? null;

        $this->assertConfig($apiUrl, $apiToken, $model);

        try {
            $client = $this->makeClient(['timeout' => $timeout]);

            $response = $client->post($apiUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiToken,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => array_filter([
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => $temp,
                    'max_tokens' => $maxTokens,
                    'stream' => false,
                ], fn($v) => $v !== null),
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $reply = $data['choices'][0]['message']['content'] ?? $data['message']['content'] ?? null;

            if ($reply === null || trim($reply) === '') {
                throw new AiEngineException(self::E18, 'Unable to reach AI service. Please try again later.', 502);
            }

            return trim($reply);

        } catch (AiEngineException $e) {
            throw $e;
        } catch (TooManyRedirectsException $e) {
            throw new AiEngineException(self::E10, 'Unable to reach AI service. Please try again later.', 502, $e);
        } catch (ConnectException $e) {
            throw new AiEngineException($this->classifyConnectException($e), 'Unable to reach AI service. Please try again later.', 503, $e);
        } catch (RequestException $e) {
            throw $this->httpException($e, $model);
        } catch (\Throwable $e) {
            throw new AiEngineException(self::E19, 'Unable to reach AI service. Please try again later.', 500, $e);
        }
    }

    public function stream(array $messages, array $options, callable $onToken): string
    {
        return ($this->beginStream($messages, $options))($onToken);
    }

    public function beginStream(array $messages, array $options): \Closure
    {
        $apiUrl = $options['api_url'] ?? '';
        $apiToken = $options['api_token'] ?? '';
        $model = $options['api_model'] ?? '';
        $timeout = $options['timeout'] ?? 30;
        $temp = (float) ($options['temperature'] ?? 0.7);
        $maxTokens = $options['max_tokens'] ?? null;

        $this->assertConfig($apiUrl, $apiToken, $model);

        // Establish the connection here so errors can be caught before streaming starts.
        // Any ConnectException / RequestException thrown here will propagate to the caller
        // (the controller) BEFORE response()->stream() is invoked.
        try {
            $client = $this->makeClient(['timeout' => $timeout]);

            $response = $client->post($apiUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiToken,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => array_filter([
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => $temp,
                    'max_tokens' => $maxTokens,
                    'stream' => true,
                ], fn($v) => $v !== null),
                'stream' => true,
            ]);

        } catch (TooManyRedirectsException $e) {
            throw new AiEngineException(self::E10, 'Unable to reach AI service. Please try again later.', 502, $e);
        } catch (ConnectException $e) {
            throw new AiEngineException($this->classifyConnectException($e), 'Unable to reach AI service. Please try again later.', 503, $e);
        } catch (RequestException $e) {
            throw $this->httpException($e, $model);
        } catch (\Throwable $e) {
            throw new AiEngineException(self::E19, 'Unable to reach AI service. Please try again later.', 500, $e);
        }

        $body = $response->getBody();

        // Return a reader closure to be called inside response()->stream().
        // Per-line parsing is OpenAI-compatible (choices[].delta.content) with
        // an Ollama-native fallback (message.content / done:true); the robust
        // read loop itself is shared with AnthropicEngine via readEventStream().
        return function (callable $onToken) use ($body): string {
            return $this->readEventStream($body, $onToken, function (array $data): array {
                return [
                    'token' => $data['choices'][0]['delta']['content'] ?? $data['message']['content'] ?? null,
                    // Ollama native signals end with done: true.
                    'stop' => ($data['done'] ?? false) === true,
                ];
            });
        };
    }

    /**
     * Read a streaming SSE / NDJSON response body, dispatching each parsed token
     * to $onToken and returning the full concatenated reply.
     *
     * Hardened against three failure modes the naive read loop ignored:
     *   1. Socket read timeout / stall — Guzzle's StreamHandler returns '' from
     *      read() while eof() stays false, which would busy-spin at 100% CPU
     *      forever. read() on a blocking body only returns '' at EOF or on
     *      timeout, so we stop instead of re-looping on empty reads.
     *   2. Client disconnect — once the browser closes the SSE connection we
     *      stop draining the upstream, which would otherwise keep burning
     *      provider tokens (and hold a worker) for the full response.
     *   3. Trailing line — a final line not terminated by "\n" (legal for a
     *      truncated stream or Ollama NDJSON) is processed after the loop
     *      instead of being silently dropped with its last token(s).
     *
     * @param  callable(array): array{token?: ?string, stop?: bool}  $parse
     *         Given the decoded JSON of one data line, returns the token text to
     *         emit (if any) and whether the stream should stop.
     */
    protected function readEventStream(StreamInterface $stream, callable $onToken, callable $parse): string
    {
        $fullReply = '';
        $buffer = '';
        $stop = false;

        while (!$stop && !$stream->eof()) {
            if ($this->clientDisconnected()) {
                break;
            }

            $chunk = $stream->read(1024);

            // A blocking body returns '' only at EOF or on a read timeout/stall —
            // in every case there is no more usable data, so stop rather than
            // spin on !eof().
            if ($chunk === '') {
                break;
            }

            $buffer .= $chunk;

            while (($nl = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $nl), "\r");
                $buffer = substr($buffer, $nl + 1);

                if ($this->consumeSseLine($line, $parse, $onToken, $fullReply)) {
                    $stop = true;
                    break;
                }
            }
        }

        // Flush a final line that had no trailing newline (truncated stream / NDJSON).
        if (!$stop && trim($buffer) !== '') {
            $this->consumeSseLine(rtrim($buffer, "\r"), $parse, $onToken, $fullReply);
        }

        return $fullReply;
    }

    /**
     * Parse one SSE/NDJSON line: skip comments/blank/event lines, honour the
     * [DONE] sentinel, decode JSON, emit any token, and report whether the
     * stream should stop. $fullReply is accumulated by reference.
     */
    private function consumeSseLine(string $line, callable $parse, callable $onToken, string &$fullReply): bool
    {
        if ($line === '' || str_starts_with($line, ':') || str_starts_with($line, 'event:')) {
            return false; // blank keep-alive, SSE comment, or event-type line
        }

        // Strip the SSE "data:" field prefix (the space after the colon is
        // optional per the SSE spec) or take the raw line (Ollama NDJSON).
        if (str_starts_with($line, 'data:')) {
            $payload = substr($line, 5);
            if (str_starts_with($payload, ' ')) {
                $payload = substr($payload, 1);
            }
        } else {
            $payload = $line;
        }

        // Terminal sentinel — tolerate "data: [DONE]" and "data:[DONE]".
        if (trim($payload) === '[DONE]') {
            return true;
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return false;
        }

        $result = $parse($data);

        $token = $result['token'] ?? null;
        if ($token !== null && $token !== '') {
            $fullReply .= $token;
            ($onToken)($token);
        }

        return (bool) ($result['stop'] ?? false);
    }

    /**
     * Whether the HTTP client (browser) has closed the connection. PHP only
     * flags this once it next tries to flush output, which the streaming
     * controller does after each token — so mid-stream disconnects are seen.
     */
    protected function clientDisconnected(): bool
    {
        return connection_aborted() === 1;
    }

    /**
     * Build the AiEngineException for a failed provider HTTP request, logging
     * the provider's error body first.
     *
     * The client-facing message stays generic (the raw provider text is never
     * returned to the browser), but the provider's response body — where the
     * real cause lives, e.g. "temperature is not supported", "max_tokens:
     * required", "model not found" — is logged server-side so operators can
     * actually diagnose the failure instead of only seeing "E17 / unreachable".
     * Only the response body is logged, never the request headers, so the API
     * token is not written to the log.
     */
    protected function httpException(RequestException $e, string $model = ''): AiEngineException
    {
        $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 500;

        $body = '';
        if ($e->hasResponse()) {
            $body = trim((string) $e->getResponse()->getBody());
            if (strlen($body) > 2000) {
                $body = substr($body, 0, 2000) . '…';
            }
        }

        Log::warning('AI Chatbox: provider request failed', [
            'engine' => static::class,
            'model' => $model,
            'status' => $status,
            'error_code' => $this->classifyHttpStatus($status),
            'provider_response' => $body,
        ]);

        return new AiEngineException(
            $this->classifyHttpStatus($status),
            'Unable to reach AI service. Please try again later.',
            $status,
            $e,
        );
    }

    // ── SupportsToolCalling ───────────────────────────────────────────────────

    public function completeWithTools(array $messages, array $tools, array $options = []): EngineResult
    {
        $apiUrl = $options['api_url'] ?? '';
        $apiToken = $options['api_token'] ?? '';
        $model = $options['api_model'] ?? '';
        $timeout = $options['timeout'] ?? 30;
        $temp = (float) ($options['temperature'] ?? 0.7);
        // Agentic turns need more room than the chat default (which may be as low as
        // 300) — a truncated tool_calls response yields incomplete argument JSON. Use
        // orchestrator_max_tokens when set, otherwise at least 1024.
        $maxTokens = (int) ($options['orchestrator_max_tokens'] ?? max((int) ($options['max_tokens'] ?? 0), 1024));

        $this->assertConfig($apiUrl, $apiToken, $model);

        try {
            $client = $this->makeClient(['timeout' => $timeout]);

            $payload = array_filter([
                'model' => $model,
                'messages' => $messages,
                'temperature' => $temp,
                'max_tokens' => $maxTokens,
                'stream' => false,
            ], fn($v) => $v !== null);

            // OpenAI-compatible tool schema: [{type:function, function:{name, description, parameters}}]
            if (!empty($tools)) {
                $payload['tools'] = array_map(fn($t) => [
                    'type' => 'function',
                    'function' => array_filter([
                        'name' => $t['name'],
                        'description' => $t['description'] ?? '',
                        'parameters' => $t['parameters'] ?? ['type' => 'object', 'properties' => (object) []],
                    ], fn($v) => $v !== null),
                ], $tools);
            }

            $response = $client->post($apiUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiToken,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $message = $data['choices'][0]['message'] ?? null;

            if (!is_array($message)) {
                throw new AiEngineException(self::E18, 'Unable to reach AI service. Please try again later.', 502);
            }

            $toolCalls = $message['tool_calls'] ?? [];
            if (!empty($toolCalls)) {
                return EngineResult::toolCalls($this->normalizeToolCalls($toolCalls), $message);
            }

            return EngineResult::text(trim((string) ($message['content'] ?? '')));

        } catch (AiEngineException $e) {
            throw $e;
        } catch (TooManyRedirectsException $e) {
            throw new AiEngineException(self::E10, 'Unable to reach AI service. Please try again later.', 502, $e);
        } catch (ConnectException $e) {
            throw new AiEngineException($this->classifyConnectException($e), 'Unable to reach AI service. Please try again later.', 503, $e);
        } catch (RequestException $e) {
            throw $this->httpException($e, $model);
        } catch (\Throwable $e) {
            throw new AiEngineException(self::E19, 'Unable to reach AI service. Please try again later.', 500, $e);
        }
    }

    public function toolResultMessages(EngineResult $result, array $toolResults): array
    {
        // Assistant turn carrying the tool_calls, then one role:tool message per result.
        $messages = [$result->rawAssistantMessage];

        foreach ($toolResults as $tr) {
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $tr['id'],
                'content' => $tr['content'],
            ];
        }

        return $messages;
    }

    /**
     * Normalize OpenAI-compatible tool_calls into [{id, name, arguments(array)}].
     * OpenAI returns function.arguments as a JSON *string*.
     *
     * @param  array<int, mixed>  $toolCalls
     * @return array<int, array{id: string, name: string, arguments: array}>
     */
    protected function normalizeToolCalls(array $toolCalls): array
    {
        $normalized = [];

        foreach ($toolCalls as $i => $call) {
            $rawArgs = $call['function']['arguments'] ?? '{}';
            $args = is_array($rawArgs) ? $rawArgs : (json_decode((string) $rawArgs, true) ?: []);

            $normalized[] = [
                'id' => $call['id'] ?? ('call_' . $i),
                'name' => $call['function']['name'] ?? '',
                'arguments' => is_array($args) ? $args : [],
            ];
        }

        return $normalized;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    protected function assertConfig(string $apiUrl, string $apiToken, string $model): void
    {
        if (empty($apiUrl)) {
            throw new AiEngineException(self::E01, 'AI API URL is not configured.', 500);
        }
        if (empty($apiToken)) {
            throw new AiEngineException(self::E03, 'AI API token is not configured.', 500);
        }
        if (!empty($model) && !preg_match('/^[a-zA-Z0-9_:.\-]+$/', $model)) {
            throw new AiEngineException(self::E04, 'Invalid model name configured.', 500);
        }
    }

    protected function makeClient(array $config): Client
    {
        return app('ai-chatbox.guzzle')($config);
    }

    protected function classifyConnectException(ConnectException $e): string
    {
        $msg = strtolower($e->getMessage());

        if (str_contains($msg, 'could not resolve host') || str_contains($msg, 'name or service not known')) {
            return self::E06;
        }
        if (str_contains($msg, 'connection refused')) {
            return self::E07;
        }
        if (str_contains($msg, 'timed out') || str_contains($msg, 'timeout')) {
            return self::E08;
        }
        if (str_contains($msg, 'ssl') || str_contains($msg, 'certificate') || str_contains($msg, 'tls')) {
            return self::E09;
        }

        return self::E11;
    }

    protected function classifyHttpStatus(int $status): string
    {
        return match ($status) {
            401 => self::E12,
            403 => self::E13,
            404 => self::E14,
            429 => self::E15,
            500, 502, 503, 504 => self::E16,
            default => self::E17,
        };
    }
}

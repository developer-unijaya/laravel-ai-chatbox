<?php
namespace DeveloperUnijaya\AiChatbox\Engine;

use DeveloperUnijaya\AiChatbox\Engine\Exceptions\AiEngineException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TooManyRedirectsException;

class AnthropicEngine extends OpenAiCompatibleEngine
{
    public function complete(array $messages, array $options = []): string
    {
        $apiUrl = $options['api_url'] ?? '';
        $apiToken = $options['api_token'] ?? '';
        $model = $options['api_model'] ?? '';
        $timeout = $options['timeout'] ?? 30;
        $temp = (float) ($options['temperature'] ?? 0.5);
        // Anthropic requires max_tokens to be a positive integer — null is not accepted.
        $maxTokens = ($options['max_tokens'] ?? null) !== null ? (int) $options['max_tokens'] : 300;

        $this->assertConfig($apiUrl, $apiToken, $model);

        [$system, $filtered] = $this->splitMessages($messages);

        try {
            $client = $this->makeClient(['timeout' => $timeout]);

            $payload = ['model' => $model, 'messages' => $filtered, 'max_tokens' => $maxTokens, 'stream' => false];
            if ($system !== '') {
                $payload['system'] = $system;
            }
            if ($this->includeTemperature($model, $options)) {
                $payload['temperature'] = $temp;
            }

            $response = $client->post($apiUrl, [
                'headers' => $this->anthropicHeaders($apiToken, $options),
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            // Concatenate every text block rather than reading content[0] — the
            // first block may be a `thinking` block (adaptive thinking is on by
            // default on newer models), which would otherwise be misread as an
            // empty response and reported as a false outage.
            $reply = $this->extractTextBlocks($data['content'] ?? null);

            if ($reply === '') {
                throw new AiEngineException(self::E18, 'Unable to reach AI service. Please try again later.', 502);
            }

            return $reply;

        } catch (AiEngineException $e) {
            throw $e;
        } catch (TooManyRedirectsException $e) {
            throw new AiEngineException(self::E10, 'Unable to reach AI service. Please try again later.', 502, $e);
        } catch (ConnectException $e) {
            throw new AiEngineException($this->classifyConnectException($e), 'Unable to reach AI service. Please try again later.', 503, $e);
        } catch (RequestException $e) {
            $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 500;
            throw new AiEngineException($this->classifyHttpStatus($status), 'Unable to reach AI service. Please try again later.', $status, $e);
        } catch (\Throwable $e) {
            throw new AiEngineException(self::E19, 'Unable to reach AI service. Please try again later.', 500, $e);
        }
    }

    public function beginStream(array $messages, array $options): \Closure
    {
        $apiUrl = $options['api_url'] ?? '';
        $apiToken = $options['api_token'] ?? '';
        $model = $options['api_model'] ?? '';
        $timeout = $options['timeout'] ?? 30;
        $temp = (float) ($options['temperature'] ?? 0.5);
        // Anthropic requires max_tokens to be a positive integer — null is not accepted.
        $maxTokens = ($options['max_tokens'] ?? null) !== null ? (int) $options['max_tokens'] : 300;

        $this->assertConfig($apiUrl, $apiToken, $model);

        [$system, $filtered] = $this->splitMessages($messages);

        try {
            $client = $this->makeClient(['timeout' => $timeout]);

            $payload = ['model' => $model, 'messages' => $filtered, 'max_tokens' => $maxTokens, 'stream' => true];
            if ($system !== '') {
                $payload['system'] = $system;
            }
            if ($this->includeTemperature($model, $options)) {
                $payload['temperature'] = $temp;
            }

            $response = $client->post($apiUrl, [
                'headers' => $this->anthropicHeaders($apiToken, $options),
                'json' => $payload,
                'stream' => true,
            ]);

        } catch (TooManyRedirectsException $e) {
            throw new AiEngineException(self::E10, 'Unable to reach AI service. Please try again later.', 502, $e);
        } catch (ConnectException $e) {
            throw new AiEngineException($this->classifyConnectException($e), 'Unable to reach AI service. Please try again later.', 503, $e);
        } catch (RequestException $e) {
            $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 500;
            throw new AiEngineException($this->classifyHttpStatus($status), 'Unable to reach AI service. Please try again later.', $status, $e);
        } catch (\Throwable $e) {
            throw new AiEngineException(self::E19, 'Unable to reach AI service. Please try again later.', 500, $e);
        }

        $stream = $response->getBody();

        // Per-line parsing follows the Anthropic Messages streaming shape
        // (content_block_delta.delta.text, message_stop); the robust read loop
        // is shared with OpenAiCompatibleEngine via readEventStream().
        return function (callable $onToken) use ($stream): string {
            return $this->readEventStream($stream, $onToken, function (array $data): array {
                $type = $data['type'] ?? '';

                if ($type === 'message_stop') {
                    return ['stop' => true];
                }

                // A mid-stream error event (e.g. overloaded_error) ends the
                // stream rather than being silently swallowed as a normal reply.
                if ($type === 'error') {
                    return ['stop' => true];
                }

                if ($type === 'content_block_delta') {
                    return ['token' => $data['delta']['text'] ?? null];
                }

                return [];
            });
        };
    }

    // ── SupportsToolCalling (Anthropic Messages API shape) ────────────────────

    public function completeWithTools(array $messages, array $tools, array $options = []): EngineResult
    {
        $apiUrl = $options['api_url'] ?? '';
        $apiToken = $options['api_token'] ?? '';
        $model = $options['api_model'] ?? '';
        $timeout = $options['timeout'] ?? 30;
        $temp = (float) ($options['temperature'] ?? 0.5);
        // Anthropic requires a positive integer. Agentic turns need more room than
        // the chat default (which may be as low as 300) — a truncated tool_use turn
        // yields incomplete argument JSON. Use orchestrator_max_tokens when set,
        // otherwise at least 1024.
        $maxTokens = (int) ($options['orchestrator_max_tokens'] ?? max((int) ($options['max_tokens'] ?? 0), 1024));

        $this->assertConfig($apiUrl, $apiToken, $model);

        [$system, $filtered] = $this->splitMessages($messages);

        try {
            $client = $this->makeClient(['timeout' => $timeout]);

            $payload = ['model' => $model, 'messages' => $filtered, 'max_tokens' => $maxTokens, 'stream' => false];
            if ($system !== '') {
                $payload['system'] = $system;
            }
            if ($this->includeTemperature($model, $options)) {
                $payload['temperature'] = $temp;
            }
            // Anthropic tool schema: [{name, description, input_schema}]
            if (!empty($tools)) {
                $payload['tools'] = array_map(fn($t) => [
                    'name' => $t['name'],
                    'description' => $t['description'] ?? '',
                    'input_schema' => $t['parameters'] ?? ['type' => 'object', 'properties' => (object) []],
                ], $tools);
            }

            $response = $client->post($apiUrl, [
                'headers' => $this->anthropicHeaders($apiToken, $options),
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $content = $data['content'] ?? null;

            if (!is_array($content)) {
                throw new AiEngineException(self::E18, 'Unable to reach AI service. Please try again later.', 502);
            }

            $toolUses = array_values(array_filter($content, fn($b) => ($b['type'] ?? '') === 'tool_use'));

            if (!empty($toolUses)) {
                $calls = array_map(fn($b) => [
                    'id' => $b['id'] ?? '',
                    'name' => $b['name'] ?? '',
                    'arguments' => is_array($b['input'] ?? null) ? $b['input'] : [],
                ], $toolUses);

                // The raw assistant turn is echoed back before the tool_result. Anthropic
                // requires tool_use.input to be an OBJECT; an empty {} decodes to a PHP []
                // which would re-encode as a JSON array and be rejected — force it back.
                $echo = array_map(function ($block) {
                    if (($block['type'] ?? '') === 'tool_use' && ($block['input'] ?? null) === []) {
                        $block['input'] = (object) [];
                    }
                    return $block;
                }, $content);

                return EngineResult::toolCalls($calls, ['role' => 'assistant', 'content' => $echo]);
            }

            return EngineResult::text($this->extractTextBlocks($content));

        } catch (AiEngineException $e) {
            throw $e;
        } catch (TooManyRedirectsException $e) {
            throw new AiEngineException(self::E10, 'Unable to reach AI service. Please try again later.', 502, $e);
        } catch (ConnectException $e) {
            throw new AiEngineException($this->classifyConnectException($e), 'Unable to reach AI service. Please try again later.', 503, $e);
        } catch (RequestException $e) {
            $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 500;
            throw new AiEngineException($this->classifyHttpStatus($status), 'Unable to reach AI service. Please try again later.', $status, $e);
        } catch (\Throwable $e) {
            throw new AiEngineException(self::E19, 'Unable to reach AI service. Please try again later.', 500, $e);
        }
    }

    public function toolResultMessages(EngineResult $result, array $toolResults): array
    {
        // 1. The assistant's tool_use turn, echoed back verbatim.
        $messages = [$result->rawAssistantMessage];

        // 2. A single user turn carrying every tool_result content block.
        $blocks = [];
        foreach ($toolResults as $tr) {
            $blocks[] = [
                'type' => 'tool_result',
                'tool_use_id' => $tr['id'],
                'content' => $tr['content'],
            ];
        }
        $messages[] = ['role' => 'user', 'content' => $blocks];

        return $messages;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Whether to include a `temperature` in the request payload.
     *
     * Newer Anthropic models — Opus 4.7+, Sonnet 5+, and the Fable/Mythos 5
     * family — REMOVED sampling parameters (`temperature`/`top_p`/`top_k`) and
     * reject them with a 400. Older models (Sonnet 4.6, Opus 4.6 and earlier)
     * still accept them, so temperature is preserved there to keep existing
     * behaviour unchanged. An explicit `temperature => null` in the options is
     * honoured as an escape hatch to omit it on any model.
     */
    private function includeTemperature(string $model, array $options): bool
    {
        if (array_key_exists('temperature', $options) && $options['temperature'] === null) {
            return false;
        }

        return !$this->modelRejectsSampling($model);
    }

    /**
     * True for Anthropic model families that reject sampling parameters with a
     * 400 (Opus 4.7 and later, Sonnet 5 and later, Fable/Mythos 5).
     */
    private function modelRejectsSampling(string $model): bool
    {
        return (bool) preg_match(
            '/(opus-4-(?:[7-9]|\d\d)|opus-[5-9]|sonnet-[5-9]|fable-\d|mythos-\d)/i',
            $model
        );
    }

    /**
     * Concatenate the text of every `text` content block in an Anthropic
     * response, skipping non-text blocks (`thinking`, `tool_use`, …). Returns
     * a trimmed string, or '' when there is no text content.
     *
     * @param  mixed  $content  The `content` array from the API response.
     */
    private function extractTextBlocks($content): string
    {
        if (!is_array($content)) {
            return '';
        }

        $text = '';
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        return trim($text);
    }

    private function anthropicHeaders(string $apiToken, array $options = []): array
    {
        return [
            'x-api-key' => $apiToken,
            'anthropic-version' => $options['anthropic_version'] ?? '2023-06-01',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * @return array{0: string, 1: array}  [system text joined, non-system messages]
     */
    private function splitMessages(array $messages): array
    {
        $systemParts = [];
        $filtered = [];

        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                $systemParts[] = $msg['content'];
            } else {
                $filtered[] = $msg;
            }
        }

        return [implode("\n\n", $systemParts), $filtered];
    }
}

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

            $payload = ['model' => $model, 'messages' => $filtered, 'temperature' => $temp, 'max_tokens' => $maxTokens, 'stream' => false];
            if ($system !== '') {
                $payload['system'] = $system;
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

            $payload = ['model' => $model, 'messages' => $filtered, 'temperature' => $temp, 'max_tokens' => $maxTokens, 'stream' => true];
            if ($system !== '') {
                $payload['system'] = $system;
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

        return function (callable $onToken) use ($stream): string {
            $fullReply = '';
            $buffer = '';

            while (!$stream->eof()) {
                $buffer .= $stream->read(1024);

                while (($nl = strpos($buffer, "\n")) !== false) {
                    $line = rtrim(substr($buffer, 0, $nl), "\r");
                    $buffer = substr($buffer, $nl + 1);

                    if ($line === '' || str_starts_with($line, ':') || str_starts_with($line, 'event:')) {
                        continue;
                    }

                    $jsonStr = str_starts_with($line, 'data: ') ? substr($line, 6) : $line;
                    $data = json_decode($jsonStr, true);

                    if (!is_array($data)) {
                        continue;
                    }

                    if (($data['type'] ?? '') === 'message_stop') {
                        break 2;
                    }

                    if (($data['type'] ?? '') === 'content_block_delta') {
                        $token = $data['delta']['text'] ?? null;

                        if ($token !== null && $token !== '') {
                            $fullReply .= $token;
                            ($onToken)($token);
                        }
                    }
                }
            }

            return $fullReply;
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

            $payload = ['model' => $model, 'messages' => $filtered, 'temperature' => $temp, 'max_tokens' => $maxTokens, 'stream' => false];
            if ($system !== '') {
                $payload['system'] = $system;
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

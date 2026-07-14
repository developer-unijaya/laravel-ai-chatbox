<?php
namespace DeveloperUnijaya\AiChatbox\Engine\Contracts;

use DeveloperUnijaya\AiChatbox\Engine\EngineResult;
use DeveloperUnijaya\AiChatbox\Engine\Exceptions\AiEngineException;

/**
 * Optional, additive capability: tool (function) calling.
 *
 * This is deliberately a SEPARATE interface from {@see AiEngineInterface} so the
 * base contract (and every existing custom engine) keeps working unchanged. The
 * orchestrator checks `instanceof SupportsToolCalling` and gracefully falls back to
 * a single plain complete() when an engine does not implement it.
 *
 * The two OpenAI-compatible and Anthropic wire formats differ; each implementing
 * engine translates the normalized tool schemas / results to and from its own shape.
 */
interface SupportsToolCalling
{
    /**
     * Send a non-streamed completion that MAY return tool-call requests.
     *
     * @param  array<int, array{role: string, content: mixed}>  $messages
     * @param  array<int, array{name: string, description: string, parameters: array}>  $tools
     *         Normalized tool schemas (name, description, JSON-Schema parameters).
     * @param  array<string, mixed>  $options  Same resolved config as complete().
     * @return EngineResult  Either a text answer or a set of tool calls.
     *
     * @throws AiEngineException
     */
    public function completeWithTools(array $messages, array $tools, array $options = []): EngineResult;

    /**
     * Build the messages to append after a tool-call turn: the assistant's
     * tool-call turn followed by the tool result(s), each in this engine's shape.
     *
     * @param  EngineResult  $result  The tool_calls result returned by completeWithTools().
     * @param  array<int, array{id: string, content: string}>  $toolResults
     *         One entry per executed tool call: the call id and its stringified result.
     * @return array<int, array<string, mixed>>  Messages to append for the next round.
     */
    public function toolResultMessages(EngineResult $result, array $toolResults): array;
}

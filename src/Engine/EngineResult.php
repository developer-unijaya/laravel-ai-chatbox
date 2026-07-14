<?php
namespace DeveloperUnijaya\AiChatbox\Engine;

/**
 * The result of a tool-aware chat completion.
 *
 * An engine that implements {@see \DeveloperUnijaya\AiChatbox\Engine\Contracts\SupportsToolCalling}
 * returns one of these instead of a plain string, so the orchestrator can tell a
 * final text answer apart from a request to call one or more tools.
 *
 *   type === 'text'        → $text holds the final assistant reply.
 *   type === 'tool_calls'  → $toolCalls holds the requested calls; $rawAssistantMessage
 *                            is the provider-shaped assistant turn to append back to the
 *                            message list before the tool results (shape differs per engine).
 */
final class EngineResult
{
    public const TYPE_TEXT = 'text';
    public const TYPE_TOOL_CALLS = 'tool_calls';

    /**
     * @param  string  $type  self::TYPE_TEXT | self::TYPE_TOOL_CALLS
     * @param  string|null  $text  Final reply when type is text.
     * @param  array<int, array{id: string, name: string, arguments: array}>  $toolCalls
     * @param  array<string, mixed>|null  $rawAssistantMessage  Provider-shaped assistant turn.
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $text = null,
        public readonly array $toolCalls = [],
        public readonly ?array $rawAssistantMessage = null,
    ) {}

    public static function text(string $text): self
    {
        return new self(self::TYPE_TEXT, $text);
    }

    /**
     * @param  array<int, array{id: string, name: string, arguments: array}>  $toolCalls
     * @param  array<string, mixed>  $rawAssistantMessage
     */
    public static function toolCalls(array $toolCalls, array $rawAssistantMessage): self
    {
        return new self(self::TYPE_TOOL_CALLS, null, $toolCalls, $rawAssistantMessage);
    }

    public function isText(): bool
    {
        return $this->type === self::TYPE_TEXT;
    }

    public function wantsTools(): bool
    {
        return $this->type === self::TYPE_TOOL_CALLS;
    }
}

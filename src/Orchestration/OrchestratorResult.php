<?php
namespace DeveloperUnijaya\AiChatbox\Orchestration;

/**
 * The outcome of Orchestrator::run(): the final assistant reply plus the ordered
 * list of tool calls that were executed to produce it (empty for a plain single-shot).
 */
final class OrchestratorResult
{
    /**
     * @param  array<int, ToolCall>  $steps
     */
    public function __construct(
        public readonly string $reply,
        public readonly array $steps = [],
    ) {}

    /**
     * @param  array<int, ToolCall>  $steps
     */
    public static function text(string $reply, array $steps = []): self
    {
        return new self($reply, $steps);
    }

    public function usedTools(): bool
    {
        return $this->steps !== [];
    }
}

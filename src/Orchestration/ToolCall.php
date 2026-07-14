<?php
namespace DeveloperUnijaya\AiChatbox\Orchestration;

/**
 * A single executed tool call within an orchestration run — the record of what the
 * model asked for, what happened, and how long it took. Used for the return value's
 * step list and (later) the audit trail.
 */
final class ToolCall
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $arguments,
        public readonly mixed $result = null,
        public readonly ?string $error = null,
        public readonly ?string $errorCode = null,
        public readonly float $durationMs = 0.0,
    ) {}

    public function ok(): bool
    {
        return $this->error === null;
    }

    /**
     * The string the model sees as this call's result (success payload or error text).
     * Arrays/objects are JSON-encoded; scalars are cast to string.
     */
    public function toModelContent(): string
    {
        if ($this->error !== null) {
            return json_encode(['error' => $this->error], JSON_UNESCAPED_SLASHES);
        }

        if (is_string($this->result)) {
            return $this->result;
        }

        return json_encode($this->result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'arguments' => $this->arguments,
            'result' => $this->result,
            'error' => $this->error,
            'error_code' => $this->errorCode,
            'duration_ms' => $this->durationMs,
        ];
    }
}

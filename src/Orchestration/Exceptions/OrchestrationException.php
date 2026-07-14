<?php
namespace DeveloperUnijaya\AiChatbox\Orchestration\Exceptions;

/**
 * Raised when the orchestration loop itself fails (as opposed to an engine/HTTP
 * failure, which is an AiEngineException with an E## code).
 *
 * Codes:
 *   O01  Max orchestration steps reached (possible tool loop).
 *   O02  Orchestration wall-clock timeout exceeded.
 *
 * Per-tool problems (O03 tool not found, O04 unauthorized, O05 bad arguments,
 * O06 tool threw) are NOT raised as exceptions — they are captured on the step and
 * fed back to the model as a tool error result so it can recover. See Orchestrator.
 */
class OrchestrationException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

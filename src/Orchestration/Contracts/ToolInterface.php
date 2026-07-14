<?php
namespace DeveloperUnijaya\AiChatbox\Orchestration\Contracts;

use Illuminate\Http\Request;

/**
 * A tool the AI can call during an orchestrated conversation.
 *
 * Host applications implement this to give the chatbox real abilities — look up an
 * order, query a table, hit an internal API, etc. A tool only runs when its class is
 * in the `orchestrator_tools` allow-list AND authorize() returns true for the request.
 *
 * Extension pattern mirrors AiEngineInterface / ConversationRepositoryInterface:
 * implement the interface, then register the class in config or the ToolRegistry.
 *
 * @see \DeveloperUnijaya\AiChatbox\Orchestration\ToolRegistry
 * @see \DeveloperUnijaya\AiChatbox\Orchestration\Orchestrator
 */
interface ToolInterface
{
    /**
     * Unique name the model uses to call this tool. Must match [a-zA-Z0-9_-]{1,64}.
     * Example: "get_order_status".
     */
    public function name(): string;

    /**
     * One-line description the model reads to decide when to use the tool.
     */
    public function description(): string;

    /**
     * JSON-Schema object describing the arguments.
     * Example: ['type' => 'object', 'properties' => [...], 'required' => [...]].
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /**
     * Return false to hide/deny this tool for the current request (authorization).
     * The current HTTP request is passed when available (null in console/queue contexts).
     */
    public function authorize(?Request $request = null): bool;

    /**
     * Execute the tool and return any JSON-serialisable value (string|scalar|array).
     * Throwing is allowed — the orchestrator catches it and reports the failure back
     * to the model so it can recover, rather than 500-ing the whole request.
     *
     * @param  array<string, mixed>  $arguments  Validated against parameters().
     * @return mixed
     */
    public function handle(array $arguments): mixed;
}

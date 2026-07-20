<?php
namespace DeveloperUnijaya\AiChatbox\Orchestration;

use DeveloperUnijaya\AiChatbox\AiManager;
use DeveloperUnijaya\AiChatbox\Engine\Contracts\SupportsToolCalling;
use DeveloperUnijaya\AiChatbox\Engine\Exceptions\AiEngineException;
use DeveloperUnijaya\AiChatbox\Engine\PromptBuilder;
use DeveloperUnijaya\AiChatbox\Orchestration\Exceptions\OrchestrationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Layer 1.5 — Orchestration.
 *
 * Runs the agentic tool-calling loop: the model may call tools, we execute them,
 * feed the results back, and repeat until it returns a final text answer (or a
 * safety limit trips). When the orchestrator is disabled — or the engine can't do
 * tools, or no tools are authorized — run() collapses to exactly one plain
 * complete() call, i.e. the package's original behaviour.
 *
 * The orchestrator is a CONSUMER of the engine/provider layer, never a replacement:
 * prompt assembly (incl. RAG injection) is still delegated to PromptBuilder, and
 * engine selection to AiManager.
 */
class Orchestrator
{
    public function __construct(
        private readonly AiManager $aiManager,
        private readonly PromptBuilder $promptBuilder,
        private readonly ToolRegistry $registry,
    ) {}

    /**
     * @param  string  $userMessage
     * @param  array<int, array{role: string, content: string}>  $history  Pre-trimmed history.
     * @param  array<string, mixed>  $cfg  Resolved package config for the active provider.
     * @param  Request|null  $request  Current HTTP request (for tool authorization).
     *
     * @throws \DeveloperUnijaya\AiChatbox\Engine\Exceptions\AiEngineException
     * @throws OrchestrationException
     */
    public function run(string $userMessage, array $history, array $cfg, ?Request $request = null): OrchestratorResult
    {
        $engine = $this->aiManager->resolveEngine($cfg);

        // Fast paths that reduce to a single plain completion:
        //  - orchestrator disabled, or
        //  - engine has no tool-calling capability, or
        //  - no tools are registered/authorized for this request.
        $schemas = [];
        if (($cfg['orchestrator_enabled'] ?? false) && $engine instanceof SupportsToolCalling) {
            $this->registry->loadFromConfig((array) ($cfg['orchestrator_tools'] ?? []));
            $schemas = $this->registry->schemas($request);
        }

        if ($schemas === []) {
            $messages = $this->promptBuilder->build($userMessage, $history, $cfg);
            return OrchestratorResult::text($engine->complete($messages, $cfg));
        }

        /** @var SupportsToolCalling $engine */
        $messages = $this->promptBuilder->build($userMessage, $history, $cfg);
        $maxSteps = max(1, (int) ($cfg['orchestrator_max_steps'] ?? 5));
        $timeout = max(1, (int) ($cfg['orchestrator_timeout'] ?? 60));
        $deadline = microtime(true) + $timeout;

        /** @var array<int, ToolCall> $steps */
        $steps = [];

        for ($i = 0; $i < $maxSteps; $i++) {
            if (microtime(true) > $deadline) {
                throw new OrchestrationException('O02', 'Orchestration timed out after ' . $timeout . 's.');
            }

            $result = $engine->completeWithTools($messages, $schemas, $cfg);

            if ($result->isText()) {
                $text = trim((string) $result->text);

                // Match the plain complete() contract: an empty final answer is an
                // error (E18), not a valid ""-reply that would be returned with 200
                // and persisted as an empty assistant turn.
                if ($text === '') {
                    throw new AiEngineException('E18', 'Unable to reach AI service. Please try again later.', 502);
                }

                return OrchestratorResult::text($text, $steps);
            }

            // The model requested one or more tools — execute each, collect results.
            $toolResults = [];
            foreach ($result->toolCalls as $call) {
                $step = $this->dispatch($call, $request);
                $steps[] = $step;
                $toolResults[] = ['id' => $step->id, 'content' => $step->toModelContent()];
            }

            $messages = array_merge($messages, $engine->toolResultMessages($result, $toolResults));
        }

        // Max steps reached without the model volunteering a final answer. Rather
        // than discard all the tool work the user already paid for (and return an
        // error bubble), make ONE last call with NO tools so the model is forced
        // to produce a text answer from the results gathered so far.
        $final = $engine->completeWithTools($messages, [], $cfg);
        $text = $final->isText() ? trim((string) $final->text) : '';

        if ($text === '') {
            throw new AiEngineException('E18', 'Unable to reach AI service. Please try again later.', 502);
        }

        return OrchestratorResult::text($text, $steps);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * Execute one requested tool call, capturing success or a recoverable failure.
     * Per-tool problems become error results fed back to the model (O03–O06); they
     * never abort the run.
     *
     * @param  array{id: string, name: string, arguments: array}  $call
     */
    private function dispatch(array $call, ?Request $request): ToolCall
    {
        $start = microtime(true);
        $id = (string) ($call['id'] ?? '');
        $name = (string) ($call['name'] ?? '');
        $args = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];

        $fail = fn(string $code, string $msg) => new ToolCall(
            $id, $name, $args, null, $msg, $code, (microtime(true) - $start) * 1000,
        );

        $tool = $this->registry->get($name);
        if ($tool === null) {
            return $fail('O03', "Unknown tool '{$name}'.");
        }

        try {
            if (!$tool->authorize($request)) {
                return $fail('O04', "Not authorized to use tool '{$name}'.");
            }
        } catch (\Throwable $e) {
            return $fail('O04', "Authorization check failed for tool '{$name}'.");
        }

        // Light required-argument validation from the tool's JSON schema.
        $required = $tool->parameters()['required'] ?? [];
        if (is_array($required)) {
            $missing = array_values(array_filter($required, fn($k) => !array_key_exists($k, $args)));
            if ($missing !== []) {
                return $fail('O05', "Missing required argument(s): " . implode(', ', $missing) . '.');
            }
        }

        try {
            $result = $tool->handle($args);
        } catch (\Throwable $e) {
            Log::warning('AI Chatbox orchestrator: tool threw during handle().', [
                'tool' => $name,
                'error' => $e->getMessage(),
            ]);
            return $fail('O06', "Tool '{$name}' failed: " . $e->getMessage());
        }

        return new ToolCall($id, $name, $args, $result, null, null, (microtime(true) - $start) * 1000);
    }
}

<?php
namespace DeveloperUnijaya\AiChatbox\Memory;

/**
 * Trims conversation history to fit within both message-count and token-count
 * limits before it is sent to the AI engine.
 *
 * Works on in-memory arrays; does not read from or write to any storage.
 * The caller is responsible for persisting the trimmed result.
 */
class ContextManager
{
    /**
     * Return a trimmed copy of $history that fits within the configured limits.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @param  array<int, array{role: string, content: string}>  $systemMessages  Already-built system message(s)
     * @param  array<string, mixed>  $cfg
     * @param  int  $ragBudget  Estimated tokens that RAG context will consume (reserved before trimming history)
     * @return array<int, array{role: string, content: string}>
     */
    public function trim(array $history, array $systemMessages, string $userMessage, array $cfg, int $ragBudget = 0): array
    {
        $historyLimit = (int) ($cfg['history_limit'] ?? 50);
        $contextTokenLimit = (int) ($cfg['context_token_limit'] ?? 4000);
        $language = $cfg['language'] ?? 'English';
        $systemPrompt = $cfg['system_prompt'] ?? '';

        // 1. Trim by pair count (fast, no token estimation needed)
        $max = $historyLimit * 2;
        if (count($history) > $max) {
            $history = array_slice($history, count($history) - $max);
        }

        if ($contextTokenLimit <= 0) {
            return $history;
        }

        // Reserve headroom for RAG chunks injected after trimming by PromptBuilder,
        // but never let the reserve consume the whole window — always keep at least
        // half the token budget for recent history. Otherwise a large RAG budget
        // (e.g. rag_top_k * rag_chunk_size > context_token_limit) would drive the
        // effective limit to 0 and silently strip ALL conversation memory.
        $effectiveLimit = max((int) floor($contextTokenLimit / 2), $contextTokenLimit - $ragBudget);

        // 2. Trim by estimated token count (~4 chars per token)
        //    The language reminder is part of the API payload but not stored in history.
        $apiMessage = (!empty($systemPrompt) && !empty($language))
        ? $userMessage . "\n\n[Important: Reply in {$language} only.]"
        : $userMessage;

        // Pre-compute the token cost of the static parts (system + current user message)
        // so the loop only needs to re-estimate the shrinking history portion each iteration.
        $fixedTokens = $this->estimateTokens(
            array_merge($systemMessages, [['role' => 'user', 'content' => $apiMessage]])
        );

        while (count($history) >= 2) {
            if ($fixedTokens + $this->estimateTokens($history) <= $effectiveLimit) {
                break;
            }

            array_splice($history, 0, 2); // drop oldest user + assistant pair
        }

        return $history;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function estimateTokens(array $messages): int
    {
        $chars = array_sum(array_map(fn($m) => mb_strlen($m['content'] ?? '', 'UTF-8'), $messages));

        return (int) ceil($chars / 4);
    }
}

<?php
namespace DeveloperUnijaya\AiChatbox\Memory\Contracts;

interface ConversationRepositoryInterface
{
    /**
     * Retrieve the stored message history for the given thread.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function getHistory(string $threadId): array;

    /**
     * Persist a single message for a thread immediately.
     * Used to save the user's prompt before the AI call begins.
     */
    public function saveMessage(string $threadId, string $role, string $content): void;

    /**
     * Overwrite the stored history for a thread.
     * Used to persist both the context-trimmed history and newly appended messages.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     */
    public function saveHistory(string $threadId, array $history): void;

    /**
     * Prune the stored history to at most $maxPairs user + assistant pairs,
     * dropping the oldest pairs first.
     */
    public function trimToLimit(string $threadId, int $maxPairs): void;

    /**
     * Delete all stored history for a thread.
     */
    public function clear(string $threadId): void;
}

<?php
namespace SyafiqUnijaya\AiChatbox\Memory;

use SyafiqUnijaya\AiChatbox\Memory\Contracts\ConversationRepositoryInterface;
use SyafiqUnijaya\AiChatbox\Memory\Models\Conversation;
use SyafiqUnijaya\AiChatbox\Memory\Models\Message;

/**
 * Stores conversation history in the database.
 * Requires the ai_chatbox_conversations and ai_chatbox_messages tables.
 *
 * Enable with: AI_CHATBOX_MEMORY_DRIVER=database
 * Then run:    php artisan migrate
 */
class DatabaseConversationRepository implements ConversationRepositoryInterface
{
    public function getHistory(string $threadId): array
    {
        $conversation = Conversation::where('thread_id', $threadId)->first();

        if (!$conversation) {
            return [];
        }

        return $this->activeMessages($conversation)
            ->get(['role', 'content'])
            ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();
    }

    public function saveHistory(string $threadId, array $history): void
    {
        $conversation = Conversation::firstOrCreate(
            ['thread_id' => $threadId],
            ['user_id' => auth()->id()]
        );

        // Count only "active" messages (after the last clear point) to determine
        // which entries in $history are already persisted vs. need inserting.
        $existingCount = $this->activeMessages($conversation)->count();
        $newMessages = array_slice($history, $existingCount);

        if (!empty($newMessages)) {
            $conversation->messages()->createMany($newMessages);
        }

        // Keep updated_at current so the prune command can use it as last-activity time
        $conversation->touch();
    }

    public function trimToLimit(string $threadId, int $maxPairs): void
    {
        $conversation = Conversation::where('thread_id', $threadId)->first();

        if (!$conversation) {
            return;
        }

        $max   = $maxPairs * 2;
        $query = $this->activeMessages($conversation);
        $total = $query->count();

        if ($total <= $max) {
            return;
        }

        $excess = $total - $max;
        $ids = $query->limit($excess)->pluck('id');

        Message::whereIn('id', $ids)->delete();
    }

    public function clear(string $threadId): void
    {
        $conversation = Conversation::where('thread_id', $threadId)->first();

        if (!$conversation) {
            return;
        }

        // Record the ID of the last stored message as the new "cleared" boundary.
        // Messages up to this point are preserved in the DB for admin review;
        // getHistory() will return an empty array for the next AI turn.
        $lastId = $conversation->messages()->max('id');
        $conversation->update(['cleared_after_id' => $lastId ?? 0]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function activeMessages(Conversation $conversation)
    {
        $query = $conversation->messages()->orderBy('id');

        if ($conversation->cleared_after_id !== null) {
            $query->where('id', '>', $conversation->cleared_after_id);
        }

        return $query;
    }
}

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

        return $conversation->messages()
            ->orderBy('id')
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

        // Only insert messages that are not already persisted.
        // The controller always passes the full history with the new pair appended at the end,
        // so slicing from the current DB count gives exactly the new messages to insert.
        $existingCount = $conversation->messages()->count();
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

        $total = $conversation->messages()->count();
        $max = $maxPairs * 2;

        if ($total <= $max) {
            return;
        }

        $excess = $total - $max;
        $ids = $conversation->messages()
            ->orderBy('id')
            ->limit($excess)
            ->pluck('id');

        Message::whereIn('id', $ids)->delete();
    }

    public function clear(string $threadId): void
    {
        $conversation = Conversation::where('thread_id', $threadId)->first();
        $conversation?->messages()->delete();
    }
}

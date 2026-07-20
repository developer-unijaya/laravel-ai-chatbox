<?php
namespace DeveloperUnijaya\AiChatbox\Memory;

use DeveloperUnijaya\AiChatbox\Memory\Contracts\ConversationRepositoryInterface;
use DeveloperUnijaya\AiChatbox\Memory\Models\Conversation;
use DeveloperUnijaya\AiChatbox\Memory\Models\Message;
use Illuminate\Database\QueryException;

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
        $conversation = $this->findConversation($threadId);

        if (!$conversation) {
            return [];
        }

        return $this->activeMessages($conversation)
            ->get(['role', 'content'])
            ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();
    }

    public function saveMessage(string $threadId, string $role, string $content): void
    {
        $conversation = $this->resolveForWrite($threadId);

        // Null means the thread is owned by someone else — never write into it.
        if ($conversation === null) {
            return;
        }

        $conversation->messages()->create(['role' => $role, 'content' => $content]);
        $conversation->touch();
    }

    public function saveHistory(string $threadId, array $history): void
    {
        $conversation = $this->resolveForWrite($threadId);

        // Null means the thread is owned by someone else — never write into it.
        if ($conversation === null) {
            return;
        }

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

    public function appendMessages(string $threadId, array $messages): void
    {
        if (empty($messages)) {
            return;
        }

        $conversation = $this->resolveForWrite($threadId);

        // Null means the thread is owned by someone else — never write into it.
        if ($conversation === null) {
            return;
        }

        // Plain append of this turn's own messages — no count diff, so concurrent
        // writes to the same thread can't drop or duplicate each other's rows.
        $conversation->messages()->createMany($messages);
        $conversation->touch();
    }

    public function trimToLimit(string $threadId, int $maxPairs): void
    {
        $conversation = $this->findConversation($threadId);

        if (!$conversation) {
            return;
        }

        $max = $maxPairs * 2;
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
        $conversation = $this->findConversation($threadId);

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

    /**
     * Find a conversation the current requester is allowed to READ.
     *
     * A thread_id is globally unique, so ownership is decided by user_id:
     *  - authenticated requester → the row's user_id must equal theirs;
     *  - guest requester (null)  → the row must be unowned (user_id === null).
     * Any mismatch returns null, so a guest can never read an owned thread and
     * an authenticated user can never read a guest/other-user thread (IDOR guard).
     */
    private function findConversation(string $threadId): ?Conversation
    {
        $conversation = Conversation::where('thread_id', $threadId)->first();

        if ($conversation === null) {
            return null;
        }

        return $conversation->user_id === auth()->id() ? $conversation : null;
    }

    /**
     * Resolve the conversation the current requester is allowed to WRITE, creating
     * it on first use. Returns null when the thread_id already exists but is owned
     * by someone else — the caller must then NOT write (never poison another's thread,
     * and never trip the unique(thread_id) constraint by attempting an insert).
     */
    private function resolveForWrite(string $threadId): ?Conversation
    {
        $userId = auth()->id();
        $existing = Conversation::where('thread_id', $threadId)->first();

        if ($existing !== null) {
            return $existing->user_id === $userId ? $existing : null;
        }

        try {
            return Conversation::create(['thread_id' => $threadId, 'user_id' => $userId]);
        } catch (QueryException $e) {
            // A concurrent first message created the row first (unique thread_id).
            // Re-fetch and re-apply the ownership check instead of failing.
            $existing = Conversation::where('thread_id', $threadId)->first();

            return ($existing !== null && $existing->user_id === $userId) ? $existing : null;
        }
    }

    private function activeMessages(Conversation $conversation)
    {
        $query = $conversation->messages()->orderBy('id');

        if ($conversation->cleared_after_id !== null) {
            $query->where('id', '>', $conversation->cleared_after_id);
        }

        return $query;
    }
}

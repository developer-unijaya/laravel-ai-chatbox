@extends('ai-chatbox::admin-layout')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/marked@13.0.3/marked.min.js" integrity="sha384-YTBHtsL8yVTHcLakYNyrOfK3K+QQcXiECuaALJ+3j7Mo681Rtzadt8NR6WrZH+eQ" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.4.2/dist/purify.min.js" integrity="sha384-AX0sZ/phUL4R6LAFP+mob0mJIWg2c3PX8wPn48ctytOl7XKfRQHbakBt5/QID7uh" crossorigin="anonymous"></script>
@endpush

@section('title', $document->title . ' — Chunks')
@section('page-title', 'Knowledge Base')

@section('navbar-right')
    <a href="{{ route('ai-chatbox.rag.index') }}" class="btn-secondary" style="padding:0.375rem 0.875rem;font-size:0.75rem;">
        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/>
        </svg>
        Knowledge Base
    </a>
@endsection

@push('styles')
<style>
    /* Chunk content box */
    .chunk-box {
        font-family: ui-monospace, 'Cascadia Code', 'Fira Mono', monospace;
        font-size: 0.75rem;
        line-height: 1.6;
        white-space: pre-wrap;
        word-break: break-word;
        overflow-y: auto;
        max-height: 11rem;
        background: #f8fafc;
        border-radius: 0.5rem;
        padding: 0.75rem 1rem;
        color: #374151;
    }
    .dark .chunk-box {
        background: rgba(17,24,39,0.6);
        color: #d1d5db;
    }

    /* Chat bubbles */
    .bubble-user {
        background-color: var(--theme);
        color: #fff;
        border-radius: 1rem 1rem 0.25rem 1rem;
        padding: 0.625rem 0.875rem;
        max-width: 82%;
        font-size: 0.875rem;
        line-height: 1.5;
        word-break: break-word;
    }
    .bubble-ai {
        background: #f3f4f6;
        color: #1f2937;
        border-radius: 1rem 1rem 1rem 0.25rem;
        padding: 0.625rem 0.875rem;
        max-width: 82%;
        font-size: 0.875rem;
        line-height: 1.5;
        word-break: break-word;
    }
    .dark .bubble-ai {
        background: #374151;
        color: #f3f4f6;
    }
    .bubble-error {
        background: #fee2e2;
        color: #991b1b;
        border-radius: 1rem 1rem 1rem 0.25rem;
        padding: 0.625rem 0.875rem;
        max-width: 82%;
        font-size: 0.875rem;
        line-height: 1.5;
    }
    .dark .bubble-error {
        background: rgba(127,29,29,0.4);
        color: #fca5a5;
    }

    /* Spinner (shared with rag.blade.php) */
    @keyframes spin { to { transform: rotate(360deg); } }
    .spinner {
        display: inline-block; border-radius: 50%;
        border: 2px solid currentColor; border-top-color: transparent;
        animation: spin 0.7s linear infinite; flex-shrink: 0;
    }

    /* Chat input focus */
    #chat-input:focus { outline: none; box-shadow: 0 0 0 2px var(--theme); }

    /* Markdown inside AI bubbles */
    .bubble-ai p { margin-bottom: 0.45rem; }
    .bubble-ai p:first-child { margin-top: 0; }
    .bubble-ai p:last-child  { margin-bottom: 0; }
    .bubble-ai h1,.bubble-ai h2,.bubble-ai h3,.bubble-ai h4 { font-weight: 700; margin: 0.6rem 0 0.2rem; line-height: 1.3; }
    .bubble-ai h1 { font-size: 1.05rem; }
    .bubble-ai h2 { font-size: 0.95rem; }
    .bubble-ai h3,.bubble-ai h4 { font-size: 0.875rem; }
    .bubble-ai ul,.bubble-ai ol { padding-left: 1.25rem; margin-bottom: 0.45rem; }
    .bubble-ai ul { list-style-type: disc; }
    .bubble-ai ol { list-style-type: decimal; }
    .bubble-ai li { margin-bottom: 0.15rem; }
    .bubble-ai code { font-family: ui-monospace,'Cascadia Code',monospace; font-size: 0.78em; background: rgba(0,0,0,0.09); padding: 0.1em 0.3em; border-radius: 0.25rem; }
    .dark .bubble-ai code { background: rgba(255,255,255,0.12); }
    .bubble-ai pre { background: rgba(0,0,0,0.08); border-radius: 0.5rem; padding: 0.6rem 0.75rem; margin-bottom: 0.45rem; overflow-x: auto; }
    .dark .bubble-ai pre { background: rgba(255,255,255,0.07); }
    .bubble-ai pre code { background: none; padding: 0; font-size: 0.8em; }
    .bubble-ai blockquote { border-left: 3px solid var(--theme); padding-left: 0.65rem; opacity: 0.75; margin-bottom: 0.45rem; }
    .bubble-ai strong { font-weight: 700; }
    .bubble-ai em { font-style: italic; }
    .bubble-ai a { color: var(--theme); text-decoration: underline; }
    .bubble-ai hr { border: none; border-top: 1px solid rgba(0,0,0,0.1); margin: 0.5rem 0; }
    .dark .bubble-ai hr { border-top-color: rgba(255,255,255,0.1); }
    .bubble-ai table { border-collapse: collapse; width: 100%; margin-bottom: 0.45rem; font-size: 0.8em; }
    .bubble-ai th,.bubble-ai td { border: 1px solid rgba(0,0,0,0.12); padding: 0.3rem 0.5rem; text-align: left; }
    .dark .bubble-ai th,.dark .bubble-ai td { border-color: rgba(255,255,255,0.1); }
    .bubble-ai th { background: rgba(0,0,0,0.05); font-weight: 600; }
    .dark .bubble-ai th { background: rgba(255,255,255,0.05); }

    /* Embedding status badges (complement rag.blade.php) */
    .badge-ready      { background: #dcfce7; color: #166534; }
    .badge-pending    { background: #fef9c3; color: #854d0e; }
    .badge-processing { background: #dbeafe; color: #1e40af; }
    .badge-failed     { background: #fee2e2; color: #991b1b; }
    .dark .badge-ready      { background: #14532d; color: #86efac; }
    .dark .badge-pending    { background: #713f12; color: #fde68a; }
    .dark .badge-processing { background: #1e3a5f; color: #93c5fd; }
    .dark .badge-failed     { background: #7f1d1d; color: #fca5a5; }
</style>
@endpush

@section('content')

{{-- Document header ──────────────────────────────────────────────────────── --}}
<div class="mb-6 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm px-6 py-4 flex flex-wrap items-center gap-4">
    <div class="flex-1 min-w-0">
        <h1 class="text-base font-bold text-gray-900 dark:text-gray-100 truncate">{{ $document->title }}</h1>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
            {{ $document->original_filename }}
            <span class="mx-1.5 text-gray-300 dark:text-gray-600">·</span>
            <span class="uppercase font-mono">{{ $document->file_type }}</span>
            <span class="mx-1.5 text-gray-300 dark:text-gray-600">·</span>
            uploaded {{ $document->created_at->diffForHumans() }}
        </p>
    </div>
    <div class="flex items-center gap-3 shrink-0">
        <div class="text-center">
            <p class="text-2xl font-bold text-gray-800 dark:text-gray-100 tabular-nums">{{ $chunks->count() }}</p>
            <p class="text-[0.65rem] uppercase tracking-wide text-gray-400 dark:text-gray-500 font-semibold">Chunks</p>
        </div>
        <div class="w-px h-10 bg-gray-200 dark:bg-gray-700"></div>
        <div class="text-center">
            <p class="text-2xl font-bold text-gray-800 dark:text-gray-100 tabular-nums">
                {{ $chunks->filter(fn($c) => !empty($c->embedding))->count() }}
            </p>
            <p class="text-[0.65rem] uppercase tracking-wide text-gray-400 dark:text-gray-500 font-semibold">Vectors</p>
        </div>
        <div class="w-px h-10 bg-gray-200 dark:bg-gray-700"></div>
        <div>
            <span class="badge badge-{{ $document->status }} text-[0.7rem]">{{ ucfirst($document->status) }}</span>
        </div>
    </div>
</div>

{{-- Two-column layout: chunks (left) + chat (right) ───────────────────────── --}}
<div class="grid grid-cols-1 xl:grid-cols-5 gap-6 items-start">

    {{-- ── Chunk list (3/5 width on xl) ──────────────────────────────────── --}}
    <div class="xl:col-span-3 space-y-3">
        <p class="text-xs font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500 px-0.5">
            Chunks &mdash; {{ $chunks->count() }} total
        </p>

        @forelse($chunks as $chunk)
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
            {{-- Chunk header --}}
            <div class="flex items-center justify-between px-4 py-2 border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-700/50">
                <span class="font-mono text-xs font-semibold text-gray-500 dark:text-gray-400">
                    #{{ $chunk->chunk_index + 1 }}
                </span>
                <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-400 dark:text-gray-500 tabular-nums">
                        {{ number_format(mb_strlen($chunk->content)) }} chars
                    </span>
                    @if(!empty($chunk->embedding))
                        <span class="badge badge-green" style="font-size:0.6rem;gap:0.25rem;">
                            <svg class="w-2.5 h-2.5" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M2 10a8 8 0 018-8v8h8a8 8 0 11-16 0z"/><path d="M12 2.252A8.014 8.014 0 0117.748 8H12V2.252z"/>
                            </svg>
                            Vector
                        </span>
                    @else
                        <span class="badge badge-gray" style="font-size:0.6rem;">Keyword</span>
                    @endif
                </div>
            </div>
            {{-- Chunk content --}}
            <div class="px-4 py-3">
                <div class="chunk-box">{{ $chunk->content }}</div>
            </div>
        </div>
        @empty
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm px-6 py-16 text-center text-gray-400 dark:text-gray-500">
            <svg class="mx-auto mb-3 h-8 w-8 text-gray-300 dark:text-gray-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>
            </svg>
            <p class="text-sm">No chunks found. Reprocess this document to generate chunks.</p>
        </div>
        @endforelse
    </div>

    {{-- ── Sticky chat panel (2/5 width on xl) ───────────────────────────── --}}
    <div class="xl:col-span-2 xl:sticky xl:top-16">
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden flex flex-col" style="height:calc(100vh - 5.5rem);">

            {{-- Chat header --}}
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 shrink-0">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 shrink-0" style="color:var(--theme)" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155"/>
                    </svg>
                    <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-200">Test Chat</h2>
                </div>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1 leading-relaxed">
                    Retrieval is scoped to <strong class="font-medium text-gray-600 dark:text-gray-300">{{ $document->title }}</strong> only.
                    @if($chunks->filter(fn($c) => !empty($c->embedding))->isNotEmpty())
                        Vector search active.
                    @else
                        Keyword search only (no embeddings stored).
                    @endif
                </p>
            </div>

            {{-- Messages area --}}
            <div id="chat-messages" class="flex-1 overflow-y-auto px-5 py-4 space-y-3">
                @if($providerConfigured)
                <p class="text-center text-xs text-gray-400 dark:text-gray-500 py-2">
                    Ask anything about this document to see how retrieval works.
                </p>
                @else
                <div class="flex flex-col items-center justify-center h-full gap-3 py-8 text-center px-4">
                    <div class="w-10 h-10 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-amber-500" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">AI provider not configured</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1 leading-relaxed">{{ $providerIssue }}</p>
                    </div>
                    <a href="{{ route('ai-chatbox.admin.index') }}" class="btn-secondary" style="font-size:0.75rem;padding:0.375rem 0.875rem;">
                        Open Dashboard
                    </a>
                </div>
                @endif
            </div>

            {{-- Input area --}}
            <div class="border-t border-gray-100 dark:border-gray-700 px-4 py-3.5 shrink-0 {{ !$providerConfigured ? 'opacity-50 pointer-events-none select-none' : '' }}">
                <div class="flex gap-2">
                    <input id="chat-input" type="text" placeholder="Ask something about this document…"
                        autocomplete="off" spellcheck="false"
                        {{ !$providerConfigured ? 'disabled' : '' }}
                        class="flex-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 px-3 py-2 text-sm transition-shadow">
                    <button id="chat-send" type="button" class="btn-primary shrink-0"
                        {{ !$providerConfigured ? 'disabled' : '' }}
                        style="padding:0.5rem 1rem;">
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"/>
                        </svg>
                        Send
                    </button>
                </div>
                <p class="mt-2 text-[0.68rem] text-gray-400 dark:text-gray-500">
                    Sends to your configured AI provider &mdash; no history saved.
                </p>
            </div>

        </div>
    </div>

</div>

@endsection

@push('scripts')
<script>
(function () {
    'use strict';

    @if(!$providerConfigured)
    return; // provider not configured — chat is disabled
    @endif

    var chatUrl       = @json(route('ai-chatbox.rag.chat', $document->id));
    var csrf          = document.querySelector('meta[name="csrf-token"]').content;
    var messages      = document.getElementById('chat-messages');
    var input         = document.getElementById('chat-input');
    var sendBtn       = document.getElementById('chat-send');
    var streamEnabled = @json($streamEnabled);

    if (typeof marked !== 'undefined') {
        marked.use({ breaks: true, gfm: true });
    }

    function renderMarkdown(text) {
        if (typeof marked !== 'undefined' && typeof DOMPurify !== 'undefined') {
            return DOMPurify.sanitize(marked.parse(text));
        }
        return text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
    }

    function scrollBottom() { messages.scrollTop = messages.scrollHeight; }

    function appendBubble(role, text) {
        var row = document.createElement('div');
        var b   = document.createElement('div');
        if (role === 'user') {
            row.className = 'flex justify-end';
            b.className   = 'bubble-user';
            b.textContent = text;
        } else if (role === 'error') {
            row.className = 'flex justify-start';
            b.className   = 'bubble-error';
            b.textContent = text;
        } else {
            row.className = 'flex justify-start';
            b.className   = 'bubble-ai';
            b.innerHTML   = renderMarkdown(text);
        }
        row.appendChild(b);
        messages.appendChild(row);
        scrollBottom();
        return row;
    }

    function appendStreamBubble() {
        var row = document.createElement('div');
        row.className = 'flex justify-start';
        var b = document.createElement('div');
        b.className = 'bubble-ai';
        row.appendChild(b);
        messages.appendChild(row);
        scrollBottom();
        return b;
    }

    function appendChunksBadge(count) {
        var p = document.createElement('p');
        p.className = 'text-center text-[0.68rem] text-gray-400 dark:text-gray-500';
        p.textContent = count === 0
            ? 'No matching chunks found'
            : count + ' chunk' + (count !== 1 ? 's' : '') + ' used as context';
        messages.appendChild(p);
        scrollBottom();
    }

    function showThinking() {
        var row = document.createElement('div');
        row.id = 'thinking';
        row.className = 'flex justify-start';
        row.innerHTML = '<div class="bubble-ai flex items-center gap-2 text-gray-400 dark:text-gray-500"><span class="spinner" style="width:0.75rem;height:0.75rem;color:var(--theme)"></span>Thinking…</div>';
        messages.appendChild(row);
        scrollBottom();
    }

    function removeThinking() {
        var el = document.getElementById('thinking');
        if (el) el.remove();
    }

    function setLoading(on) {
        sendBtn.disabled = on;
        input.disabled   = on;
        if (on) {
            sendBtn.innerHTML = '<span class="spinner" style="width:1rem;height:1rem"></span>';
        } else {
            sendBtn.innerHTML = '<svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"/></svg>Send';
        }
    }

    async function sendMessage() {
        var query = input.value.trim();
        if (!query) return;
        input.value = '';
        setLoading(true);
        appendBubble('user', query);
        showThinking();
        try {
            if (streamEnabled) {
                await sendStreaming(query);
            } else {
                await sendJson(query);
            }
        } catch (err) {
            removeThinking();
            appendBubble('error', 'Request failed — check your network or provider config.');
            setLoading(false);
            input.focus();
        }
    }

    async function sendJson(query) {
        var res  = await fetch(chatUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body:    JSON.stringify({ message: query }),
        });
        var data = await res.json();
        removeThinking();
        if (data.error) {
            appendBubble('error', data.error);
        } else {
            appendBubble('ai', data.reply);
            appendChunksBadge(data.chunks_used ?? 0);
        }
        setLoading(false);
        input.focus();
    }

    async function sendStreaming(query) {
        var res = await fetch(chatUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream', 'X-CSRF-TOKEN': csrf },
            body:    JSON.stringify({ message: query }),
        });

        // Non-2xx means an error JSON was returned (e.g. 422, 502) before streaming started
        if (!res.ok) {
            var data = await res.json().catch(function () { return {}; });
            removeThinking();
            appendBubble('error', data.error || 'Request failed (' + res.status + ').');
            setLoading(false);
            input.focus();
            return;
        }

        removeThinking();

        var aiBubble  = null;
        var fullReply = '';
        var chunksUsed = 0;
        var reader    = res.body.getReader();
        var decoder   = new TextDecoder();
        var buffer    = '';

        try {
            outer: while (true) {
                var chunk = await reader.read();
                if (chunk.done) break;

                buffer += decoder.decode(chunk.value, { stream: true });

                var idx;
                while ((idx = buffer.indexOf('\n')) !== -1) {
                    var line = buffer.slice(0, idx).replace(/\r$/, '');
                    buffer = buffer.slice(idx + 1);

                    if (!line || !line.startsWith('data: ')) continue;
                    var payload = line.slice(6);
                    if (payload === '[DONE]') { reader.cancel(); break outer; }

                    try {
                        var evt = JSON.parse(payload);
                        if ('chunks_used' in evt) { chunksUsed = evt.chunks_used; continue; }
                        if (evt.error) {
                            appendBubble('error', evt.error);
                            setLoading(false);
                            input.focus();
                            return;
                        }
                        if (evt.token) {
                            if (!aiBubble) { aiBubble = appendStreamBubble(); }
                            fullReply += evt.token;
                            aiBubble.textContent = fullReply;
                            scrollBottom();
                        }
                    } catch (e) {}
                }
            }
        } catch (e) {}

        if (aiBubble) {
            aiBubble.innerHTML = renderMarkdown(fullReply);
            scrollBottom();
        }
        appendChunksBadge(chunksUsed);
        setLoading(false);
        input.focus();
    }

    sendBtn.addEventListener('click', sendMessage);
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });
})();
</script>
@endpush

@extends('ai-chatbox::admin-layout')

@section('title', 'Conversations')
@section('page-title', 'Conversations')

@section('navbar-right')
    <span id="total-badge" class="badge badge-blue"></span>
@endsection

@push('head')
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify/dist/purify.min.js"></script>
@endpush

@push('styles')
<style>
    /* Row hover */
    .conv-row { cursor: pointer; transition: background-color 0.1s; }
    .conv-row:hover { background-color: color-mix(in srgb, var(--theme) 6%, transparent); }

    /* Skeleton pulse */
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
    .skeleton { animation: pulse 1.4s ease-in-out infinite; background: #e5e7eb; border-radius: 4px; height: 1rem; }
    .dark .skeleton { background: #374151; }

    /* Pagination */
    .page-btn {
        display: inline-flex; align-items: center; justify-content: center;
        width: 2rem; height: 2rem; border-radius: 0.375rem;
        font-size: 0.8rem; font-weight: 500;
        border: 1px solid #d1d5db; background: #fff; color: #374151;
        cursor: pointer; transition: background 0.1s;
    }
    .dark .page-btn { background: #1f2937; border-color: #374151; color: #d1d5db; }
    .page-btn:hover:not(:disabled) { background: color-mix(in srgb, var(--theme) 10%, transparent); }
    .page-btn.active { background-color: var(--theme); color: #fff; border-color: var(--theme); }
    .page-btn:disabled { opacity: 0.4; cursor: not-allowed; }

    /* Modal */
    #msg-modal-backdrop {
        position: fixed; inset: 0; z-index: 50;
        background: rgba(0,0,0,0.5); backdrop-filter: blur(2px);
        align-items: center; justify-content: center; padding: 1rem;
    }
    #msg-modal {
        background: #fff; border-radius: 0.75rem;
        width: 100%; max-width: 640px; max-height: 85vh;
        display: flex; flex-direction: column;
        box-shadow: 0 20px 60px rgba(0,0,0,0.25); overflow: hidden;
    }
    .dark #msg-modal { background: #1f2937; }

    /* Chat bubbles */
    .bubble-user {
        align-self: flex-end; background-color: var(--theme); color: #fff;
        border-radius: 1rem 1rem 0.25rem 1rem;
        padding: 0.6rem 0.9rem; max-width: 78%;
        white-space: pre-wrap; word-break: break-word; font-size: 0.875rem;
    }
    .bubble-assistant {
        align-self: flex-start; background: #f3f4f6; color: #111827;
        border-radius: 1rem 1rem 1rem 0.25rem;
        padding: 0.6rem 0.9rem; max-width: 78%;
        word-break: break-word; font-size: 0.875rem;
    }
    .dark .bubble-assistant { background: #374151; color: #f3f4f6; }

    /* Markdown prose inside assistant bubbles */
    .bubble-assistant p { margin: 0 0 0.5em; }
    .bubble-assistant p:last-child { margin-bottom: 0; }
    .bubble-assistant ul { list-style: disc; margin: 0.25em 0 0.5em 1.25em; }
    .bubble-assistant ol { list-style: decimal; margin: 0.25em 0 0.5em 1.25em; }
    .bubble-assistant li { margin-bottom: 0.2em; }
    .bubble-assistant h1,.bubble-assistant h2,.bubble-assistant h3,
    .bubble-assistant h4,.bubble-assistant h5,.bubble-assistant h6
        { font-weight: 700; margin: 0.6em 0 0.25em; line-height: 1.3; }
    .bubble-assistant h1 { font-size: 1.1em; }
    .bubble-assistant h2 { font-size: 1em; }
    .bubble-assistant h3 { font-size: 0.95em; }
    .bubble-assistant code { background: rgba(0,0,0,0.1); border-radius: 3px; padding: 0.1em 0.35em; font-size: 0.82em; font-family: monospace; }
    .dark .bubble-assistant code { background: rgba(255,255,255,0.12); }
    .bubble-assistant pre { background: rgba(0,0,0,0.08); border-radius: 6px; padding: 0.6em 0.8em; overflow-x: auto; margin: 0.4em 0; }
    .dark .bubble-assistant pre { background: rgba(0,0,0,0.3); }
    .bubble-assistant pre code { background: none; padding: 0; }
    .bubble-assistant blockquote { border-left: 3px solid rgba(0,0,0,0.18); padding-left: 0.75em; margin: 0.4em 0; opacity: 0.85; }
    .dark .bubble-assistant blockquote { border-left-color: rgba(255,255,255,0.2); }
    .bubble-assistant a { text-decoration: underline; opacity: 0.85; }
    .bubble-assistant strong { font-weight: 700; }
    .bubble-assistant em { font-style: italic; }
    .bubble-assistant hr { border: none; border-top: 1px solid rgba(0,0,0,0.12); margin: 0.5em 0; }
    .bubble-assistant table { border-collapse: collapse; font-size: 0.85em; margin: 0.4em 0; width: 100%; }
    .bubble-assistant th, .bubble-assistant td { border: 1px solid rgba(0,0,0,0.15); padding: 0.25em 0.5em; }
    .dark .bubble-assistant th, .dark .bubble-assistant td { border-color: rgba(255,255,255,0.15); }
    .bubble-assistant th { font-weight: 600; background: rgba(0,0,0,0.05); }

    /* Smooth scrollbar – modal body */
    #modal-body {
        scroll-behavior: smooth;
        scrollbar-width: thin;
        scrollbar-color: rgba(156,163,175,0.5) transparent;
    }
    #modal-body::-webkit-scrollbar { width: 5px; }
    #modal-body::-webkit-scrollbar-track { background: transparent; }
    #modal-body::-webkit-scrollbar-thumb {
        background: rgba(156,163,175,0.45);
        border-radius: 9999px;
        transition: background 0.2s;
    }
    #modal-body::-webkit-scrollbar-thumb:hover { background: rgba(107,114,128,0.7); }
    .dark #modal-body::-webkit-scrollbar-thumb { background: rgba(75,85,99,0.55); }
    .dark #modal-body::-webkit-scrollbar-thumb:hover { background: rgba(107,114,128,0.8); }

    /* Spinner */
    .spin { animation: spin 0.7s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }
</style>
@endpush

@section('content')

    <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">Click a row to view the full message history.</p>

    {{-- ── Search bar ───────────────────────────────────────────────────────── --}}
    <div class="mb-4">
        <div class="relative max-w-sm">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <circle cx="11" cy="11" r="8"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35"/>
            </svg>
            <input id="search-input" type="text" placeholder="Search messages…" class="w-full pl-9 pr-9 py-2 text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-[var(--theme)] focus:border-transparent transition">
            <button id="search-clear" class="absolute right-2.5 top-1/2 -translate-y-1/2 hidden text-gray-400 hover:text-gray-600 dark:hover:text-gray-200" aria-label="Clear search">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
    </div>

    {{-- ── Table card ───────────────────────────────────────────────────────── --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">

        <div id="loading-bar" class="h-0.5 w-full overflow-hidden hidden">
            <div class="h-full animate-pulse" style="background:var(--theme);width:60%;margin-left:20%;border-radius:9999px;"></div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/40 border-b border-gray-200 dark:border-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold text-gray-500 dark:text-gray-400 text-xs uppercase tracking-wide">Thread ID</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-500 dark:text-gray-400 text-xs uppercase tracking-wide">User</th>
                    <th class="px-4 py-3 text-center font-semibold text-gray-500 dark:text-gray-400 text-xs uppercase tracking-wide">Msgs</th>
                    <th class="px-4 py-3 text-left font-semibold text-gray-500 dark:text-gray-400 text-xs uppercase tracking-wide">Last Message</th>
                    <th class="px-4 py-3 text-right font-semibold text-gray-500 dark:text-gray-400 text-xs uppercase tracking-wide">Last Active</th>
                </tr>
                </thead>
                <tbody id="conv-tbody" class="divide-y divide-gray-100 dark:divide-gray-700/50">
                </tbody>
            </table>
        </div>

        <div id="pagination-wrap" class="flex items-center justify-between gap-4 px-4 py-3 border-t border-gray-200 dark:border-gray-700 flex-wrap">
            <p id="pagination-info" class="text-xs text-gray-500 dark:text-gray-400"></p>
            <div id="pagination-btns" class="flex items-center gap-1.5"></div>
        </div>
    </div>

    {{-- ── Message modal ─────────────────────────────────────────────────────── --}}
    <div id="msg-modal-backdrop" style="display:none" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <div id="msg-modal">
            <div class="flex items-start justify-between px-5 py-4 border-b border-gray-200 dark:border-gray-700 shrink-0">
                <div>
                    <h2 id="modal-title" class="font-semibold text-base">Conversation</h2>
                    <p id="modal-meta" class="text-xs text-gray-500 dark:text-gray-400 mt-0.5"></p>
                </div>
                <div class="flex items-center gap-1 ml-4 shrink-0">
                    <button id="modal-copy-btn" title="Copy conversation" class="relative p-1.5 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 transition-colors" aria-label="Copy conversation">
                        <svg id="copy-icon" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <rect x="9" y="2" width="13" height="13" rx="2" ry="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                        </svg>
                        <svg id="copy-check-icon" class="w-5 h-5 hidden text-green-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 6L9 17l-5-5"/>
                        </svg>
                    </button>
                    <button id="modal-close-btn" class="p-1.5 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 transition-colors" aria-label="Close">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>
            <div id="modal-body" class="flex-1 overflow-y-auto px-5 py-4 flex flex-col gap-3"></div>
        </div>
    </div>

@endsection

@push('scripts')
<script>
    const DATA_URL     = @json($dataUrl);
    const MESSAGES_URL = @json($messagesUrl);

    let currentPage   = 1;
    let totalPages    = 1;
    let currentSearch = '';
    let searchTimer   = null;
    let currentConversation = null;

    // ── Fetch & render conversations ────────────────────────────────────────
    async function loadPage(page, search) {
        search = search ?? currentSearch;
        setLoading(true);
        try {
            const params = new URLSearchParams({ page });
            if (search) params.set('search', search);
            const res  = await fetch(`${DATA_URL}?${params}`);
            const json = await res.json();
            renderRows(json.data, search);
            renderPagination(json);
            currentPage   = json.current_page;
            totalPages    = json.last_page;
            currentSearch = search;

            const totalEl = document.getElementById('total-badge');
            totalEl.textContent = `${json.total} conversation${json.total !== 1 ? 's' : ''}`;
        } catch (e) {
            document.getElementById('conv-tbody').innerHTML =
                `<tr><td colspan="5" class="px-4 py-8 text-center text-sm text-red-500">Failed to load conversations. Please refresh.</td></tr>`;
        } finally {
            setLoading(false);
        }
    }

    function setLoading(on) {
        document.getElementById('loading-bar').classList.toggle('hidden', !on);
        if (on) document.getElementById('conv-tbody').innerHTML = skeletonRows(8);
    }

    function skeletonRows(n) {
        return Array.from({length: n}, () => `
            <tr>
                <td class="px-4 py-3"><div class="skeleton w-28"></div></td>
                <td class="px-4 py-3"><div class="skeleton w-16"></div></td>
                <td class="px-4 py-3 text-center"><div class="skeleton w-6 mx-auto"></div></td>
                <td class="px-4 py-3"><div class="skeleton w-48"></div></td>
                <td class="px-4 py-3 text-right"><div class="skeleton w-20 ml-auto"></div></td>
            </tr>`).join('');
    }

    function renderRows(rows, search) {
        const tbody = document.getElementById('conv-tbody');
        if (!rows.length) {
            const msg = search
                ? `No conversations found matching <strong class="font-semibold">"${escHtml(search)}"</strong>.`
                : 'No conversations yet.';
            tbody.innerHTML = `<tr><td colspan="5" class="px-4 py-10 text-center text-sm text-gray-400 dark:text-gray-500">${msg}</td></tr>`;
            return;
        }
        tbody.innerHTML = rows.map(row => {
            const thread  = row.thread_id ? row.thread_id.substring(0, 12) + (row.thread_id.length > 12 ? '…' : '') : '—';
            const user    = row.user_name ? escHtml(row.user_name) : '<span class="text-gray-400 italic">Guest</span>';
            const preview = row.last_preview ? escHtml(row.last_preview) : '<span class="text-gray-400 italic">empty</span>';
            const roleClass = row.last_role === 'user' ? 'badge-blue' : 'badge-gray';
            const roleBadge = row.last_role ? `<span class="badge ${roleClass} mr-1.5">${escHtml(row.last_role)}</span>` : '';
            return `
            <tr class="conv-row" data-id="${row.id}" tabindex="0" role="button" aria-label="View conversation ${thread}">
                <td class="px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-300 whitespace-nowrap">${escHtml(thread)}</td>
                <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-300 whitespace-nowrap">${user}</td>
                <td class="px-4 py-3 text-center text-xs font-semibold">${row.messages_count}</td>
                <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-300 max-w-xs truncate">${roleBadge}${preview}</td>
                <td class="px-4 py-3 text-right text-xs text-gray-400 dark:text-gray-500 whitespace-nowrap">${escHtml(row.updated_at)}</td>
            </tr>`;
        }).join('');

        tbody.querySelectorAll('.conv-row').forEach(tr => {
            tr.addEventListener('click',   () => openModal(+tr.dataset.id));
            tr.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openModal(+tr.dataset.id); } });
        });
    }

    function renderPagination(json) {
        const info = document.getElementById('pagination-info');
        const wrap = document.getElementById('pagination-btns');
        const from = (json.current_page - 1) * json.per_page + 1;
        const to   = Math.min(json.current_page * json.per_page, json.total);
        info.textContent = json.total ? `Showing ${from}–${to} of ${json.total}` : '';
        wrap.innerHTML = '';

        const prev = pageBtn('‹', json.current_page <= 1);
        prev.addEventListener('click', () => loadPage(json.current_page - 1, currentSearch));
        wrap.appendChild(prev);

        const pages = pageWindow(json.current_page, json.last_page);
        let lastP = 0;
        pages.forEach(p => {
            if (p - lastP > 1) {
                const dots = document.createElement('span');
                dots.textContent = '…'; dots.className = 'text-gray-400 text-xs px-1';
                wrap.appendChild(dots);
            }
            const btn = pageBtn(p, false, p === json.current_page);
            btn.addEventListener('click', () => { if (p !== json.current_page) loadPage(p, currentSearch); });
            wrap.appendChild(btn);
            lastP = p;
        });

        const next = pageBtn('›', json.current_page >= json.last_page);
        next.addEventListener('click', () => loadPage(json.current_page + 1, currentSearch));
        wrap.appendChild(next);
    }

    function pageBtn(label, disabled, active = false) {
        const btn = document.createElement('button');
        btn.textContent = label;
        btn.className   = 'page-btn' + (active ? ' active' : '');
        btn.disabled    = disabled;
        return btn;
    }

    function pageWindow(cur, last, delta = 3) {
        const range = new Set([1, last]);
        for (let i = Math.max(1, cur - delta); i <= Math.min(last, cur + delta); i++) range.add(i);
        return [...range].sort((a, b) => a - b);
    }

    // ── Modal ────────────────────────────────────────────────────────────────
    let modalConvId   = null;
    let modalLastPage = 1;
    let modalCurPage  = 1;

    function highlightText(escaped, kw) {
        if (!kw || kw.length < 3) return escaped;
        const safe = kw.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return escaped.replace(new RegExp(`(${safe})`, 'gi'),
            '<mark class="bg-yellow-200 dark:bg-yellow-600/60 rounded-sm px-0.5 text-inherit">$1</mark>');
    }

    function highlightHtml(html, kw) {
        if (!kw || kw.length < 3) return html;
        const safe = kw.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const re = new RegExp(`(${safe})`, 'gi');
        return html.replace(/>([^<]+)</g, (_, text) =>
            '>' + text.replace(re, '<mark class="bg-yellow-200 dark:bg-yellow-600/60 rounded-sm px-0.5 text-inherit">$1</mark>') + '<');
    }

    function renderMessageBubbles(messages, userName) {
        return messages.map(m => {
            const isUser  = m.role === 'user';
            const bubble  = isUser ? 'bubble-user' : 'bubble-assistant';
            const label   = isUser ? userName : 'Assistant';
            const align   = isUser ? 'items-end' : 'items-start';
            const content = isUser
                ? highlightText(escHtml(m.content), currentSearch)
                : highlightHtml(renderMarkdown(m.content), currentSearch);
            return `
            <div class="flex flex-col ${align} gap-0.5">
                <span class="text-[0.65rem] text-gray-400 px-1">${escHtml(label)} · ${escHtml(m.created_at ?? '')}</span>
                <div class="${bubble}">${content}</div>
            </div>`;
        }).join('');
    }

    async function openModal(id) {
        const backdrop = document.getElementById('msg-modal-backdrop');
        const body     = document.getElementById('modal-body');
        const meta     = document.getElementById('modal-meta');
        const title    = document.getElementById('modal-title');

        modalConvId  = id;
        modalCurPage = 1;
        title.textContent = 'Loading…';
        meta.textContent  = '';
        body.innerHTML    = `<div class="flex justify-center py-8"><svg class="spin w-8 h-8 text-gray-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path></svg></div>`;
        backdrop.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        try {
            const url  = MESSAGES_URL.replace('__id__', id) + '?page=1';
            const res  = await fetch(url);
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const json = await res.json();

            modalLastPage = json.last_page ?? 1;
            const userName = json.user_name || (json.user_id ? String(json.user_id) : 'User');
            title.textContent = `Thread: ${json.thread_id ?? '—'}`;
            meta.textContent  = json.user_id ? `User: ${json.user_name || json.user_id}` : 'Guest session';
            currentConversation = { threadId: json.thread_id ?? '—', userName, messages: Array.from(json.messages) };

            if (!json.messages.length) {
                body.innerHTML = `<p class="text-center text-sm text-gray-400 py-8">No messages in this conversation.</p>`;
                return;
            }

            const loadMoreBtn = modalLastPage > 1
                ? `<div id="load-more-wrap" class="flex justify-center py-3"><button onclick="loadMoreMessages()" class="text-xs px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">Load earlier messages (page 1 of ${modalLastPage})</button></div>`
                : '';

            body.innerHTML = loadMoreBtn + renderMessageBubbles(json.messages, userName);
            body.scrollTop = body.scrollHeight;
        } catch (e) {
            body.innerHTML = `<p class="text-center text-sm text-red-500 py-8">Failed to load messages: ${e.message}</p>`;
        }
    }

    async function loadMoreMessages() {
        if (!modalConvId || modalCurPage >= modalLastPage) return;
        modalCurPage++;

        const wrap = document.getElementById('load-more-wrap');
        if (wrap) wrap.innerHTML = `<p class="text-xs text-gray-400 py-2">Loading…</p>`;

        try {
            const url  = MESSAGES_URL.replace('__id__', modalConvId) + `?page=${modalCurPage}`;
            const res  = await fetch(url);
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const json = await res.json();
            const userName = currentConversation?.userName ?? 'User';
            const newHtml  = renderMessageBubbles(json.messages, userName);

            const w = document.getElementById('load-more-wrap');
            if (w) {
                w.outerHTML = (modalCurPage < modalLastPage
                    ? `<div id="load-more-wrap" class="flex justify-center py-3"><button onclick="loadMoreMessages()" class="text-xs px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">Load earlier messages (page ${modalCurPage} of ${modalLastPage})</button></div>`
                    : '') + newHtml;
            }

            if (currentConversation) {
                currentConversation.messages = Array.from(json.messages).concat(currentConversation.messages);
            }
        } catch (e) {
            const w = document.getElementById('load-more-wrap');
            if (w) w.innerHTML = `<p class="text-xs text-red-500 py-2">Failed to load: ${e.message}</p>`;
        }
    }

    function closeModal() {
        document.getElementById('msg-modal-backdrop').style.display = 'none';
        document.body.style.overflow = '';
        currentConversation = null;
        resetCopyIcon();
    }

    function resetCopyIcon() {
        document.getElementById('copy-icon').classList.remove('hidden');
        document.getElementById('copy-check-icon').classList.add('hidden');
    }

    function copyConversation() {
        if (!currentConversation) return;
        const { threadId, userName, messages } = currentConversation;
        const lines = [`Thread: ${threadId}`, `User: ${userName}`, ''];
        messages.forEach(m => {
            const label = m.role === 'user' ? userName : 'Assistant';
            const ts    = m.created_at ? ` ${m.created_at}` : '';
            lines.push(`[${label}]${ts}`);
            lines.push(m.content ?? '');
            lines.push('');
        });
        const text     = lines.join('\n');
        const onSuccess = () => {
            document.getElementById('copy-icon').classList.add('hidden');
            document.getElementById('copy-check-icon').classList.remove('hidden');
            setTimeout(resetCopyIcon, 2000);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(onSuccess).catch(() => fallbackCopy(text, onSuccess));
        } else {
            fallbackCopy(text, onSuccess);
        }
    }

    function fallbackCopy(text, onSuccess) {
        const ta = document.createElement('textarea');
        ta.value = text; ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0';
        document.body.appendChild(ta); ta.focus(); ta.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        if (ok) onSuccess();
    }

    document.getElementById('modal-copy-btn').addEventListener('click', copyConversation);
    document.getElementById('modal-close-btn').addEventListener('click', closeModal);
    document.getElementById('msg-modal-backdrop').addEventListener('click', function (e) { if (e.target === this) closeModal(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

    // ── Helpers ──────────────────────────────────────────────────────────────
    function escHtml(str) {
        if (str == null) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function renderMarkdown(text) {
        if (!text) return '';
        if (typeof marked === 'undefined') return escHtml(text);
        const raw = marked.parse(String(text), { breaks: true, gfm: true });
        return typeof DOMPurify !== 'undefined' ? DOMPurify.sanitize(raw) : raw;
    }

    if (typeof marked !== 'undefined') { marked.setOptions({ breaks: true, gfm: true }); }

    // ── Search wiring ─────────────────────────────────────────────────────────
    const searchInput = document.getElementById('search-input');
    const searchClear = document.getElementById('search-clear');

    searchInput.addEventListener('input', () => {
        const val = searchInput.value;
        searchClear.classList.toggle('hidden', !val);
        clearTimeout(searchTimer);
        const trimmed = val.trim();
        if (trimmed.length > 0 && trimmed.length < 3) return;
        searchTimer = setTimeout(() => loadPage(1, trimmed), 350);
    });

    searchClear.addEventListener('click', () => {
        searchInput.value = '';
        searchClear.classList.add('hidden');
        searchInput.focus();
        loadPage(1, '');
    });

    // ── Init ──────────────────────────────────────────────────────────────────
    loadPage(1);
</script>
@endpush

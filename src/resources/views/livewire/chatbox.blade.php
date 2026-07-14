@once
    @include('ai-chatbox::chatbox-config')
    <link rel="stylesheet" href="{{ asset('vendor/ai-chatbox/css/chatbox.css') }}?v={{ $aiChatboxVersion }}">
    @if(config('ai-chatbox.markdown', true))
    <script src="https://cdn.jsdelivr.net/npm/marked@13.0.3/marked.min.js" integrity="sha384-YTBHtsL8yVTHcLakYNyrOfK3K+QQcXiECuaALJ+3j7Mo681Rtzadt8NR6WrZH+eQ" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3.4.2/dist/purify.min.js" integrity="sha384-AX0sZ/phUL4R6LAFP+mob0mJIWg2c3PX8wPn48ctytOl7XKfRQHbakBt5/QID7uh" crossorigin="anonymous"></script>
    @endif
@endonce

<div
    id="ai-chatbox-wrapper"
    class="ai-chatbox--{{ config('ai-chatbox.position', 'bottom-right') }}"
    x-data="aiChatboxWidget()"
    x-init="init()"
>
    <div id="ai-chatbox-offline-toast"
         role="alert" aria-live="assertive"
         :class="{ visible: toastVisible }"
         x-text="toastMessage">
    </div>

    <button id="ai-chatbox-toggle"
            :disabled="isChecking"
            aria-label="Toggle chat"
            @click="toggleChat()">
        @php
            $aicbRawIcon = config('ai-chatbox.toggle_icon');
            $aicbIconUrl = $aicbRawIcon
                ? (preg_match('#^https?://#i', $aicbRawIcon) ? $aicbRawIcon : asset($aicbRawIcon))
                : null;
        @endphp
        @if($aicbIconUrl)
        <img x-show="!isOpen && !isChecking" src="{{ $aicbIconUrl }}" alt="Chat">
        @else
        <svg x-show="!isOpen && !isChecking" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
            <path d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2z"/>
        </svg>
        @endif
        <svg x-show="isOpen && !isChecking" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
        </svg>
        <svg x-show="isChecking" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <circle cx="12" cy="12" r="10" stroke-opacity=".25"/>
            <path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round">
                <animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="0.8s" repeatCount="indefinite"/>
            </path>
        </svg>
    </button>

    <div id="ai-chatbox-window" :class="{ open: isOpen }" aria-live="polite">

        <div id="ai-chatbox-header">
            <span x-text="title"></span>
            <div class="ai-chatbox-header-actions">
                <button class="ai-chatbox-header-btn" title="New conversation" aria-label="New conversation" @click="newConversation()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/>
                    </svg>
                </button>
                <button class="ai-chatbox-header-btn" title="Clear conversation" aria-label="Clear conversation" @click="clearConversation()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/>
                    </svg>
                </button>
            </div>
        </div>

        <div id="ai-chatbox-messages" x-ref="msgs">
            <template x-for="(msg, i) in messages" :key="i">
                <div :class="msgClass(msg)" x-html="msgContent(msg)"></div>
            </template>
            <div x-show="isTyping" class="ai-chatbox-typing">
                <span></span><span></span><span></span>
            </div>
        </div>

        <form id="ai-chatbox-form" autocomplete="off" @submit.prevent="sendMessage()">
            <textarea id="ai-chatbox-input"
                   x-ref="inputEl"
                   x-model="inputText"
                   :placeholder="placeholder"
                   maxlength="2000"
                   :disabled="isLoading"
                   rows="1"
                   required
                   @input="autoResize($el)"
                   @keydown.enter.exact.prevent="sendMessage()"></textarea>
            <button type="submit" id="ai-chatbox-send" aria-label="Send" :disabled="isLoading">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                </svg>
            </button>
        </form>
    </div>
</div>

<script>
function aiChatboxWidget() {
    var cfg         = window.AiChatboxConfig || {};
    var url         = cfg.url;
    var streamUrl   = cfg.streamUrl;
    var clearUrl    = cfg.clearUrl;
    var healthUrl   = cfg.healthUrl;
    var streamOn    = cfg.stream !== false && !!cfg.streamUrl && typeof ReadableStream !== 'undefined';
    var STORAGE_KEY = cfg.storageKey || 'ai_chatbox_ui';
    var THREAD_KEY  = STORAGE_KEY + '_tid';
    var storage     = cfg.storageType === 'session' ? sessionStorage : localStorage;
    var toastTimer  = null;
    var audioCtx    = null;
    var AudioCtx    = window.AudioContext || window.webkitAudioContext;

    return {
        title:        cfg.title        || 'AI Assistant',
        placeholder:  cfg.placeholder  || 'Type your message...',
        greeting:     cfg.greeting     || '',
        markdownOn:   cfg.markdown     !== false,
        soundOn:      cfg.sound        !== false,
        soundVolume:  typeof cfg.soundVolume === 'number' ? cfg.soundVolume : 0.4,
        healthCheck:  cfg.healthCheck  !== false,
        offlineMsg:   cfg.offlineMessage || 'AI service is currently unreachable.',

        isOpen:        false,
        isChecking:    false,
        isLoading:     false,
        isTyping:      false,
        greetingShown: false,
        messages:      [],
        inputText:     '',
        toastMessage:  '',
        toastVisible:  false,
        threadId:      '',

        init() {
            if (cfg.themeColor) {
                document.documentElement.style.setProperty('--chatbox-color', cfg.themeColor);
            }
            (function (wrap) {
                var scheme = cfg.colorScheme;
                if (scheme !== 'light' && scheme !== 'dark') return;
                var d = scheme === 'dark';
                wrap.style.setProperty('--chatbox-shadow',    d ? '0 8px 32px rgba(0,0,0,0.5)'  : '0 8px 32px rgba(0,0,0,0.18)');
                wrap.style.setProperty('--chatbox-bg',        d ? '#1e1e2e' : '#ffffff');
                wrap.style.setProperty('--chatbox-msg-bg',    d ? '#2a2a3d' : '#f3f4f6');
                wrap.style.setProperty('--chatbox-text',      d ? '#e2e8f0' : '#111827');
                wrap.style.setProperty('--chatbox-muted',     d ? '#94a3b8' : '#6b7280');
                wrap.style.setProperty('--chatbox-border',    d ? '#374151' : '#e5e7eb');
                wrap.style.setProperty('--chatbox-input-bg',  d ? '#2a2a3d' : '#f9fafb');
                wrap.style.setProperty('--chatbox-scrollbar', d ? '#4b5563' : '#d1d5db');
            }(this.$el));
            this.threadId = this.loadOrCreateThreadId();
            this.loadFromStorage();
            this.$nextTick(() => this.scrollToBottom());
        },

        // ── UUID ──
        generateUUID() {
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
                var r = Math.random() * 16 | 0;
                return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
            });
        },
        loadOrCreateThreadId() {
            try { var s = storage.getItem(THREAD_KEY); if (s) return s; } catch (_) {}
            var id = this.generateUUID();
            try { storage.setItem(THREAD_KEY, id); } catch (_) {}
            return id;
        },

        // ── Storage ──
        saveToStorage() {
            try {
                storage.setItem(STORAGE_KEY, JSON.stringify(
                    this.messages.filter(m => m.role !== 'error')
                ));
            } catch (_) {}
        },
        loadFromStorage() {
            try {
                var stored = JSON.parse(storage.getItem(STORAGE_KEY) || 'null');
                if (!Array.isArray(stored) || stored.length === 0) return;
                this.messages = stored;
                if (this.greeting && stored[0] && stored[0].role === 'ai' && stored[0].text === this.greeting) {
                    this.greetingShown = true;
                }
            } catch (_) {}
        },

        // ── CSRF ──
        getCsrfHeaders() {
            var m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
            if (m) return { 'X-XSRF-TOKEN': decodeURIComponent(m[1]) };
            var meta = document.querySelector('meta[name="csrf-token"]');
            return { 'X-CSRF-TOKEN': (meta && meta.getAttribute('content')) || cfg.token };
        },

        // ── Message rendering ──
        escapeHtml(str) {
            return String(str)
                .replace(/&/g,'&amp;').replace(/</g,'&lt;')
                .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        },
        msgClass(msg) {
            return ['ai-chatbox-msg', msg.role, msg.streaming ? 'ai-chatbox-streaming' : '']
                .filter(Boolean).join(' ');
        },
        msgContent(msg) {
            if (msg.role === 'ai' && this.markdownOn && !msg.streaming
                    && typeof window.marked !== 'undefined'
                    && typeof window.DOMPurify !== 'undefined') {
                return window.DOMPurify.sanitize(window.marked.parse(msg.text));
            }
            return this.escapeHtml(msg.text);
        },

        // ── Scroll ──
        scrollToBottom() {
            var el = this.$refs.msgs;
            if (el) el.scrollTop = el.scrollHeight;
        },

        // ── Toast ──
        showToast(msg) {
            this.toastMessage = msg;
            this.toastVisible = true;
            if (toastTimer) clearTimeout(toastTimer);
            toastTimer = setTimeout(() => { this.toastVisible = false; }, 4000);
        },

        // ── Sound ──
        ping() {
            if (!this.soundOn || !AudioCtx) return;
            try {
                if (!audioCtx) audioCtx = new AudioCtx();
                var ctx = audioCtx, gain = ctx.createGain();
                gain.connect(ctx.destination);
                gain.gain.setValueAtTime(this.soundVolume, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.4);
                var osc = ctx.createOscillator();
                osc.connect(gain); osc.type = 'sine';
                osc.frequency.setValueAtTime(880, ctx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(660, ctx.currentTime + 0.15);
                osc.start(ctx.currentTime); osc.stop(ctx.currentTime + 0.4);
            } catch (_) {}
        },

        // ── HTTP ──
        postJson(endpoint, payload) {
            var headers = Object.assign(
                { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                this.getCsrfHeaders()
            );
            return fetch(endpoint, { method: 'POST', headers, body: JSON.stringify(payload) })
                .then(r => r.ok ? r.json() : r.json().then(d => Promise.reject(d)));
        },
        getJson(endpoint) {
            var headers = Object.assign({ 'Accept': 'application/json' }, this.getCsrfHeaders());
            return fetch(endpoint, { headers })
                .then(r => r.ok ? r.json() : r.json().then(d => Promise.reject(d)));
        },

        // ── Open / close ──
        openWindow() {
            this.isOpen = true;
            if (!this.greetingShown && this.greeting) {
                this.messages.push({ role: 'ai', text: this.greeting });
                this.greetingShown = true;
                this.saveToStorage();
            }
            this.$nextTick(() => {
                if (this.$refs.inputEl) this.$refs.inputEl.focus();
                this.scrollToBottom();
            });
        },
        async toggleChat() {
            if (this.isOpen) { this.isOpen = false; return; }
            if (!this.healthCheck) { this.openWindow(); return; }
            if (this.isChecking) return;
            this.isChecking = true;
            try {
                await this.getJson(healthUrl);
                this.openWindow();
            } catch (err) {
                this.showToast((err && err.message) ? err.message : this.offlineMsg);
            } finally {
                this.isChecking = false;
            }
        },

        // ── Clear / New ──
        clearConversation() {
            this.postJson(clearUrl, { thread_id: this.threadId }).catch(() => {});
            this.messages = [];
            try { storage.removeItem(STORAGE_KEY); } catch (_) {}
            this.greetingShown = false;
            if (this.greeting) {
                this.messages.push({ role: 'ai', text: this.greeting });
                this.greetingShown = true;
                this.saveToStorage();
            }
        },
        newConversation() {
            this.threadId = this.generateUUID();
            try { storage.setItem(THREAD_KEY, this.threadId); } catch (_) {}
            this.messages = [];
            try { storage.removeItem(STORAGE_KEY); } catch (_) {}
            this.greetingShown = false;
            if (this.greeting) {
                this.messages.push({ role: 'ai', text: this.greeting });
                this.greetingShown = true;
                this.saveToStorage();
            }
        },

        // ── Textarea auto-resize ──
        autoResize(el) {
            el.style.height = 'auto';
            var lineH = parseFloat(getComputedStyle(el).lineHeight) || 20;
            var maxH  = lineH * 3 + 18;
            el.style.height = Math.min(el.scrollHeight, maxH) + 'px';
            el.style.overflowY = el.scrollHeight > maxH ? 'auto' : 'hidden';
        },
        resetInputHeight() {
            var el = this.$refs.inputEl;
            if (el) { el.style.height = 'auto'; el.style.overflowY = 'hidden'; }
        },

        // ── Send ──
        async sendMessage() {
            var text = this.inputText.trim();
            if (!text) return;
            this.messages.push({ role: 'user', text });
            this.saveToStorage();
            this.inputText = '';
            this.resetInputHeight();
            this.isLoading = true;
            this.isTyping  = true;
            this.$nextTick(() => this.scrollToBottom());

            if (streamOn) {
                await this.sendStreaming(text);
            } else {
                await this.sendFallback(text);
            }
        },

        // ── Streaming ──
        async sendStreaming(text) {
            this.isTyping = false;
            this.messages.push({ role: 'ai', text: '', streaming: true });
            var idx = this.messages.length - 1;

            try {
                var headers = Object.assign(
                    { 'Content-Type': 'application/json', 'Accept': 'text/event-stream' },
                    this.getCsrfHeaders()
                );
                var response = await fetch(streamUrl, {
                    method: 'POST', headers,
                    body: JSON.stringify({ message: text, thread_id: this.threadId })
                });

                if (!response.ok) {
                    var d = await response.json().catch(() => ({}));
                    this.messages[idx] = { role: 'error', text: (d && d.error) || 'Something went wrong. Please try again.' };
                    return;
                }

                var reader = response.body.getReader();
                var decoder = new TextDecoder();
                var buffer = '';
                var done = false;

                while (!done) {
                    var { done: rdone, value } = await reader.read();
                    if (rdone) break;
                    buffer += decoder.decode(value, { stream: true });
                    var parts = buffer.split('\n\n');
                    buffer = parts.pop();

                    for (var part of parts) {
                        var dataLine = part.split('\n').find(l => l.startsWith('data: '));
                        if (!dataLine) continue;
                        var raw = dataLine.slice(6);
                        if (raw === '[DONE]') { done = true; break; }
                        try {
                            var json = JSON.parse(raw);
                            if (json.token) {
                                this.messages[idx].text += json.token;
                                this.$nextTick(() => this.scrollToBottom());
                            }
                        } catch (_) {}
                    }
                }

                var finalText = this.messages[idx].text;
                this.messages[idx] = { role: 'ai', text: finalText, streaming: false };
                if (finalText) { this.saveToStorage(); this.ping(); }
                else { this.messages[idx] = { role: 'error', text: 'No response received. Please try again.' }; }

            } catch (_) {
                this.messages[idx] = { role: 'error', text: 'Network error. Please check your connection.' };
            } finally {
                this.isLoading = false;
                this.$nextTick(() => this.scrollToBottom());
            }
        },

        // ── Fallback POST ──
        async sendFallback(text) {
            try {
                var data = await this.postJson(url, { message: text, thread_id: this.threadId });
                this.isTyping = false;
                this.messages.push({ role: 'ai', text: data.reply || 'No response.' });
                this.saveToStorage();
                this.ping();
            } catch (err) {
                this.isTyping = false;
                this.messages.push({ role: 'error', text: (err && err.error) || 'Something went wrong. Please try again.' });
            } finally {
                this.isLoading = false;
                this.$nextTick(() => this.scrollToBottom());
            }
        },
    };
}
</script>

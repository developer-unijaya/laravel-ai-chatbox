@php
    $scheme     = $colorScheme ?? 'auto';
    $themeColor = $themeColor  ?? '#0dad35';
@endphp
<!DOCTYPE html>
<html lang="en" @if($scheme === 'dark') class="dark" @endif>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') - AI Chatbox</title>
    {{--
        Tailwind Play CDN (pinned to 3.4.17). This is a convenience for the admin
        panel and is Tailwind's own "not for production" build (it JIT-compiles CSS
        in the browser). It CANNOT carry an SRI `integrity` hash: cdn.tailwindcss.com
        serves no `Access-Control-Allow-Origin` header, so adding `crossorigin` +
        `integrity` would make the browser block the script and break the panel.
        For a hardened / offline deployment, self-host a compiled admin stylesheet
        and replace this tag — see the "Admin panel assets" note in the README.
    --}}
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    <script>tailwind.config = { darkMode: 'class' }</script>
    @stack('head')
    @if($scheme === 'auto')
    <script>
        (function () {
            var mq = window.matchMedia('(prefers-color-scheme: dark)');
            function apply() { document.documentElement.classList.toggle('dark', mq.matches); }
            apply();
            mq.addEventListener('change', apply);
        })();
    </script>
    @endif
    <style>
        :root { --theme: {{ $themeColor }}; }

        /* ── Sidebar navigation ─────────────────────────────────────────────── */
        .nav-link {
            display: flex; align-items: center; gap: 0.625rem;
            padding: 0.5rem 0.75rem; border-radius: 0.5rem;
            font-size: 0.875rem; font-weight: 500;
            color: #4b5563;
            transition: background-color 0.15s, color 0.15s;
            text-decoration: none;
        }
        .dark .nav-link { color: #9ca3af; }
        .nav-link:hover { background-color: #f3f4f6; color: #111827; }
        .dark .nav-link:hover { background-color: rgba(55,65,81,0.5); color: #f3f4f6; }
        .nav-link.active {
            background-color: color-mix(in srgb, var(--theme) 12%, transparent);
            color: var(--theme);
        }

        /* ── Shared buttons ─────────────────────────────────────────────────── */
        .btn-primary {
            background-color: var(--theme); color: #fff;
            display: inline-flex; align-items: center; gap: 0.5rem;
            border-radius: 0.5rem; padding: 0.5rem 1.125rem;
            font-size: 0.875rem; font-weight: 500;
            transition: filter 0.15s, opacity 0.15s;
            border: none; cursor: pointer; text-decoration: none;
        }
        .btn-primary:hover:not(:disabled) { filter: brightness(0.88); }
        .btn-primary:focus { outline: 2px solid var(--theme); outline-offset: 2px; }
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }

        .btn-secondary {
            display: inline-flex; align-items: center; gap: 0.5rem;
            border-radius: 0.5rem; padding: 0.5rem 1.25rem;
            font-size: 0.875rem; font-weight: 500;
            color: var(--theme);
            background-color: color-mix(in srgb, var(--theme) 10%, transparent);
            transition: background-color 0.15s; text-decoration: none;
            border: 1px solid color-mix(in srgb, var(--theme) 25%, transparent);
        }
        .btn-secondary:hover { background-color: color-mix(in srgb, var(--theme) 18%, transparent); }

        /* ── Shared utilities ───────────────────────────────────────────────── */
        .stat-card { border-left: 3px solid var(--theme); }

        .section-heading {
            font-size: 0.7rem; font-weight: 700; letter-spacing: 0.08em;
            text-transform: uppercase; color: var(--theme);
        }

        .config-key { font-family: ui-monospace, 'Cascadia Code', monospace; font-size: 0.8rem; }
        .config-val { font-family: ui-monospace, 'Cascadia Code', monospace; font-size: 0.8rem; word-break: break-all; }

        /* ── Badges ─────────────────────────────────────────────────────────── */
        .badge { display: inline-flex; align-items: center; padding: 0.125rem 0.5rem; border-radius: 9999px; font-size: 0.7rem; font-weight: 600; }
        .badge-green  { background: #dcfce7; color: #166534; }
        .badge-red    { background: #fee2e2; color: #991b1b; }
        .badge-yellow { background: #fef9c3; color: #854d0e; }
        .badge-blue   { background: #dbeafe; color: #1e40af; }
        .badge-gray   { background: #f3f4f6; color: #374151; }
        .dark .badge-green  { background: #14532d; color: #86efac; }
        .dark .badge-red    { background: #7f1d1d; color: #fca5a5; }
        .dark .badge-yellow { background: #713f12; color: #fde68a; }
        .dark .badge-blue   { background: #1e3a5f; color: #93c5fd; }
        .dark .badge-gray   { background: #374151; color: #d1d5db; }
    </style>
    @stack('styles')
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-gray-100 antialiased">

<div class="flex min-h-screen">

    {{-- ── Sidebar ─────────────────────────────────────────────────────────── --}}
    <aside id="sidebar" class="fixed inset-y-0 left-0 z-30 w-64 flex flex-col bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 -translate-x-full md:translate-x-0 transition-transform duration-200 ease-in-out">

        {{-- Brand --}}
        <div class="flex items-center gap-2.5 px-5 py-[1.125rem] border-b border-gray-100 dark:border-gray-700 shrink-0">
            <svg class="w-5 h-5 shrink-0" style="color:var(--theme)" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23-.693L5 14.5m14.8.8l1.402 1.402c1 1 .03 2.716-1.42 2.416L12 17.25l-7.782 1.468c-1.45.3-2.42-1.416-1.42-2.416L4.2 15.3" />
            </svg>
            <div class="min-w-0">
                <p class="font-semibold text-sm leading-tight truncate">AI Chatbox</p>
                <p class="text-[0.65rem] text-gray-400 dark:text-gray-500 leading-tight mt-0.5">Admin Panel</p>
            </div>
        </div>

        {{-- Navigation --}}
        <nav class="flex-1 px-3 py-4 space-y-0.5 overflow-y-auto">
            <p class="px-3 mb-2 text-[0.6rem] font-bold uppercase tracking-widest text-gray-400 dark:text-gray-500">Menu</p>

            <a href="{{ route('ai-chatbox.admin.index') }}" class="nav-link {{ request()->routeIs('ai-chatbox.admin.index') ? 'active' : '' }}">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
                </svg>
                Dashboard
            </a>

            <a href="{{ route('ai-chatbox.admin.conversations') }}" class="nav-link {{ request()->routeIs('ai-chatbox.admin.conversations') ? 'active' : '' }}">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
                </svg>
                Conversations
            </a>

            <a href="{{ route('ai-chatbox.rag.index') }}" class="nav-link {{ request()->routeIs('ai-chatbox.rag.*') ? 'active' : '' }}">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
                Knowledge Base
            </a>
        </nav>

        {{-- Sidebar footer --}}
        <div class="px-5 py-3 border-t border-gray-100 dark:border-gray-700 shrink-0">
            <a href="/" class="flex items-center gap-1.5 text-xs text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                </svg>
                App Home
            </a>
        </div>
    </aside>

    {{-- Mobile overlay --}}
    <div id="sidebar-overlay" class="fixed inset-0 z-20 bg-black/50 hidden md:hidden" onclick="closeSidebar()">
    </div>

    {{-- ── Main area ─────────────────────────────────────────────────────────── --}}
    <div class="flex flex-1 flex-col md:ml-64 min-w-0">

        {{-- ── Top navbar ──────────────────────────────────────────────────────── --}}
        <header class="sticky top-0 z-10 h-14 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 flex items-center gap-3 px-5 shrink-0">

            {{-- Mobile hamburger --}}
            <button onclick="openSidebar()" class="md:hidden p-1.5 -ml-1 rounded-lg text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors" aria-label="Open navigation">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                </svg>
            </button>

            {{-- Breadcrumb --}}
            <div class="flex items-center gap-2 text-sm min-w-0 flex-1">
                <span class="text-gray-400 dark:text-gray-500 hidden sm:inline shrink-0">AI Chatbox</span>
                <svg class="w-3.5 h-3.5 text-gray-300 dark:text-gray-600 hidden sm:block shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                </svg>
                <span class="font-semibold text-gray-800 dark:text-gray-100 truncate">
                    @yield('page-title', 'Admin')
                </span>
            </div>

            {{-- Right slot --}}
            <div class="flex items-center gap-3 shrink-0 text-xs text-gray-500 dark:text-gray-400">
                @yield('navbar-right')
            </div>
        </header>

        {{-- ── Flash messages ───────────────────────────────────────────────────── --}}
        @if(session('success'))
        <div class="mx-5 mt-4 flex items-center gap-2 rounded-lg bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 px-4 py-3 text-sm text-green-800 dark:text-green-300">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
            </svg>
            {{ session('success') }}
        </div>
        @endif
        @if(session('error'))
        <div class="mx-5 mt-4 flex items-center gap-2 rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-800 dark:text-red-300">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15.75h.007v.008H12v-.008z"/>
            </svg>
            {{ session('error') }}
        </div>
        @endif

        {{-- ── Page content ─────────────────────────────────────────────────────── --}}
        <main class="flex-1 px-5 py-6 min-w-0">
            @yield('content')
        </main>

        {{-- ── Footer ───────────────────────────────────────────────────────────── --}}
        <footer class="px-5 py-3 border-t border-gray-100 dark:border-gray-700/50 shrink-0">
            <p class="text-[0.7rem] text-gray-400 dark:text-gray-600 text-center">
                AI Chatbox Admin &mdash;
                <a href="https://github.com/developer-unijaya/laravel-ai-chatbox" class="underline hover:text-gray-600 dark:hover:text-gray-400 transition-colors" target="_blank" rel="noopener noreferrer">developer-unijaya/laravel-ai-chatbox</a>
            </p>
        </footer>

    </div>{{-- end main --}}

</div>{{-- end flex wrapper --}}

<script>
    function openSidebar() {
        document.getElementById('sidebar').classList.remove('-translate-x-full');
        document.getElementById('sidebar-overlay').classList.remove('hidden');
    }
    function closeSidebar() {
        document.getElementById('sidebar').classList.add('-translate-x-full');
        document.getElementById('sidebar-overlay').classList.add('hidden');
    }
</script>

@stack('scripts')
</body>
</html>

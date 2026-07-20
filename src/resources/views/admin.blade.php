@extends('ai-chatbox::admin-layout')

@php $activeProvider = $configGroups['AI API']['active_provider'] ?? 'default'; @endphp

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('navbar-right')
    Laravel {{ $env['laravel'] }} &nbsp;·&nbsp; PHP {{ $env['php'] }} &nbsp;·&nbsp;
    <span class="{{ $env['app_debug'] ? 'text-amber-600 dark:text-amber-400 font-medium' : '' }}">
        {{ $env['app_env'] }}{{ $env['app_debug'] ? ' (debug)' : '' }}
    </span>
@endsection

@section('content')

    {{-- ── Stat cards row 1 — RAG + Memory ─────────────────────────────────── --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-4">

        {{-- RAG stats --}}
        @if($ragEnabled && $ragStats)
        <a href="{{ $ragUrl }}" class="stat-card bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm block hover:shadow-md hover:brightness-[0.97] dark:hover:brightness-110 transition-all" title="Open Knowledge Base">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Documents</p>
            <p class="text-2xl font-bold">{{ $ragStats['documents'] }}</p>
            <p class="text-xs mt-1 flex flex-wrap gap-x-2">
                <span class="text-green-600 dark:text-green-400">{{ $ragStats['documents_ready'] }} ready</span>
                @if($ragStats['documents_processing'] > 0)
                    <span class="text-blue-600 dark:text-blue-400">{{ $ragStats['documents_processing'] }} processing</span>
                @endif
                @if($ragStats['documents_failed'] > 0)
                    <span class="text-red-600 dark:text-red-400">{{ $ragStats['documents_failed'] }} failed</span>
                @endif
            </p>
        </a>
        <a href="{{ $ragUrl }}" class="stat-card bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm block hover:shadow-md hover:brightness-[0.97] dark:hover:brightness-110 transition-all" title="Open Knowledge Base">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Chunks</p>
            <p class="text-2xl font-bold">{{ $ragStats['total_chunks'] }}</p>
            <p class="text-xs mt-1">
                @if($ragStats['null_chunks'] > 0)
                    <span class="text-red-600 dark:text-red-400">{{ $ragStats['null_chunks'] }} missing embeddings</span>
                @else
                    <span class="text-green-600 dark:text-green-400">all embedded</span>
                @endif
            </p>
        </a>
        @else
        <div class="stat-card bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm col-span-2">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">RAG</p>
            <p class="text-sm text-gray-500 dark:text-gray-400">Disabled — set <code class="config-key">AI_CHATBOX_RAG=true</code> to enable</p>
        </div>
        @endif

        {{-- Memory stats --}}
        @if($memoryStats && !isset($memoryStats['error']))
        <a href="{{ $conversationsUrl }}" class="stat-card bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm block hover:shadow-md hover:brightness-[0.97] dark:hover:brightness-110 transition-all" title="View conversations">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Conversations</p>
            <p class="text-2xl font-bold">{{ $memoryStats['conversations'] }}</p>
            <p class="text-xs text-gray-400 mt-1">database driver</p>
        </a>
        <div class="stat-card bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Messages</p>
            <p class="text-2xl font-bold">{{ $memoryStats['messages'] }}</p>
            <p class="text-xs text-gray-400 mt-1">across all threads</p>
        </div>
        @elseif($memoryStats && isset($memoryStats['error']))
        <div class="stat-card bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm col-span-2">
            <p class="text-xs text-red-500 mb-1">Memory DB Error</p>
            <p class="text-xs text-gray-500">{{ $memoryStats['error'] }}</p>
        </div>
        @else
        <div class="stat-card bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm col-span-2">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Memory Driver</p>
            <p class="text-sm font-medium">Session</p>
            <p class="text-xs text-gray-400 mt-1">history not persisted to DB</p>
        </div>
        @endif
    </div>

    {{-- ── Stat cards row 2 — Activity (database memory only) ─────────────── --}}
    @if(!empty($activityStats))
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">

        <div class="stat-card bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Messages Today</p>
            <p class="text-2xl font-bold">{{ $activityStats['messages_today'] }}</p>
            <p class="text-xs text-gray-400 mt-1">since midnight</p>
        </div>

        <div class="stat-card bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Messages This Week</p>
            <p class="text-2xl font-bold">{{ $activityStats['messages_week'] }}</p>
            <p class="text-xs text-gray-400 mt-1">since {{ now()->startOfWeek()->format('D d M') }}</p>
        </div>

        <div class="stat-card bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Avg Msgs / Conv</p>
            <p class="text-2xl font-bold">{{ $activityStats['avg_messages'] }}</p>
            <p class="text-xs text-gray-400 mt-1">all time average</p>
        </div>

        <div class="stat-card bg-white dark:bg-gray-800 rounded-xl p-4 shadow-sm">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Auth / Guest</p>
            <p class="text-2xl font-bold">{{ $activityStats['auth_conversations'] }}<span class="text-base font-normal text-gray-400"> / {{ $activityStats['guest_conversations'] }}</span></p>
            <p class="text-xs text-gray-400 mt-1">authenticated vs guest</p>
        </div>

    </div>
    @else
    <div class="mb-8"></div>
    @endif

    {{-- ── Diagnostics panel ────────────────────────────────────────────────── --}}
    @php
        $diagErrors   = collect($diagnostics)->where('level', 'error');
        $diagWarnings = collect($diagnostics)->where('level', 'warning');
        $diagInfos    = collect($diagnostics)->where('level', 'info');
    @endphp

    <div class="flex items-center justify-between mb-3">
        <span class="section-heading">Diagnostics</span>
        <span class="text-[0.65rem] text-gray-400 dark:text-gray-500">Checked at {{ $diagCheckedAt }}</span>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">

        {{-- Errors --}}
        <div class="rounded-xl border overflow-hidden flex flex-col {{ $diagErrors->isNotEmpty() ? 'border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800' }}">
            <div class="flex items-center gap-2 px-4 py-2.5 border-b {{ $diagErrors->isNotEmpty() ? 'bg-red-100 dark:bg-red-900/40 border-red-200 dark:border-red-800' : 'border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-700/30' }}">
                <svg class="w-4 h-4 shrink-0 {{ $diagErrors->isNotEmpty() ? 'text-red-600 dark:text-red-400' : 'text-gray-400 dark:text-gray-500' }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9.303 3.376c.866 1.5-.217 3.374-1.948 3.374H4.645c-1.73 0-2.813-1.874-1.948-3.374L10.05 3.378c.866-1.5 3.032-1.5 3.898 0l5.355 9.748z"/>
                </svg>
                <span class="text-sm font-semibold {{ $diagErrors->isNotEmpty() ? 'text-red-700 dark:text-red-300' : 'text-gray-500 dark:text-gray-400' }}">
                    {{ $diagErrors->count() }} Error{{ $diagErrors->count() !== 1 ? 's' : '' }}
                </span>
            </div>
            @if($diagErrors->isNotEmpty())
            <ul class="divide-y divide-red-100 dark:divide-red-800/50 flex-1">
                @foreach($diagErrors->groupBy('group') as $group => $items)
                    @foreach($items as $d)
                    <li class="flex items-start gap-3 px-4 py-3 text-sm">
                        <span class="badge badge-red mt-0.5 shrink-0">{{ $group }}</span>
                        <span class="text-red-700 dark:text-red-300">{{ $d['message'] }}</span>
                    </li>
                    @endforeach
                @endforeach
            </ul>
            @else
            <div class="flex flex-col items-center justify-center px-4 py-8 flex-1 text-center">
                <svg class="w-6 h-6 text-green-400 dark:text-green-500 mb-1.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                </svg>
                <span class="text-sm text-gray-400 dark:text-gray-500">No errors</span>
            </div>
            @endif
        </div>

        {{-- Warnings --}}
        <div class="rounded-xl border overflow-hidden flex flex-col {{ $diagWarnings->isNotEmpty() ? 'border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800' }}">
            <div class="flex items-center gap-2 px-4 py-2.5 border-b {{ $diagWarnings->isNotEmpty() ? 'bg-amber-100 dark:bg-amber-900/40 border-amber-200 dark:border-amber-800' : 'border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-700/30' }}">
                <svg class="w-4 h-4 shrink-0 {{ $diagWarnings->isNotEmpty() ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400 dark:text-gray-500' }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126z"/>
                </svg>
                <span class="text-sm font-semibold {{ $diagWarnings->isNotEmpty() ? 'text-amber-700 dark:text-amber-300' : 'text-gray-500 dark:text-gray-400' }}">
                    {{ $diagWarnings->count() }} Warning{{ $diagWarnings->count() !== 1 ? 's' : '' }}
                </span>
            </div>
            @if($diagWarnings->isNotEmpty())
            <ul class="divide-y divide-amber-100 dark:divide-amber-800/50 flex-1">
                @foreach($diagWarnings->groupBy('group') as $group => $items)
                    @foreach($items as $d)
                    <li class="flex items-start gap-3 px-4 py-3 text-sm">
                        <span class="badge badge-yellow mt-0.5 shrink-0">{{ $group }}</span>
                        <span class="text-amber-700 dark:text-amber-300">{{ $d['message'] }}</span>
                    </li>
                    @endforeach
                @endforeach
            </ul>
            @else
            <div class="flex flex-col items-center justify-center px-4 py-8 flex-1 text-center">
                <svg class="w-6 h-6 text-green-400 dark:text-green-500 mb-1.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                </svg>
                <span class="text-sm text-gray-400 dark:text-gray-500">No warnings</span>
            </div>
            @endif
        </div>

        {{-- Notices --}}
        <div class="rounded-xl border overflow-hidden flex flex-col {{ $diagInfos->isNotEmpty() ? 'border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800' }}">
            <div class="flex items-center gap-2 px-4 py-2.5 border-b {{ $diagInfos->isNotEmpty() ? 'bg-blue-100 dark:bg-blue-900/40 border-blue-200 dark:border-blue-800' : 'border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-700/30' }}">
                <svg class="w-4 h-4 shrink-0 {{ $diagInfos->isNotEmpty() ? 'text-blue-600 dark:text-blue-400' : 'text-gray-400 dark:text-gray-500' }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/>
                </svg>
                <span class="text-sm font-semibold {{ $diagInfos->isNotEmpty() ? 'text-blue-700 dark:text-blue-300' : 'text-gray-500 dark:text-gray-400' }}">
                    {{ $diagInfos->count() }} Notice{{ $diagInfos->count() !== 1 ? 's' : '' }}
                </span>
            </div>
            @if($diagInfos->isNotEmpty())
            <ul class="divide-y divide-blue-100 dark:divide-blue-800/50 flex-1">
                @foreach($diagInfos->groupBy('group') as $group => $items)
                    @foreach($items as $d)
                    <li class="flex items-start gap-3 px-4 py-3 text-sm">
                        <span class="badge badge-blue mt-0.5 shrink-0">{{ $group }}</span>
                        <span class="text-blue-700 dark:text-blue-300">{{ $d['message'] }}</span>
                    </li>
                    @endforeach
                @endforeach
            </ul>
            @else
            <div class="flex flex-col items-center justify-center px-4 py-8 flex-1 text-center">
                <svg class="w-6 h-6 text-green-400 dark:text-green-500 mb-1.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                </svg>
                <span class="text-sm text-gray-400 dark:text-gray-500">No notices</span>
            </div>
            @endif
        </div>

    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- ── Left column — config groups + recent conversations ─────────── --}}
        <div class="lg:col-span-2 space-y-5">

            @foreach($configGroups as $groupName => $keys)
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-700">
                    <span class="section-heading">{{ $groupName }}</span>
                </div>
                <div class="divide-y divide-gray-50 dark:divide-gray-700/60">
                    @foreach($keys as $key => $val)
                    <div class="flex items-start gap-3 px-5 py-3">
                        <span class="config-key text-gray-500 dark:text-gray-400 shrink-0 w-52">{{ $key }}</span>
                        <span class="config-val text-gray-800 dark:text-gray-200 flex-1">
                            @if(is_null($val))
                                <span class="text-gray-400 dark:text-gray-500 italic">null</span>
                            @elseif(is_bool($val))
                                <span class="badge {{ $val ? 'badge-green' : 'badge-gray' }}">{{ $val ? 'true' : 'false' }}</span>
                            @elseif(is_array($val))
                                {{ implode(', ', $val) }}
                            @elseif($key === 'system_prompt' || $key === 'rag_context_prompt')
                                <span class="line-clamp-2 text-gray-600 dark:text-gray-400">{{ $val }}</span>
                            @elseif($key === 'active_provider')
                                <span class="badge badge-green">{{ $val ?: 'default' }}</span>
                            @elseif($key === 'rag_embedding_url')
                                <span class="break-all">{{ $val ?: '—' }}</span>
                            @elseif($key === 'toggle_icon')
                                @php $resolvedIcon = preg_match('#^https?://#i', $val) ? $val : asset($val); @endphp
                                <span class="flex items-center gap-2 flex-wrap">
                                    <span class="break-all text-gray-600 dark:text-gray-400">{{ $val }}</span>
                                    <img src="{{ $resolvedIcon }}" alt="toggle icon preview"
                                         class="w-6 h-6 rounded object-contain border border-gray-200 dark:border-gray-600 bg-indigo-500 p-0.5 shrink-0"
                                         onerror="this.style.display='none'">
                                </span>
                            @else
                                {{ $val }}
                            @endif
                        </span>
                    </div>
                    @endforeach
                </div>
            </div>
            @endforeach

        </div>

        {{-- ── Right column ─────────────────────────────────────────────────── --}}
        <div class="space-y-5">

            {{-- Package Info & Queue Health --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-700">
                    <span class="section-heading">Package &amp; Queue</span>
                </div>
                <div class="divide-y divide-gray-50 dark:divide-gray-700/60">
                    <div class="flex items-center justify-between px-5 py-2.5 text-sm">
                        <span class="text-gray-500 dark:text-gray-400 shrink-0">Version</span>
                        <span class="config-val text-xs text-gray-700 dark:text-gray-300">
                            @if($queueHealth['version'] !== 'dev')
                                v{{ $queueHealth['version'] }}
                            @else
                                <span class="badge badge-gray">dev</span>
                            @endif
                        </span>
                    </div>
                    @if($queueHealth['hasQueue'])
                    <div class="flex items-center justify-between px-5 py-2.5 text-sm">
                        <span class="text-gray-500 dark:text-gray-400 shrink-0">Pending Jobs</span>
                        <span class="badge {{ $queueHealth['pending'] > 0 ? 'badge-yellow' : 'badge-green' }}">
                            {{ $queueHealth['pending'] }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between px-5 py-2.5 text-sm">
                        <span class="text-gray-500 dark:text-gray-400 shrink-0">Failed Jobs</span>
                        <span class="badge {{ $queueHealth['failed'] > 0 ? 'badge-red' : 'badge-green' }}">
                            {{ $queueHealth['failed'] }}
                        </span>
                    </div>
                    @else
                    <div class="px-5 py-2.5 text-xs text-gray-400 dark:text-gray-500">
                        No <code class="config-key">jobs</code> table — queue driver not using DB.
                    </div>
                    @endif
                    <div class="flex items-center justify-between px-5 py-2.5 text-sm">
                        <span class="text-gray-500 dark:text-gray-400 shrink-0">Rate Limit</span>
                        <span class="text-xs text-gray-700 dark:text-gray-300 font-mono">
                            {{ $rateLimitCfg['limit'] }}&thinsp;req / {{ $rateLimitCfg['window'] }}&thinsp;min
                            @if($rateLimitCfg['limit'] === 0)
                                <span class="badge badge-red ml-1">disabled</span>
                            @endif
                        </span>
                    </div>
                </div>
            </div>

            {{-- Top Users --}}
            @if(!empty($topUsers))
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-700">
                    <span class="section-heading">Top Users</span>
                </div>
                <ul class="divide-y divide-gray-50 dark:divide-gray-700/60">
                    @php $topMax = $topUsers[0]['count'] ?? 1; @endphp
                    @foreach($topUsers as $rank => $u)
                    <li class="px-5 py-2.5">
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-xs font-medium text-gray-700 dark:text-gray-300 truncate max-w-[70%]">
                                {{ $u['user_name'] ?? 'User #' . $u['user_id'] }}
                            </span>
                            <span class="text-xs text-gray-400 shrink-0">{{ $u['count'] }} conv{{ $u['count'] !== 1 ? 's' : '' }}</span>
                        </div>
                        <div class="w-full bg-gray-100 dark:bg-gray-700 rounded-full h-1.5">
                            <div class="h-1.5 rounded-full" style="width:{{ round($u['count'] / $topMax * 100) }}%;background:var(--theme);opacity:{{ 1 - $rank * 0.15 }}"></div>
                        </div>
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif

            {{-- Named providers --}}
            @if(!empty($namedProviders))
            @php
                $providerTokenPlaceholders = [
                    'your-api-key', 'your-api-token', 'sk-xxx', 'changeme', 'secret',
                    'your-ollama-token', 'your-token', 'placeholder', 'token',
                ];
                $healthBaseUrl = url(config('ai-chatbox.route_prefix') . '/health');
            @endphp
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-700">
                    <span class="section-heading">Named Providers</span>
                </div>
                <div class="px-5 py-3 space-y-3">
                    @foreach($namedProviders as $name => $provider)
                    @php
                        $isActive = ($name === $activeProvider);
                        $pUrl    = $provider['api_url']   ?? '';
                        $pToken  = $provider['api_token'] ?? '';
                        $pModel  = $provider['api_model'] ?? '';
                        $urlOk   = !empty($pUrl) && filter_var($pUrl, FILTER_VALIDATE_URL);
                        $tokenOk = !empty($pToken) && !in_array(strtolower($pToken), $providerTokenPlaceholders);
                        $modelOk = !empty($pModel);
                        $isComplete = $urlOk && $tokenOk && $modelOk;
                        $incompleteReasons = [];
                        if (!$urlOk)   $incompleteReasons[] = 'api_url not set or invalid';
                        if (!$tokenOk) $incompleteReasons[] = 'api_token missing or placeholder';
                        if (!$modelOk) $incompleteReasons[] = 'api_model not set';
                        $resultId = 'hc-result-' . $name;
                    @endphp
                    <div class="rounded-lg px-3 py-2.5 {{ $isActive ? 'bg-gray-50 dark:bg-gray-700/50' : 'bg-gray-50 dark:bg-gray-700/30' }}"
                         style="{{ $isActive ? 'outline: 2px solid var(--theme); outline-offset: -2px;' : '' }}">
                        <div class="flex items-center gap-2 mb-1.5">
                            <p class="text-xs font-semibold {{ $isActive ? 'text-gray-900 dark:text-gray-100' : 'text-gray-700 dark:text-gray-300' }} flex-1">{{ $name }}</p>
                            @if($isActive)
                                <span class="badge badge-green">active</span>
                            @endif
                            <span class="badge {{ $isComplete ? 'badge-green' : 'badge-red' }}">
                                {{ $isComplete ? 'complete' : 'incomplete' }}
                            </span>
                        </div>

                        @foreach($provider as $k => $v)
                        @php $isSecret = is_string($v) && $v !== '' && preg_match('/(?:_token|_secret|_key|_password|password)$/i', $k); @endphp
                        <div class="grid grid-cols-[auto_1fr] gap-x-3 text-xs mb-0.5">
                            <span class="config-key text-gray-400 whitespace-nowrap">{{ $k }}</span>
                            <span class="config-val {{ $isActive ? 'text-gray-700 dark:text-gray-200' : 'text-gray-600 dark:text-gray-300' }} break-all">
                                @if($isSecret)
                                    {{ str_repeat('•', min(12, max(0, strlen($v) - 4))) }}{{ strlen($v) > 4 ? substr($v, -4) : $v }}
                                @else
                                    {{ $v ?: '—' }}
                                @endif
                            </span>
                        </div>
                        @endforeach

                        @if(!$isComplete)
                        <p class="text-xs text-red-500 dark:text-red-400 mt-1.5">{{ implode(', ', $incompleteReasons) }}</p>
                        @endif

                        <div class="flex items-center gap-2 mt-2">
                            @if($isComplete)
                            <button type="button"
                                    onclick="providerHealthCheck(this, '{{ $healthBaseUrl }}?provider={{ urlencode($name) }}', '{{ $resultId }}')"
                                    class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-medium border transition-colors
                                           border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300
                                           hover:border-[var(--theme)] hover:text-[var(--theme)]
                                           disabled:opacity-50 disabled:cursor-not-allowed">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z"/>
                                </svg>
                                Test
                            </button>
                            @else
                            <button type="button" disabled title="{{ implode('; ', $incompleteReasons) }}"
                                    class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-medium border
                                           border-gray-200 dark:border-gray-700 text-gray-400 dark:text-gray-600
                                           cursor-not-allowed opacity-50">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z"/>
                                </svg>
                                Test
                            </button>
                            @endif
                            <span id="{{ $resultId }}" class="text-xs"></span>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @push('scripts')
            <script>
            async function providerHealthCheck(btn, url, resultId) {
                btn.disabled = true;
                const original = btn.innerHTML;
                btn.innerHTML = '<svg class="w-3 h-3 animate-spin" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>&nbsp;Checking…';
                const el = document.getElementById(resultId);
                el.innerHTML = '';
                try {
                    const res  = await fetch(url);
                    const data = await res.json();
                    if (data.status === 'online') {
                        el.innerHTML = '<span class="badge badge-green">online</span>';
                    } else {
                        const msg  = data.message || 'offline';
                        const code = data.code ? ' <span style="opacity:0.75">[' + data.code + ']</span>' : '';
                        el.innerHTML = '<span class="badge badge-red">' + msg + code + '</span>';
                    }
                } catch (e) {
                    el.innerHTML = '<span class="badge badge-red">request failed</span>';
                }
                btn.innerHTML = original;
                btn.disabled  = false;
            }
            </script>
            @endpush
            @endif

            {{-- Widget Preview --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-700">
                    <span class="section-heading">Widget Preview</span>
                </div>
                <div class="px-5 py-4 space-y-4">
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">Toggle Button</p>
                        <div class="flex items-center gap-4">
                            <div class="w-14 h-14 rounded-full flex items-center justify-center shadow-md shrink-0"
                                 style="background:var(--theme)">
                                @if($toggleIconUrl)
                                    <img src="{{ $toggleIconUrl }}" alt="Toggle icon"
                                         class="w-[26px] h-[26px] object-contain"
                                         onerror="this.replaceWith(document.getElementById('aicb-admin-default-icon').cloneNode(true))">
                                @else
                                    <svg class="w-[26px] h-[26px] text-white" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2z"/>
                                    </svg>
                                @endif
                            </div>
                            <div class="text-xs text-gray-600 dark:text-gray-400 space-y-1">
                                <p><span class="font-medium text-gray-700 dark:text-gray-300">Icon:</span>
                                    @if($toggleIconUrl)
                                        <span class="badge badge-green">custom</span>
                                    @else
                                        <span class="badge badge-gray">default SVG</span>
                                    @endif
                                </p>
                                <p><span class="font-medium text-gray-700 dark:text-gray-300">Color:</span>
                                    <span class="inline-flex items-center gap-1">
                                        <span class="inline-block w-3 h-3 rounded-full border border-gray-200 dark:border-gray-600"
                                              style="background:{{ $themeColor }}"></span>
                                        <code class="config-key">{{ $themeColor }}</code>
                                    </span>
                                </p>
                                <p><span class="font-medium text-gray-700 dark:text-gray-300">Position:</span>
                                    <code class="config-key">{{ $configGroups['Widget']['position'] ?? 'bottom-right' }}</code>
                                </p>
                            </div>
                        </div>
                        @if($toggleIconUrl)
                        <p class="mt-2 text-xs text-gray-400 dark:text-gray-500 break-all font-mono leading-relaxed">
                            {{ $configGroups['Widget']['toggle_icon'] ?? '' }}
                        </p>
                        @endif
                    </div>

                    <svg id="aicb-admin-default-icon" class="w-[26px] h-[26px] text-white hidden" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2z"/>
                    </svg>

                    <div class="space-y-1.5 text-xs border-t border-gray-100 dark:border-gray-700 pt-3">
                        @foreach([
                            'Title'    => $configGroups['Widget']['title']    ?? '—',
                            'Greeting' => $configGroups['Widget']['greeting'] ?? '—',
                            'Frontend' => $configGroups['Widget']['frontend'] ?? '—',
                        ] as $label => $value)
                        <div class="flex gap-2">
                            <span class="text-gray-500 dark:text-gray-400 w-16 shrink-0">{{ $label }}</span>
                            <span class="text-gray-700 dark:text-gray-300 truncate">{{ $value }}</span>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Frontend Driver --}}
            @php
                $drivers = [
                    'vue'      => ['label' => 'Vue 3',    'desc' => 'Pre-built Vue 3 widget. Zero-config, recommended default.',     'req' => null],
                    'blade'    => ['label' => 'Blade',    'desc' => 'Vanilla JS widget. No framework required.',                     'req' => null],
                    'livewire' => ['label' => 'Livewire', 'desc' => 'Alpine.js widget mounted via Livewire.',                        'req' => 'livewire/livewire'],
                    'none'     => ['label' => 'None',     'desc' => 'Outputs window.AiChatboxConfig only. Bring your own frontend.', 'req' => null],
                ];
                $livewireInstalled = class_exists(\Livewire\Livewire::class);
            @endphp
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-700">
                    <span class="section-heading">Frontend Driver</span>
                </div>
                <div class="px-5 py-3 space-y-2">
                    @foreach($drivers as $driverKey => $info)
                    @php $isActiveFrontend = ($frontend === $driverKey); @endphp
                    <div class="rounded-lg px-3 py-2.5 bg-gray-50 dark:bg-gray-700/30"
                         style="{{ $isActiveFrontend ? 'outline: 2px solid var(--theme); outline-offset: -2px;' : '' }}">
                        <div class="flex items-center gap-2 mb-0.5">
                            <span class="text-xs font-semibold {{ $isActiveFrontend ? 'text-gray-900 dark:text-gray-100' : 'text-gray-600 dark:text-gray-400' }}">{{ $info['label'] }}</span>
                            @if($isActiveFrontend)
                                <span class="badge badge-green">active</span>
                            @endif
                            @if($info['req'])
                                @if($driverKey === 'livewire' && !$livewireInstalled)
                                    <span class="badge badge-red">not installed</span>
                                @elseif($driverKey === 'livewire')
                                    <span class="badge badge-green">installed</span>
                                @endif
                            @endif
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $info['desc'] }}</p>
                        @if($info['req'])
                            <p class="text-xs mt-1 font-mono {{ ($driverKey === 'livewire' && !$livewireInstalled) ? 'text-red-500 dark:text-red-400' : 'text-gray-400' }}">
                                requires: {{ $info['req'] }}
                            </p>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- RAG embedding status + KB size --}}
            @if($ragEnabled)
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-700">
                    <span class="section-heading">Embedding &amp; Knowledge Base</span>
                </div>
                <div class="px-5 py-4 space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-gray-500 dark:text-gray-400">URL</span>
                        <span class="config-val text-xs text-right break-all text-gray-700 dark:text-gray-300">
                            {{ $configGroups['RAG']['rag_embedding_url'] ?: '—' }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-gray-500 dark:text-gray-400">Model</span>
                        <span class="config-val text-xs text-gray-700 dark:text-gray-300">
                            {{ $configGroups['RAG']['rag_embedding_model'] ?: '—' }}
                        </span>
                    </div>
                    @if($ragStats)
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-gray-500 dark:text-gray-400">Coverage</span>
                        @php
                            $total    = $ragStats['total_chunks'];
                            $embedded = $ragStats['embedded_chunks'];
                            $pct      = $total > 0 ? round($embedded / $total * 100) : 0;
                        @endphp
                        <span class="badge {{ $pct === 100 ? 'badge-green' : ($pct === 0 ? 'badge-red' : 'badge-yellow') }}">
                            {{ $pct }}% ({{ $embedded }}/{{ $total }})
                        </span>
                    </div>
                    @endif
                    @if($kbSize)
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-gray-500 dark:text-gray-400">Content Size</span>
                        <span class="text-xs font-mono text-gray-700 dark:text-gray-300">{{ $kbSize['formatted'] }}</span>
                    </div>
                    @endif
                    @if($ragStats && $ragStats['documents'] > 0)
                    {{-- Embedding coverage bar --}}
                    <div>
                        <div class="w-full bg-gray-100 dark:bg-gray-700 rounded-full h-1.5 mt-1">
                            <div class="h-1.5 rounded-full {{ $pct === 100 ? '' : ($pct === 0 ? 'bg-red-400' : 'bg-amber-400') }}"
                                 style="{{ $pct > 0 ? 'width:' . $pct . '%;background:var(--theme)' : 'width:100%' }}"></div>
                        </div>
                        <p class="text-[0.65rem] text-gray-400 mt-1">{{ $pct }}% embedded</p>
                    </div>
                    @endif
                </div>
            </div>
            @endif

            {{-- Environment --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-700">
                    <span class="section-heading">Environment</span>
                </div>
                <div class="divide-y divide-gray-50 dark:divide-gray-700/60">
                    @foreach(['laravel' => 'Laravel', 'php' => 'PHP', 'app_env' => 'App Env', 'app_url' => 'App URL'] as $k => $label)
                    <div class="flex items-center justify-between gap-2 px-5 py-2.5 text-sm">
                        <span class="text-gray-500 dark:text-gray-400 shrink-0">{{ $label }}</span>
                        <span class="config-val text-xs text-right text-gray-700 dark:text-gray-300 break-all">{{ $env[$k] }}</span>
                    </div>
                    @endforeach
                    <div class="flex items-center justify-between gap-2 px-5 py-2.5 text-sm">
                        <span class="text-gray-500 dark:text-gray-400 shrink-0">Debug</span>
                        <span class="badge {{ $env['app_debug'] ? 'badge-red' : 'badge-green' }}">{{ $env['app_debug'] ? 'ON' : 'off' }}</span>
                    </div>
                </div>
            </div>

        </div>{{-- end right column --}}
    </div>{{-- end grid --}}

@endsection

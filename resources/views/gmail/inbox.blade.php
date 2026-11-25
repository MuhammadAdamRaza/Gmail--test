@extends('layouts.app')

@section('title', ucfirst(strtolower($activeLabel)) . ' - Gmail Workspace')

@section('content')
    <div class="bg-white rounded-2xl border border-slate-200/90 shadow-2xs overflow-hidden">
        <!-- Toolbar -->
        <div class="px-5 py-3.5 border-b border-slate-200/80 flex items-center justify-between gap-4 bg-slate-50/70 flex-wrap">
            <div class="flex items-center gap-2.5 flex-wrap">
                <h2 class="text-base font-bold text-slate-900">
                    {{ $activeLabel === 'ALL' ? 'All Mail' : ucfirst(strtolower($activeLabel)) }}
                </h2>
                @if($resultSizeEstimate > 0)
                    <span class="px-2 py-0.5 rounded-full bg-slate-200/70 text-slate-600 text-[11px] font-medium">
                        ~{{ number_format($resultSizeEstimate) }}
                    </span>
                @endif
                @if($searchQuery !== '')
                    <span class="px-2.5 py-0.5 rounded-full bg-blue-100 text-blue-700 text-xs font-medium">
                        "{{ $searchQuery }}"
                    </span>
                    <a href="{{ route('gmail.inbox', ['label' => $activeLabel, 'perPage' => $perPage ?? 25]) }}" onclick="showLoader()" class="text-xs text-slate-500 hover:text-slate-800 underline">Clear</a>
                @endif
            </div>

            <div class="flex items-center gap-2.5 flex-wrap">
                <button
                    type="button"
                    id="extractAllKwBtn"
                    onclick="extractMissingKeywords()"
                    class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-indigo-50 border border-indigo-200 text-[11px] font-semibold text-indigo-700 cursor-pointer hover:bg-indigo-100 transition"
                    title="Extract AI keywords for all emails on this page"
                >
                    <span>✨ AI Keywords (All)</span>
                </button>

                <button
                    type="button"
                    id="autoSyncBadge"
                    onclick="toggleAutoSync()"
                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-50 border border-emerald-200 text-[11px] font-medium text-emerald-700 cursor-pointer hover:bg-emerald-100 transition"
                    title="Click to pause/resume 25s auto-sync"
                >
                    <span id="autoSyncDot" class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span id="autoSyncText">Auto-sync: ON</span>
                </button>

                <form method="GET" action="{{ route('gmail.inbox') }}" class="inline" onchange="showLoader()">
                    <input type="hidden" name="label" value="{{ $activeLabel }}">
                    @if($searchQuery !== '')
                        <input type="hidden" name="q" value="{{ $searchQuery }}">
                    @endif
                    <select name="perPage" onchange="this.form.submit()" class="text-xs font-medium border border-slate-200 rounded-lg px-2.5 py-1.5 bg-white text-slate-700 outline-none cursor-pointer hover:border-slate-300">
                        <option value="25" {{ ($perPage ?? 25) == 25 ? 'selected' : '' }}>25 / page</option>
                        <option value="50" {{ ($perPage ?? 25) == 50 ? 'selected' : '' }}>50 / page</option>
                        <option value="100" {{ ($perPage ?? 25) == 100 ? 'selected' : '' }}>100 / page</option>
                    </select>
                </form>

                <a
                    href="{{ route('gmail.inbox', ['label' => $activeLabel, 'q' => $searchQuery ?: null, 'perPage' => $perPage ?? 25, 'refresh' => 1]) }}"
                    onclick="showLoader()"
                    class="p-2 rounded-lg text-slate-500 hover:text-blue-600 hover:bg-blue-50 transition"
                    title="Sync now from Gmail"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                </a>
            </div>
        </div>

        <div id="inboxListContainer">
            @include('gmail.partials.message-list')
        </div>
    </div>

    @php
        $syncUrl = route('gmail.inbox', [
            'label' => $activeLabel,
            'q' => $searchQuery ?: null,
            'perPage' => $perPage ?? 25,
            'refresh' => 1,
            'partial' => 1,
        ]);
        $keywordsUrl = route('gmail.keywords');
        $csrfToken = csrf_token();
        $hasPageToken = !empty($pageToken);
    @endphp

    <script>
        (function () {
            const hasPageToken = @json($hasPageToken);
            const syncUrl = @json($syncUrl);
            const keywordsUrl = @json($keywordsUrl);
            const csrfToken = @json($csrfToken);
            let autoSyncEnabled = !hasPageToken;
            let isSyncing = false;

            const badge = document.getElementById('autoSyncBadge');
            const dot = document.getElementById('autoSyncDot');
            const text = document.getElementById('autoSyncText');
            const container = document.getElementById('inboxListContainer');

            function updateBadge() {
                if (!badge) return;
                if (autoSyncEnabled) {
                    badge.className = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-50 border border-emerald-200 text-[11px] font-medium text-emerald-700 cursor-pointer hover:bg-emerald-100 transition';
                    dot.className = 'w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse';
                    text.textContent = 'Auto-sync: ON';
                } else {
                    badge.className = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-slate-100 border border-slate-200 text-[11px] font-medium text-slate-500 cursor-pointer hover:bg-slate-200 transition';
                    dot.className = 'w-1.5 h-1.5 rounded-full bg-slate-400';
                    text.textContent = hasPageToken ? 'Auto-sync: Page 1 only' : 'Auto-sync: PAUSED';
                }
            }

            window.toggleAutoSync = function () {
                if (hasPageToken) return;
                autoSyncEnabled = !autoSyncEnabled;
                updateBadge();
            };

            async function runAutoSync() {
                if (!autoSyncEnabled || isSyncing || document.hidden) return;

                const composeModal = document.getElementById('composeModal');
                if (composeModal && !composeModal.classList.contains('hidden')) return;

                isSyncing = true;
                text.textContent = 'Syncing...';

                try {
                    const res = await fetch(syncUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    if (res.ok) {
                        const html = await res.text();
                        if (html.trim() !== '' && container) {
                            container.innerHTML = html;
                        }
                    }
                } catch (e) {
                    // Silently ignore transient network issues during background sync
                } finally {
                    isSyncing = false;
                    updateBadge();
                }
            }

            function renderKeywordPills(el, kws) {
                if (Array.isArray(kws) && kws.length > 0) {
                    el.innerHTML = '';
                    kws.forEach(kw => {
                        const span = document.createElement('span');
                        span.className = 'inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-medium bg-indigo-50 text-indigo-700 border border-indigo-200/70';
                        span.textContent = kw;
                        el.appendChild(span);
                    });
                    el.dataset.loaded = '1';
                }
            }

            window.extractSingleKeyword = async function (btn) {
                const el = btn.closest('.ai-kw-slot');
                if (!el || el.dataset.loaded === '1') return;

                btn.disabled = true;
                btn.textContent = 'Extracting...';

                try {
                    const res = await fetch(keywordsUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({
                            emails: [{
                                id: el.dataset.msgId,
                                from: el.dataset.from || '',
                                subject: el.dataset.subject || '',
                                snippet: el.dataset.snippet || '',
                            }]
                        }),
                    });

                    if (res.ok) {
                        const data = await res.json();
                        const kws = (data.keywords || {})[el.dataset.msgId];
                        if (Array.isArray(kws) && kws.length > 0) {
                            renderKeywordPills(el, kws);
                            return;
                        }
                    }
                } catch (e) {}

                btn.disabled = false;
                btn.textContent = '✨ AI Keywords';
            };

            window.extractMissingKeywords = async function () {
                const slots = Array.from(document.querySelectorAll('.ai-kw-slot[data-loaded="0"]'));
                if (slots.length === 0) return;

                const allBtn = document.getElementById('extractAllKwBtn');
                if (allBtn) {
                    allBtn.disabled = true;
                    allBtn.textContent = 'Extracting...';
                }

                for (let i = 0; i < slots.length; i += 8) {
                    const batchSlots = slots.slice(i, i + 8);
                    batchSlots.forEach(el => {
                        const b = el.querySelector('button');
                        if (b) { b.disabled = true; b.textContent = 'Extracting...'; }
                    });

                    const payload = batchSlots.map(el => ({
                        id: el.dataset.msgId,
                        from: el.dataset.from || '',
                        subject: el.dataset.subject || '',
                        snippet: el.dataset.snippet || '',
                    }));

                    try {
                        const res = await fetch(keywordsUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: JSON.stringify({ emails: payload }),
                        });

                        if (!res.ok) continue;
                        const data = await res.json();
                        const map = data.keywords || {};

                        batchSlots.forEach(el => {
                            const kws = map[el.dataset.msgId];
                            if (Array.isArray(kws) && kws.length > 0) {
                                renderKeywordPills(el, kws);
                            } else {
                                const b = el.querySelector('button');
                                if (b) { b.disabled = false; b.textContent = '✨ AI Keywords'; }
                            }
                        });
                    } catch (e) {
                        batchSlots.forEach(el => {
                            const b = el.querySelector('button');
                            if (b) { b.disabled = false; b.textContent = '✨ AI Keywords'; }
                        });
                    }
                }

                if (allBtn) {
                    allBtn.disabled = false;
                    allBtn.textContent = '✨ AI Keywords (All)';
                }
            };

            updateBadge();
            setInterval(runAutoSync, 25000);
        })();
    </script>
@endsection

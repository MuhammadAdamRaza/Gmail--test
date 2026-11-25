@php
    $avatarColors = [
        'bg-blue-600 text-white',
        'bg-emerald-600 text-white',
        'bg-violet-600 text-white',
        'bg-amber-600 text-white',
        'bg-rose-600 text-white',
        'bg-cyan-600 text-white',
        'bg-indigo-600 text-white',
    ];
@endphp

@if(count($messages) === 0)
    <div class="py-16 px-6 text-center">
        <div class="w-12 h-12 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center mx-auto mb-3">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
            </svg>
        </div>
        <p class="text-sm font-semibold text-slate-700">No messages found</p>
        <p class="text-xs text-slate-500 mt-1">Try another folder or clear your search filter.</p>
    </div>
@else
    <div class="divide-y divide-slate-100">
        @foreach($messages as $msg)
            @php
                $initial = strtoupper(substr($msg['fromName'], 0, 1)) ?: '?';
                $colorClass = $avatarColors[ord($initial) % count($avatarColors)];
            @endphp
            <div class="flex items-start sm:items-center gap-3 px-4 sm:px-5 py-3.5 hover:bg-slate-50/90 transition group {{ $msg['isUnread'] ? 'bg-blue-50/35' : '' }}">
                <!-- Star Toggle -->
                <form action="{{ route('gmail.star', $msg['id']) }}" method="POST" class="shrink-0 pt-1 sm:pt-0">
                    @csrf
                    <input type="hidden" name="star" value="{{ $msg['isStarred'] ? '0' : '1' }}">
                    <button type="submit" title="{{ $msg['isStarred'] ? 'Unstar' : 'Star' }}" class="text-lg leading-none cursor-pointer transition {{ $msg['isStarred'] ? 'text-amber-400 scale-110' : 'text-slate-300 hover:text-amber-400' }}">
                        {{ $msg['isStarred'] ? '★' : '☆' }}
                    </button>
                </form>

                <!-- Deterministic Color Avatar -->
                <div class="w-9 h-9 rounded-full flex items-center justify-center text-xs font-bold shrink-0 shadow-2xs {{ $colorClass }}">
                    {{ $initial }}
                </div>

                <!-- Clickable Content -->
                <a href="{{ route('gmail.show', $msg['id']) }}" onclick="showLoader()" class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="text-sm truncate {{ $msg['isUnread'] ? 'font-bold text-slate-900' : 'font-medium text-slate-700' }}">
                                {{ $msg['fromName'] }}
                            </span>
                            @if($msg['isUnread'])
                                <span class="w-2 h-2 rounded-full bg-blue-600 shrink-0" title="Unread"></span>
                            @endif
                        </div>
                        <span class="text-xs shrink-0 {{ $msg['isUnread'] ? 'font-semibold text-blue-700' : 'text-slate-400' }}">
                            {{ $msg['date'] }}
                        </span>
                    </div>

                    <div class="mt-0.5 flex flex-col sm:flex-row sm:items-center gap-0.5 sm:gap-2">
                        <span class="text-sm truncate {{ $msg['isUnread'] ? 'font-semibold text-slate-900' : 'text-slate-800' }}">
                            {{ $msg['subject'] }}
                        </span>
                        <span class="hidden sm:inline text-slate-300">·</span>
                        <span class="text-xs text-slate-500 truncate">
                            {{ $msg['snippet'] }}
                        </span>
                    </div>

                    <!-- AI Keywords (On-Demand to save tokens) -->
                    <div
                        class="ai-kw-slot mt-1.5 flex flex-wrap items-center gap-1.5"
                        data-msg-id="{{ $msg['id'] }}"
                        data-from="{{ $msg['fromName'] }}"
                        data-subject="{{ $msg['subject'] }}"
                        data-snippet="{{ $msg['snippet'] }}"
                        data-loaded="{{ !empty($msg['keywords']) ? '1' : '0' }}"
                    >
                        @if(!empty($msg['keywords']))
                            @foreach($msg['keywords'] as $kw)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-medium bg-indigo-50 text-indigo-700 border border-indigo-200/70">
                                    {{ $kw }}
                                </span>
                            @endforeach
                        @else
                            <button
                                type="button"
                                onclick="event.preventDefault(); event.stopPropagation(); extractSingleKeyword(this);"
                                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-medium bg-slate-100 hover:bg-indigo-50 text-slate-600 hover:text-indigo-700 border border-slate-200/80 hover:border-indigo-200 transition cursor-pointer"
                            >
                                <span>✨ AI Keywords</span>
                            </button>
                        @endif
                    </div>
                </a>

                <!-- Quick Row Actions -->
                <div class="hidden sm:flex items-center gap-1 opacity-0 group-hover:opacity-100 transition shrink-0">
                    <form action="{{ route('gmail.read', $msg['id']) }}" method="POST">
                        @csrf
                        <input type="hidden" name="read" value="{{ $msg['isUnread'] ? '1' : '0' }}">
                        <button type="submit" title="{{ $msg['isUnread'] ? 'Mark as read' : 'Mark as unread' }}" class="p-1.5 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50 cursor-pointer transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                        </button>
                    </form>

                    @if(!$msg['isTrash'])
                        <form action="{{ route('gmail.archive', $msg['id']) }}" method="POST">
                            @csrf
                            <button type="submit" title="Archive" class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-200/70 cursor-pointer transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                                </svg>
                            </button>
                        </form>

                        <form action="{{ route('gmail.trash', $msg['id']) }}" method="POST">
                            @csrf
                            <button type="submit" title="Move to Trash" class="p-1.5 rounded-lg text-slate-400 hover:text-red-600 hover:bg-red-50 cursor-pointer transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </form>
                    @else
                        <form action="{{ route('gmail.untrash', $msg['id']) }}" method="POST">
                            @csrf
                            <button type="submit" title="Restore from Trash" class="px-2.5 py-1 rounded-lg text-xs font-medium text-emerald-700 bg-emerald-50 hover:bg-emerald-100 cursor-pointer transition">
                                Restore
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    @if(!empty($hasPrev) || $nextPageToken)
        <div class="px-5 py-3.5 border-t border-slate-200/80 bg-slate-50/70 flex items-center justify-between gap-3 flex-wrap">
            <span class="text-xs text-slate-400">
                Page {{ $currentPage ?? 1 }} · Showing {{ count($messages) }} messages
            </span>
            <div class="flex items-center gap-2">
                @if(!empty($hasPrev))
                    <a
                        href="{{ route('gmail.inbox', ['label' => $activeLabel, 'q' => $searchQuery ?: null, 'perPage' => $perPage ?? 25, 'pageToken' => $prevPageToken ?: null]) }}"
                        onclick="showLoader()"
                        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-white border border-slate-200 text-xs font-semibold text-slate-700 hover:bg-slate-100 shadow-2xs transition"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                        </svg>
                        <span>Previous Page</span>
                    </a>
                @endif

                @if($nextPageToken)
                    <a
                        href="{{ route('gmail.inbox', ['label' => $activeLabel, 'q' => $searchQuery ?: null, 'perPage' => $perPage ?? 25, 'pageToken' => $nextPageToken]) }}"
                        onclick="showLoader()"
                        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-white border border-slate-200 text-xs font-semibold text-slate-700 hover:bg-slate-100 shadow-2xs transition"
                    >
                        <span>Load Next Page</span>
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                @endif
            </div>
        </div>
    @endif
@endif

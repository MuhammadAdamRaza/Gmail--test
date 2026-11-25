@extends('layouts.app')

@section('title', ucfirst(strtolower($activeLabel)) . ' - Gmail Workspace')

@section('content')
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

    <div class="bg-white rounded-2xl border border-slate-200/90 shadow-2xs overflow-hidden">
        <!-- Toolbar -->
        <div class="px-5 py-3.5 border-b border-slate-200/80 flex items-center justify-between gap-4 bg-slate-50/70">
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

            <div class="flex items-center gap-2.5">
                <span class="text-xs text-slate-500 hidden sm:inline">
                    {{ count($messages) }} shown
                </span>

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
                    title="Sync latest from Gmail"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                </a>
            </div>
        </div>

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

            @if($nextPageToken)
                <div class="px-5 py-3.5 border-t border-slate-200/80 bg-slate-50/70 flex items-center justify-between">
                    <span class="text-xs text-slate-400">Showing {{ count($messages) }} messages on this page</span>
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
                </div>
            @endif
        @endif
    </div>
@endsection

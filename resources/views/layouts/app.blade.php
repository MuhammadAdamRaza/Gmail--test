<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-100/70">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Gmail Workspace')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        @keyframes progressSlide {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(300%); }
        }
        .loading-bar { animation: progressSlide 1.1s infinite ease-in-out; }
    </style>
</head>
<body class="h-full text-slate-800 antialiased">
    <!-- Top Loading Indicator -->
    <div id="globalLoader" class="hidden fixed top-0 left-0 right-0 h-0.5 z-50 bg-blue-100 overflow-hidden">
        <div class="h-full w-1/3 bg-blue-600 loading-bar"></div>
    </div>

    @hasSection('fullpage')
        @yield('fullpage')
    @else
        <div class="min-h-screen flex flex-col">
            <!-- Top Navbar -->
            <header class="sticky top-0 z-30 bg-white/95 backdrop-blur border-b border-slate-200/90 shadow-2xs">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        @isset($account)
                            <button
                                type="button"
                                onclick="document.getElementById('sidebarNav').classList.toggle('hidden')"
                                class="md:hidden p-2 rounded-lg text-slate-500 hover:bg-slate-100 cursor-pointer"
                                aria-label="Toggle Menu"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                                </svg>
                            </button>
                        @endisset

                        <a href="{{ route('gmail.home') }}" class="flex items-center gap-2.5 group">
                            <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-red-500 via-rose-500 to-rose-600 flex items-center justify-center text-white shadow-sm shadow-rose-500/25 group-hover:scale-105 transition">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                </svg>
                            </div>
                            <div class="hidden sm:block">
                                <span class="font-bold text-slate-900 tracking-tight text-base">Gmail</span>
                                <span class="text-xs font-medium text-blue-600 bg-blue-50 px-1.5 py-0.5 rounded-md ml-1">Workspace</span>
                            </div>
                        </a>
                    </div>

                    @isset($account)
                        <form action="{{ route('gmail.inbox') }}" method="GET" class="flex-1 max-w-xl" onsubmit="showLoader()">
                            <input type="hidden" name="label" value="{{ $activeLabel ?? 'INBOX' }}">
                            <input type="hidden" name="perPage" value="{{ $perPage ?? 25 }}">
                            <div class="relative group">
                                <svg class="w-4 h-4 text-slate-400 group-focus-within:text-blue-500 absolute left-3.5 top-1/2 -translate-y-1/2 transition" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"/>
                                </svg>
                                <input
                                    type="text"
                                    name="q"
                                    value="{{ $searchQuery ?? '' }}"
                                    placeholder="Search mail (e.g. from:google, subject:invoice, has:attachment)..."
                                    class="w-full pl-10 pr-4 py-2 text-sm bg-slate-100/90 border border-slate-200/60 rounded-full focus:bg-white focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition"
                                >
                            </div>
                        </form>

                        <div class="flex items-center gap-2">
                            <div class="hidden md:flex items-center gap-2 pl-2 pr-3 py-1 rounded-full bg-slate-100 border border-slate-200/80 text-xs font-medium text-slate-700">
                                <span class="w-5 h-5 rounded-full bg-emerald-600 text-white text-[10px] font-bold flex items-center justify-center">
                                    {{ strtoupper(substr($account->email, 0, 1)) }}
                                </span>
                                <span class="max-w-[180px] truncate">{{ $account->email }}</span>
                            </div>
                            <form action="{{ route('gmail.disconnect') }}" method="POST" onsubmit="return confirm('Disconnect {{ $account->email }}?')">
                                @csrf
                                <button type="submit" class="px-3 py-1.5 text-xs font-medium text-slate-600 hover:text-red-600 hover:bg-red-50 hover:border-red-200 rounded-full border border-slate-200 transition cursor-pointer">
                                    Disconnect
                                </button>
                            </form>
                        </div>
                    @endisset
                </div>
            </header>

            <!-- Main Layout -->
            <div class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 py-5 flex flex-col md:flex-row gap-5">
                @isset($account)
                    <!-- Sidebar -->
                    <aside id="sidebarNav" class="hidden md:block w-full md:w-56 shrink-0 space-y-4">
                        <button
                            type="button"
                            onclick="document.getElementById('composeModal').classList.remove('hidden')"
                            class="w-full flex items-center justify-center gap-2.5 px-4 py-3 rounded-2xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold text-sm shadow-md shadow-blue-600/20 hover:shadow-lg hover:-translate-y-0.5 transition cursor-pointer"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.3" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                            </svg>
                            Compose
                        </button>

                        @php
                            $folders = [
                                'INBOX' => ['label' => 'Inbox', 'icon' => 'M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4'],
                                'STARRED' => ['label' => 'Starred', 'icon' => 'M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z'],
                                'SENT' => ['label' => 'Sent', 'icon' => 'M12 19l9 2-9-18-9 18 9-2zm0 0v-8'],
                                'DRAFT' => ['label' => 'Drafts', 'icon' => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z'],
                                'ALL' => ['label' => 'All Mail', 'icon' => 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z'],
                                'SPAM' => ['label' => 'Spam', 'icon' => 'M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z'],
                                'TRASH' => ['label' => 'Trash', 'icon' => 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16'],
                            ];
                            $currentFolder = $activeLabel ?? '';
                        @endphp

                        <nav class="bg-white rounded-2xl border border-slate-200/80 p-2 space-y-0.5 shadow-2xs">
                            @foreach($folders as $key => $folder)
                                @php $isActive = $currentFolder === $key; @endphp
                                <a
                                    href="{{ route('gmail.inbox', ['label' => $key, 'perPage' => $perPage ?? 25]) }}"
                                    onclick="showLoader()"
                                    class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition {{ $isActive ? 'bg-blue-50 text-blue-700 font-semibold' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' }}"
                                >
                                    <svg class="w-4 h-4 shrink-0 {{ $isActive ? 'text-blue-600' : 'text-slate-400' }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $folder['icon'] }}"/>
                                    </svg>
                                    <span>{{ $folder['label'] }}</span>
                                </a>
                            @endforeach
                        </nav>

                        <div class="bg-white rounded-2xl border border-slate-200/80 p-3.5 space-y-2 shadow-2xs">
                            <div class="flex items-center justify-between text-[11px] text-slate-500">
                                <span>Timezone</span>
                                <span class="font-medium text-slate-700">PKT (UTC+5)</span>
                            </div>
                            <a href="{{ route('gmail.connect') }}" class="flex items-center justify-center gap-1.5 w-full py-1.5 text-xs font-medium text-slate-600 hover:text-blue-700 bg-slate-50 hover:bg-blue-50 rounded-lg border border-slate-200/70 transition">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                </svg>
                                Reconnect Google
                            </a>
                        </div>
                    </aside>
                @endisset

                <!-- Main Content -->
                <main class="flex-1 min-w-0">
                    @if(session('success'))
                        <div class="mb-4 flex items-center gap-2.5 px-4 py-3 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm shadow-2xs">
                            <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            </svg>
                            <span>{{ session('success') }}</span>
                        </div>
                    @endif

                    @if(session('error'))
                        <div class="mb-4 flex items-center gap-2.5 px-4 py-3 rounded-2xl bg-red-50 border border-red-200 text-red-800 text-sm shadow-2xs">
                            <svg class="w-4 h-4 text-red-600 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <span>{{ session('error') }}</span>
                        </div>
                    @endif

                    @if($errors->any())
                        <div class="mb-4 px-4 py-3 rounded-2xl bg-red-50 border border-red-200 text-red-800 text-sm space-y-1 shadow-2xs">
                            @foreach($errors->all() as $error)
                                <div>{{ $error }}</div>
                            @endforeach
                        </div>
                    @endif

                    @yield('content')
                </main>
            </div>
        </div>

        @isset($account)
            <!-- Compose Modal -->
            <div id="composeModal" class="hidden fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4" onclick="if(event.target === this) this.classList.add('hidden')">
                <div class="bg-white w-full max-w-xl rounded-2xl shadow-2xl border border-slate-200 overflow-hidden">
                    <div class="px-5 py-4 bg-slate-900 text-white flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                            </svg>
                            <h3 class="font-semibold text-sm">New Message</h3>
                        </div>
                        <button type="button" onclick="document.getElementById('composeModal').classList.add('hidden')" class="text-slate-400 hover:text-white cursor-pointer">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                    <form action="{{ route('gmail.compose') }}" method="POST" enctype="multipart/form-data" class="p-5 space-y-3.5" onsubmit="showLoader()">
                        @csrf
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">To</label>
                            <input type="text" name="to" required value="{{ old('to') }}" placeholder="recipient@example.com" class="w-full px-3.5 py-2 text-sm border border-slate-200 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">CC</label>
                                <input type="text" name="cc" value="{{ old('cc') }}" placeholder="cc@example.com" class="w-full px-3.5 py-2 text-sm border border-slate-200 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">BCC</label>
                                <input type="text" name="bcc" value="{{ old('bcc') }}" placeholder="bcc@example.com" class="w-full px-3.5 py-2 text-sm border border-slate-200 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Subject</label>
                            <input type="text" name="subject" required value="{{ old('subject') }}" placeholder="Subject" class="w-full px-3.5 py-2 text-sm border border-slate-200 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Message</label>
                            <textarea name="body" rows="6" required maxlength="10000" placeholder="Write your message..." class="w-full px-3.5 py-2 text-sm border border-slate-200 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">{{ old('body') }}</textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600 mb-1">Attachments (optional, max 5 files)</label>
                            <input type="file" name="attachments[]" multiple class="w-full text-xs text-slate-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200">
                        </div>
                        <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                            <button type="button" onclick="document.getElementById('composeModal').classList.add('hidden')" class="px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 rounded-xl transition cursor-pointer">
                                Cancel
                            </button>
                            <button type="submit" class="px-5 py-2 text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-xl shadow-sm transition cursor-pointer">
                                Send Email
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endisset

        <script>
            function showLoader() {
                const loader = document.getElementById('globalLoader');
                if (loader) loader.classList.remove('hidden');
            }
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    const modal = document.getElementById('composeModal');
                    if (modal) modal.classList.add('hidden');
                }
            });
        </script>
    @endif
</body>
</html>

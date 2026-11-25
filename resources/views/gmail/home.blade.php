@extends('layouts.app')

@section('title', 'Gmail Test — Connect Your Account')

@section('fullpage')
<div class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-blue-50 flex flex-col justify-center py-12 px-4 sm:px-6">
    <div class="max-w-lg w-full mx-auto">
        @if(session('success'))
            <div class="mb-5 flex items-center gap-2.5 px-4 py-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">
                <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                </svg>
                <span>{{ session('success') }}</span>
            </div>
        @endif

        @if(session('error'))
            <div class="mb-5 flex items-center gap-2.5 px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-red-800 text-sm">
                <svg class="w-4 h-4 text-red-600 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        <div class="bg-white rounded-3xl shadow-xl shadow-slate-200/60 border border-slate-200/80 p-8 sm:p-10">
            <div class="flex items-center gap-3.5 mb-6">
                <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-red-500 to-rose-600 flex items-center justify-center text-white shadow-md shadow-red-500/20">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Gmail Test Workspace</h1>
                    <p class="text-sm text-slate-500">Laravel + Google OAuth 2.0 &amp; Gmail API</p>
                </div>
            </div>

            <p class="text-sm text-slate-600 leading-relaxed mb-6">
                Connect your Google account securely to browse your inbox, search messages, view HTML/plain emails, download attachments, compose new emails, and send threaded replies.
            </p>

            @if($account)
                <div class="mb-6 p-4 rounded-2xl bg-emerald-50/70 border border-emerald-200">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-10 h-10 rounded-full bg-emerald-600 text-white font-semibold flex items-center justify-center shrink-0">
                                {{ strtoupper(substr($account->email, 0, 1)) }}
                            </div>
                            <div class="min-w-0">
                                <div class="text-xs font-medium text-emerald-700 uppercase tracking-wider">Connected Account</div>
                                <div class="text-sm font-semibold text-slate-900 truncate">{{ $account->email }}</div>
                            </div>
                        </div>
                        <span class="px-2.5 py-1 text-xs font-medium rounded-full bg-emerald-100 text-emerald-700 shrink-0">
                            Ready
                        </span>
                    </div>
                </div>

                <div class="space-y-3">
                    <a href="{{ route('gmail.inbox') }}" class="w-full flex items-center justify-center gap-2 px-5 py-3.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold text-sm shadow-sm shadow-blue-600/25 transition">
                        <span>Open Gmail Inbox</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                        </svg>
                    </a>

                    <div class="grid grid-cols-2 gap-3">
                        <a href="{{ route('gmail.connect') }}" class="flex items-center justify-center px-4 py-2.5 rounded-xl border border-slate-200 bg-slate-50 hover:bg-slate-100 text-slate-700 font-medium text-sm transition">
                            Switch Account
                        </a>
                        <form action="{{ route('gmail.disconnect') }}" method="POST">
                            @csrf
                            <button type="submit" class="w-full px-4 py-2.5 rounded-xl border border-slate-200 bg-white hover:bg-red-50 hover:text-red-600 hover:border-red-200 text-slate-600 font-medium text-sm transition cursor-pointer">
                                Disconnect
                            </button>
                        </form>
                    </div>
                </div>
            @else
                <a href="{{ route('gmail.connect') }}" class="w-full flex items-center justify-center gap-3 px-5 py-3.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-semibold text-sm shadow-sm transition">
                    <svg class="w-5 h-5" viewBox="0 0 24 24">
                        <path fill="#EA4335" d="M12 5c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 1.09 14.97 0 12 0 7.31 0 3.26 2.69 1.27 6.61l3.66 2.84C5.8 6.89 8.64 5 12 5z"/>
                        <path fill="#4285F4" d="M23.49 12.27c0-.79-.07-1.54-.19-2.27H12v4.51h6.47c-.29 1.52-1.14 2.81-2.4 3.68l3.71 2.88c2.17-2 3.71-4.95 3.71-8.8z"/>
                        <path fill="#FBBC05" d="M4.93 14.55A7.18 7.18 0 014.55 12c0-.89.15-1.75.38-2.55L1.27 6.61A11.97 11.97 0 000 12c0 1.94.46 3.77 1.27 5.39l3.66-2.84z"/>
                        <path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.71-2.88c-1.08.72-2.45 1.16-4.22 1.16-3.36 0-6.2-2.27-7.21-5.32l-3.66 2.84C3.26 21.31 7.31 24 12 24z"/>
                    </svg>
                    <span>Connect with Google</span>
                </a>
            @endif

            <div class="mt-8 pt-6 border-t border-slate-100 grid grid-cols-3 gap-3 text-center">
                <div class="p-2.5 rounded-xl bg-slate-50">
                    <div class="text-xs font-semibold text-slate-800">Encrypted</div>
                    <div class="text-[11px] text-slate-500 mt-0.5">Tokens in DB</div>
                </div>
                <div class="p-2.5 rounded-xl bg-slate-50">
                    <div class="text-xs font-semibold text-slate-800">Auto Refresh</div>
                    <div class="text-[11px] text-slate-500 mt-0.5">Offline OAuth</div>
                </div>
                <div class="p-2.5 rounded-xl bg-slate-50">
                    <div class="text-xs font-semibold text-slate-800">Threaded</div>
                    <div class="text-[11px] text-slate-500 mt-0.5">RFC 2822 Reply</div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

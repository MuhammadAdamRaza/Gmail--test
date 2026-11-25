@extends('layouts.app')

@section('title', $message['subject'] . ' - Gmail Test')

@section('content')
    <div class="space-y-5">
        <!-- Top Action Bar -->
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <a href="{{ route('gmail.inbox') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-600 hover:text-slate-900 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
                Back to Inbox
            </a>

            <div class="flex items-center gap-2 flex-wrap">
                <form action="{{ route('gmail.star', $message['id']) }}" method="POST">
                    @csrf
                    <input type="hidden" name="star" value="{{ $message['isStarred'] ? '0' : '1' }}">
                    <button type="submit" class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-xs font-medium text-slate-700 cursor-pointer">
                        {{ $message['isStarred'] ? '★ Starred' : '☆ Star' }}
                    </button>
                </form>

                <form action="{{ route('gmail.read', $message['id']) }}" method="POST">
                    @csrf
                    <input type="hidden" name="read" value="0">
                    <button type="submit" class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-xs font-medium text-slate-700 cursor-pointer">
                        Mark Unread
                    </button>
                </form>

                @if(!$message['isTrash'])
                    <form action="{{ route('gmail.archive', $message['id']) }}" method="POST">
                        @csrf
                        <button type="submit" class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-xs font-medium text-slate-700 cursor-pointer">
                            Archive
                        </button>
                    </form>

                    <form action="{{ route('gmail.trash', $message['id']) }}" method="POST">
                        @csrf
                        <button type="submit" class="px-3 py-1.5 rounded-lg border border-red-200 bg-white hover:bg-red-50 text-xs font-medium text-red-600 cursor-pointer">
                            Trash
                        </button>
                    </form>
                @else
                    <form action="{{ route('gmail.untrash', $message['id']) }}" method="POST">
                        @csrf
                        <button type="submit" class="px-3 py-1.5 rounded-lg border border-emerald-200 bg-emerald-50 text-xs font-medium text-emerald-700 cursor-pointer">
                            Restore
                        </button>
                    </form>
                @endif

                <button type="button" onclick="document.getElementById('forwardCard').classList.toggle('hidden')" class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-xs font-medium text-slate-700 cursor-pointer">
                    Forward
                </button>
            </div>
        </div>

        <!-- Thread Subject Header -->
        <div class="bg-white rounded-2xl border border-slate-200 px-6 py-4 flex items-center justify-between gap-4">
            <h2 class="text-xl font-bold text-slate-900">{{ $message['subject'] }}</h2>
            <span class="text-xs text-slate-400 font-mono shrink-0">
                {{ count($message['threadMessages']) }} {{ count($message['threadMessages']) === 1 ? 'message' : 'messages' }}
            </span>
        </div>

        <!-- Forward Card (Hidden by default) -->
        <div id="forwardCard" class="hidden bg-white rounded-2xl border border-blue-200 shadow-xs p-6">
            <h3 class="text-sm font-semibold text-slate-900 mb-3">Forward Message</h3>
            <form method="POST" action="{{ route('gmail.forward', $message['id']) }}" class="space-y-3">
                @csrf
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Forward To</label>
                    <input type="email" name="to" required placeholder="recipient@example.com" class="w-full px-3.5 py-2 text-sm border border-slate-200 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Optional Note</label>
                    <textarea name="note" rows="3" maxlength="2000" placeholder="Add a note..." class="w-full px-3.5 py-2 text-sm border border-slate-200 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none"></textarea>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="document.getElementById('forwardCard').classList.add('hidden')" class="px-4 py-2 text-xs font-medium text-slate-600 hover:bg-slate-100 rounded-xl cursor-pointer">Cancel</button>
                    <button type="submit" class="px-4 py-2 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-xl cursor-pointer">Send Forward</button>
                </div>
            </form>
        </div>

        <!-- Thread Messages -->
        @foreach($message['threadMessages'] as $idx => $threadMsg)
            <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
                <div class="p-6 border-b border-slate-100">
                    <div class="flex items-start justify-between gap-4 flex-wrap">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-blue-600 text-white font-semibold flex items-center justify-center shrink-0">
                                {{ strtoupper(substr($threadMsg['fromName'], 0, 1)) }}
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-slate-900">{{ $threadMsg['from'] }}</div>
                                @if(!empty($threadMsg['to']))
                                    <div class="text-xs text-slate-500">To: {{ $threadMsg['to'] }}</div>
                                @endif
                                @if(!empty($threadMsg['cc']))
                                    <div class="text-xs text-slate-500">Cc: {{ $threadMsg['cc'] }}</div>
                                @endif
                            </div>
                        </div>
                        <div class="text-xs text-slate-500">{{ $threadMsg['date'] }}</div>
                    </div>

                    @if(count($threadMsg['attachments']) > 0)
                        <div class="mt-4 pt-4 border-t border-slate-100 flex flex-wrap gap-2">
                            @foreach($threadMsg['attachments'] as $att)
                                <a
                                    href="{{ route('gmail.attachment', ['id' => $threadMsg['id'], 'attachmentId' => $att['id'], 'filename' => $att['filename'], 'mime' => $att['mimeType']]) }}"
                                    class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-xs font-medium text-slate-700 transition"
                                >
                                    <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                                    </svg>
                                    <span>{{ $att['filename'] }}</span>
                                    <span class="text-slate-400">({{ number_format($att['size'] / 1024, 1) }} KB)</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>

                <!-- Body -->
                <div class="p-6">
                    @if(!empty($threadMsg['htmlBody']))
                        <div class="flex items-center justify-end gap-2 mb-3">
                            <button type="button" onclick="toggleThreadBody({{ $idx }}, 'html')" id="btnHtml_{{ $idx }}" class="px-3 py-1 rounded-lg text-xs font-medium bg-blue-50 text-blue-700 cursor-pointer">Rich HTML</button>
                            <button type="button" onclick="toggleThreadBody({{ $idx }}, 'plain')" id="btnPlain_{{ $idx }}" class="px-3 py-1 rounded-lg text-xs font-medium text-slate-500 hover:bg-slate-100 cursor-pointer">Plain Text</button>
                        </div>

                        <div id="htmlWrap_{{ $idx }}">
                            <iframe
                                sandbox="allow-same-origin"
                                srcdoc="{{ $threadMsg['htmlBody'] }}"
                                class="w-full min-h-[260px] border border-slate-100 rounded-xl bg-white"
                                onload="this.style.height = Math.max(260, (this.contentWindow.document.body.scrollHeight + 24)) + 'px';"
                            ></iframe>
                        </div>

                        <div id="plainWrap_{{ $idx }}" class="hidden whitespace-pre-wrap break-words text-sm text-slate-700 leading-relaxed bg-slate-50 p-4 rounded-xl border border-slate-100">{{ $threadMsg['plainBody'] }}</div>
                    @else
                        <div class="whitespace-pre-wrap break-words text-sm text-slate-700 leading-relaxed">{{ $threadMsg['plainBody'] }}</div>
                    @endif
                </div>
            </div>
        @endforeach

        <script>
            function toggleThreadBody(idx, mode) {
                const htmlWrap = document.getElementById('htmlWrap_' + idx);
                const plainWrap = document.getElementById('plainWrap_' + idx);
                const btnHtml = document.getElementById('btnHtml_' + idx);
                const btnPlain = document.getElementById('btnPlain_' + idx);
                if (mode === 'html') {
                    htmlWrap.classList.remove('hidden');
                    plainWrap.classList.add('hidden');
                    btnHtml.className = 'px-3 py-1 rounded-lg text-xs font-medium bg-blue-50 text-blue-700 cursor-pointer';
                    btnPlain.className = 'px-3 py-1 rounded-lg text-xs font-medium text-slate-500 hover:bg-slate-100 cursor-pointer';
                } else {
                    htmlWrap.classList.add('hidden');
                    plainWrap.classList.remove('hidden');
                    btnPlain.className = 'px-3 py-1 rounded-lg text-xs font-medium bg-blue-50 text-blue-700 cursor-pointer';
                    btnHtml.className = 'px-3 py-1 rounded-lg text-xs font-medium text-slate-500 hover:bg-slate-100 cursor-pointer';
                }
            }
        </script>

        <!-- Reply Card -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-xs p-6">
            <h3 class="text-sm font-semibold text-slate-900 mb-3">
                Reply to {{ $message['fromName'] }}
            </h3>
            <form method="POST" action="{{ route('gmail.reply', $message['id']) }}" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <textarea
                    name="body"
                    rows="5"
                    maxlength="5000"
                    required
                    placeholder="Write your reply..."
                    class="w-full px-4 py-3 text-sm border border-slate-200 rounded-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 outline-none transition"
                >{{ old('body') }}</textarea>

                <div class="flex items-center justify-between gap-4 flex-wrap">
                    <div class="flex items-center gap-4 flex-wrap">
                        <label class="inline-flex items-center gap-2 text-xs text-slate-600 cursor-pointer">
                            <input type="checkbox" name="reply_all" value="1" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                            <span>Reply All</span>
                        </label>
                        <input type="file" name="attachments[]" multiple class="text-xs text-slate-600 file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-slate-100 file:text-slate-700 hover:file:bg-slate-200">
                    </div>

                    <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-medium text-sm shadow-sm transition cursor-pointer">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
                        </svg>
                        Send Reply
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection

@if($recording->status === 'ready' && $recording->expires_at?->isFuture())
    <a href="{{ route('recordings.index', ['play'=>$recording->id]) }}" class="inline-flex min-h-10 items-center gap-2 rounded-xl bg-blue-50 px-3 text-xs font-bold text-blue-700 hover:bg-blue-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600" aria-label="پخش صدای تماس">▶ پخش</a>
    @if(auth()->user()->hasPermission('recordings.download'))<a href="{{ route('recordings.download', $recording->id) }}" class="inline-flex min-h-10 items-center rounded-xl px-3 text-xs font-bold text-slate-600 hover:bg-slate-100">دانلود</a>@endif
@else
    <span class="text-xs text-slate-500">{{ $recording->status === 'ready' ? 'منقضی‌شده' : $recording->statusLabel() }}</span>
@endif

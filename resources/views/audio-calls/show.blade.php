@extends('layouts.portal')
@section('title', 'جزئیات تماس صوتی')
@section('content')
<section class="panel mx-auto max-w-2xl space-y-4 p-6">
    <h1 class="text-xl font-bold">تماس صوتی با <bdi>{{ $call->destination }}</bdi></h1>
    <p role="status" class="rounded-xl bg-blue-50 p-4 font-bold text-blue-900">{{ __('audio_calls.'.($call->outcome ?? $call->status), locale: 'fa') }}</p>
    <dl class="space-y-2 text-sm">
        <div><dt class="inline font-bold">شناسه تماس</dt> <dd class="inline"><bdi>{{ $call->id }}</bdi></dd></div>
        <div><dt class="inline font-bold">شناسه تماس‌گیرنده</dt> <dd class="inline"><bdi>{{ $call->caller_id }}</bdi></dd></div>
        <div><dt class="inline font-bold">زمان پاسخ</dt> <dd class="inline"><bdi>{{ $call->answered_at?->timezone(config('voip.display_timezone'))->format('Y/m/d H:i:s') ?? '—' }}</bdi></dd></div>
        <div><dt class="inline font-bold">زمان آغاز پخش</dt> <dd class="inline"><bdi>{{ $call->playback_started_at?->timezone(config('voip.display_timezone'))->format('Y/m/d H:i:s') ?? '—' }}</bdi></dd></div>
    </dl>
    <div class="flex flex-wrap gap-4 text-sm font-bold text-blue-700">
        <a href="{{ route('customer.audio-calls.index') }}#call-history">تاریخچه تماس‌ها</a>
        <a href="{{ route('customer.audio-calls.index', ['to' => $call->destination, 'caller_id_id' => $call->caller_id_id, 'audio_id' => $call->audio_id]) }}#new-call">استفاده دوباره از تنظیمات</a>
        @unless($call->finished_at)
            <a href="{{ route('customer.audio-calls.show', $call->id) }}">به‌روزرسانی وضعیت</a>
        @endunless
    </div>
</section>
@endsection

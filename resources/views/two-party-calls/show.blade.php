@extends('layouts.portal')
@section('title', 'جزئیات تماس دوطرفه')
@section('content')
<div data-two-party-page>
    <a href="{{ route('customer.two-party.index') }}" class="text-sm font-bold text-blue-700">بازگشت به تماس دوطرفه</a>
    <section data-call-card data-status-url="{{ $resource['url'] }}" data-cancel-url="{{ $resource['cancel_url'] }}" class="mt-5 rounded-2xl border border-slate-200 bg-white p-6">
        <h1 class="text-xl font-black">داخلی {{ $call->agent_label }} ← <bdi>{{ $call->customer_number }}</bdi></h1>
        <p class="mt-3 text-sm text-slate-500">شماره تماس‌گیرنده: <bdi>{{ $call->caller_id }}</bdi></p>
        <p data-call-status role="status" class="mt-5 font-bold">{{ $resource['status_label'] }} {{ $resource['failure_reason'] }}</p>
        <p class="mt-3 text-sm">مدت مکالمه: <span data-call-duration>{{ $call->conversation_seconds }}</span> ثانیه</p>
        <button data-call-cancel type="button" @if($call->terminal()) hidden @endif class="mt-4 min-h-11 rounded-xl border border-red-200 px-5 text-sm font-bold text-red-700">لغو تماس</button>
        <ol data-call-events class="mt-6 space-y-3 border-r-2 border-blue-100 pr-5">@foreach($resource['events'] as $event)<li class="text-sm">{{ $event['status'] }} <time class="mr-3 text-xs text-slate-400">{{ \Carbon\CarbonImmutable::parse($event['at'])->timezone(config('voip.display_timezone'))->format('H:i:s') }}</time></li>@endforeach</ol>
        <div data-call-recording class="mt-6 border-t border-slate-100 pt-5"><p data-recording-status class="text-sm text-slate-500">{{ $resource['recording_status'] ?: 'ضبط خاموش است' }}</p><audio data-recording-player controls preload="none" @unless($resource['recording_audio']) hidden @endunless @if($resource['recording_audio']) src="{{ $resource['recording_audio'] }}" @endif class="mt-3 w-full"></audio><a data-recording-download @unless($resource['recording_download']) hidden @endunless href="{{ $resource['recording_download'] ?: '#' }}" class="mt-3 inline-block text-sm font-bold text-blue-700">دانلود صدای مکالمه</a></div>
    </section>
</div>
@endsection

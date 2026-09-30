@if($schedule)
    @foreach(['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'] as $day => $name)
        @if(!empty($schedule['weekly'][$day]))<p class="text-xs leading-6">{{ $name }}: @foreach($schedule['weekly'][$day] as $interval)<span dir="ltr">{{ $interval['start'] }}–{{ $interval['end'] }}</span>{{ $loop->last ? '' : '، ' }}@endforeach</p>@endif
    @endforeach
    <small class="mt-1 block text-slate-500">{{ $schedule['timezone'] ?? 'منطقه زمانی نامعتبر' }} · {{ count($schedule['closed_dates'] ?? []) }} تعطیلی ویژه</small>
@else
    <span>همیشه</span>
@endif

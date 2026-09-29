@php($renderedSections = 0)
@foreach ($navigationSections as $section)
    @php($visibleItems = array_values(array_filter($section['items'], fn ($item) => ($item['available'] ?? true) && (! isset($item['permission']) || $panelUser->hasPermission($item['permission'])))))
    @if (count($visibleItems))
        <div class="{{ $renderedSections ? 'mt-7' : '' }} px-3 text-[10px] font-bold tracking-wide text-blue-100/55">{{ $section['label'] }}</div>
        <nav class="mt-3 space-y-1" aria-label="{{ $section['label'] }}">
            @foreach ($visibleItems as $item)
                @php($active = request()->routeIs(...($item['active'] ?? [$item['route']])))
                <a href="{{ route($item['route']) }}" @if ($active) aria-current="page" @endif @class(['flex items-center gap-3 rounded-xl px-3 font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white', 'h-11 text-[13px]' => ! $mobile, 'py-3 text-sm' => $mobile, 'bg-white/12 text-white' => $active, 'text-blue-100/75 hover:bg-white/8 hover:text-white' => ! $active])>
                    <svg aria-hidden="true" class="size-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $item['icon'] }}"/></svg>
                    <span class="flex-1">{{ $item['label'] }}</span>
                    @if ($active)<span aria-hidden="true" class="size-1.5 rounded-full bg-[#62a4ff]"></span>@endif
                </a>
            @endforeach
        </nav>
        @php($renderedSections++)
    @endif
@endforeach

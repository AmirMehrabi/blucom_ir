<ol class="mr-4 mt-2 list-decimal space-y-2 border-r border-slate-200 pr-5 text-slate-700">
    @foreach ($keys as $key)
        @php
            $node = $followMeCatalog->get($key);
        @endphp
        <li>
            @if ($node)
                @php
                    $link = match (explode(':', $key)[0]) {
                        'queue' => route('teams.index').'#queue-'.$node->id,
                        'ivr' => route('ivr-menus.edit', $node),
                        default => request()->attributes->get('customer_portal') ? route('customer.extensions.show', $node).'#follow-me' : route('sip-extensions.edit', $node),
                    };
                    $edges = $followMe->edges($node);
                    $branchLabel = match (explode(':', $key)[0]) {
                        'queue' => 'پس از پایان انتظار',
                        'ivr' => 'انتخاب‌های تماس‌گیرنده و مقصد پیش‌فرض',
                        default => 'اگر داخلی پاسخ ندهد',
                    };
                @endphp
                <a href="{{ $link }}" class="font-semibold text-blue-700 underline">{{ $followMe->label($node) }}</a>
                @if (!$followMe->available($node)) <span class="text-slate-500">· در دسترس نیست</span> @endif
                @if ($edges && !in_array($key, $path, true) && count($path) <= 5 && $remaining->nodes > 0)
                    @php
                        $remaining->nodes--;
                    @endphp
                    <p class="mt-1 text-xs text-slate-500">{{ $branchLabel }}</p>
                    @include('shared.follow-me-tree', ['keys' => $edges, 'path' => [...$path, $key]])
                @endif
            @else
                <span class="text-slate-500">مقصد حذف شده</span>
            @endif
        </li>
    @endforeach
</ol>

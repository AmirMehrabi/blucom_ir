<ul class="grid gap-3 sm:grid-cols-2" aria-label="امکانات پلن">
    @foreach($selection['features'] as $feature)
    <li class="flex items-center gap-2 leading-7"><svg aria-hidden="true" class="size-5 shrink-0 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6"/></svg>{{ $feature }}</li>
    @endforeach
</ul>
@if(count($selection['limits']))
<div class="mt-6 border-t pt-5"><p class="text-sm font-bold text-slate-600">ظرفیت پلن برای حساب کسب‌وکار</p><ul class="mt-3 flex flex-wrap gap-2">@foreach($selection['limits'] as $limit)<li class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">{{ $limit }}</li>@endforeach</ul></div>
@endif

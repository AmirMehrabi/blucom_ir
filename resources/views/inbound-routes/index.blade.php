@extends('layouts.portal')
@section('content')
<div class="mb-7">
    <h1 class="text-xl font-extrabold">مقصد تماس‌های ورودی</h1>
    <p class="mt-1 text-sm text-slate-500">برای هر شماره مشخص کنید تماس‌ها به کدام پاسخ‌گو برسند.</p>
</div>

@if (session('status'))
    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
        <ul class="list-disc space-y-1 pr-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
@if ($numbers->isEmpty() || $extensions->isEmpty())
    <div class="mb-5 rounded-xl border border-blue-200 bg-blue-50 px-5 py-4 text-sm leading-7 text-blue-900">
        برای تعیین مقصد تماس، ابتدا
        @if ($numbers->isEmpty())<a class="font-bold underline" href="{{ route('admin.sip-numbers.index') }}">یک شماره فعال</a>@endif
        @if ($numbers->isEmpty() && $extensions->isEmpty()) و @endif
        @if ($extensions->isEmpty())<a class="font-bold underline" href="{{ route('sip-extensions.index') }}">یک داخلی فعال</a>@endif
        داشته باشید.
    </div>
@endif

<section class="panel mb-6 overflow-hidden">
    <div class="border-b border-slate-100 p-5"><h2 class="font-bold">اتصال یک شماره به پاسخ‌گو</h2></div>
    <form method="POST" action="{{ route('inbound-routes.store') }}" class="grid gap-4 p-5 sm:grid-cols-2">
        @csrf
        <div>
            <label class="mb-1 block text-xs font-bold text-slate-500">شماره‌ای که مشتری با آن تماس می‌گیرد</label>
            <select name="sip_number_id" required class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                <option value="">— انتخاب شماره —</option>
                @foreach ($numbers as $number)
                    <option value="{{ $number->id }}" @selected((string) old('sip_number_id') === (string) $number->id)>{{ $number->normalized_number }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-bold text-slate-500">تماس به کجا برود؟</label>
            <input type="hidden" name="destination_type" value="extension">
            <select name="destination_id" required class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                <option value="">— انتخاب پاسخ‌گو —</option>
                @foreach ($extensions as $extension)
                    <option value="{{ $extension->id }}" @selected((string) old('destination_id') === (string) $extension->id)>{{ $extension->display_name ?: 'داخلی '.$extension->extension }} · {{ $extension->extension }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500">در حال حاضر می‌توانید تماس را به یک داخلی فعال وصل کنید.</p>
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input type="hidden" name="enabled" value="0" />
            <input type="checkbox" name="enabled" value="1" @checked(old('enabled', true)) />
            فعال
        </label>
        <div class="flex items-end">
            <button class="rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-bold text-white" @disabled($numbers->isEmpty() || $extensions->isEmpty())>ذخیره مقصد تماس</button>
        </div>
    </form>
</section>

<section class="panel overflow-hidden">
    <div class="border-b border-slate-100 p-5"><h2 class="font-bold">شماره‌ها و مقصد تماس آن‌ها</h2></div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[640px] text-right text-sm">
            <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                <tr>
                    <th class="px-5 py-3">شماره</th>
                    <th class="px-5 py-3">پاسخ‌گو</th>
                    <th class="px-5 py-3">وضعیت</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($routes as $route)
                    <tr class="hover:bg-slate-50">
                        <td class="px-5 py-4 font-semibold" dir="ltr">{{ $route->sipNumber?->normalized_number }}</td>
                        <td class="px-5 py-4">{{ $route->destinationLabel() }}</td>
                        <td class="px-5 py-4">
                            <form method="POST" action="{{ route('inbound-routes.update', $route) }}" class="flex flex-wrap items-center gap-2">
                                @csrf
                                @method('PUT')
                                @if ($route->destination_type === \App\Models\InboundRoute::DESTINATION_EXTENSION)
                                <input type="hidden" name="destination_type" value="extension">
                                <select name="destination_id" aria-label="پاسخ‌گوی تماس" class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs">
                                    @foreach ($extensions as $extension)
                                        <option value="{{ $extension->id }}" @selected($route->destination_id === $extension->id)>{{ $extension->display_name ?: 'داخلی '.$extension->extension }} · {{ $extension->extension }}</option>
                                    @endforeach
                                </select>
                                @endif
                                <label class="text-xs"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked($route->enabled)> فعال</label>
                                <button class="rounded-lg bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-700">ذخیره</button>
                            </form>
                        </td>
                        <td class="px-5 py-4">
                            <form method="POST" action="{{ route('inbound-routes.destroy', $route) }}" onsubmit="return confirm('حذف مسیر؟')">
                                @csrf
                                @method('DELETE')
                                <button class="text-xs font-bold text-red-600">حذف</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-5 py-6 text-slate-400" colspan="4">هنوز مقصدی برای تماس‌های ورودی تعیین نشده است.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

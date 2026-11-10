@if(!auth()->user()->isAdmin() && auth()->user()->hasPermission('reports.export'))
<div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4">
    <div><p class="text-sm font-bold">این گزارش را در اکسل بررسی کنید</p><p class="mt-1 text-xs text-slate-500">تمام تماس‌های مطابق فیلترهای فعلی، همراه خلاصه نتایج؛ فایل در پس‌زمینه آماده می‌شود.</p></div>
    <div class="flex items-center gap-3"><form method="POST" data-export-request action="{{ route('reports.exports.store') }}">@csrf
    @foreach($filters as $key=>$value)@if($value !== null)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach
    <button class="min-h-11 rounded-xl bg-emerald-700 px-4 text-sm font-bold text-white hover:bg-emerald-800">ساخت خروجی Excel</button></form>
    <a class="text-sm font-bold text-blue-700" href="{{ route('reports.exports.index') }}">خروجی‌های من</a></div>
</div>
@endif

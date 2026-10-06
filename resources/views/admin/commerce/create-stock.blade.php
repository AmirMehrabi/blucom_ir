<details class="panel mt-6 p-5" id="new-stock" open>
    <summary class="cursor-pointer font-bold">آماده‌سازی موجودی فروش</summary>
    <p class="mt-2 text-sm text-slate-500">شماره بدون مالک و بدون مسیر تماس ذخیره می‌شود. بررسی فنی و انتشار در مرحله بعد انجام می‌شود.</p>
    <form method="POST" action="{{ route('admin.inventory.store') }}" class="mt-5 grid gap-4 sm:grid-cols-2">
        @csrf
        <label>شماره<input class="mt-1 w-full rounded-lg border p-3" name="number" dir="ltr" value="{{ old('number') }}" required></label>
        <label>برچسب<input class="mt-1 w-full rounded-lg border p-3" name="label" value="{{ old('label') }}"></label>
        <label>اتصال زیرساخت<select name="provider_gateway_id" class="mt-1 w-full rounded-lg border p-3"><option value="">بعداً انتخاب می‌کنم</option>@foreach($gateways->whereNull('tenant_id') as $gateway)<option value="{{ $gateway->id }}" @selected(old('provider_gateway_id') == $gateway->id)>{{ $gateway->name }}</option>@endforeach</select></label>
        <label>پیش‌شماره‌های مجاز مقصد<input class="mt-1 w-full rounded-lg border p-3" name="destination_prefixes_text" dir="ltr" placeholder="+9821 +989" value="{{ old('destination_prefixes_text') }}"><small class="text-slate-500">با فاصله جدا کنید؛ سیاست شماره در فاز مجوز تماس اعمال خواهد شد.</small></label>
        <div class="flex gap-4">@foreach(['enabled' => 'فعال در تنظیمات', 'inbound_enabled' => 'ورودی', 'outbound_enabled' => 'خروجی'] as $field => $label)<label><input type="hidden" name="{{ $field }}" value="0"><input type="checkbox" name="{{ $field }}" value="1" @checked(old($field, true))> {{ $label }}</label>@endforeach</div>
        <button class="rounded-xl bg-blue-600 p-3 font-bold text-white">ذخیره و ادامه بررسی</button>
    </form>
</details>

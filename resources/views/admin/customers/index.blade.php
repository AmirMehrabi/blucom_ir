@extends('layouts.portal')
@section('title', 'مشتریان')
@section('content')
<div class="mb-7"><h1 class="text-2xl font-black">مشتریان و سازمان‌ها</h1><p class="mt-2 text-sm text-slate-500">حساب‌های مشتری از کاربران داخلی جدا هستند. ورود مشتریان از {{ config('portal.customer_domain') }} انجام می‌شود.</p></div>
<div class="grid gap-5 lg:grid-cols-2">
    <section class="panel p-5">
        <h2 class="font-bold">سازمان و مالک جدید</h2>
        <form method="POST" action="{{ route('admin.customers.store') }}" class="mt-4 space-y-4">@csrf
            <input type="hidden" name="role" value="owner">
            <label class="block text-sm font-semibold">نام مالک<input name="name" required maxlength="100" class="mt-2 w-full rounded-xl border p-3"></label>
            <label class="block text-sm font-semibold">موبایل<input name="mobile" required dir="ltr" class="mt-2 w-full rounded-xl border p-3"></label>
            <label class="block text-sm font-semibold">نام سازمان<input name="business" required maxlength="255" class="mt-2 w-full rounded-xl border p-3"></label>
            <button class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white">ساخت سازمان و مالک</button>
        </form>
    </section>
    <section class="panel p-5">
        <h2 class="font-bold">همکار مشتری</h2>
        <form method="POST" action="{{ route('admin.customers.store') }}" class="mt-4 space-y-4">@csrf
            <input type="hidden" name="role" value="staff">
            <label class="block text-sm font-semibold">نام<input name="name" required maxlength="100" class="mt-2 w-full rounded-xl border p-3"></label>
            <label class="block text-sm font-semibold">موبایل<input name="mobile" required dir="ltr" class="mt-2 w-full rounded-xl border p-3"></label>
            <label class="block text-sm font-semibold">سازمان<select name="tenant_id" required class="mt-2 w-full rounded-xl border p-3"><option value="">انتخاب کنید</option>@foreach ($tenants as $tenant)<option value="{{ $tenant->id }}">{{ $tenant->name }}</option>@endforeach</select></label>
            <p class="text-xs text-slate-500">همکار جدید فقط داشبورد، خط‌ها و وضعیت پاسخ‌گویی را می‌بیند. دسترسی‌های بیشتر را پس از ساخت حساب تعیین کنید.</p>
            <button class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white">ساخت همکار</button>
        </form>
    </section>
</div>
<section class="mt-7 space-y-3" aria-label="فهرست مشتریان">
    @forelse ($customers as $customer)
        <details class="panel p-5">
            <summary class="cursor-pointer text-sm"><strong>{{ $customer->name }}</strong> · {{ $customer->tenant->name }} · {{ $customer->role === \App\Enums\CustomerRole::Owner ? 'مالک' : 'همکار' }} · {{ $customer->isDisabled() ? 'غیرفعال' : 'فعال' }} <span dir="ltr">{{ $customer->mobile }}</span></summary>
            <form method="POST" action="{{ route('admin.customers.update', $customer) }}" class="mt-5 space-y-4 border-t pt-5">@csrf @method('PUT')
                <div class="grid gap-4 sm:grid-cols-3">
                    <label class="text-sm font-semibold">نام<input name="name" value="{{ $customer->name }}" required class="mt-2 w-full rounded-xl border p-3"></label>
                    <label class="text-sm font-semibold">داخلی<select name="sip_extension_id" class="mt-2 w-full rounded-xl border p-3"><option value="">بدون داخلی</option>@foreach ($extensions->where('tenant_id', $customer->tenant_id) as $extension)<option value="{{ $extension->id }}" @selected($customer->sip_extension_id === $extension->id)>{{ $extension->display_name ?: $extension->extension }} · {{ $extension->extension }}</option>@endforeach</select></label>
                    <label class="flex items-center gap-2 text-sm"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked(!$customer->isDisabled())>حساب فعال</label>
                </div>
                <fieldset><legend class="mb-3 text-sm font-bold">دسترسی‌های مشتری</legend><div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">@foreach ($permissionLabels as $key => $label)<label class="flex items-center gap-2 rounded-xl border p-3 text-sm"><input type="checkbox" name="permissions[]" value="{{ $key }}" @checked($customer->permissions->contains('permission', $key))>{{ $label }}</label>@endforeach</div></fieldset>
                <button class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white">ذخیره تغییرات</button>
            </form>
            @if ($customer->role === \App\Enums\CustomerRole::Owner)
                <form method="POST" action="{{ route('admin.customers.business', $customer->tenant) }}" class="mt-4 border-t pt-4">@csrf @method('PUT')
                    <input type="hidden" name="status" value="{{ $customer->tenant->isActive() ? 'disabled' : 'active' }}">
                    <p class="mb-3 text-xs text-slate-500">غیرفعال کردن سازمان دسترسی همه مشتریان آن سازمان و تماس‌های جدید را متوقف می‌کند.</p>
                    <button class="rounded-xl border px-4 py-2 text-sm font-bold {{ $customer->tenant->isActive() ? 'text-red-600' : 'text-emerald-600' }}">{{ $customer->tenant->isActive() ? 'غیرفعال کردن سازمان' : 'فعال کردن سازمان' }}</button>
                </form>
            @endif
        </details>
    @empty
        <p class="panel p-5 text-sm text-slate-500">هنوز حساب مشتری ساخته نشده است.</p>
    @endforelse
    {{ $customers->links() }}
</section>
@endsection

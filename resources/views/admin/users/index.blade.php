@extends('layouts.portal')
@section('title', 'کاربران')
@section('content')
<div class="mb-7"><h1 class="text-2xl font-black">کاربران و دسترسی‌ها</h1><p class="mt-2 text-sm text-slate-500">مدیران به همه بخش‌ها دسترسی دارند. برای اپراتورها فقط کارهای موردنیاز را انتخاب کنید.</p></div>

<section class="panel p-5 sm:p-6">
    <h2 class="font-bold">افزودن کاربر</h2>
    <form method="POST" action="{{ route('users.store') }}" class="mt-5 space-y-5">@csrf
        <div class="grid gap-4 md:grid-cols-3">
            <label class="text-sm font-semibold">نام<input name="name" value="{{ old('name') }}" required class="mt-2 w-full rounded-xl border p-3"></label>
            <label class="text-sm font-semibold">موبایل<input name="mobile" value="{{ old('mobile') }}" required dir="ltr" class="mt-2 w-full rounded-xl border p-3" placeholder="09123456789"></label>
            <label class="text-sm font-semibold">نقش<select name="role" class="mt-2 w-full rounded-xl border p-3"><option value="operator">اپراتور</option><option value="admin">مدیر</option></select></label>
        </div>
        <fieldset><legend class="mb-3 text-sm font-bold">دسترسی‌های اپراتور</legend><div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($permissionLabels as $key => $label)
                <label class="flex items-center gap-2 rounded-xl border border-slate-200 p-3 text-sm"><input type="checkbox" name="permissions[]" value="{{ $key }}" @checked(in_array($key, old('permissions', \App\Support\Permissions::OPERATOR_DEFAULTS)))><span>{{ $label }}</span></label>
            @endforeach
        </div></fieldset>
        <button class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white">ساخت کاربر</button>
    </form>
</section>

<section class="mt-7 space-y-3" aria-label="فهرست کاربران">
    @foreach ($users as $user)
        <details class="panel group p-5"><summary class="flex cursor-pointer list-none items-center justify-between gap-4"><span><strong>{{ $user->name }}</strong><small class="mr-3 text-slate-500" dir="ltr">{{ $user->mobile }}</small></span><span class="text-xs font-bold {{ $user->disabled_at ? 'text-red-600' : 'text-blue-700' }}">{{ $user->isAdmin() ? 'مدیر' : 'اپراتور' }} · {{ $user->disabled_at ? 'غیرفعال' : 'فعال' }}</span></summary>
            <form method="POST" action="{{ route('users.update', $user) }}" class="mt-5 space-y-5 border-t pt-5">@csrf @method('PUT')
                <div class="grid gap-4 sm:grid-cols-3">
                    <label class="text-sm font-semibold">نام<input name="name" value="{{ $user->name }}" required class="mt-2 w-full rounded-xl border p-3"></label>
                    <label class="text-sm font-semibold">نقش<select name="role" class="mt-2 w-full rounded-xl border p-3"><option value="operator" @selected(!$user->isAdmin())>اپراتور</option><option value="admin" @selected($user->isAdmin())>مدیر</option></select></label>
                    <label class="flex items-center gap-2 text-sm font-semibold"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked(!$user->disabled_at)>حساب فعال</label>
                </div>
                <fieldset><legend class="mb-3 text-sm font-bold">دسترسی‌های اپراتور</legend><div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($permissionLabels as $key => $label)
                        <label class="flex items-center gap-2 rounded-xl border border-slate-200 p-3 text-sm"><input type="checkbox" name="permissions[]" value="{{ $key }}" @checked($user->permissions->contains('permission', $key))><span>{{ $label }}</span></label>
                    @endforeach
                </div></fieldset>
                <button class="rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white">ذخیره تغییرات</button>
            </form>
        </details>
    @endforeach
</section>
@endsection

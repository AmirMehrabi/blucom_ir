@extends('layouts.portal')
@section('content')
<h1 class="mb-2 text-xl font-extrabold">شماره‌های DID</h1>
<p class="mb-6 text-sm text-slate-500">شماره‌ها را ثبت کنید و دسترسی ورودی و خروجی را تنظیم کنید.</p>
@if (session('status')) <div class="mb-4 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-700">{{ session('status') }}</div> @endif
@if ($errors->any()) <div class="mb-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div> @endif
<section class="panel mb-6 p-5">
    <h2 class="mb-4 font-bold">شماره جدید</h2>
    <form method="POST" action="{{ route('admin.sip-numbers.store') }}" class="grid gap-4 sm:grid-cols-2">
        @csrf
        <label class="text-sm">شماره<input name="number" value="{{ old('number') }}" required dir="ltr" class="mt-1 block w-full rounded-xl border border-slate-200 p-2"></label>
        <label class="text-sm">برچسب<input name="label" value="{{ old('label') }}" class="mt-1 block w-full rounded-xl border border-slate-200 p-2"></label>
        <div class="flex flex-wrap items-center gap-5 text-sm">
            <label><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" checked> فعال</label>
            <label><input type="hidden" name="inbound_enabled" value="0"><input type="checkbox" name="inbound_enabled" value="1" checked> ورودی</label>
            <label><input type="hidden" name="outbound_enabled" value="0"><input type="checkbox" name="outbound_enabled" value="1" checked> خروجی</label>
        </div>
        <div><button class="rounded-xl bg-slate-900 px-5 py-2 text-sm font-bold text-white">ثبت شماره</button></div>
    </form>
</section>
<section class="panel overflow-x-auto">
    <table class="w-full min-w-[800px] text-right text-sm">
        <thead class="bg-slate-50 text-slate-500"><tr><th class="p-4">شماره</th><th class="p-4">نرمال‌شده</th><th class="p-4">تنظیمات</th><th class="p-4">عملیات</th></tr></thead>
        <tbody>
        @forelse ($numbers as $number)
            <tr class="border-t border-slate-100">
                <td class="p-4" dir="ltr">{{ $number->number }}</td>
                <td class="p-4" dir="ltr">{{ $number->normalized_number }}</td>
                <td class="p-4">
                    <form method="POST" action="{{ route('admin.sip-numbers.update', $number) }}" class="flex flex-wrap items-center gap-3">
                        @csrf @method('PUT')
                        <input name="label" value="{{ $number->label }}" placeholder="برچسب" class="rounded-lg border border-slate-200 p-2">
                        <label><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked($number->enabled)> فعال</label>
                        <label><input type="hidden" name="inbound_enabled" value="0"><input type="checkbox" name="inbound_enabled" value="1" @checked($number->inbound_enabled)> ورودی</label>
                        <label><input type="hidden" name="outbound_enabled" value="0"><input type="checkbox" name="outbound_enabled" value="1" @checked($number->outbound_enabled)> خروجی</label>
                        <button class="font-bold text-blue-700">ذخیره</button>
                    </form>
                </td>
                <td class="p-4"><form method="POST" action="{{ route('admin.sip-numbers.destroy', $number) }}" onsubmit="return confirm('حذف شماره؟')">@csrf @method('DELETE')<button class="font-bold text-red-600">حذف</button></form></td>
            </tr>
        @empty <tr><td class="p-5 text-slate-400" colspan="4">شماره‌ای ثبت نشده است.</td></tr> @endforelse
        </tbody>
    </table>
    <div class="p-4">{{ $numbers->links() }}</div>
</section>
@endsection

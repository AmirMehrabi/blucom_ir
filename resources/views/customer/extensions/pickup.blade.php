@extends('layouts.portal')
@section('title', 'پاسخ‌گویی به تماس همکار')
@section('content')
<a href="{{ route('customer.extensions.index') }}" class="commerce-link">→ داخلی‌ها</a>
<h1 class="mt-4 text-2xl font-black">پاسخ‌گویی به تماس همکار</h1>
<p class="mt-3 text-sm leading-7 text-slate-600">از تلفن متصل خود، برای پاسخ به داخلی مشخص <bdi dir="ltr">**شماره‌داخلی</bdi> و برای قدیمی‌ترین تماس گروه <bdi dir="ltr">*8</bdi> را شماره‌گیری کنید. فقط اعضای همان گروه و سازمان مجازند. این گروه تعیین نمی‌کند کدام تلفن زنگ بخورد.</p>
<p class="mt-3 text-sm leading-7 text-slate-600">این نسخه تماس مستقیمِ هنوز پاسخ‌داده‌نشده را پشتیبانی می‌کند. تماس‌های پاسخ‌داده‌شده، صف و منوی صوتی قابل برداشت نیستند. اگر تماس مجازی موجود نباشد، درخواست پایان می‌یابد.</p>
@if(!config('voip.pickup_enabled'))<p role="status" class="mt-4 rounded-xl bg-amber-50 p-4 text-sm">فعال‌سازی زیرساخت این قابلیت هنوز انجام نشده است. تنظیمات ذخیره می‌شوند، اما برداشت تماس تا فعال‌سازی سرویس امکان‌پذیر نیست.</p>@endif
<form method="POST" action="{{ route('customer.extensions.pickup.update') }}" class="panel mt-6 space-y-6 p-6">
@csrf @method('PUT')
@foreach(['pickup_enabled'=>'فعال‌سازی پاسخ‌گویی به تماس همکار', 'pickup_directed'=>'برداشت مستقیم با **شماره‌داخلی', 'pickup_group'=>'برداشت گروهی با *8'] as $key=>$label)
<label class="flex items-center gap-3"><input type="hidden" name="{{ $key }}" value="0"><input type="checkbox" name="{{ $key }}" value="1" @checked(old($key, $tenant->$key))>{{ $label }}</label>
@endforeach
<div class="overflow-x-auto"><table class="w-full text-right text-sm"><thead><tr><th class="p-3">داخلی</th><th class="p-3">گروه برداشت تماس</th><th class="p-3">اجازه برداشت تماس همکار</th></tr></thead><tbody>
@foreach($extensions as $extension)
<tr class="border-t"><td class="p-3">{{ $extension->display_name }} · <bdi>{{ $extension->extension }}</bdi><input type="hidden" name="extensions[{{ $loop->index }}][id]" value="{{ $extension->id }}"></td>
<td class="p-3"><select aria-label="گروه داخلی {{ $extension->extension }}" name="extensions[{{ $loop->index }}][pickup_group_id]" class="rounded-xl border p-3"><option value="">بدون گروه</option>@foreach($groups as $group)<option value="{{ $group->id }}" @selected((string)old('extensions.'.$loop->parent->index.'.pickup_group_id', $extension->pickup_group_id) === (string)$group->id)>{{ $group->name }}</option>@endforeach</select></td>
<td class="p-3"><input type="hidden" name="extensions[{{ $loop->index }}][pickup_allowed]" value="0"><input aria-label="اجازه برداشت برای داخلی {{ $extension->extension }}" type="checkbox" name="extensions[{{ $loop->index }}][pickup_allowed]" value="1" @checked(old('extensions.'.$loop->index.'.pickup_allowed', $extension->pickup_allowed))></td></tr>
@endforeach
</tbody></table></div>
<p class="text-xs leading-7 text-slate-500">خاموش‌کردن اجازه برداشت یک داخلی، دریافت تماس خودش یا برداشت تماس آن توسط همکار مجاز را متوقف نمی‌کند. تغییرات برای درخواست بعدی اعمال می‌شوند و تماس جاری قطع نمی‌شود.</p>
<label class="block text-sm font-bold">ساخت گروه جدید<input name="new_group" maxlength="100" value="{{ old('new_group') }}" placeholder="مثلاً فروش" class="mt-2 block w-full rounded-xl border p-3"></label>
<label class="block text-sm font-bold">حذف گروه<select name="delete_group" class="mt-2 block w-full rounded-xl border p-3"><option value="">هیچ‌کدام</option>@foreach($groups as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach</select></label>
<p class="text-xs text-slate-500">با حذف گروه، عضویت داخلی‌های آن پاک می‌شود. پس از ساخت گروه، اعضای آن را انتخاب و دوباره ذخیره کنید.</p>
<button class="commerce-button">ذخیره تنظیمات</button>
</form>
@endsection

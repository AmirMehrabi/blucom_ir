<div>
    <label class="block text-sm font-bold" for="{{ $inputId }}">شماره داخلی</label>
    <input required id="{{ $inputId }}" name="extension" value="{{ old('line_form', 'answer') === $formName ? old('extension', $suggestedExtension) : $suggestedExtension }}" inputmode="numeric" dir="ltr" maxlength="9" aria-describedby="{{ $inputId }}-hint{{ $errors->has('extension') && old('line_form', 'answer') === $formName ? ' '.$inputId.'-error' : '' }}" @if($errors->has('extension') && old('line_form', 'answer') === $formName) aria-invalid="true" @endif class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3">
    <p id="{{ $inputId }}-hint" class="mt-2 text-xs leading-6 text-slate-500">شماره پیشنهادی قابل تغییر است؛ ۳ تا ۹ رقم، بدون صفر در ابتدا. در دسترس بودن هنگام ذخیره بررسی می‌شود.</p>
    @if($errors->has('extension') && old('line_form', 'answer') === $formName)<p id="{{ $inputId }}-error" class="mt-2 text-xs leading-6 text-red-700">{{ $errors->first('extension') }}</p>@endif
</div>

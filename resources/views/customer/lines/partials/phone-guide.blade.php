<section id="phone-guide" class="mt-6 rounded-2xl border border-blue-100 bg-blue-50/50 p-4 sm:p-5" data-phone-guide>
    <h3 class="font-black">راهنمای اتصال تلفن</h3>
    <p class="mt-2 text-xs leading-7 text-slate-600">نوع دستگاه را انتخاب کنید؛ نام فیلدها و مراحل متناسب با همان دستگاه نمایش داده می‌شود.</p>
    <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <label class="block text-sm font-bold" for="guide-device">با چه دستگاهی تماس می‌گیرید؟
            <select id="guide-device" data-guide-device class="mt-2 min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 py-3">
                <option value="zoiper">Zoiper · نرم‌افزار موبایل و کامپیوتر</option>
                <option value="yealink">Yealink · تلفن رومیزی SIP</option>
                <option value="other">سایر تلفن‌ها و نرم‌افزارهای SIP</option>
            </select>
        </label>
        <label class="block text-sm font-bold" for="guide-extension">کدام داخلی را وصل می‌کنید؟
            <select id="guide-extension" data-guide-extension class="mt-2 min-h-11 w-full rounded-xl border border-slate-200 bg-white px-3 py-3" @disabled($extensions->where('enabled', true)->isEmpty())>
                @forelse($extensions->where('enabled', true) as $phone)
                    <option value="{{ $phone->extension }}" data-phone-id="{{ $phone->id }}" @selected(($credentials['extension'] ?? $route?->destination?->extension) === $phone->extension)>{{ $phone->display_name ?: 'تلفن' }} · {{ $phone->extension }}</option>
                @empty<option value="">ابتدا یک داخلی بسازید</option>@endforelse
            </select>
        </label>
    </div>
    <div class="mt-4 overflow-hidden rounded-xl border border-slate-200 bg-white">
        <dl class="divide-y divide-slate-100 px-4 text-sm">
            @foreach(['host' => ['Server / Domain', config('voip.sip_host')], 'port' => ['Port', config('voip.sip_port')], 'username' => ['Username', 'شماره داخلی انتخاب‌شده'], 'auth' => ['Authentication username', 'همان شماره داخلی'], 'transport' => ['Transport', 'UDP'], 'password' => ['Password', 'رمز اتصال همین داخلی']] as $key => [$label, $value])
                <div class="flex flex-wrap items-center justify-between gap-2 py-3"><dt class="text-xs text-slate-500" data-guide-label="{{ $key }}" dir="ltr">{{ $label }}</dt><dd class="flex min-w-0 items-center gap-2"><bdi data-guide-value="{{ $key }}" class="break-all font-bold" dir="{{ in_array($key, ['username', 'auth', 'password']) ? 'auto' : 'ltr' }}">{{ $value }}</bdi>@if(in_array($key, ['host', 'port', 'username', 'auth']))<button type="button" data-guide-copy="{{ $key }}" aria-label="کپی {{ $label }}" class="min-h-11 px-2 text-xs font-bold text-blue-700">کپی</button>@endif</dd></div>
            @endforeach
        </dl>
    </div>
    <a data-guide-credentials href="#phones" class="mt-3 inline-flex min-h-11 items-center text-xs font-bold text-blue-700">دیدن اطلاعات اتصال و رمز داخلی ↑</a>
    <div class="mt-4" data-guide-panel="zoiper">
        <h4 class="text-sm font-bold">اتصال با Zoiper</h4>
        <ol class="mt-3 list-decimal space-y-2 pr-5 text-sm leading-8 text-slate-700">
            <li>در Zoiper از بخش Accounts، افزودن حساب و تنظیم دستی را انتخاب کنید؛ نوع حساب را SIP بگذارید.</li>
            <li>Username و Password را از داخلی انتخاب‌شده وارد کنید. برای Host / Domain از <bdi dir="ltr">{{ config('voip.sip_host') }}:{{ config('voip.sip_port') }}</bdi> استفاده کنید؛ اگر پورت فیلد جدا دارد، آن را جداگانه وارد کنید.</li>
            <li>اگر Authentication username خواسته شد، همان شماره داخلی را وارد کنید. Outbound proxy لازم نیست؛ اتصال SIP / UDP را انتخاب کنید.</li>
            <li>حساب را ذخیره کنید و وضعیت Registered را بررسی کنید؛ سپس تماس آزمایشی بگیرید.</li>
        </ol>
        <details class="mt-3 text-xs leading-7 text-slate-600"><summary class="min-h-11 cursor-pointer font-bold">خطای STUN در Zoiper</summary><p>در تنظیمات شبکهٔ حساب، STUN را روی «Don't use STUN» بگذارید، ذخیره کنید و دوباره امتحان کنید.</p></details>
        <a href="https://www.zoiper.com/en/support/answer/for/android/108/Configuring_a_VoIP_account" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex min-h-11 items-center text-xs font-bold text-blue-700">راهنمای رسمی Zoiper ↗</a>
    </div>
    <div class="mt-4" data-guide-panel="yealink">
        <h4 class="text-sm font-bold">اتصال تلفن رومیزی Yealink</h4>
        <p class="mt-2 text-xs leading-7 text-slate-600">برای مدل‌های SIP رومیزی مانند سری T3، T4 و T5؛ نام منوها ممکن است با مدل و نسخه نرم‌افزار تفاوت داشته باشد.</p>
        <ol class="mt-3 list-decimal space-y-2 pr-5 text-sm leading-8 text-slate-700">
            <li>تلفن را به برق یا PoE و شبکه وصل کنید. IP تلفن را از صفحه Status پیدا کنید؛ در بسیاری از مدل‌ها دکمه OK صفحه وضعیت را باز می‌کند.</li>
            <li>روی کامپیوتری در همان شبکه، IP تلفن را در مرورگر باز کنید و با اطلاعات مدیریت خودِ دستگاه وارد شوید. رمز مدیریت دستگاه با رمز داخلی بلوکام متفاوت است.</li>
            <li>به <bdi dir="ltr">Account → Register</bdi> بروید و یک حساب خالی انتخاب کنید. <bdi dir="ltr">Line Active = Enabled</bdi> را تنظیم کنید؛ Label و Display Name را نام شخص یا تلفن بگذارید.</li>
            <li>در Register Name و User Name، شماره داخلی انتخاب‌شده را وارد کنید؛ Password را رمز اتصال همان داخلی بگذارید.</li>
            <li>در SIP Server 1، آدرس Server Host و Port را مطابق جدول و در دو فیلد جدا وارد کنید. Transport را UDP و Enable Outbound Proxy Server را Disabled بگذارید.</li>
            <li>Confirm را بزنید و منتظر <bdi dir="ltr">Register Status: Registered</bdi> بمانید؛ سپس تماس ورودی و خروجی مجاز را آزمایش کنید.</li>
        </ol>
        <a href="https://support.yealink.com/docs/sip-t58w/account-registration/0d20b03058174a31baeaa3e2d5bda4a7" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex min-h-11 items-center text-xs font-bold text-blue-700">راهنمای رسمی ثبت حساب Yealink ↗</a>
    </div>
    <div class="mt-4" data-guide-panel="other">
        <h4 class="text-sm font-bold">اتصال سایر دستگاه‌های SIP</h4>
        <ol class="mt-3 list-decimal space-y-2 pr-5 text-sm leading-8 text-slate-700">
            <li>در نرم‌افزار یا پنل دستگاه، یک حساب SIP بسازید.</li>
            <li>Server / Registrar، پورت، Username و Password را مطابق جدول وارد کنید. Auth ID همان شماره داخلی است؛ Transport را UDP انتخاب کنید.</li>
            <li>Outbound proxy را خالی یا غیرفعال بگذارید؛ ذخیره کنید و ثبت حساب و صدای دوطرفه را با یک تماس واقعی بررسی کنید.</li>
        </ol>
    </div>
    <details class="mt-4 border-t border-blue-100 pt-3 text-xs leading-7 text-slate-600"><summary class="min-h-11 cursor-pointer font-bold">اگر تلفن وصل نشد</summary><ul class="mt-2 list-disc space-y-2 pr-4"><li>نام کاربری، رمز داخلی، آدرس سرور و پورت را دوباره بررسی کنید؛ رمز حساب بلوکام یا رمز مدیریت دستگاه را وارد نکنید.</li><li>اگر رمز را تغییر داده‌اید، آن را در تلفن هم به‌روز کنید. پیام Disabled در این صفحه به تنظیم داخلی مربوط است؛ وضعیت Registered را خودِ تلفن نشان می‌دهد.</li><li>اگر صدا قطع یا یک‌طرفه است، اتصال اینترنت و تنظیمات شبکه را با مسئول شبکه یا پشتیبانی بررسی کنید.</li></ul></details>
</section>

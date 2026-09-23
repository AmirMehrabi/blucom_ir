<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $isAdmin ? 'ورود مدیر' : 'ورود / ثبت‌نام' }} · بلوکام</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
    <style>
        .otp-input {
            width: 3rem;
            height: 3.25rem;
            text-align: center;
            font-size: 1.35rem;
            font-weight: 800;
            border-radius: 0.9rem;
            border: 1.5px solid #dbe1ea;
            background: #fff;
            color: #0f172a;
            outline: none;
            transition: border-color .15s, box-shadow .15s, transform .1s;
        }
        .otp-input:focus {
            border-color: #1f64a8;
            box-shadow: 0 0 0 4px rgba(31,100,168,.15);
            transform: translateY(-1px);
        }
        .otp-input.filled {
            border-color: #93c5fd;
            background: #f8fafc;
        }
        .otp-input.invalid {
            border-color: #ef4444;
            box-shadow: 0 0 0 4px rgba(239,68,68,.12);
        }
        @media (max-width: 480px) {
            .otp-input { width: 2.5rem; height: 2.85rem; font-size: 1.15rem; }
        }
        .brand-panel {
            background:
                radial-gradient(1200px 600px at 10% 0%, rgba(56,189,248,.18), transparent 55%),
                radial-gradient(900px 500px at 100% 100%, rgba(37,99,235,.28), transparent 50%),
                linear-gradient(145deg, #071a3b 0%, #0b2f66 48%, #1f64a8 100%);
        }
        .field {
            width: 100%;
            border-radius: 0.9rem;
            border: 1.5px solid #dbe1ea;
            background: #fff;
            padding: 0.85rem 1rem;
            font-size: 1rem;
            color: #0f172a;
            outline: none;
            transition: border-color .15s, box-shadow .15s;
        }
        .field:focus {
            border-color: #1f64a8;
            box-shadow: 0 0 0 4px rgba(31,100,168,.12);
        }
        .field::placeholder { color: #94a3b8; }
        .btn-primary {
            width: 100%;
            border-radius: 0.9rem;
            background: #1f64a8;
            color: #fff;
            font-weight: 800;
            padding: 0.9rem 1rem;
            transition: background .15s, transform .1s, opacity .15s;
            box-shadow: 0 10px 24px rgba(31,100,168,.28);
        }
        .btn-primary:hover:not(:disabled) { background: #164f87; }
        .btn-primary:active:not(:disabled) { transform: translateY(1px); }
        .btn-primary:disabled { opacity: .7; cursor: not-allowed; }
        .btn-ghost {
            border-radius: 0.75rem;
            border: 1px solid #dbe1ea;
            background: #fff;
            color: #1f64a8;
            font-weight: 700;
            font-size: .875rem;
            padding: .45rem .8rem;
            transition: background .15s, border-color .15s;
        }
        .btn-ghost:hover:not(:disabled) { background: #f8fafc; border-color: #93c5fd; }
        .btn-ghost:disabled { opacity: .5; cursor: not-allowed; }
        .msg-error { color: #b91c1c; }
        .msg-ok { color: #047857; }
    </style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
<div class="grid min-h-screen lg:grid-cols-2">
    {{-- Brand / marketing column --}}
    <aside class="brand-panel relative hidden overflow-hidden text-white lg:flex lg:flex-col lg:justify-between lg:p-12 xl:p-16">
        <div class="relative z-10">
            <a href="/" class="inline-flex items-center gap-3" aria-label="بلوکام">
                <span class="grid size-11 place-items-center rounded-2xl bg-white/10 text-xl font-black backdrop-blur">ب</span>
                <span class="text-2xl font-black tracking-tight">بلو<span class="text-sky-300">کام</span></span>
            </a>
            <p class="mt-10 text-sm font-bold uppercase tracking-[0.2em] text-sky-200/80">
                {{ $isAdmin ? 'پنل مدیریت' : 'فضای کاری سازمانی' }}
            </p>
            <h2 class="mt-4 max-w-md text-4xl font-black leading-tight xl:text-5xl">
                {{ $isAdmin
                    ? 'کنترل کامل زیرساخت صوتی سازمان'
                    : 'تلفن سازمانی، ساده و قابل اتکا' }}
            </h2>
            <p class="mt-5 max-w-md text-base leading-8 text-sky-100/85 xl:text-lg">
                {{ $isAdmin
                    ? 'دروازه‌ها، شماره‌ها و مسیرهای تماس را از یک کنسول واحد مدیریت کنید — بدون ویرایش فایل‌های پیکربندی.'
                    : 'شماره‌ها، داخلی‌ها و مسیرهای تماس را در یک جا بسازید. ثبت‌نام با شماره موبایل، بدون رمز عبور.' }}
            </p>
        </div>

        <ul class="relative z-10 grid gap-4 sm:grid-cols-2 xl:gap-5">
            @foreach($isAdmin ? [
                ['t' => 'دروازه‌های SIP', 'd' => 'اتصال امن به اپراتور'],
                ['t' => 'تخصیص شماره', 'd' => 'مدیریت DID برای هر مستأجر'],
                ['t' => 'مسیریابی زنده', 'd' => 'بدون restart سرور'],
                ['t' => 'ایزوله‌سازی', 'd' => 'جداکامل دسترسی مستأجرها'],
            ] : [
                ['t' => 'ورود بدون رمز', 'd' => 'کد یک‌بارمصرف روی موبایل'],
                ['t' => 'شماره و داخلی', 'd' => 'ایجاد و ویرایش سریع'],
                ['t' => 'مسیر ورودی/خروجی', 'd' => 'کنترل مقصد تماس‌ها'],
                ['t' => 'امنیت چندمستأجره', 'd' => 'داده‌های شما فقط برای شما'],
            ] as $feature) as $feature)
                <li class="rounded-2xl border border-white/10 bg-white/5 p-4 backdrop-blur-sm">
                    <p class="font-extrabold">{{ $feature['t'] }}</p>
                    <p class="mt-1 text-sm text-sky-100/75">{{ $feature['d'] }}</p>
                </li>
            @endforeach
        </ul>

        <p class="relative z-10 text-xs text-sky-100/55">© {{ now()->year }} بلوکام · مرکز خدمات صوتی</p>

        <div class="pointer-events-none absolute -left-24 -top-24 size-72 rounded-full bg-sky-400/20 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-32 -right-16 size-96 rounded-full bg-blue-500/25 blur-3xl"></div>
    </aside>

    {{-- Form column --}}
    <main class="flex min-h-screen items-center justify-center px-5 py-10 sm:px-8 lg:px-12">
        <div class="w-full max-w-md">
            <div class="mb-8 flex items-start justify-between gap-4 lg:hidden">
                <a href="/" class="flex items-center gap-2.5" aria-label="بلوکام">
                    <span class="grid size-10 place-items-center rounded-xl bg-[#1f64a8] text-lg font-black text-white">ب</span>
                    <span class="text-lg font-black">بلو<span class="text-[#1f64a8]">کام</span></span>
                </a>
                <span class="rounded-full px-3 py-1 text-[11px] font-bold {{ $isAdmin ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800' }}">
                    {{ $isAdmin ? 'پنل مدیریت' : 'پنل مشتری' }}
                </span>
            </div>

            <header class="mb-8">
                <div class="hidden items-center justify-between gap-3 lg:flex">
                    <span class="rounded-full px-3 py-1 text-[11px] font-bold {{ $isAdmin ? 'bg-amber-50 text-amber-700 ring-1 ring-amber-200' : 'bg-blue-50 text-blue-700 ring-1 ring-blue-200' }}">
                        {{ $isAdmin ? 'پنل مدیریت' : 'پنل مشتری' }}
                    </span>
                </div>
                <h1 class="mt-4 text-3xl font-black tracking-tight text-slate-900">
                    {{ $isAdmin ? 'ورود مدیر بلوکام' : 'ورود به بلوکام' }}
                </h1>
                <p class="mt-2 text-sm leading-6 text-slate-500" id="subtitle">
                    {{ $isAdmin
                        ? 'با شماره موبایل مدیر وارد شوید. کد ۶ رقمی ارسال می‌شود.'
                        : 'شماره موبایل خود را وارد کنید؛ حساب ندارید؟ همین‌جا ساخته می‌شود.' }}
                </p>
            </header>

            <form id="otp-form" method="post" action="javascript:void(0)" class="space-y-5" novalidate autocomplete="on">
                @csrf
                <input type="hidden" name="ajax" value="1">

                {{-- Step 1: mobile --}}
                <div id="step-mobile" class="space-y-5">
                    <label class="block">
                        <span class="mb-2 block text-sm font-bold text-slate-700">شماره موبایل</span>
                        <input
                            id="mobile"
                            name="mobile"
                            type="tel"
                            required
                            inputmode="tel"
                            autocomplete="tel"
                            placeholder="0912 123 4567"
                            class="field"
                            dir="ltr"
                            style="text-align:right"
                        >
                        <span class="mt-1.5 block text-xs text-slate-400">مثال: ۰۹۱۲۱۲۳۴۵۶۷</span>
                    </label>
                    <button type="submit" class="btn-primary" id="btn-mobile">ارسال کد تأیید</button>
                </div>

                {{-- Step 2: OTP --}}
                <div id="step-otp" class="hidden space-y-5">
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-bold text-slate-700">کد تأیید</p>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    کد به شماره <span id="masked-mobile" class="font-bold text-slate-700" dir="ltr"></span> ارسال شد
                                </p>
                            </div>
                            <button type="button" id="btn-change-mobile" class="btn-ghost">تغییر شماره</button>
                        </div>

                        <div class="mt-4 flex justify-center gap-2" dir="ltr" role="group" aria-label="کد ۶ رقمی">
                            @for ($i = 0; $i < 6; $i++)
                                <input
                                    type="text"
                                    class="otp-input"
                                    data-otp-index="{{ $i }}"
                                    inputmode="numeric"
                                    pattern="[0-9]*"
                                    maxlength="1"
                                    autocomplete="{{ $i === 0 ? 'one-time-code' : 'off' }}"
                                    aria-label="رقم {{ $i + 1 }}"
                                    {{ $i === 0 ? 'autofocus' : '' }}
                                >
                            @endfor
                        </div>

                        <div class="mt-4 flex flex-col items-center gap-2">
                            <button type="submit" class="btn-primary" id="btn-verify" disabled>تأیید و ورود</button>
                            <div class="flex items-center gap-3 text-xs">
                                <span id="otp-timer" class="text-slate-400"></span>
                                <button type="button" id="btn-resend" class="font-bold text-[#1f64a8] disabled:text-slate-400" disabled>
                                    ارسال مجدد کد
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <p id="message" class="min-h-5 text-sm msg-error" role="alert" aria-live="polite"></p>
            </form>

            <div class="mt-8 rounded-2xl border border-slate-200 bg-white p-4 text-xs leading-6 text-slate-500">
                <p class="font-bold text-slate-700">نکته امنیتی</p>
                <p class="mt-1">
                    رمز عبور نداریم؛ فقط کد یک‌بارمصرف. هرگز کد را با کسی به اشتراک نگذارید.
                    {{ $isAdmin ? 'این ورود مخصوص مدیران است.' : 'در صورت نیاز به دسترسی مدیر، با پشتیبانی تماس بگیرید.' }}
                </p>
            </div>
        </div>
    </main>
</div>

<script>
(() => {
    const f = document.querySelector('#otp-form');
    const m = document.querySelector('#message');
    const stepMobile = document.querySelector('#step-mobile');
    const stepOtp = document.querySelector('#step-otp');
    const btnMobile = document.querySelector('#btn-mobile');
    const btnVerify = document.querySelector('#btn-verify');
    const btnResend = document.querySelector('#btn-resend');
    const btnChange = document.querySelector('#btn-change-mobile');
    const timerEl = document.querySelector('#otp-timer');
    const maskedEl = document.querySelector('#masked-mobile');
    const mobileInput = document.querySelector('#mobile');
    const otpInputs = Array.from(document.querySelectorAll('.otp-input'));

    let challenge = null;
    let cooldownTimer = null;
    let submitting = false;
    let otpStage = false;

    function setMessage(text, ok = false) {
        m.textContent = text || '';
        m.className = 'min-h-5 text-sm ' + (text ? (ok ? 'msg-ok' : 'msg-error') : '');
    }

    function setBusy(btn, busy, busyLabel, idleLabel) {
        btn.disabled = busy;
        btn.textContent = busy ? busyLabel : idleLabel;
    }

    function onlyDigits(value) {
        return (value || '').replace(/\D/g, '');
    }

    function otpValue() {
        return otpInputs.map((el) => onlyDigits(el.value).slice(0, 1)).join('');
    }

    function syncOtpUi() {
        const code = otpValue();
        otpInputs.forEach((el, i) => {
            el.classList.toggle('filled', !!code[i]);
            el.classList.remove('invalid');
        });
        btnVerify.disabled = code.length !== 6 || submitting;
    }

    function focusOtp(index) {
        const i = Math.max(0, Math.min(5, index));
        otpInputs[i].focus();
        otpInputs[i].select();
    }

    function fillOtp(code) {
        const digits = onlyDigits(code).slice(0, 6).split('');
        otpInputs.forEach((el, i) => {
            el.value = digits[i] || '';
        });
        syncOtpUi();
        if (digits.length === 6) focusOtp(5);
        else focusOtp(digits.length);
    }

    function clearOtp() {
        otpInputs.forEach((el) => {
            el.value = '';
            el.classList.remove('filled', 'invalid');
        });
        syncOtpUi();
    }

    function maskMobile(raw) {
        const d = onlyDigits(raw);
        if (d.length < 7) return d;
        const head = d.slice(0, 4);
        const tail = d.slice(-3);
        return head + '••••' + tail;
    }

    function startCooldown(seconds) {
        clearInterval(cooldownTimer);
        let left = Math.max(0, seconds | 0);
        const render = () => {
            if (left <= 0) {
                timerEl.textContent = '';
                btnResend.disabled = false;
                clearInterval(cooldownTimer);
                return;
            }
            timerEl.textContent = `ارسال مجدد تا ${left} ثانیه`;
            btnResend.disabled = true;
            left -= 1;
        };
        render();
        cooldownTimer = setInterval(render, 1000);
    }

    async function post(url, body) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 12000);
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('[name=_token]').value
                },
                body: JSON.stringify(body),
                signal: controller.signal
            });
            const text = await res.text();
            let data = {};
            try {
                data = text ? JSON.parse(text) : {};
            } catch (e) {
                data = { message: 'پاسخ نامعتبر از سرور دریافت شد.' };
            }
            if (res.status === 419) throw new Error('جلسه منقضی شده است. صفحه را تازه‌سازی کنید.');
            if (res.status === 429) throw new Error(data.message || 'تعداد درخواست‌ها زیاد است. کمی صبر کنید.');
            if (!res.ok) {
                if (data.errors) {
                    const first = Object.values(data.errors).flat()[0];
                    throw new Error(first || data.message || 'خطا در ارسال کد');
                }
                throw new Error(data.message || 'خطا در ارسال کد');
            }
            return data;
        } catch (err) {
            if (err && err.name === 'AbortError') {
                throw new Error('پاسخ سرور بیش از حد طول کشید. لطفاً دوباره تلاش کنید.');
            }
            if (err instanceof TypeError) {
                throw new Error('اتصال به سرور برقرار نشد. اتصال شبکه را بررسی کنید.');
            }
            throw err;
        } finally {
            clearTimeout(timer);
        }
    }

    async function requestOtp(isResend = false) {
        const mobile = onlyDigits(mobileInput.value);
        if (mobile.length < 10) {
            setMessage('شماره موبایل معتبر وارد کنید.');
            mobileInput.focus();
            return;
        }

        const idle = isResend ? 'ارسال مجدد' : 'ارسال کد تأیید';
        setBusy(isResend ? btnResend : btnMobile, true, 'لطفاً صبر کنید…', idle);
        setMessage('');

        try {
            const data = await post('/auth/otp/request', { mobile });
            if (!data.challenge_id) {
                throw new Error(data.message || 'ارسال کد ممکن نشد.');
            }
            challenge = data.challenge_id;
            otpStage = true;
            stepMobile.classList.add('hidden');
            stepOtp.classList.remove('hidden');
            maskedEl.textContent = maskMobile(mobile);
            document.querySelector('#subtitle').textContent = 'کد ۶ رقمی را وارد کنید. می‌توانید با Tab جابه‌جا شوید.';
            clearOtp();
            startCooldown(data.cooldown_seconds || {{ (int) config('auth.otp.resend_cooldown_seconds') }});
            setMessage(isResend ? 'کد جدید ارسال شد.' : (data.message || 'کد تأیید ارسال شد.'), true);
            focusOtp(0);
        } catch (err) {
            setMessage(err && err.message ? err.message : 'خطای غیرمنتظره رخ داد.');
        } finally {
            setBusy(btnMobile, false, 'لطفاً صبر کنید…', 'ارسال کد تأیید');
            btnResend.disabled = btnResend.disabled && timerEl.textContent !== '';
        }
    }

    async function verifyOtp() {
        const code = otpValue();
        if (code.length !== 6) {
            setMessage('کد ۶ رقمی را کامل وارد کنید.');
            return;
        }

        setBusy(btnVerify, true, 'در حال ورود…', 'تأیید و ورود');
        submitting = true;
        setMessage('');

        try {
            const data = await post('/auth/otp/verify', {
                mobile: onlyDigits(mobileInput.value),
                code,
                challenge_id: challenge
            });
            setMessage('ورود موفق. در حال انتقال…', true);
            window.location.href = data.redirect || '/';
        } catch (err) {
            submitting = false;
            setBusy(btnVerify, false, 'در حال ورود…', 'تأیید و ورود');
            setMessage(err && err.message ? err.message : 'کد واردشده معتبر نیست.');
            otpInputs.forEach((el) => el.classList.add('invalid'));
            clearOtp();
            focusOtp(0);
        }
    }

    f.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (submitting) return;
        if (!otpStage) await requestOtp(false);
        else await verifyOtp();
    });

    btnResend.addEventListener('click', async () => {
        if (btnResend.disabled) return;
        await requestOtp(true);
    });

    btnChange.addEventListener('click', () => {
        otpStage = false;
        challenge = null;
        clearInterval(cooldownTimer);
        timerEl.textContent = '';
        btnResend.disabled = true;
        stepOtp.classList.add('hidden');
        stepMobile.classList.remove('hidden');
        document.querySelector('#subtitle').textContent = @js($isAdmin
            ? 'با شماره موبایل مدیر وارد شوید. کد ۶ رقمی ارسال می‌شود.'
            : 'شماره موبایل خود را وارد کنید؛ حساب ندارید؟ همین‌جا ساخته می‌شود.');
        clearOtp();
        setMessage('');
        mobileInput.focus();
        mobileInput.select();
    });

    mobileInput.addEventListener('input', () => {
        const digits = onlyDigits(mobileInput.value).slice(0, 11);
        mobileInput.value = digits;
        setMessage('');
    });

    mobileInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            requestOtp(false);
        }
    });

    otpInputs.forEach((input, index) => {
        input.addEventListener('focus', () => input.select());

        input.addEventListener('input', (e) => {
            const raw = e.target.value;
            const pasted = onlyDigits(raw);

            if (pasted.length > 1) {
                e.preventDefault();
                fillOtp(pasted);
                if (pasted.length >= 6) verifyOtp();
                return;
            }

            const digit = pasted.slice(-1);
            input.value = digit;
            syncOtpUi();

            if (digit && index < 5) focusOtp(index + 1);
            if (otpValue().length === 6) verifyOtp();
        });

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace') {
                if (input.value) {
                    input.value = '';
                    syncOtpUi();
                    if (index > 0) focusOtp(index - 1);
                    e.preventDefault();
                } else if (index > 0) {
                    e.preventDefault();
                    otpInputs[index - 1].value = '';
                    syncOtpUi();
                    focusOtp(index - 1);
                }
                return;
            }

            if (e.key === 'Delete') {
                input.value = '';
                syncOtpUi();
                e.preventDefault();
                return;
            }

            if (e.key === 'ArrowLeft' && index > 0) {
                e.preventDefault();
                focusOtp(index - 1);
                return;
            }

            if (e.key === 'ArrowRight' && index < 5) {
                e.preventDefault();
                focusOtp(index + 1);
                return;
            }

            if (e.key === 'Enter') {
                e.preventDefault();
                if (otpValue().length === 6) verifyOtp();
                return;
            }

            // Allow Tab to move naturally between boxes
            if (e.key === 'Tab') return;
        });

        input.addEventListener('paste', (e) => {
            e.preventDefault();
            const text = (e.clipboardData || window.clipboardData).getData('text');
            const digits = onlyDigits(text).slice(0, 6);
            if (!digits) return;
            fillOtp(digits);
            if (digits.length === 6) verifyOtp();
        });
    });

    // Initial focus
    if (!otpStage) mobileInput.focus();
    syncOtpUi();
})();
</script>
</body>
</html>

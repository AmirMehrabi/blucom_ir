<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $isAdmin ? 'ورود مدیر' : 'ورود' }} · بلوکام</title>
    @vite(['resources/css/app.css','resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 grid place-items-center p-6">
<main class="w-full max-w-md rounded-2xl bg-white p-8 shadow-xl">
    <div class="flex items-center justify-between gap-3">
        <h1 class="text-2xl font-black">{{ $isAdmin ? 'ورود مدیر بلوکام' : 'ورود به بلوکام' }}</h1>
        <span class="rounded-full px-3 py-1 text-xs font-bold {{ $isAdmin ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800' }}">
            {{ $isAdmin ? 'پنل مدیریت' : 'پنل مشتری' }}
        </span>
    </div>
    <p class="mt-2 text-sm text-slate-500">
        {{ $isAdmin ? 'با شماره مدیر وارد شوید.' : 'با شماره موبایل وارد شوید.' }}
    </p>
    <form id="otp-form" class="mt-8 space-y-4">
        @csrf
        <input id="mobile" required inputmode="tel" placeholder="09121234567" class="w-full rounded-xl border p-3" autocomplete="tel">
        <div id="code-wrap" class="hidden">
            <input id="code" inputmode="numeric" maxlength="6" placeholder="کد ۶ رقمی" class="w-full rounded-xl border p-3" autocomplete="one-time-code">
        </div>
        <button class="w-full rounded-xl bg-blue-600 p-3 font-bold text-white">ادامه</button>
        <p id="message" class="text-sm text-red-600"></p>
    </form>
</main>
<script>
const f = document.querySelector('#otp-form');
const m = document.querySelector('#message');
const c = document.querySelector('#code-wrap');
const btn = f.querySelector('button');
let challenge;

async function post(url, body) {
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('[name=_token]').value
        },
        body: JSON.stringify(body)
    });

    const text = await res.text();
    let data = {};
    try {
        data = text ? JSON.parse(text) : {};
    } catch (e) {
        data = { message: 'پاسخ نامعتبر از سرور دریافت شد.' };
    }

    if (res.status === 419) {
        throw new Error('جلسه منقضی شده است. صفحه را تازه‌سازی کنید.');
    }

    if (res.status === 429) {
        throw new Error(data.message || 'تعداد درخواست‌ها زیاد است. کمی صبر کنید.');
    }

    if (!res.ok) {
        if (data.errors) {
            const first = Object.values(data.errors).flat()[0];
            throw new Error(first || data.message || 'خطا در ارسال کد');
        }
        throw new Error(data.message || 'خطا در ارسال کد');
    }

    return data;
}

f.addEventListener('submit', async (e) => {
    e.preventDefault();
    m.textContent = '';
    m.className = 'text-sm text-red-600';
    btn.disabled = true;
    btn.textContent = 'لطفاً صبر کنید…';

    try {
        const mobile = document.querySelector('#mobile').value;

        if (c.classList.contains('hidden')) {
            const data = await post('/auth/otp/request', { mobile });
            if (!data.challenge_id) {
                throw new Error(data.message || 'ارسال کد ممکن نشد.');
            }
            challenge = data.challenge_id;
            c.classList.remove('hidden');
            m.textContent = data.message || 'کد تأیید ارسال شد.';
            m.className = 'text-sm text-emerald-600';
            document.querySelector('#code').focus();
            return;
        }

        const data = await post('/auth/otp/verify', {
            mobile,
            code: document.querySelector('#code').value,
            challenge_id: challenge
        });
        location.href = data.redirect || '/';
    } catch (err) {
        m.textContent = err && err.message ? err.message : 'خطای غیرمنتظره رخ داد.';
    } finally {
        btn.disabled = false;
        btn.textContent = 'ادامه';
    }
});
</script>
</body>
</html>

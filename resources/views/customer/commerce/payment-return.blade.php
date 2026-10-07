<!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><title>نتیجهٔ پرداخت · بلوکام</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body class="grid min-h-screen place-items-center bg-[#f5f8fd] p-5 text-slate-900"><main class="panel w-full max-w-lg p-7 sm:p-9">
    <a class="commerce-link text-lg font-black no-underline" href="{{ route('home') }}">بلوکام</a>
    @if($result === 'paid')<h1 class="mt-7 text-2xl font-black">پرداخت تأیید شد</h1><p class="mt-4 leading-8 text-slate-600">برای دیدن جزئیات پرداخت و وضعیت آماده‌سازی شماره، سفارش خود را در حساب کاربری پیگیری کنید.</p>
    @elseif($result === 'incomplete')<h1 class="mt-7 text-2xl font-black">پرداخت تکمیل نشد</h1><p class="mt-4 leading-8 text-slate-600">برای بررسی وضعیت یا ادامهٔ خرید، به سفارش خود برگردید. اگر مبلغی از حسابتان کسر شده، پیش از پرداخت دوباره وضعیت سفارش را بررسی کنید.</p>
    @else<h1 class="mt-7 text-2xl font-black">نتیجهٔ پرداخت در حال بررسی است</h1><p class="mt-4 leading-8 text-slate-600">نیازی به پرداخت دوباره نیست. وضعیت سفارش را از حساب کاربری پیگیری کنید؛ اگر مبلغی از حسابتان کسر شده، با پشتیبانی تماس بگیرید.</p>@endif
    <a href="{{ route('customer.orders.index') }}" class="commerce-button mt-7 w-full">مشاهدهٔ سفارش‌های من</a><a href="{{ route('contact') }}" class="commerce-link mt-4 flex min-h-11 items-center justify-center text-sm font-bold">تماس با پشتیبانی</a>
</main></body></html>

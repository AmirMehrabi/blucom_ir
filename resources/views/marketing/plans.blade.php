@extends('layouts.marketing')

@section('title', 'طرح‌ها و هزینه‌ها | بلوکام')
@section('description', 'هزینه پیشنهادی راه‌اندازی تلفن کاری بلوکام برای تیم‌های کوچک و در حال رشد، با توضیح روشن درباره هزینه خط، تماس، میزبانی و پشتیبانی.')

@section('content')
<main id="main">
    <section class="marketing-page-head" aria-labelledby="plans-title">
        <div class="landing-container">
            <p class="landing-kicker">طرح‌ها و هزینه‌ها</p>
            <h1 id="plans-title">هزینهٔ راه‌اندازی،<br><span>روشن و قابل بررسی.</span></h1>
            <p>بلوکام فعلاً یک سرویس اشتراکی با خرید خودکار نیست. کار را با بررسی خط و نیاز تیم شما شروع می‌کنیم، محدوده اجرا را می‌نویسیم و بعد قیمت نهایی را اعلام می‌کنیم. اعداد زیر نقطهٔ شروع برای پروژه‌های معمول هستند.</p>
        </div>
    </section>

    <section class="landing-section plans-section" aria-labelledby="packages-title">
        <div class="landing-container">
            <div class="landing-section-heading">
                <p class="landing-kicker">پیشنهاد بسته‌ها</p>
                <h2 id="packages-title">به اندازهٔ کاری که لازم دارید.</h2>
                <p>مبنای این قیمت‌ها راه‌اندازی اولیه است؛ تعداد تماس همزمان و سازگاری خط باید پیش از سفارش بررسی شود.</p>
            </div>
            <div class="plans-grid">
                <article class="plan-card">
                    <div class="plan-card-head"><p class="plan-overline">برای یک تیم کوچک</p><h3>شروع</h3><p>یک شماره کاری با پاسخ‌گویی مشخص.</p></div>
                    <p class="plan-price"><span>از ۸٬۹۰۰٬۰۰۰</span> تومان</p><p class="plan-price-note">هزینهٔ یک‌بارهٔ راه‌اندازی</p>
                    <ul><li>تا ۱ شماره کاری</li><li>تا ۵ تلفن داخلی</li><li>تنظیم مسیر تماس ورودی و خروجی</li><li>راهنمای اتصال تلفن نرم‌افزاری</li><li>آزمون تماس پس از راه‌اندازی</li></ul>
                    <a class="landing-button landing-button-outline" href="{{ route('contact') }}">درباره این طرح بپرسید <span aria-hidden="true">←</span></a>
                </article>
                <article class="plan-card plan-card-featured">
                    <div class="plan-card-head"><p class="plan-overline">برای چند پاسخ‌گو</p><h3>تیم</h3><p>چند شماره و همکار، با مسیرهای جداگانه.</p></div>
                    <p class="plan-price"><span>از ۱۶٬۹۰۰٬۰۰۰</span> تومان</p><p class="plan-price-note">هزینهٔ یک‌بارهٔ راه‌اندازی</p>
                    <ul><li>تا ۳ شماره کاری</li><li>تا ۱۵ تلفن داخلی</li><li>مسیر پاسخ‌گویی جدا برای هر شماره</li><li>تنظیم شماره‌های مجاز برای تماس خروجی</li><li>آزمون و تحویل سناریوی تماس</li></ul>
                    <a class="landing-button landing-button-primary" href="{{ route('contact') }}">درباره این طرح بپرسید <span aria-hidden="true">←</span></a>
                </article>
                <article class="plan-card">
                    <div class="plan-card-head"><p class="plan-overline">برای شرایط متفاوت</p><h3>متناسب با شما</h3><p>وقتی ظرفیت یا مسیر تماس از حالت معمول فراتر می‌رود.</p></div>
                    <p class="plan-custom-price">پس از بررسی</p><p class="plan-price-note">پیشنهاد و محدودهٔ کار مکتوب</p>
                    <ul><li>بررسی شماره‌ها و ارائه‌دهنده‌های موجود</li><li>برآورد ظرفیت تلفن و تماس همزمان</li><li>بررسی نیازهای پاسخ‌گویی ویژه</li><li>تعیین موارد قابل اجرا پیش از قرارداد</li><li>قیمت‌گذاری بر پایهٔ کار واقعی</li></ul>
                    <a class="landing-button landing-button-outline" href="{{ route('contact') }}">شرایطتان را بگویید <span aria-hidden="true">←</span></a>
                </article>
            </div>
            <p class="plans-caveat">این قیمت‌ها پیشنهاد اولیه برای اجرای استاندارد هستند، نه پیش‌فاکتور یا تعهد به اتصال هر نوع خط. مبلغ نهایی و محدوده پشتیبانی پس از بررسی فنی، به‌صورت مکتوب اعلام می‌شود.</p>
        </div>
    </section>

    <section class="landing-section plans-details" aria-labelledby="costs-title">
        <div class="landing-container plans-details-grid">
            <div><p class="landing-kicker">شفافیت هزینه</p><h2 id="costs-title">چه چیزهایی جداگانه حساب می‌شوند؟</h2><p>هزینهٔ راه‌اندازی بلوکام فقط بخشی از کل هزینهٔ تلفن کاری است. پیش از تصمیم، این موارد را کنار هم ببینید.</p></div>
            <div class="plans-cost-list">
                <article><span>۰۱</span><div><h3>خط و مکالمه</h3><p>خرید یا اجاره شماره، کانال تماس و کارکرد مکالمه تابع تعرفهٔ ارائه‌دهنده خط است و در ارقام بالا نیست.</p></div></article>
                <article><span>۰۲</span><div><h3>میزبانی و نگهداری</h3><p>اگر به سرور، میزبانی مدیریت‌شده یا پشتیبانی مستمر نیاز باشد، هزینه و مسئولیت هرکدام جدا در پیشنهاد نوشته می‌شود.</p></div></article>
                <article><span>۰۳</span><div><h3>تجهیزات و کار اضافه</h3><p>تلفن رومیزی، شبکه داخلی، حضور در محل و تغییرات خارج از محدوده اولیه، پس از بررسی جداگانه قیمت‌گذاری می‌شوند.</p></div></article>
            </div>
        </div>
    </section>

    <section class="landing-section plans-bottom" aria-labelledby="plans-next-title"><div class="landing-container landing-contact-grid"><div><p class="landing-kicker">قدم بعدی</p><h2 id="plans-next-title">اول خط و نیازتان را بررسی کنیم.</h2><p>نوع خط، تعداد همکاران و شیوه پاسخ‌گویی را بگویید. اگر طرح آماده‌ای مناسب شما باشد، همان را پیشنهاد می‌کنیم؛ اگر نباشد، دلیل و هزینهٔ کار را روشن می‌نویسیم.</p></div><a class="landing-button landing-button-primary" href="{{ route('contact') }}">راه‌های ارتباط با بلوکام <span aria-hidden="true">←</span></a></div></section>
</main>
@endsection

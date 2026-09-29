@extends('layouts.marketing')

@section('title', 'بلوکام | تماس‌های کاری، سر جای خودشان')
@section('description', 'بلوکام به کسب‌وکارها کمک می‌کند شماره کاری، تلفن‌های تیم و مسیر پاسخ‌گویی تماس‌ها را در یک جای روشن مدیریت کنند.')

@section('content')
    <main id="main">
        <section class="landing-hero" aria-labelledby="hero-title">
            <div class="landing-hero-art" aria-hidden="true"></div>
            <div class="landing-container landing-hero-inner">
                <p class="landing-eyebrow"><span class="landing-eyebrow-line"></span> تلفن کاری برای تیم‌های واقعی</p>
                <h1 id="hero-title">تماس‌های کاری،<br><span>سر جای خودشان.</span></h1>
                <p class="landing-hero-copy">شماره کاری، تلفن همکاران و مسیر پاسخ‌گویی را مرتب کنید؛ تا تماس مشتری به کسی برسد که باید پاسخ بدهد. بلوکام برای همین کار ساخته شده است.</p>
                <div class="landing-hero-actions">
                    <a class="landing-button landing-button-primary" href="{{ route('contact') }}">درباره راه‌اندازی صحبت کنیم <span aria-hidden="true">←</span></a>
                    <a class="landing-text-link" href="#what">ببینید چه کاری انجام می‌دهیم <span aria-hidden="true">↙</span></a>
                </div>
                <p class="landing-hero-note">برای شروع، شرایط خط و نیاز تیم شما را بررسی می‌کنیم.</p>
            </div>
            <div class="landing-hero-fade" aria-hidden="true"></div>
        </section>

        <section id="what" class="landing-section landing-intro" aria-labelledby="what-title">
            <div class="landing-container">
                <div class="landing-section-heading">
                    <p class="landing-kicker">کاری که می‌کنیم</p>
                    <h2 id="what-title">یک مسیر روشن برای هر تماس.</h2>
                    <p>تلفن کاری فقط داشتن یک شماره نیست. باید بدانید تماس از کجا وارد می‌شود، چه کسی پاسخ می‌دهد و برای تماس خروجی از کدام خط استفاده می‌شود.</p>
                </div>
                <div class="landing-flow" aria-label="مسیر معمول تماس ورودی">
                    <div><span class="landing-flow-number">۰۱</span><h3>شماره کاری شما</h3><p>شماره‌ای که مشتری با آن تماس می‌گیرد.</p></div>
                    <span class="landing-flow-arrow" aria-hidden="true">←</span>
                    <div><span class="landing-flow-number">۰۲</span><h3>مسیر پاسخ‌گویی</h3><p>مشخص می‌کنید هر شماره به کجا برسد.</p></div>
                    <span class="landing-flow-arrow" aria-hidden="true">←</span>
                    <div><span class="landing-flow-number">۰۳</span><h3>تلفن همکار شما</h3><p>تماس روی تلفن نرم‌افزاری یا دستگاه سازگار زنگ می‌خورد.</p></div>
                </div>
            </div>
        </section>

        <section class="landing-section landing-capabilities" aria-labelledby="capabilities-title">
            <div class="landing-container landing-split-heading">
                <div><p class="landing-kicker">آنچه در اختیار دارید</p><h2 id="capabilities-title">ابزارهای لازم، در یک جا.</h2></div>
                <p>از تنظیمات زیرساخت تا کارهای روزمره تیم، هدف این است که معلوم باشد هر شماره و هر تلفن چگونه کار می‌کند.</p>
            </div>
            <div class="landing-container landing-feature-grid">
                <article class="landing-feature"><span class="landing-feature-icon" aria-hidden="true">01 /</span><h3>شماره‌ها و اتصال‌ها</h3><p>شماره‌های کاری و اتصال به ارائه‌دهنده خط ثبت و مدیریت می‌شوند. سازگاری و جزئیات اتصال هنگام راه‌اندازی بررسی می‌شود.</p></article>
                <article class="landing-feature"><span class="landing-feature-icon" aria-hidden="true">02 /</span><h3>تلفن‌های تیم</h3><p>برای همکاران داخلی تعریف می‌کنید تا بتوانند با تلفن نرم‌افزاری سازگار، مانند Zoiper، پاسخ بدهند یا تماس بگیرند.</p></article>
                <article class="landing-feature"><span class="landing-feature-icon" aria-hidden="true">03 /</span><h3>مسیر تماس‌ها</h3><p>برای هر شماره تعیین می‌کنید تماس ورودی به چه کسی برسد. تماس خروجی هم از مسیر و شماره مجاز انجام می‌شود.</p></article>
            </div>
        </section>

        <section id="how" class="landing-section landing-how" aria-labelledby="how-title">
            <div class="landing-container landing-how-grid">
                <div class="landing-how-lead"><p class="landing-kicker">نحوه شروع</p><h2 id="how-title">اول نیاز شما را می‌فهمیم. بعد تلفن را تنظیم می‌کنیم.</h2><p>هر شرکت خط، ارائه‌دهنده و شیوه پاسخ‌گویی خودش را دارد. به همین دلیل راه‌اندازی را با بررسی همین جزئیات شروع می‌کنیم، نه با وعده فعال‌سازی یکسان برای همه.</p><a class="landing-inline-link" href="{{ route('contact') }}">با ما تماس بگیرید <span aria-hidden="true">←</span></a></div>
                <ol class="landing-steps">
                    <li><span>۱</span><div><h3>وضعیت فعلی را می‌گویید</h3><p>چه شماره‌ای دارید، از چه ارائه‌دهنده‌ای استفاده می‌کنید و چند نفر باید پاسخ‌گو باشند.</p></div></li>
                    <li><span>۲</span><div><h3>امکان اتصال را بررسی می‌کنیم</h3><p>مشخصات خط و روش اتصال را می‌سنجیم و درباره کارهایی که لازم است شفاف صحبت می‌کنیم.</p></div></li>
                    <li><span>۳</span><div><h3>مسیر تماس را می‌چینیم</h3><p>شماره، تلفن همکاران و مسیر تماس ورودی و خروجی تنظیم و با تماس آزمایشی بررسی می‌شود.</p></div></li>
                </ol>
            </div>
        </section>

        <section class="landing-section landing-honest" aria-labelledby="honest-title">
            <div class="landing-container landing-honest-inner">
                <span class="landing-honest-quote" aria-hidden="true">“</span>
                <div><p class="landing-kicker">حرف روشن</p><h2 id="honest-title">قرار نیست تلفن، کارِ اضافه‌ی تیم شما باشد.</h2><p>اگر یک شماره کاری با پاسخ‌گویی مشخص و تماس خروجی کنترل‌شده می‌خواهید، درباره راه‌حل مناسب شما صحبت می‌کنیم. اگر به امکانات پیچیده‌تری نیاز دارید، همان ابتدا محدوده کار و امکان انجام آن را روشن می‌کنیم.</p></div>
            </div>
        </section>

        <section id="questions" class="landing-section landing-faq" aria-labelledby="questions-title">
            <div class="landing-container landing-faq-grid">
                <div><p class="landing-kicker">پرسش‌های معمول</p><h2 id="questions-title">پیش از شروع، چه چیزهایی را باید بدانید؟</h2></div>
                <div class="landing-faq-list">
                    <details><summary>می‌توانیم از شماره فعلی شرکت استفاده کنیم؟ <span aria-hidden="true">+</span></summary><p>در بسیاری از سناریوها بله، اما به نوع شماره و امکان اتصال ارائه‌دهنده شما بستگی دارد. پیش از راه‌اندازی آن را بررسی می‌کنیم.</p></details>
                    <details><summary>برای پاسخ‌گویی به دستگاه تلفن نیاز داریم؟ <span aria-hidden="true">+</span></summary><p>لزوماً نه. تلفن نرم‌افزاری سازگار، مانند Zoiper، هم می‌تواند استفاده شود. انتخاب دستگاه یا نرم‌افزار به شرایط تیم شما بستگی دارد.</p></details>
                    <details><summary>هزینه چطور مشخص می‌شود؟ <span aria-hidden="true">+</span></summary><p>برای کارهای رایج، هزینهٔ شروع را در <a href="{{ route('plans') }}">صفحهٔ طرح‌ها</a> آورده‌ایم. هزینه نهایی پس از بررسی اتصال خط مشخص می‌شود؛ تعرفه تماس و هزینه خط از سوی ارائه‌دهنده جداگانه محاسبه می‌شود.</p></details>
                    <details><summary>تماس خروجی با چه شماره‌ای نمایش داده می‌شود؟ <span aria-hidden="true">+</span></summary><p>از شماره‌ای که برای کسب‌وکار شما مجاز و تنظیم شده است. نمایش شماره به تنظیمات و قواعد ارائه‌دهنده خط نیز وابسته است.</p></details>
                </div>
            </div>
        </section>

        <section id="contact" class="landing-section landing-contact" aria-labelledby="contact-title">
            <div class="landing-container landing-contact-grid">
                <div><p class="landing-kicker">گفت‌وگو را شروع کنیم</p><h2 id="contact-title">از وضعیت تلفن شرکتتان بگویید.</h2><p>شماره و ارائه‌دهنده فعلی، تعداد همکاران و شیوه پاسخ‌گویی دلخواهتان را بگویید. ما درباره امکان راه‌اندازی و قدم بعدی پاسخ می‌دهیم.</p></div>
                <div class="landing-contact-actions"><a class="landing-button landing-button-primary" href="{{ route('contact') }}">راه‌های ارتباط با ما <span aria-hidden="true">↖</span></a><a class="landing-button landing-button-outline" href="mailto:info@blucom.ir">ایمیل به info@blucom.ir <span aria-hidden="true">↖</span></a></div>
            </div>
        </section>
    </main>
@endsection

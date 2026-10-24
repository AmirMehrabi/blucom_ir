@extends('layouts.marketing')

@section('title', 'بلوکام | تلفن ابری برای شرکت‌ها')
@section('description', 'بلوکام به شرکت‌ها کمک می‌کند شماره کاری، داخلی همکاران و مسیر پاسخ‌گویی تماس‌ها را از یک جا مدیریت کنند.')

@section('content')
@php($testimonials = collect(config('marketing.testimonials')))
<main id="main">
    <section class="landing-hero home-hero" aria-labelledby="hero-title">
        <div class="landing-hero-art" aria-hidden="true"></div>
        <div class="landing-container home-hero-grid">
            <div class="home-hero-message">
                <h1 id="hero-title">تماس‌های کاری، <span>سر جای خودشان.</span></h1>
                <p>شماره کاری، تلفن همکاران و مسیر پاسخ‌گویی را از یک جا مدیریت کنید؛ با شماره جدید یا خط فعلی شرکتتان.</p>
                <div class="home-hero-actions">
                    <a class="landing-button landing-button-primary" href="{{ route('plans') }}">دیدن طرح‌ها و هزینه‌ها <span aria-hidden="true">←</span></a>
                    <a class="landing-button landing-button-outline" href="{{ route('contact') }}">مشاوره برای خط فعلی <span aria-hidden="true">←</span></a>
                </div>
            </div>
        </div>
        <div class="landing-hero-fade" aria-hidden="true"></div>
    </section>

    <section id="customers" class="home-section home-customers-section" aria-labelledby="customers-title">
        <div class="landing-container home-customers-inner">
            <div class="home-section-intro">
                <h2 id="customers-title">همراهان بلوکام</h2>
                <p>بلوکام همراه کسب‌وکارهایی است که می‌خواهند هر تماس، مقصد مشخصی داشته باشد.</p>
            </div>
            <ol class="home-customer-list" aria-label="مشتریان بلوکام">
                @forelse(config('marketing.customers') as $customer)
                    <li>
                        <a class="home-customer-link" href="{{ $customer['url'] }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $customer['name'] }} (باز شدن در زبانهٔ جدید)">
                            @if(!empty($customer['logo']))<span class="home-customer-logo"><img src="{{ asset($customer['logo']) }}" alt="نشان {{ $customer['name'] }}" loading="lazy"></span>@endif
                            <span class="home-customer-name"><strong>{{ $customer['name'] }}</strong></span>
                        </a>
                    </li>
                @empty
                    <li class="home-customer-empty">نام مشتریان پس از اجازهٔ انتشار اضافه می‌شود.</li>
                @endforelse
            </ol>
        </div>
    </section>

    <section id="product" class="home-section home-demo-section" aria-labelledby="product-title">
        <div class="landing-container">
            <div class="home-section-intro">
                <p class="landing-kicker">تلفن کاری در یک نگاه</p>
                <h2 id="product-title">شماره، همکاران و مسیر تماس؛ همه‌چیز روشن.</h2>
                <p>بلوکام ابزارهای اصلی تلفن شرکت را یک‌جا نگه می‌دارد تا تماس‌ها به مقصد درست برسند.</p>
            </div>
            <div class="home-capabilities-grid">
                <article class="home-capability-card"><span>۰۱</span><h3>شمارهٔ کاری</h3><p>شمارهٔ جدید یا امکان استفاده از خط فعلی را بررسی می‌کنیم.</p></article>
                <article class="home-capability-card"><span>۰۲</span><h3>تلفن همکاران</h3><p>برای اعضای تیم، داخلی و روش پاسخ‌گویی مناسب تعریف می‌شود.</p></article>
                <article class="home-capability-card"><span>۰۳</span><h3>مسیر تماس</h3><p>مشخص می‌کنید تماس به کدام همکار یا تیم برسد.</p></article>
                <article class="home-capability-card"><span>۰۴</span><h3>مدیریت ساده</h3><p>تنظیمات اصلی تلفن شرکت از یک پنل پیگیری می‌شود.</p></article>
            </div>
        </div>
    </section>

    <section id="voices" class="home-section home-voices-section" aria-labelledby="voices-title">
        <div class="landing-container">
            <div class="home-section-intro"><p class="landing-kicker">از زبان مشتریان</p><h2 id="voices-title">وقتی مسیر تماس روشن می‌شود، چه چیزی بهتر می‌شود؟</h2><p>روایت‌هایی کوتاه از تجربهٔ کار روزمره با بلوکام.</p></div>
            <div class="home-testimonial-grid home-testimonial-grid--{{ min($testimonials->count(), 3) }}">
                @forelse($testimonials as $testimonial)
                    <blockquote class="home-testimonial-card">
                        <span class="home-testimonial-quote" aria-hidden="true">“</span>
                        <p>«{{ $testimonial['quote'] }}»</p>
                        <footer>
                            @if(!empty($testimonial['name']))
                                <strong>{{ $testimonial['name'] }}</strong>
                                @if(!empty($testimonial['role']))<span>{{ $testimonial['role'] }}</span>@endif
                            @endif
                            @if(!empty($testimonial['draft']))<span class="home-testimonial-status">پیشنهادی · در انتظار تأیید</span>@endif
                        </footer>
                    </blockquote>
                @empty
                    <p class="home-testimonial-empty">روایت مشتریان پس از دریافت و تأیید منتشر می‌شود.</p>
                @endforelse
            </div>
        </div>
    </section>

    <section id="faq" class="home-section home-faq-section" aria-labelledby="faq-title">
        <div class="landing-container landing-faq-grid">
            <div class="home-section-intro">
                <p class="landing-kicker">پرسش‌های رایج</p>
                <h2 id="faq-title">پیش از شروع چه چیزهایی را بدانید؟</h2>
                <p>شرایط هر شرکت متفاوت است؛ پاسخ‌ها را پیش از راه‌اندازی شفاف می‌کنیم.</p>
            </div>
            <div class="landing-faq-list">
                <details><summary>آیا باید شمارهٔ جدید بگیریم؟ <span aria-hidden="true">+</span></summary><p>نه لزوماً. امکان استفاده از خط فعلی شرکت را پیش از راه‌اندازی بررسی می‌کنیم.</p></details>
                <details><summary>همکاران چطور به تلفن کاری پاسخ می‌دهند؟ <span aria-hidden="true">+</span></summary><p>بسته به نیاز تیم، تماس‌ها به داخلی‌ها و مسیرهای پاسخ‌گویی مشخص هدایت می‌شوند.</p></details>
                <details><summary>هزینهٔ راه‌اندازی و استفاده چطور مشخص می‌شود؟ <span aria-hidden="true">+</span></summary><p>هزینه‌ها و موارد خارج از طرح را با توجه به شماره‌ها و نیازهای شرکت، پیش از شروع روشن می‌کنیم.</p></details>
                <details><summary>اگر نیاز ما خاص باشد چه می‌شود؟ <span aria-hidden="true">+</span></summary><p>امکان‌پذیری و محدودیت‌ها را بررسی می‌کنیم و پیش از شروع دربارهٔ آن‌ها شفاف می‌گوییم.</p></details>
            </div>
        </div>
    </section>

    <section id="letter" class="home-section home-letter-section" aria-labelledby="letter-title">
        <div class="landing-container home-letter-wrap">
            <div class="home-letter-stack">
                <article class="home-letter-paper">
                    <p class="home-letter-greeting">سلام،</p>
                    <p>تلفن شرکت نباید موضوعی باشد که هر روز مجبور باشید به آن فکر کنید. باید بتوانید تماس بگیرید، تماس‌ها را پاسخ دهید و مطمئن باشید هر تماس به کسی می‌رسد که باید.</p>
                    <p>اما همیشه این‌طور نیست.</p>
                    <p>گاهی مشخص نیست تماس‌های ورودی به کجا می‌روند. گاهی برای اضافه کردن یک همکار یا تغییر مسیر تماس‌ها باید چند نفر درگیر شوند. گاهی هم شرکت هزینهٔ یک سیستم تلفنی را می‌پردازد، بدون اینکه دقیقاً بداند چه امکاناتی دارد و چه محدودیت‌هایی پیش رویش است.</p>
                    <p>مسئله این نیست که تلفن کاری باید امکانات بیشتری داشته باشد. مسئله این است که باید درست کار کند.</p>
                                        <p>ما بلوکام را با همین نگاه شروع کردیم.</p>
                    <p>می‌خواستیم راه‌اندازی و مدیریت تلفن شرکت‌ها ساده‌تر و قابل‌فهم‌تر باشد. شماره‌های کاری مشخص باشند، تماس‌ها به مقصد درست برسند و هر تیم بتواند متناسب با شیوهٔ کار خودش از تلفن استفاده کند.</p>
                    <p>برای همین، کار ما از شناخت وضعیت فعلی شرکت شروع می‌شود، نه پیشنهاد یک راه‌حل از پیش تعیین‌شده.</p>
                    <p>ابتدا بررسی می‌کنیم چه خطوطی دارید، تماس‌ها چطور پاسخ داده می‌شوند و چه چیزی باید تغییر کند. بعد روشن می‌کنیم چه کاری امکان‌پذیر است، چه هزینه‌ای دارد و چه محدودیت‌هایی وجود دارد.</p>
                    <p>اگر امکانی هنوز آماده نیست، همان را می‌گوییم. ترجیح می‌دهیم از ابتدا انتظارات روشنی داشته باشیم تا اینکه چیزی را وعده بدهیم که نتوانیم به آن عمل کنیم.</p>
                    <p>ما معتقدیم یک سیستم تلفنی خوب قرار نیست توجه زیادی به خودش جلب کند. قرار است کارش را آن‌قدر درست انجام دهد که شما بتوانید روی کار اصلی شرکتتان تمرکز کنید.</p>
                    <p>این چیزی است که می‌خواهیم با بلوکام بسازیم.</p>
                    <!-- <details class="home-letter-full">
                        <summary>ادامهٔ نامه <span aria-hidden="true">＋</span></summary>
                        <div>

                        </div>
                    </details> -->
                    <p>اگر فکر می‌کنید تلفن شرکتتان می‌تواند بهتر از امروز کار کند، خوشحال می‌شوم از وضعیت فعلی‌تان بشنوم.</p>
                    <div class="home-letter-signature"><strong>امیرمسعود مهرابیان</strong><span>بنیان‌گذار بلوکام</span></div>
                </article>
            </div>
        </div>
            <div class="landing-container home-letter-cta">
                <div><p class="landing-kicker">قدم بعدی</p><h2>بیایید مسیر تماس‌های شرکتتان را روشن کنیم.</h2></div>
                <a class="landing-button landing-button-primary" href="{{ route('contact') }}">مشاوره راه‌اندازی <span aria-hidden="true">←</span></a>
            </div>
    </section>
</main>
@endsection

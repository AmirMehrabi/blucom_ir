@extends('layouts.marketing')

@section('title', 'بلوکام | تماس‌های کاری، سر جای خودشان')
@section('description', 'بلوکام به کسب‌وکارها کمک می‌کند شماره کاری، تلفن‌های تیم و مسیر پاسخ‌گویی تماس‌ها را روشن و قابل مدیریت کنند.')

@section('content')
<main id="main">
    <section class="landing-hero home-hero" aria-labelledby="hero-title">
        <div class="landing-hero-art" aria-hidden="true"></div>
        <div class="landing-container home-hero-grid">
            <div class="home-hero-message">
                <p class="landing-kicker">تلفن کاری، بدون پیچیدگی اضافه</p>
                <h1 id="hero-title">تماس‌های کاری،<br><span>سر جای خودشان.</span></h1>
                <p>شماره، همکاران و مسیر پاسخ‌گویی را در یک جا مرتب کنید. وقتی کسی تماس می‌گیرد، معلوم باشد تلفن چه کسی باید زنگ بخورد.</p>
            </div>
            <nav class="home-hero-nav" aria-label="بخش‌های صفحه اصلی">
                <span class="home-hero-nav-label">در این صفحه</span>
                <a href="#letter">نامه بنیان‌گذار <span>۰۱</span></a>
                <a href="#customers">مشتریان <span>۰۲</span></a>
                <a href="#voices">از زبان مشتریان <span>۰۳</span></a>
                <a href="#demo">بلوکام در عمل <span>۰۴</span></a>
            </nav>
        </div>
        <div class="landing-hero-fade" aria-hidden="true"></div>
    </section>

    <section id="letter" class="home-section home-letter-section" aria-labelledby="letter-title">
        <div class="landing-container home-letter-wrap">
            <div class="home-section-intro">
                <p class="landing-kicker">یک نامه کوتاه</p>
                <h2 id="letter-title">از طرف بنیان‌گذار بلوکام</h2>
            </div>
            <article class="home-letter-paper">
                <div class="home-letter-top"><span>بلوکام</span><span>نامه‌ای دربارهٔ کار ساده‌تر</span></div>
                <p class="home-letter-greeting">سلام،</p>
                <p>تلفن کاری زمانی خوب کار می‌کند که در شلوغی روز، خیال آدم‌ها از آن راحت باشد. شماره‌ها روشن باشند، تماس‌ها به همکار درست برسند و هرکس بداند برای تماس خروجی از چه مسیری استفاده می‌کند.</p>
                <p>بلوکام از توجه به همین جزئیات روزمره شکل گرفته است. نظم دادن به مسیر تماس شاید کار کوچکی به نظر برسد، اما برای تیمی که هر روز با مشتریانش گفت‌وگو می‌کند، تفاوتی ملموس می‌سازد.</p>
                <p>پیش از هر راه‌اندازی، خط موجود و شیوهٔ پاسخ‌گویی تیم بررسی می‌شود. آنچه امروز قابل اجراست، همراه با محدودهٔ کار و هزینه، روشن و مکتوب گفته می‌شود. دربارهٔ امکاناتی که هنوز آماده نیستند هم وعده‌ای داده نمی‌شود.</p>
                <p>اگر تلفن شرکت شما به نظم بیشتری نیاز دارد، از وضعیت امروزتان برایمان بگویید. گفت‌وگو می‌تواند از همان‌جا آغاز شود.</p>
                <div class="home-letter-signature"><span>با احترام،</span><strong>امیرمسعود مهرابیان</strong><span>بنیان‌گذار بلوکام</span></div>
            </article>
        </div>
    </section>

    <section id="customers" class="home-section home-customers-section" aria-labelledby="customers-title">
        <div class="landing-container">
            <div class="home-section-intro"><p class="landing-kicker">آشنایی</p><h2 id="customers-title">یک دقیقه با چند مشتری ما آشنا شوید.</h2><p>پشت هر خط تلفن، یک تیم و شیوهٔ کار واقعی هست. نام مشتریان را با اجازهٔ انتشار در اینجا آورده‌ایم؛ روایت‌هایشان را هم پس از تأیید خودشان اضافه می‌کنیم.</p></div>
            <ol class="home-customer-list">
                @forelse(config('marketing.customers') as $customer)
                    <li>
                        <span class="home-customer-index">{{ ['۰۱', '۰۲', '۰۳', '۰۴', '۰۵', '۰۶'][$loop->index] ?? '—' }}</span>
                        <div>
                        @if(!empty($customer['logo']))<img src="{{ asset($customer['logo']) }}" alt="نشان {{ $customer['name'] }}">@endif
                        <h3>{{ $customer['name'] }}</h3>
                        @if(!empty($customer['person']))<p>{{ $customer['person'] }}</p>@endif
                        </div>
                    </li>
                @empty
                    <li><span class="home-customer-index">۰۱</span><div><h3>معرفی مشتریان به‌زودی</h3><p>نام‌ها را پس از اجازهٔ انتشار اضافه می‌کنیم.</p></div></li>
                @endforelse
            </ol>
        </div>
    </section>

    <section id="voices" class="home-section home-voices-section" aria-labelledby="voices-title">
        <div class="landing-container">
            <div class="home-section-intro"><p class="landing-kicker">از زبان مشتریان</p><h2 id="voices-title">از مشتریان پرسیدیم: «از وقتی به بلوکام آمدید، چه چیزی بهتر شد؟»</h2><p>متن‌های زیر پیش‌نویس هستند. پس از تأیید مشتریان، روایت‌ها را با نام خودشان منتشر می‌کنیم.</p></div>
            <div class="home-testimonial-grid">
                @forelse(config('marketing.testimonials') as $testimonial)
                    <blockquote class="home-testimonial-card">
                        <span class="home-testimonial-quote" aria-hidden="true">“</span>
                        <p>«{{ $testimonial['quote'] }}»</p>
                        <footer>
                            @if(!empty($testimonial['draft']))
                                <strong>متن پیشنهادی · در انتظار تأیید</strong>
                            @else
                                <strong>{{ $testimonial['name'] }}</strong>
                                @if(!empty($testimonial['role']))<span>{{ $testimonial['role'] }}</span>@endif
                            @endif
                        </footer>
                    </blockquote>
                @empty
                    @foreach (['پاسخ‌گویی به تماس‌ها', 'هماهنگی میان همکاران', 'کار با شماره‌های کاری'] as $topic)
                        <div class="home-testimonial-placeholder">
                            <span class="home-quote-mark" aria-hidden="true">“</span>
                            <h3>{{ $topic }}</h3>
                            <p>جای روایت مشتری دربارهٔ این موضوع؛ پس از دریافت و تأیید متن منتشر می‌شود.</p>
                        </div>
                    @endforeach
                @endforelse
            </div>
        </div>
    </section>

    <section id="demo" class="home-section home-demo-section" aria-labelledby="demo-title">
        <div class="landing-container">
            <div class="home-section-intro"><p class="landing-kicker">بلوکام در عمل</p><h2 id="demo-title">از شماره تا پاسخ، مسیر مشخص است.</h2><p>این نمایش ساده، منطق کار بلوکام را نشان می‌دهد. جزئیات راه‌اندازی به خط و ارائه‌دهندهٔ شما بستگی دارد.</p></div>
            <div class="home-demo" data-home-demo>
                <div class="home-demo-tabs" role="tablist" aria-label="نوع تماس">
                    <button type="button" id="demo-inbound-tab" role="tab" aria-controls="demo-inbound" aria-selected="true" data-demo-target="inbound">تماس ورودی</button>
                    <button type="button" id="demo-outbound-tab" role="tab" aria-controls="demo-outbound" aria-selected="false" tabindex="-1" data-demo-target="outbound">تماس خروجی</button>
                </div>
                <div class="home-demo-panel" id="demo-inbound" role="tabpanel" aria-labelledby="demo-inbound-tab" data-demo-panel="inbound">
                    <p class="home-demo-caption">مشتری با شماره کاری شما تماس می‌گیرد</p>
                    <div class="home-demo-path"><div><span>۰۱</span><strong>شماره کاری</strong><small>تماس وارد می‌شود</small></div><i aria-hidden="true">←</i><div><span>۰۲</span><strong>مسیر پاسخ‌گویی</strong><small>شماره به داخلی مربوط وصل می‌شود</small></div><i aria-hidden="true">←</i><div><span>۰۳</span><strong>تلفن همکار</strong><small>فرد تعیین‌شده پاسخ می‌دهد</small></div></div>
                </div>
                <div class="home-demo-panel" id="demo-outbound" role="tabpanel" aria-labelledby="demo-outbound-tab" data-demo-panel="outbound" hidden>
                    <p class="home-demo-caption">همکار شما از تلفن کاری تماس می‌گیرد</p>
                    <div class="home-demo-path"><div><span>۰۱</span><strong>تلفن همکار</strong><small>تماس آغاز می‌شود</small></div><i aria-hidden="true">←</i><div><span>۰۲</span><strong>شماره مجاز</strong><small>شماره خروجی بررسی می‌شود</small></div><i aria-hidden="true">←</i><div><span>۰۳</span><strong>ارائه‌دهنده خط</strong><small>تماس از مسیر تأییدشده برقرار می‌شود</small></div></div>
                </div>
                <div class="home-demo-bottom"><p>بلوکام تنظیمات را نگه می‌دارد؛ موتور تلفنی تماس را برقرار می‌کند.</p><a href="{{ route('plans') }}">طرح‌ها و هزینه‌ها <span aria-hidden="true">←</span></a></div>
            </div>
        </div>
    </section>
</main>
@endsection

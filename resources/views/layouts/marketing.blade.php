<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="description" content="@yield('description')">
    <title>@yield('title')</title>
    <link rel="preload" as="font" type="font/woff2" href="{{ Vite::asset('public/assets/fonts/ravi/Ravi-VF.woff2') }}" crossorigin>
    <link rel="preload" as="image" href="{{ Vite::asset('resources/images/hero-concepts/voice-current-desktop.webp') }}" fetchpriority="high" media="(min-width: 761px)">
    <link rel="preload" as="image" href="{{ Vite::asset('resources/images/hero-concepts/voice-current-mobile.webp') }}" fetchpriority="high" media="(max-width: 760px)">
    @include('shared.brand-icons')
    @vite(['resources/css/marketing.css'])
</head>
<body class="landing-page">
    <a class="landing-skip" href="#main">رفتن به محتوای اصلی</a>
    <header class="landing-header">
        <div class="landing-container landing-header-inner">
            <a class="landing-brand" href="{{ route('home') }}" aria-label="بلوکام، صفحه اصلی">
                @include('shared.brand-mark', ['brandMarkClass' => 'landing-brand-symbol'])
                <img class="landing-brand-wordmark" src="{{ asset('assets/brand/blucom-wordmark.webp') }}" alt="" aria-hidden="true" width="256" height="133">
            </a>
            <nav class="landing-nav" aria-label="ناوبری اصلی">
                <a href="{{ route('home') }}#product">خدمات</a>
                <a href="{{ route('home') }}#customers">مشتریان</a>
                <a href="{{ route('home') }}#faq">پرسش‌های رایج</a>
                <a href="{{ route('home') }}#letter">دربارهٔ بلوکام</a>
                <a href="{{ route('plans') }}" @if(request()->routeIs('plans')) aria-current="page" @endif>طرح‌ها و هزینه‌ها</a>
                <a href="{{ route('contact') }}" @if(request()->routeIs('contact')) aria-current="page" @endif>تماس با ما</a>
            </nav>
            <div class="landing-auth-links">
                <a class="landing-login" href="{{ route('customer.login') }}">ورود مشتریان <span aria-hidden="true">↖</span></a>
                <a class="landing-login landing-register" href="{{ route('customer.register') }}">ثبت‌نام</a>
            </div>
        </div>
    </header>

    @yield('content')

    <footer class="landing-footer">
        <div class="landing-container landing-footer-inner">
            <div class="landing-footer-brand">
                <a class="landing-brand" href="{{ route('home') }}" aria-label="بلوکام، صفحه اصلی">@include('shared.brand-mark', ['brandMarkClass' => 'landing-brand-symbol'])<img class="landing-brand-wordmark" src="{{ asset('assets/brand/blucom-wordmark.webp') }}" alt="" aria-hidden="true" width="256" height="133"></a>
                <p>تلفن کاری، با مسیر روشن.</p>
                <a referrerpolicy='origin' target='_blank' href='https://trustseal.enamad.ir/?id=8095245&Code=bmusaIEYcRwRBsup0TmuorCTQs86VvtI'><img referrerpolicy='origin' src='https://trustseal.enamad.ir/logo.aspx?id=8095245&Code=bmusaIEYcRwRBsup0TmuorCTQs86VvtI' alt='' style='cursor:pointer' code='bmusaIEYcRwRBsup0TmuorCTQs86VvtI' loading='lazy' decoding='async'></a>

            </div>
            <div class="landing-footer-links">
                <a href="{{ route('home') }}#product">خدمات</a>
                <a href="{{ route('home') }}#customers">مشتریان</a>
                <a href="{{ route('home') }}#faq">پرسش‌های رایج</a>
                <a href="{{ route('home') }}#letter">دربارهٔ بلوکام</a>
                <a href="{{ route('plans') }}">طرح‌ها و هزینه‌ها</a>
                <a href="{{ route('contact') }}">ارتباط با ما</a>
            </div>
            <small>© {{ now()->year }} بلوکام</small>
        </div>
    </footer>
</body>
</html>

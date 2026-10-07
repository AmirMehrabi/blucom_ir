<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="description" content="@yield('description')">
    <title>@yield('title')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="landing-page">
    <a class="landing-skip" href="#main">رفتن به محتوای اصلی</a>
    <header class="landing-header @if(request()->routeIs('home')) landing-header-home @endif">
        <div class="landing-container landing-header-inner">
            @if(request()->routeIs('home'))
                <a class="landing-home-name" href="{{ route('home') }}">بلوکام</a>
                <a class="landing-home-logo" href="{{ route('home') }}" aria-label="بلوکام، صفحه اصلی">
                    <span class="landing-brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
                </a>
            @else
                <a class="landing-brand" href="{{ route('home') }}" aria-label="بلوکام، صفحه اصلی">
                    <span class="landing-brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
                    <span>بلوکام</span>
                </a>
                <nav class="landing-nav" aria-label="ناوبری اصلی">
                    <a href="{{ route('home') }}#demo">محصول</a>
                    <a href="{{ route('plans') }}" @if(request()->routeIs('plans')) aria-current="page" @endif>طرح‌ها و هزینه‌ها</a>
                    <a href="{{ route('contact') }}" @if(request()->routeIs('contact')) aria-current="page" @endif>ارتباط با ما</a>
                </nav>
            @endif
            <div class="landing-auth-links">
                <a class="landing-login" href="{{ route('customer.login') }}">ورود مشتریان <span aria-hidden="true">↖</span></a>
                <a class="landing-login" href="{{ route('customer.register') }}">ثبت‌نام</a>
            </div>
        </div>
    </header>

    @yield('content')

    <footer class="landing-footer">
        <div class="landing-container landing-footer-inner">
            <div><a class="landing-brand" href="{{ route('home') }}">بلوکام</a><p>تلفن کاری، با مسیر روشن.</p></div>
            <div class="landing-footer-links">
                <a href="{{ route('plans') }}">طرح‌ها و هزینه‌ها</a>
                <a href="{{ route('contact') }}">ارتباط با ما</a>
                <a href="tel:+982191093464" dir="ltr">021 9109 3464</a>
                <a href="mailto:info@blucom.ir">info@blucom.ir</a>
                <span>کرمان، میدان قرنی، ساختمان پدر، واحد ۳۰۲</span>
            </div>
            <small>© {{ now()->year }} بلوکام</small>
        </div>
    </footer>
</body>
</html>

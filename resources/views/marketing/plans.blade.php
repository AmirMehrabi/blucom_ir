@extends('layouts.marketing')

@section('title', 'طرح‌ها و هزینه‌ها | بلوکام')
@section('description', 'طرح‌های پیشنهادی تلفن کاری بلوکام؛ حرفه‌ای، کسب‌وکار و سازمانی، با هزینه ماهانه روشن و راه‌اندازی مدیریت‌شده.')

@section('content')
<main id="main">
    <section class="marketing-page-head" aria-labelledby="plans-title">
        <div class="landing-container">
            <p class="landing-kicker">طرح‌ها و هزینه‌ها</p>
            <h1 id="plans-title">تلفن کاری حرفه‌ای،<br><span>با هزینهٔ روشن.</span></h1>
            <p>سه انتخاب ساده برای تیم‌هایی که راه‌اندازی و نگهداری تلفن کاری را به بلوکام می‌سپارند. طرح‌های زیر پیشنهاد اولیه‌اند؛ پیش از فعال‌سازی، سازگاری خط، ظرفیت و محدودهٔ خدمات را بررسی و در پیشنهاد مکتوب مشخص می‌کنیم.</p>
        </div>
    </section>

    <section class="landing-section plans-section" aria-labelledby="packages-title">
        <div class="landing-container">
            <div class="landing-section-heading">
                <p class="landing-kicker">پیشنهاد بسته‌ها</p>
                <h2 id="packages-title">حرفه‌ای، کسب‌وکار یا سازمانی.</h2>
                <p>امکانات اصلی تماس در همهٔ طرح‌ها مشترک است؛ انتخاب شما به تعداد شماره‌ها، داخلی‌ها و سطح همراهی مورد نیاز بستگی دارد.</p>
            </div>
            <div class="plans-grid">
                @php
                    $persianNumber = fn ($value) => strtr(number_format($value), ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹', ',' => '٬']);
                @endphp
                @forelse($plans as $plan)
                    @php
                        $package = $plan->marketing;
                        $quoteOnly = $package['quote_only'] ?? false;
                        $featured = $package['featured'] ?? false;
                        $limits = $plan->versions->first()?->limits ?? [];
                    @endphp
                    <article class="plan-card {{ $featured ? 'plan-card-featured' : '' }}">
                        <div class="plan-card-head">
                            <p class="plan-overline">{{ $package['overline'] }}</p>
                            <h3>{{ $plan->name }}</h3>
                            <p>{{ $package['description'] }}</p>
                        </div>
                        @if($quoteOnly)
                            <p class="plan-custom-price">قیمت توافقی</p>
                            <p class="plan-price-note">هزینهٔ ماهانه و راه‌اندازی در پیشنهاد مکتوب</p>
                        @else
                            <p class="plan-price"><span>{{ $persianNumber($package['monthly_amount']) }}</span> تومان</p>
                            <p class="plan-price-note">ماهانه · میزبانی و نگهداری مدیریت‌شده</p>
                            <p class="plan-price-note">هزینهٔ یک‌بارهٔ راه‌اندازی: {{ $persianNumber($package['setup_amount']) }} تومان</p>
                        @endif
                        <ul>
                            @if(!$quoteOnly)
                                <li>تا {{ $persianNumber($limits['sip_numbers'] ?? $package['numbers']) }} شماره کاری</li>
                                <li>تا {{ $persianNumber($limits['extensions']) }} تلفن داخلی</li>
                            @endif
                            @foreach($package['features'] as $feature)
                                <li>{{ $feature }}</li>
                            @endforeach
                        </ul>
                        <a class="landing-button {{ $featured ? 'landing-button-primary' : 'landing-button-outline' }}" href="{{ route('contact') }}">{{ $quoteOnly ? 'شرایطتان را بگویید' : 'درباره این طرح بپرسید' }} <span aria-hidden="true">←</span></a>
                    </article>
                @empty
                    <p>برای دریافت طرح‌ها و قیمت‌های جاری با بلوکام تماس بگیرید.</p>
                @endforelse
            </div>
            <p class="plans-caveat">قیمت‌ها به تومان و برای خدمات استاندارد پیشنهادی بلوکام هستند. هزینهٔ خط و مکالمه جداست. ظرفیت تماس همزمان به امکانات خط و ارائه‌دهنده بستگی دارد و پیش از قرارداد مشخص می‌شود. مبلغ نهایی، مالیات احتمالی، ساعات پشتیبانی و محدودهٔ خدمات در پیشنهاد مکتوب اعلام می‌شوند.</p>
        </div>
    </section>

    <section class="landing-section plans-details" aria-labelledby="costs-title">
        <div class="landing-container plans-details-grid">
            <div><p class="landing-kicker">شفافیت هزینه</p><h2 id="costs-title">چه چیزهایی جداگانه حساب می‌شوند؟</h2><p>هزینهٔ ماهانه، میزبانی و نگهداری استاندارد بلوکام را پوشش می‌دهد؛ راه‌اندازی فقط یک‌بار پرداخت می‌شود. هزینه‌های خارج از طرح را پیش از شروع مشخص می‌کنیم.</p></div>
            <div class="plans-cost-list">
                <article><span>۰۱</span><div><h3>خط و مکالمه</h3><p>خرید یا اجاره شماره، کانال تماس و کارکرد مکالمه تابع تعرفهٔ ارائه‌دهنده خط است و در ارقام بالا نیست.</p></div></article>
                <article><span>۰۲</span><div><h3>زیرساخت اختصاصی</h3><p>میزبانی استاندارد در هزینهٔ ماهانهٔ طرح است. سرور اختصاصی یا نیاز زیرساختی ویژه، در صورت درخواست جداگانه قیمت‌گذاری می‌شود.</p></div></article>
                <article><span>۰۳</span><div><h3>تجهیزات و کار اضافه</h3><p>تلفن رومیزی، شبکه داخلی، حضور در محل و تغییرات خارج از محدوده اولیه، پس از بررسی جداگانه قیمت‌گذاری می‌شوند.</p></div></article>
            </div>
        </div>
    </section>

    <section class="landing-section plans-bottom" aria-labelledby="plans-next-title"><div class="landing-container landing-contact-grid"><div><p class="landing-kicker">قدم بعدی</p><h2 id="plans-next-title">طرح مناسب تیم شما را پیدا کنیم.</h2><p>نوع خط، تعداد شماره‌ها، تعداد داخلی‌ها و ظرفیت تماس همزمان مورد نیاز را بگویید تا طرح مناسب و هزینهٔ کامل راه‌اندازی و خدمات ماهانه را پیشنهاد کنیم.</p></div><a class="landing-button landing-button-primary" href="{{ route('contact') }}">راه‌های ارتباط با بلوکام <span aria-hidden="true">←</span></a></div></section>
</main>
@endsection

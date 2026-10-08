@extends('layouts.marketing')

@section('title', 'تماس با بلوکام')
@section('description', 'برای مشاوره راه‌اندازی تلفن کاری با بلوکام تماس بگیرید یا پیام بفرستید.')

@section('content')
<main id="main">
    <section class="marketing-page-head contact-page-head" aria-labelledby="contact-page-title">
        <div class="landing-container contact-head-inner">
            <p class="landing-kicker">تماس با بلوکام</p>
            <h1 id="contact-page-title">برای شروع، <span>با ما در تماس باشید.</span></h1>
            <p>پرسشی درباره راه‌اندازی تلفن کاری دارید؟ راه مناسب خودتان را انتخاب کنید یا پیام بفرستید؛ لازم نیست از قبل جزئیات فنی را بدانید.</p>
            <div class="contact-quick-actions" aria-label="راه‌های تماس سریع">
                <a class="landing-button landing-button-primary" href="tel:+982191093464"><span aria-hidden="true">☎</span> ۰۲۱ ۹۱۰۹ ۳۴۶۴</a>
                <a class="landing-button landing-button-outline" href="mailto:info@blucom.ir"><span aria-hidden="true">✉</span> info@blucom.ir</a>
            </div>
        </div>
    </section>

    <section class="landing-section contact-main" aria-label="اطلاعات تماس و فرم پیام">
        <div class="landing-container contact-main-grid">
            <aside class="contact-info" aria-labelledby="contact-info-title">
                <p class="landing-kicker">راه‌های ارتباط</p>
                <h2 id="contact-info-title">سریع و مستقیم</h2>
                <div class="contact-info-list">
                    <a class="contact-info-item" href="tel:+982191093464">
                        <span class="contact-info-icon" aria-hidden="true">☎</span>
                        <span><small>تلفن</small><strong dir="ltr">021 9109 3464</strong><em>تماس برای مشاوره و راه‌اندازی</em></span>
                        <span class="contact-info-arrow" aria-hidden="true">↖</span>
                    </a>
                    <a class="contact-info-item" href="mailto:info@blucom.ir">
                        <span class="contact-info-icon" aria-hidden="true">✉</span>
                        <span><small>ایمیل</small><strong dir="ltr">info@blucom.ir</strong><em>برای شرح نیاز یا پیگیری</em></span>
                        <span class="contact-info-arrow" aria-hidden="true">↖</span>
                    </a>
                    <div class="contact-info-item contact-address">
                        <span class="contact-info-icon" aria-hidden="true">⌖</span>
                        <span><small>نشانی دفتر</small><strong>کرمان، میدان قرنی، ساختمان پدر، واحد ۳۰۲</strong><em>برای مراجعه حضوری، لطفاً از قبل هماهنگ کنید.</em><a class="contact-map-link" href="https://maps.google.com/?q={{ urlencode('کرمان، میدان قرنی، ساختمان پدر') }}" target="_blank" rel="noopener noreferrer">دیدن نشانی روی نقشه <span aria-hidden="true">↖</span></a></span>
                    </div>
                </div>
                <p class="contact-privacy-note"><span aria-hidden="true">⌑</span> اطلاعات فرم فقط برای پاسخ‌گویی به پیام شما استفاده می‌شود. رمز تلفن یا اطلاعات ورود را ارسال نکنید.</p>
            </aside>

            <section class="contact-form-panel" id="contact-form" aria-labelledby="contact-form-title" tabindex="-1">
                <div class="contact-form-heading">
                    <div><p class="landing-kicker">پیام به ما</p><h2 id="contact-form-title">درخواستتان را بنویسید</h2></div>
                    <span class="contact-required-note">* فیلدهای ضروری</span>
                </div>

                @if(session('contact-sent'))
                    <div class="contact-alert contact-alert-success" role="status" tabindex="-1">پیامتان به دست ما رسید. در اولین فرصت با شما تماس می‌گیریم.</div>
                @endif
                @if(session('contact-error'))
                    <div class="contact-alert contact-alert-error" role="alert">ارسال پیام انجام نشد. لطفاً دوباره تلاش کنید یا با تلفن ۰۲۱ ۹۱۰۹ ۳۴۶۴ تماس بگیرید.</div>
                @endif
                @if($errors->any())
                    <div class="contact-alert contact-alert-error" role="alert">لطفاً موارد مشخص‌شده را بررسی کنید و دوباره بفرستید.</div>
                @endif

                <form method="post" action="{{ route('contact.send') }}" class="contact-form">
                    @csrf
                    <div class="contact-form-row">
                        <div class="contact-field">
                            <label for="contact-name">نام شما <span>*</span></label>
                            <input id="contact-name" name="name" type="text" autocomplete="name" maxlength="120" value="{{ old('name') }}" required aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}" @if($errors->has('name')) aria-describedby="contact-name-error" @endif>
                            @error('name')<small class="contact-field-error" id="contact-name-error">{{ $message }}</small>@enderror
                        </div>
                        <div class="contact-field">
                            <label for="contact-topic">موضوع پیام</label>
                            <select id="contact-topic" name="topic">
                                <option value="">انتخاب کنید</option>
                                @foreach(['راه‌اندازی تلفن کاری', 'خرید شماره', 'پشتیبانی مشتریان', 'سایر'] as $topic)
                                    <option value="{{ $topic }}" @selected(old('topic') === $topic)>{{ $topic }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="contact-form-row">
                        <div class="contact-field">
                            <label for="contact-phone">شماره تماس <span class="contact-optional">یا</span></label>
                            <input id="contact-phone" name="phone" type="tel" autocomplete="tel" inputmode="tel" maxlength="32" value="{{ old('phone') }}" placeholder="مثلاً ۰۹۱۲۱۲۳۴۵۶۷" aria-invalid="{{ $errors->has('phone') ? 'true' : 'false' }}" aria-describedby="contact-channel-hint @if($errors->has('phone')) contact-phone-error @endif">
                            @if($errors->has('phone') && !$errors->has('email'))<small class="contact-field-error" id="contact-phone-error">{{ $errors->first('phone') }}</small>@endif
                        </div>
                        <div class="contact-field">
                            <label for="contact-email">ایمیل <span class="contact-optional">یا</span></label>
                            <input id="contact-email" name="email" type="email" autocomplete="email" inputmode="email" maxlength="254" dir="ltr" value="{{ old('email') }}" placeholder="name@example.com" aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}" aria-describedby="contact-channel-hint @if($errors->has('email')) contact-email-error @endif">
                            @if($errors->has('email') && !$errors->has('phone'))<small class="contact-field-error" id="contact-email-error">{{ $errors->first('email') }}</small>@endif
                        </div>
                    </div>
                    @if($errors->has('email') && $errors->has('phone'))<small class="contact-field-error contact-row-error">لطفاً شماره تماس یا ایمیل خود را وارد کنید.</small>@endif
                    <small class="contact-field-hint" id="contact-channel-hint">برای اینکه بتوانیم پاسخ بدهیم، شماره تماس یا ایمیل را وارد کنید.</small>
                    <div class="contact-field">
                        <label for="contact-message">پیام شما <span>*</span></label>
                        <textarea id="contact-message" name="message" rows="5" maxlength="4000" required placeholder="کوتاه بگویید چه کمکی از ما می‌خواهید…" aria-invalid="{{ $errors->has('message') ? 'true' : 'false' }}" @if($errors->has('message')) aria-describedby="contact-message-error" @endif>{{ old('message') }}</textarea>
                        @error('message')<small class="contact-field-error" id="contact-message-error">{{ $message }}</small>@enderror
                    </div>
                    <div class="contact-honeypot" aria-hidden="true"><label for="contact-website">وب‌سایت</label><input id="contact-website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
                    <div class="contact-submit-row"><button class="landing-button landing-button-primary" type="submit">ارسال پیام <span aria-hidden="true">←</span></button><small>با ارسال فرم، درخواست شما برای پاسخ‌گویی به بلوکام می‌رسد.</small></div>
                </form>
            </section>
        </div>
    </section>
</main>
@endsection

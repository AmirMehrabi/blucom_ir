@extends('layouts.marketing')

@section('title', 'ارتباط با ما | بلوکام')
@section('description', 'برای بررسی راه‌اندازی تلفن کاری با بلوکام تماس بگیرید: ۰۲۱۹۱۰۹۳۴۶۴، info@blucom.ir، کرمان، میدان قرنی، ساختمان پدر، واحد ۳۰۲.')

@section('content')
<main id="main">
    <section class="marketing-page-head contact-page-head" aria-labelledby="contact-page-title">
        <div class="landing-container">
            <p class="landing-kicker">ارتباط با بلوکام</p>
            <h1 id="contact-page-title">بیایید از تلفن<br><span>شرکت شما شروع کنیم.</span></h1>
            <p>لازم نیست از قبل طرح یا اصطلاحات فنی را بدانید. وضعیت فعلی، شماره‌ها و نوع پاسخ‌گویی موردنظرتان را بگویید تا بتوانیم دربارهٔ امکان اجرا و قدم بعدی دقیق صحبت کنیم.</p>
        </div>
    </section>

    <section class="landing-section contact-methods" aria-labelledby="contact-methods-title">
        <div class="landing-container contact-methods-grid">
            <div><p class="landing-kicker">راه‌های ارتباط</p><h2 id="contact-methods-title">هر راهی برایتان راحت‌تر است.</h2><p>برای بررسی اولیه می‌توانید تماس بگیرید یا ایمیل بفرستید. مراجعه حضوری را هم بهتر است از پیش هماهنگ کنید.</p></div>
            <div class="contact-method-list">
                <article><span class="contact-method-label">تلفن</span><div><a class="contact-method-value" href="tel:+982191093464" dir="ltr">021 9109 3464</a><p>برای گفت‌وگوی اولیه و پرسش درباره راه‌اندازی.</p></div><span class="contact-method-arrow" aria-hidden="true">↖</span></article>
                <article><span class="contact-method-label">ایمیل</span><div><a class="contact-method-value" href="mailto:info@blucom.ir">info@blucom.ir</a><p>برای فرستادن شرح نیاز و اطلاعات غیرمحرمانهٔ خط.</p></div><span class="contact-method-arrow" aria-hidden="true">↖</span></article>
                <article><span class="contact-method-label">نشانی</span><div><p class="contact-method-value">کرمان، میدان قرنی، ساختمان پدر، واحد ۳۰۲</p><p>برای مراجعه، پیش از آمدن هماهنگ کنید.</p></div></article>
            </div>
        </div>
    </section>

    <section class="landing-section contact-brief" aria-labelledby="brief-title">
        <div class="landing-container contact-brief-grid">
            <div><p class="landing-kicker">برای پاسخ دقیق‌تر</p><h2 id="brief-title">چه اطلاعاتی کمک می‌کند؟</h2><p>یک توضیح کوتاه کافی است. اگر جزئیاتی را نمی‌دانید، همان را در گفت‌وگو روشن می‌کنیم.</p></div>
            <ol><li><span>۱</span><div><h3>شماره‌ها و ارائه‌دهنده خط</h3><p>چند شماره دارید و خط را از کدام شرکت گرفته‌اید؟</p></div></li><li><span>۲</span><div><h3>تعداد پاسخ‌گوها</h3><p>چند نفر قرار است تماس بگیرند یا پاسخ بدهند؟</p></div></li><li><span>۳</span><div><h3>مسیر دلخواه تماس</h3><p>تماس هر شماره باید به چه کسی یا چه بخشی برسد؟</p></div></li></ol>
        </div>
        <div class="landing-container"><p class="contact-security-note">لطفاً رمز عبور خط، رمز تلفن یا اطلاعات ورود را در ایمیل اولیه نفرستید. اگر برای راه‌اندازی لازم باشند، روش امن دریافتشان را هماهنگ می‌کنیم.</p></div>
    </section>

    <section class="landing-section contact-final" aria-labelledby="contact-final-title"><div class="landing-container landing-contact-grid"><div><p class="landing-kicker">از همین‌جا</p><h2 id="contact-final-title">یک گفت‌وگوی ساده کافی است.</h2><p>برای شروع، لازم نیست چیزی بخرید یا فرمی طولانی پر کنید. با ما تماس بگیرید یا شرح نیازتان را ایمیل کنید.</p></div><div class="landing-contact-actions"><a class="landing-button landing-button-primary" href="tel:+982191093464">تماس با بلوکام <span aria-hidden="true">↖</span></a><a class="landing-button landing-button-outline" href="mailto:info@blucom.ir">ارسال ایمیل <span aria-hidden="true">↖</span></a></div></div></section>
</main>
@endsection

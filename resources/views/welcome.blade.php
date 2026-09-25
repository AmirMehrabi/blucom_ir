<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>بلوکام — تلفن سازمانی، بدون دردسر</title>
    <meta name="description" content="ارائه‌دهنده خود را وصل کنید، شماره‌تان را ثبت کنید، پاسخ‌گو را انتخاب کنید و تلفن را راه بیندازید.">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .hero-art {
            background-image: url('{{ asset('assets/images/blucom-hero.png') }}');
            background-size: cover;
            background-position: center;
        }
        .headline-mark {
            background-image: linear-gradient(transparent 62%, rgba(31, 100, 168, 0.18) 62%);
        }
        @media (prefers-reduced-motion: no-preference) {
            .pulse-dot { animation: pulse-ring 1.8s ease-out infinite; }
            @keyframes pulse-ring {
                0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.55); }
                70% { box-shadow: 0 0 0 10px rgba(34, 197, 94, 0); }
                100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
            }
        }
    </style>
</head>
<body class="bg-[#fbfaf7] text-[#22221f] antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:right-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-[#1f64a8] focus:px-4 focus:py-2 focus:text-white">پرش به محتوا</a>

    <header class="border-b border-[#e6e5df]/80 bg-[#fbfaf7]/90 backdrop-blur-sm">
        <nav class="mx-auto flex max-w-5xl items-center justify-between px-5 py-5" aria-label="ناوبری اصلی">
            <a href="/" class="flex items-center gap-2.5" aria-label="بلوکام — صفحه اصلی">
                <span class="grid size-8 place-items-center rounded-md bg-[#22221f] text-sm font-black text-[#fbfaf7]">ب</span>
                <span class="text-lg font-black tracking-tight">بلوکام</span>
            </a>
            <div class="flex items-center gap-1 text-sm font-bold">
                <a href="#how" class="hidden rounded-md px-3 py-2 text-[#5f625e] transition hover:bg-white hover:text-[#22221f] sm:inline-flex">امکانات</a>
                <a href="/login" class="rounded-md px-3 py-2 text-[#5f625e] transition hover:bg-white hover:text-[#22221f]">ورود</a>
                <a href="/login" class="rounded-md bg-[#22221f] px-3.5 py-2 text-[#fbfaf7] transition hover:bg-[#1f64a8]">شروع</a>
            </div>
        </nav>
    </header>

    <main id="main">
        {{-- Hero --}}
        <section class="relative isolate overflow-hidden border-b border-[#e6e5df]">
            <div class="hero-art pointer-events-none absolute inset-0 -z-10 opacity-70" aria-hidden="true"></div>
            <div class="pointer-events-none absolute inset-0 -z-10 bg-gradient-to-l from-[#fbfaf7]/40 via-[#fbfaf7]/85 to-[#fbfaf7]" aria-hidden="true"></div>

            <div class="mx-auto grid max-w-5xl items-center gap-12 px-5 pb-16 pt-14 sm:pb-24 sm:pt-20 lg:grid-cols-[1.1fr_.9fr] lg:gap-16 lg:pb-28 lg:pt-24">
                <div>
                    <p class="inline-flex items-center gap-2 rounded-full border border-[#e0ddd4] bg-white/70 px-3 py-1 text-xs font-bold text-[#5f625e] backdrop-blur">
                        <span class="size-1.5 rounded-full bg-[#c56d42]" aria-hidden="true"></span>
                        تلفن سازمانی
                    </p>

                    <h1 class="mt-6 text-[2.6rem] font-black leading-[1.18] tracking-tight sm:text-6xl lg:text-[4.25rem]">
                        تلفن کاری‌تان را
                        <span class="headline-mark">ساده</span>
                        نگه دارید.
                    </h1>

                    <p class="mt-6 max-w-xl text-lg leading-9 text-[#5f625e]">
                        ارائه‌دهنده و شماره خود را وصل کنید —
                        بدون ماژول‌هایی که هرگز بازشان نمی‌کنید،
                        و بدون اینکه تلفن تبدیل به پروژه‌ی جانبی تیم شود.
                    </p>

                    <div class="mt-8 flex flex-wrap items-center gap-3">
                        <a href="/login" class="group inline-flex items-center gap-2 rounded-md bg-[#1f64a8] px-6 py-3.5 text-sm font-bold text-white shadow-[0_10px_30px_-12px_rgba(31,100,168,0.7)] transition hover:bg-[#164f87]">
                            وارد شوید
                            <span class="transition-transform group-hover:-translate-x-1" aria-hidden="true">←</span>
                        </a>
                        <a href="#how" class="inline-flex items-center rounded-md border border-[#d9dedb] bg-white/80 px-6 py-3.5 text-sm font-bold text-[#4f5551] backdrop-blur transition hover:border-[#22221f] hover:text-[#22221f]">
                            چطور کار می‌کند؟
                        </a>
                    </div>

                    <p class="mt-5 text-sm text-[#858a85]">ورود با کد یک‌بارمصرف روی موبایل. رمز عبوری برای یاد کردن نیست.</p>
                </div>

                {{-- Product vignette: the one flow that matters --}}
                <aside class="relative" aria-label="نمونه مسیر تماس">
                    <div class="rounded-2xl border border-[#e0ddd4] bg-white/90 p-5 shadow-[0_24px_60px_-30px_rgba(34,34,31,0.35)] backdrop-blur sm:p-6">
                        <div class="flex items-center justify-between gap-3 border-b border-[#eef0eb] pb-4">
                            <p class="text-xs font-black uppercase tracking-[0.14em] text-[#858a85]">مسیر تماس</p>
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-bold text-emerald-700">
                                <span class="pulse-dot size-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                                نمونه مسیر
                            </span>
                        </div>

                        <ol class="mt-5 space-y-3">
                            <li class="flex items-center gap-3 rounded-xl border border-[#eef0eb] bg-[#fbfaf7] px-3.5 py-3">
                                <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-[#22221f] text-[11px] font-black text-[#fbfaf7]">۱</span>
                                <div class="min-w-0">
                                    <p class="text-[11px] font-bold text-[#858a85]">تماس ورودی</p>
                                    <p class="truncate text-sm font-black" dir="ltr">021 0000 0000</p>
                                </div>
                                <span class="mr-auto text-[#c56d42]" aria-hidden="true">↓</span>
                            </li>
                            <li class="flex items-center gap-3 rounded-xl border border-[#eef0eb] bg-[#fbfaf7] px-3.5 py-3">
                                <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-[#1f64a8] text-[11px] font-black text-white">۲</span>
                                <div class="min-w-0">
                                    <p class="text-[11px] font-bold text-[#858a85]">مسیریابی</p>
                                    <p class="truncate text-sm font-black">شماره → پاسخ‌گو</p>
                                </div>
                                <span class="mr-auto text-[#c56d42]" aria-hidden="true">↓</span>
                            </li>
                            <li class="flex items-center gap-3 rounded-xl border border-[#d7e7db] bg-[#f3faf5] px-3.5 py-3">
                                <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-emerald-600 text-[11px] font-black text-white">۳</span>
                                <div class="min-w-0">
                                    <p class="text-[11px] font-bold text-emerald-700/80">پاسخ</p>
                                    <p class="truncate text-sm font-black">تلفن تیم · زنگ خورد</p>
                                </div>
                            </li>
                        </ol>

                        <div class="mt-5 grid grid-cols-3 gap-2 border-t border-[#eef0eb] pt-4 text-center">
                            <div>
                                <p class="text-lg font-black leading-none">۱</p>
                                <p class="mt-1 text-[11px] text-[#858a85]">شماره</p>
                            </div>
                            <div class="border-x border-[#eef0eb]">
                                <p class="text-lg font-black leading-none">۴</p>
                                <p class="mt-1 text-[11px] text-[#858a85]">پاسخ‌گو</p>
                            </div>
                            <div>
                                <p class="text-lg font-black leading-none">۰</p>
                                <p class="mt-1 text-[11px] text-[#858a85]">فایل XML دستی</p>
                            </div>
                        </div>
                    </div>

                    <div class="absolute -bottom-4 -left-3 -z-10 h-24 w-24 rounded-full bg-[#c56d42]/15 blur-2xl sm:-left-6" aria-hidden="true"></div>
                </aside>
            </div>
        </section>

        {{-- The problem, plainly --}}
        <section class="border-b border-[#e6e5df]">
            <div class="mx-auto max-w-3xl px-5 py-16 sm:py-20">
                <p class="eyebrow-like text-sm font-bold text-[#c56d42]">مسئله</p>
                <h2 class="mt-3 text-2xl font-black tracking-tight sm:text-4xl">
                    مشتری نباید دنبال شما بگردد.
                </h2>
                <div class="mt-6 space-y-4 text-lg leading-9 text-[#5f625e]">
                    <p>
                        وقتی هر نفر شماره شخصی‌اش را می‌دهد، تماس‌ها پراکنده می‌شود.
                        کسی جواب نمی‌دهد. کسی نمی‌داند چه خبر است. تجربه مشتری خراب می‌شود — نه به‌خاطر محصول شما، به‌خاطر تلفن.
                    </p>
                    <p>
                        بلوکام همین آشوب را جمع می‌کند: شماره خودتان، پاسخ‌گوی مشخص، و تلفنی که تیم با آن کار می‌کند.
                    </p>
                </div>
            </div>
        </section>

        {{-- What it does --}}
        <section id="how" class="border-b border-[#e6e5df]">
            <div class="mx-auto max-w-3xl px-5 py-16 sm:py-20">
                <p class="text-sm font-bold text-[#1f64a8]">چه کار می‌کند</p>
                <h2 class="mt-3 text-2xl font-black tracking-tight sm:text-4xl">کارهایی که واقعاً لازم دارید</h2>

                <ol class="mt-10 border-t border-[#e6e5df]">
                    @foreach ([
                        ['n' => '۰۱', 't' => 'ارائه‌دهنده و شماره خودتان', 'b' => 'حساب ارائه‌دهنده و شماره‌هایی را که خودتان دارید ثبت کنید. هر شماره به اتصال درست پیوند می‌خورد.'],
                        ['n' => '۰۲', 't' => 'پاسخ‌گوی روشن', 'b' => 'برای هر شماره مشخص کنید تلفن چه کسی زنگ بخورد. تماس خروجی فقط با شماره تأییدشده انجام می‌شود.'],
                        ['n' => '۰۳', 't' => 'ورود بدون رمز', 'b' => 'کد یک‌بارمصرف روی موبایل. نه رمزی برای لو رفتن، نه فرموزی برای فراموش شدن.'],
                        ['n' => '۰۴', 't' => 'راه‌اندازی تلفن', 'b' => 'مشخصات تلفن نرم‌افزاری یا رومیزی را دریافت کنید و یک تماس آزمایشی بگیرید.'],
                    ] as $item)
                        <li class="group grid gap-3 border-b border-[#e6e5df] py-7 sm:grid-cols-[3.5rem_1fr] sm:gap-6">
                            <span class="inline-flex h-fit w-fit rounded-md bg-[#f3f1ea] px-2 py-1 text-sm font-black text-[#c56d42] transition group-hover:bg-[#22221f] group-hover:text-[#fbfaf7]">{{ $item['n'] }}</span>
                            <div>
                                <h3 class="text-lg font-black">{{ $item['t'] }}</h3>
                                <p class="mt-2 leading-8 text-[#5f625e]">{{ $item['b'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        {{-- What we don't do --}}
        <section class="border-b border-[#e6e5df] bg-[#f3f1ea]">
            <div class="mx-auto max-w-3xl px-5 py-16 sm:py-20">
                <p class="text-sm font-bold text-[#c56d42]">برعکسِ خیلی از سرویس‌ها</p>
                <h2 class="mt-3 text-2xl font-black tracking-tight sm:text-4xl">چه چیزی نمی‌سازیم</h2>
                <p class="mt-4 max-w-2xl leading-8 text-[#5f625e]">
                    نرم‌افزار خوب یعنی مرز داشتن. بلوکام عمداً کوچک می‌ماند تا قابل‌فهم بماند.
                </p>
                <ul class="mt-8 grid gap-3 sm:grid-cols-2">
                    @foreach ([
                        'مرکز تماس غول‌پیکر با ده‌ها ماژولی که ۹۰٪ تیم‌ها هرگز لازمشان ندارند.',
                        'گزارش‌های رنگی و نمودارهایی که کسی آخر ماه نگاه نمی‌کند.',
                        'قفل شدن به زیرساخت شما با فایل‌های پیکربندی دستی.',
                        'ترفند «پلان رایگان» که نصف امکانات را گروگان می‌گیرد.',
                    ] as $item)
                        <li class="flex gap-3 rounded-xl border border-[#e0ddd4] bg-[#fbfaf7]/70 p-4 text-base leading-7 text-[#4f5551]">
                            <span class="mt-2 size-1.5 shrink-0 rounded-full bg-[#c56d42]" aria-hidden="true"></span>
                            <span>{{ $item }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>

        {{-- How it works --}}
        <section class="border-b border-[#e6e5df]">
            <div class="mx-auto max-w-3xl px-5 py-16 sm:py-20">
                <p class="text-sm font-bold text-[#1f64a8]">شروع</p>
                <h2 class="mt-3 text-2xl font-black tracking-tight sm:text-4xl">چهار قدم روشن</h2>
                <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ([
                        ['k' => 'قدم اول', 't' => 'ارائه‌دهنده را وصل کنید', 'b' => 'اطلاعات اتصال شرکتی را که خط را از آن گرفته‌اید وارد کنید.'],
                        ['k' => 'قدم دوم', 't' => 'شماره خود را ثبت کنید', 'b' => 'شماره را وارد کنید و ارائه‌دهنده مربوط به آن را انتخاب کنید.'],
                        ['k' => 'قدم سوم', 't' => 'پاسخ‌گو را انتخاب کنید', 'b' => 'مشخص کنید تماس‌های این شماره به تلفن چه کسی برسند.'],
                        ['k' => 'قدم چهارم', 't' => 'تلفن را تنظیم کنید', 'b' => 'مشخصات تلفن را بگیرید و تماس آزمایشی انجام دهید.'],
                    ] as $i => $step)
                        <div class="rounded-xl border border-[#e6e5df] bg-white/60 p-5 transition hover:border-[#22221f]/30 hover:bg-white">
                            <p class="text-xs font-black uppercase tracking-wider text-[#858a85]">{{ $step['k'] }}</p>
                            <p class="mt-3 text-2xl font-black text-[#1f64a8]">{{ ['۱', '۲', '۳', '۴'][$i] }}</p>
                            <h3 class="mt-2 font-black leading-7">{{ $step['t'] }}</h3>
                            <p class="mt-2 text-sm leading-7 text-[#5f625e]">{{ $step['b'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Philosophy --}}
        <section class="border-b border-[#e6e5df] bg-[#22221f] text-[#fbfaf7]">
            <div class="mx-auto max-w-3xl px-5 py-16 sm:py-20">
                <blockquote>
                    <p class="text-2xl font-black leading-relaxed tracking-tight sm:text-4xl">
                        سیستم تلفنی باید کار را جلو ببرد،
                        <span class="text-[#93c5fd]">نه اینکه خودش تبدیل به کار شود.</span>
                    </p>
                    <footer class="mt-5 text-sm text-[#858a85]">قاعده‌ای که بلوکام را می‌سازد</footer>
                </blockquote>
                <p class="mt-8 max-w-2xl text-lg leading-9 text-[#c5c8c2]">
                    کار خوب به فضا نیاز دارد. تلفن خوب هم همین‌طور: کوتاه، روشن، سر جایش.
                    ما پیچیدگی را پشت صحنه نگه می‌داریم تا شما روی مشتری و کار اصلی‌تان متمرکز بمانید.
                </p>
            </div>
        </section>

        {{-- Who it's for --}}
        <section class="border-b border-[#e6e5df]">
            <div class="mx-auto max-w-3xl px-5 py-16 sm:py-20">
                <div class="grid gap-10 sm:grid-cols-2">
                    <div>
                        <h2 class="text-xl font-black">برای چه کسانی است</h2>
                        <ul class="mt-4 space-y-3 text-[#5f625e] leading-8">
                            @foreach ([
                                'تیم‌ها و شرکت‌هایی که می‌خواهند یک شماره‌ی کاریِ مرتب داشته باشند.',
                                'کسانی که از پخش‌شدن تماس‌ها روی موبایل شخصی خسته شده‌اند.',
                                'مدیرانی که وقت و حوصله‌ی پیکربندی سرور را ندارند — و لازم هم ندارند.',
                            ] as $item)
                                <li class="flex gap-2.5">
                                    <span class="mt-3 size-1.5 shrink-0 rounded-full bg-[#1f64a8]" aria-hidden="true"></span>
                                    <span>{{ $item }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="rounded-2xl border border-dashed border-[#d9dedb] bg-[#f3f1ea]/60 p-5 sm:p-6">
                        <h2 class="text-xl font-black">برای چه کسانی نیست</h2>
                        <ul class="mt-4 space-y-3 text-[#5f625e] leading-8">
                            @foreach ([
                                'کسانی که دنبال یک ERP با ۲۰۰ ماژول می‌گردند.',
                                'تیم‌هایی که صبح تا شب تنظیمات تلفن را سرگرمی خودشان می‌دانند.',
                                'هرکسی که انتظار دارد نرم‌افزار جای فکر کردن را هم بگیرد.',
                            ] as $item)
                                <li class="flex gap-2.5">
                                    <span class="mt-3 size-1.5 shrink-0 rounded-full bg-[#c56d42]" aria-hidden="true"></span>
                                    <span>{{ $item }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        {{-- CTA --}}
        <section class="border-b border-[#e6e5df] bg-[#1f64a8] text-white">
            <div class="mx-auto flex max-w-3xl flex-col gap-8 px-5 py-16 sm:py-20 md:flex-row md:items-end md:justify-between">
                <div>
                    <p class="text-sm font-bold text-blue-100/90">بدون فشار</p>
                    <h2 class="mt-3 text-3xl font-black tracking-tight sm:text-4xl">
                        خودتان امتحان کنید.
                    </h2>
                    <p class="mt-4 max-w-xl text-lg leading-9 text-blue-100">
                        نه تماس فروش داریم، نه دموی ۴۵ دقیقه‌ای.
                    با شماره موبایل وارد شوید، سپس ارائه‌دهنده و خط خودتان را ثبت کنید.
                    </p>
                </div>
                <div class="shrink-0">
                    <a href="/login" class="inline-flex items-center gap-2 rounded-md bg-white px-6 py-3.5 text-sm font-black text-[#1f64a8] transition hover:bg-blue-50">
                        ورود / شروع
                        <span aria-hidden="true">←</span>
                    </a>
                    <p class="mt-3 text-sm text-blue-100/85">بدون کارت بانکی. بدون تعهد.</p>
                </div>
            </div>
        </section>
    </main>

    <footer class="mx-auto flex max-w-5xl flex-col gap-3 px-5 py-10 text-sm text-[#858a85] sm:flex-row sm:items-center sm:justify-between">
        <p class="font-black text-[#22221f]">بلوکام</p>
        <p>تلفن سازمانی، بی‌سروصدا. © {{ now()->year }}</p>
        <a href="/login" class="font-bold text-[#5f625e] transition hover:text-[#1f64a8]">ورود به پنل</a>
    </footer>
</body>
</html>

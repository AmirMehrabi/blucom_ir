<?php

return [
    'contact_email' => env('CONTACT_INBOX', 'info@blucom.ir'),
    'customers' => [
        ['name' => 'آوینوکس', 'url' => 'https://avinox.ir', 'logo' => 'assets/images/customers/avinox.svg'],
        ['name' => 'پی‌بای', 'url' => 'https://paybuy.ir', 'logo' => 'assets/images/customers/paybuy.svg'],
        ['name' => 'آواپرداز', 'url' => 'https://avapardaz.ir', 'logo' => 'assets/images/customers/avapardaz.svg'],
    ],
    // Testimonials supplied with speaker names for publication.
    'testimonials' => [
        [
            'topic' => 'پاسخ‌گویی روشن‌تر',
            'quote' => 'حالا برای هر شماره می‌دانیم تماس باید به چه کسی برسد. وقتی مسئول پاسخ‌گویی عوض می‌شود، مسیر تماس را دوباره مشخص می‌کنیم.',
            'name' => 'محمد ذکایی',
            'role' => 'هادر',
            'draft' => false,
        ],
        [
            'topic' => 'شماره کاری مشخص',
            'quote' => 'تماس‌های کاری از شماره‌های شخصی جدا شده‌اند و همکاران می‌دانند برای تماس خروجی از کدام خط استفاده کنند.',
            'name' => 'محمدامین امیری فر',
            'role' => 'حسابرو',
            'draft' => false,
        ],
        [
            'topic' => 'مدیریت تماس‌های کاری',
            'quote' => 'برای ما مهم بود تماس‌های کاری مسیر مشخصی داشته باشند و اضافه شدن همکاران، مدیریت تلفن را پیچیده نکند. با بلوکام توانستیم مسیر پاسخ‌گویی را متناسب با نیاز ماشین‌سازان تنظیم کنیم.',
            'name' => 'شهرام شریفی‌پور',
            'role' => 'ماشین‌سازان',
            'draft' => false,
        ],
    ],
];

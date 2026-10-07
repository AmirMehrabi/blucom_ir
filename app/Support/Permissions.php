<?php

namespace App\Support;

final class Permissions
{
    public const DASHBOARD_VIEW = 'dashboard.view';

    public const LIVE_VIEW = 'live.view';

    public const LINES_VIEW = 'lines.view';

    public const CALLS_VIEW = 'calls.view';

    public const RECORDINGS_VIEW = 'recordings.view';

    public const RECORDINGS_DOWNLOAD = 'recordings.download';

    public const RECORDINGS_DELETE = 'recordings.delete';

    public const RECORDINGS_MANAGE = 'recordings.manage';

    public const QUEUES_WORK = 'queues.work';

    public const PROVIDERS_MANAGE = 'providers.manage';

    public const NUMBERS_MANAGE = 'numbers.manage';

    public const PHONES_MANAGE = 'phones.manage';

    public const NUMBERS_PURCHASE = 'numbers.purchase';

    public const BILLING_VIEW = 'billing.view';

    public const BILLING_MANAGE = 'billing.manage';

    // Reservation and billing service permissions; paid checkout arrives later.
    public const CUSTOMER_ASSIGNABLE = [
        self::DASHBOARD_VIEW,
        self::LIVE_VIEW,
        self::LINES_VIEW,
        self::CALLS_VIEW,
        self::RECORDINGS_VIEW,
        self::RECORDINGS_DOWNLOAD,
        self::RECORDINGS_DELETE,
        self::RECORDINGS_MANAGE,
        self::QUEUES_WORK,
        self::PHONES_MANAGE,
        self::NUMBERS_PURCHASE,
        self::BILLING_VIEW,
        self::BILLING_MANAGE,
    ];

    public const CUSTOMER_OWNER_DEFAULTS = self::CUSTOMER_ASSIGNABLE;

    public const CUSTOMER_STAFF_DEFAULTS = [self::DASHBOARD_VIEW, self::LINES_VIEW, self::QUEUES_WORK];

    public const OPERATOR_DEFAULTS = [
        self::DASHBOARD_VIEW,
        self::LIVE_VIEW,
        self::LINES_VIEW,
        self::CALLS_VIEW,
        self::RECORDINGS_VIEW,
        self::RECORDINGS_DOWNLOAD,
        self::RECORDINGS_DELETE,
        self::RECORDINGS_MANAGE,
        self::QUEUES_WORK,
        self::PROVIDERS_MANAGE,
        self::NUMBERS_MANAGE,
        self::PHONES_MANAGE,
    ];

    public const OPERATOR_ASSIGNABLE = self::OPERATOR_DEFAULTS;

    public const LABELS = [
        self::DASHBOARD_VIEW => 'دیدن داشبورد',
        self::LIVE_VIEW => 'دیدن وضعیت زنده داخلی‌ها',
        self::LINES_VIEW => 'دیدن خط‌ها',
        self::CALLS_VIEW => 'دیدن تاریخچه تماس‌ها',
        self::RECORDINGS_VIEW => 'دیدن و پخش صدای تماس‌ها',
        self::RECORDINGS_DOWNLOAD => 'دانلود صدای تماس‌ها',
        self::RECORDINGS_DELETE => 'حذف صدای تماس‌ها',
        self::RECORDINGS_MANAGE => 'تنظیم ضبط و نگهداری تماس‌ها',
        self::QUEUES_WORK => 'تغییر وضعیت پاسخ‌گویی تیم',
        self::PROVIDERS_MANAGE => 'مدیریت اتصال ارائه‌دهنده',
        self::NUMBERS_MANAGE => 'ثبت و ویرایش شماره',
        self::PHONES_MANAGE => 'تنظیم پاسخ‌گو و تلفن',
        self::NUMBERS_PURCHASE => 'خرید شماره',
        self::BILLING_VIEW => 'دیدن صورتحساب',
        self::BILLING_MANAGE => 'مدیریت اشتراک',
    ];
}

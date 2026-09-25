<?php

namespace App\Support;

final class Permissions
{
    public const DASHBOARD_VIEW = 'dashboard.view';

    public const LINES_VIEW = 'lines.view';

    public const PROVIDERS_MANAGE = 'providers.manage';

    public const NUMBERS_MANAGE = 'numbers.manage';

    public const PHONES_MANAGE = 'phones.manage';

    public const OPERATOR_DEFAULTS = [
        self::DASHBOARD_VIEW,
        self::LINES_VIEW,
        self::PROVIDERS_MANAGE,
        self::NUMBERS_MANAGE,
        self::PHONES_MANAGE,
    ];

    public const OPERATOR_ASSIGNABLE = self::OPERATOR_DEFAULTS;

    public const LABELS = [
        self::DASHBOARD_VIEW => 'دیدن داشبورد',
        self::LINES_VIEW => 'دیدن خط‌ها',
        self::PROVIDERS_MANAGE => 'مدیریت اتصال ارائه‌دهنده',
        self::NUMBERS_MANAGE => 'ثبت و ویرایش شماره',
        self::PHONES_MANAGE => 'تنظیم پاسخ‌گو و تلفن',
    ];
}

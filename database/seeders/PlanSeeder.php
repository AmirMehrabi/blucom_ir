<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        // Suggested package prices in toman (IRT), not per-number billing offers.
        $packages = [
            'professional' => [
                'name' => 'حرفه‌ای', 'order' => 1, 'featured' => false,
                'overline' => 'برای یک تیم کوچک',
                'description' => 'یک شماره کاری با پاسخ‌گویی مشخص.',
                'monthly_amount' => 2900000, 'setup_amount' => 9900000,
                'numbers' => 1, 'extensions' => 5,
                'features' => ['تنظیم مسیر تماس ورودی و خروجی', 'راهنمای اتصال تلفن نرم‌افزاری', 'آزمون تماس و آموزش شروع کار', 'پشتیبانی در ساعات کاری'],
            ],
            'business' => [
                'name' => 'کسب‌وکار', 'order' => 2, 'featured' => true,
                'overline' => 'پیشنهاد ما برای تیم‌های در حال رشد',
                'description' => 'چند شماره و همکار، با مسیرهای جداگانه.',
                'monthly_amount' => 5900000, 'setup_amount' => 19900000,
                'numbers' => 3, 'extensions' => 15,
                'features' => ['مسیر پاسخ‌گویی جدا برای هر شماره', 'تنظیم شماره‌های مجاز برای تماس خروجی', 'آزمون تماس و آموزش تیم', 'اولویت رسیدگی در ساعات کاری'],
            ],
            'enterprise' => [
                'name' => 'سازمانی', 'order' => 3, 'featured' => false,
                'overline' => 'برای شرایط متفاوت',
                'description' => 'برای تیم‌های بزرگ‌تر یا نیازهای اجرایی متفاوت.',
                'monthly_amount' => null, 'setup_amount' => null,
                'numbers' => null, 'extensions' => null,
                'features' => ['بیش از ۳ شماره یا ۱۵ تلفن داخلی', 'برآورد ظرفیت تلفن و تماس همزمان', 'برنامهٔ راه‌اندازی متناسب با تیم', 'تعیین موارد قابل اجرا پیش از قرارداد', 'توافق جداگانه دربارهٔ سطح پشتیبانی'],
            ],
        ];

        DB::transaction(function () use ($packages) {
            foreach ($packages as $slug => $package) {
                $name = $package['name'];
                unset($package['name']);
                $plan = Plan::query()->firstOrCreate(['slug' => $slug], [
                    'name' => $name,
                    'marketing' => [...$package, 'currency' => 'IRT', 'quote_only' => $slug === 'enterprise'],
                ]);

                // Never overwrite existing plans or their immutable published versions.
                // Enterprise capacity is agreed per customer; no arbitrary limits are seeded.
                if ($plan->wasRecentlyCreated && $slug !== 'enterprise') {
                    $plan->versions()->create([
                        'version' => 1,
                        'features' => ['extensions', 'inbound_routing', 'outbound_routing'],
                        'limits' => ['extensions' => $package['extensions'], 'sip_numbers' => $package['numbers'], 'queues' => 0, 'ivr_menus' => 0],
                        'limit_scope' => 'tenant', 'billing_interval' => 'monthly',
                        'published_at' => now(),
                    ]);
                }
            }
        });
    }
}

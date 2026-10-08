<?php

namespace Tests\Feature;

use App\Mail\MarketingContactMessage;
use App\Models\Plan;
use App\Models\PlanVersion;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MarketingPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_can_read_the_plans_page_from_public_domain(): void
    {
        $this->seed(PlanSeeder::class);

        foreach (['blucom.ir'] as $host) {
            $this->get('https://'.$host.'/plans')
                ->assertOk()
                ->assertSee('هزینهٔ یک‌بارهٔ راه‌اندازی')
                ->assertSee('۲٬۹۰۰٬۰۰۰')
                ->assertSee('۹٬۹۰۰٬۰۰۰')
                ->assertSee('۵٬۹۰۰٬۰۰۰')
                ->assertSee('۱۹٬۹۰۰٬۰۰۰')
                ->assertSee('ماهانه · میزبانی و نگهداری مدیریت‌شده')
                ->assertSee('هزینهٔ خط و مکالمه جداست')
                ->assertSee('قیمت توافقی');
        }
    }

    public function test_plan_seeder_is_repeatable_and_preserves_changes(): void
    {
        $this->seed(PlanSeeder::class);
        $plan = Plan::query()->where('slug', 'professional')->firstOrFail();
        $marketing = $plan->marketing;
        $marketing['monthly_amount'] = 3200000;
        $plan->update(['marketing' => $marketing, 'archived' => true]);
        $version = $plan->versions()->firstOrFail();
        $publishedAt = $version->published_at;

        $this->seed(PlanSeeder::class);

        $this->assertSame(3, Plan::query()->count());
        $this->assertSame(2, PlanVersion::query()->count());
        $this->assertSame(3200000, $plan->fresh()->marketing['monthly_amount']);
        $this->assertTrue($plan->fresh()->archived);
        $this->assertTrue($version->fresh()->published_at->equalTo($publishedAt));
        $this->assertSame(['extensions' => 5, 'sip_numbers' => 1, 'queues' => 0, 'ivr_menus' => 0], $version->limits);
        $this->assertSame(0, Plan::query()->where('slug', 'enterprise')->firstOrFail()->versions()->count());
    }

    public function test_plans_page_reads_database_prices_and_hides_archived_and_unpublished_plans(): void
    {
        $this->seed(PlanSeeder::class);
        $professional = Plan::query()->where('slug', 'professional')->firstOrFail();
        $marketing = $professional->marketing;
        $marketing['monthly_amount'] = 3200000;
        $professional->update(['name' => '<script>alert(1)</script>', 'marketing' => $marketing]);
        Plan::query()->where('slug', 'business')->firstOrFail()->update(['archived' => true]);
        $draft = Plan::query()->create(['name' => 'Unpublished package', 'marketing' => $marketing]);
        $draft->versions()->create(['version' => 1, 'features' => [], 'limits' => [], 'billing_interval' => 'monthly', 'limit_scope' => 'tenant']);

        $this->get('https://blucom.ir/plans')
            ->assertOk()
            ->assertSee('۳٬۲۰۰٬۰۰۰')
            ->assertDontSee('۲٬۹۰۰٬۰۰۰')
            ->assertDontSee('۵٬۹۰۰٬۰۰۰')
            ->assertDontSee('Unpublished package')
            ->assertSee('<script>alert(1)</script>')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('قیمت توافقی');
    }

    public function test_empty_plan_catalog_has_a_contact_fallback(): void
    {
        $this->get('https://blucom.ir/plans')
            ->assertOk()
            ->assertSee('برای دریافت طرح‌ها و قیمت‌های جاری با بلوکام تماس بگیرید.')
            ->assertDontSee('۲٬۹۰۰٬۰۰۰');
    }

    public function test_contact_page_shows_the_provided_contact_details(): void
    {
        $this->get('https://blucom.ir/contact')
            ->assertOk()
            ->assertSee('contact-form')
            ->assertSee('name="message"', false)
            ->assertSee('tel:+982191093464', false)
            ->assertSee('mailto:info@blucom.ir', false)
            ->assertSee('کرمان، میدان قرنی، ساختمان پدر، واحد ۳۰۲');
    }

    public function test_contact_form_emails_the_submission_to_the_contact_inbox(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'marketing.contact_email' => 'info@blucom.ir']);

        $this->post('https://blucom.ir/contact', [
            'name' => 'Test Visitor',
            'email' => 'visitor@example.com',
            'topic' => 'راه‌اندازی تلفن کاری',
            'message' => 'We need help setting up our business phone system.',
        ])->assertRedirect(route('contact').'#contact-form')
            ->assertSessionHas('contact-sent');

        Mail::assertSent(MarketingContactMessage::class, fn (MarketingContactMessage $mail) =>
            $mail->hasTo('info@blucom.ir') && $mail->submission['name'] === 'Test Visitor'
        );
    }

    public function test_homepage_links_to_both_public_pages(): void
    {
        $this->get('https://blucom.ir/')
            ->assertOk()
            ->assertSee('https://blucom.ir/plans', false)
            ->assertSee('https://blucom.ir/contact', false);
    }

    public function test_homepage_uses_the_supplied_founder_and_customer_names(): void
    {
        $this->get('https://blucom.ir/')
            ->assertOk()
            ->assertSee('امیرمسعود مهرابیان')
            ->assertSee('آواپرداز کیهان کریمان')
            ->assertSee('محمدامین ادهمی')
            ->assertSee('حسابرو')
            ->assertSee('مبیت')
            ->assertSee('دیده‌بان نت')
            ->assertSee('هادر')
            ->assertSee('حالا برای هر شماره می‌دانیم تماس باید به چه کسی برسد')
            ->assertSee('محمد ذکایی')
            ->assertSee('محمدامین امیری فر')
            ->assertSee('مسعود سلطانی')
            ->assertDontSee('متن پیشنهادی · در انتظار تأیید');
    }

    public function test_homepage_handles_an_unattributed_testimonial(): void
    {
        config()->set('marketing.testimonials', [
            ['quote' => 'متن پیشنهادی', 'draft' => false],
        ]);

        $this->get('https://blucom.ir/')
            ->assertOk()
            ->assertSee('متن پیشنهادی · در انتظار تأیید');
    }
}

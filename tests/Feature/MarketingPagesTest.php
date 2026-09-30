<?php

namespace Tests\Feature;

use Tests\TestCase;

class MarketingPagesTest extends TestCase
{
    public function test_guests_can_read_the_plans_page_from_both_public_hosts(): void
    {
        foreach (['hub.blucom.local', 'admin.blucom.local'] as $host) {
            $this->get('http://'.$host.'/plans')
                ->assertOk()
                ->assertSee('هزینهٔ یک‌بارهٔ راه‌اندازی')
                ->assertSee('۸٬۹۰۰٬۰۰۰')
                ->assertSee('۱۶٬۹۰۰٬۰۰۰')
                ->assertSee('میزبانی و نگهداری');
        }
    }

    public function test_contact_page_shows_the_provided_contact_details(): void
    {
        $this->get('http://hub.blucom.local/contact')
            ->assertOk()
            ->assertSee('tel:+982191093464', false)
            ->assertSee('mailto:info@blucom.ir', false)
            ->assertSee('کرمان، میدان قرنی، ساختمان پدر، واحد ۳۰۲');
    }

    public function test_homepage_links_to_both_public_pages(): void
    {
        $this->get('http://hub.blucom.local/')
            ->assertOk()
            ->assertSee('http://hub.blucom.local/plans', false)
            ->assertSee('http://hub.blucom.local/contact', false);
    }

    public function test_homepage_uses_the_supplied_founder_and_customer_names(): void
    {
        $this->get('http://hub.blucom.local/')
            ->assertOk()
            ->assertSee('امیرمسعود مهرابیان')
            ->assertSee('آواپرداز کیهان کریمان')
            ->assertSee('محمدامین ادهمی')
            ->assertSee('حسابرو')
            ->assertSee('مبیت')
            ->assertSee('دیده‌بان نت')
            ->assertSee('هادر')
            ->assertSee('حالا برای هر شماره می‌دانیم تماس باید به چه کسی برسد');
    }
}

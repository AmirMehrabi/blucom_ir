<?php

namespace Tests\Feature;

use Tests\TestCase;

class LandingPageTest extends TestCase
{
    public function test_landing_page_renders_with_37signals_style_content(): void
    {
        $response = $this->get('http://hub.blucom.local/');

        $response->assertOk()
            ->assertSee('بلوکام')
            ->assertSee('تلفن کاری‌تان را')
            ->assertSee('headline-mark', false)
            ->assertSee('نگه دارید')
            ->assertSee('چه چیزی نمی‌سازیم')
            ->assertSee('سه قدم، تمام')
            ->assertSee('خودتان امتحان کنید')
            ->assertSee('نه تماس فروش داریم')
            ->assertSee('مسیر تماس')
            ->assertSee('blucom-hero.png')
            ->assertSee('href="/login"', false)
            ->assertSee('#how');
    }

    public function test_landing_page_has_no_form_that_can_get_submit(): void
    {
        $html = $this->get('http://hub.blucom.local/')->assertOk()->getContent();

        $this->assertStringNotContainsString('<form', $html, 'Landing page should not contain forms.');
    }

    public function test_landing_page_is_readable_rtl_document(): void
    {
        $html = $this->get('http://hub.blucom.local/')->assertOk()->getContent();

        $this->assertStringContainsString('lang="fa"', $html);
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('<main id="main">', $html);
    }
}

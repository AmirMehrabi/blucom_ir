<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('https://blucom.ir/');

        $response->assertOk()
            ->assertSee('تماس‌های کاری')
            ->assertSee('تماس با ما')
            ->assertSee('https://blucom.ir/contact', false)
            ->assertDontSee('tel:+982191093464', false)
            ->assertDontSee('mailto:info@blucom.ir', false)
            ->assertDontSee('کرمان، میدان قرنی، ساختمان پدر، واحد ۳۰۲');
    }
}

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
            ->assertSee('tel:+982191093464', false)
            ->assertSee('mailto:info@blucom.ir', false)
            ->assertSee('کرمان، میدان قرنی، ساختمان پدر، واحد ۳۰۲');
    }
}

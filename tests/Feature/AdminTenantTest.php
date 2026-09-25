<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_portal_is_disabled_and_admin_can_open_gateway_management(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->actingAs($admin)->get('/portal')->assertNotFound();
        $this->actingAs($admin)->get('/admin/tenants')->assertNotFound();
        $this->actingAs($admin)->get('/sip-gateways')->assertOk();
    }

    public function test_customer_cannot_open_admin_configuration(): void
    {
        $customer = User::factory()->create(['user_type' => UserType::Operator]);

        foreach (['/admin', '/admin/sip-numbers', '/sip-extensions', '/sip-gateways', '/inbound-routes', '/outbound-routes'] as $path) {
            $this->actingAs($customer)->get($path)->assertForbidden();
        }

        $this->actingAs($customer)->get('/auth/me')->assertOk();
    }

    public function test_dashboard_shows_configured_counts_without_fake_health(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('داخلی‌های فعال')
            ->assertSee('شماره‌های فعال')
            ->assertDontSee('Registered');
    }

    public function test_all_admin_configuration_pages_render(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        foreach (['/admin/sip-numbers', '/sip-extensions', '/inbound-routes', '/outbound-routes'] as $path) {
            $this->actingAs($admin)->get($path)->assertOk();
        }
    }
}

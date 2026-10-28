<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\User;
use App\Services\CustomerAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerImpersonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_handoff_once_to_the_selected_customer_on_the_customer_domain(): void
    {
        $customer = app(CustomerAccountService::class)->createOwner('Owner', '+989120001221', 'Business');
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $this->actingAs($admin, 'web');
        $this->get('https://admin.blucom.ir/admin/customers')->assertOk()->assertSee('ورود به پنل مشتری');
        $response = $this->post('https://admin.blucom.ir/admin/customers/'.$customer->id.'/impersonate');
        $url = $response->headers->get('Location');
        $this->assertStringStartsWith('https://my.blucom.ir/auth/impersonate/', $url);
        $this->assertDatabaseCount('customer_impersonation_tokens', 1);
        $this->get($url)->assertRedirect($customer->homePath())->assertSessionHas('impersonator_id', $admin->id);
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertAuthenticatedAs($admin, 'web');
        $this->assertDatabaseCount('customer_impersonation_tokens', 0);
        $this->get($url)->assertForbidden();
        $this->post('https://my.blucom.ir/auth/logout')->assertRedirect();
        $this->assertGuest('customer');
        $this->assertAuthenticatedAs($admin, 'web');
    }

    public function test_only_admins_can_issue_handoffs_and_disabled_customers_are_blocked(): void
    {
        $customer = app(CustomerAccountService::class)->createOwner('Owner', '+989120001221', 'Business');
        $path = 'https://admin.blucom.ir/admin/customers/'.$customer->id.'/impersonate';
        $this->post($path)->assertRedirect('https://admin.blucom.ir/login');
        $this->actingAs(User::factory()->create(['user_type' => UserType::Operator]), 'web')->post($path)->assertForbidden();
        $this->actingAs($customer, 'customer')->post('https://my.blucom.ir/admin/customers/'.$customer->id.'/impersonate')->assertNotFound();
        $this->actingAs(User::factory()->create(['user_type' => UserType::Admin]), 'web');
        $customer->update(['disabled_at' => now()]);
        $this->post($path)->assertForbidden();
        $customer->update(['disabled_at' => null]);
        $customer->tenant->update(['status' => 'disabled']);
        $this->post($path)->assertForbidden();
        $this->assertDatabaseCount('customer_impersonation_tokens', 0);
    }

    public function test_expired_handoffs_and_revoked_admin_access_are_rejected(): void
    {
        $customer = app(CustomerAccountService::class)->createOwner('Owner', '+989120001221', 'Business');
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $this->actingAs($admin, 'web');
        $path = 'https://admin.blucom.ir/admin/customers/'.$customer->id.'/impersonate';
        $url = $this->post($path)->headers->get('Location');
        $this->travel(61)->seconds();
        $this->get($url)->assertForbidden();
        $url = $this->post($path)->headers->get('Location');
        $admin->update(['disabled_at' => now()]);
        $this->get($url)->assertForbidden();
        $this->get('https://my.blucom.ir/auth/impersonate/invalid')->assertForbidden();
        $this->assertGuest('customer');
    }
}

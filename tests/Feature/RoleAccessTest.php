<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\SipNumber;
use App\Models\User;
use App\Services\BlucomOwner;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_operator_with_limited_access_and_change_it(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->actingAs($admin)->post('/users', [
            'name' => 'Sara',
            'mobile' => '09123456789',
            'role' => 'operator',
            'permissions' => [Permissions::LINES_VIEW],
        ])->assertRedirect('/users');

        $operator = User::query()->where('mobile', '+989123456789')->firstOrFail();
        $this->assertSame(UserType::Operator, $operator->user_type);
        $this->assertNotNull($operator->tenant_id);
        $this->assertTrue($operator->hasPermission(Permissions::LINES_VIEW));
        $this->assertFalse($operator->hasPermission(Permissions::PROVIDERS_MANAGE));

        $this->actingAs($operator)->get('/setup/lines')->assertOk()
            ->assertSee('خط‌ها')
            ->assertDontSee('href="'.route('users.index').'"', false)
            ->assertDontSee('href="'.route('customer.setup.provider').'"', false);
        $this->actingAs($operator)->get('/dashboard')->assertForbidden();
        $this->actingAs($operator)->post('/setup/providers', [
            'display_name' => 'Blocked', 'provider_name' => 'Carrier',
            'connection_method' => 'ip', 'host' => 'sip.example.test',
        ])->assertForbidden();
        $this->actingAs($operator)->get('/users')->assertForbidden();

        $this->actingAs($admin)->put('/users/'.$operator->id, [
            'name' => 'Sara',
            'role' => 'operator',
            'enabled' => '1',
            'permissions' => [Permissions::PROVIDERS_MANAGE],
        ])->assertRedirect('/users');

        $this->assertTrue($operator->fresh()->hasPermission(Permissions::PROVIDERS_MANAGE));
        $this->assertTrue($operator->fresh()->hasPermission(Permissions::LINES_VIEW));
        $this->actingAs($operator->fresh())->get('/setup/provider')->assertOk();
    }

    public function test_operator_cannot_grant_itself_system_permissions(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $operator = User::factory()->create(['user_type' => UserType::Operator]);

        $this->actingAs($operator)->put('/users/'.$operator->id, [
            'name' => 'Operator', 'role' => 'admin', 'enabled' => '1',
        ])->assertForbidden();
        $this->actingAs($admin)->put('/users/'.$operator->id, [
            'name' => 'Operator', 'role' => 'operator', 'enabled' => '1',
            'permissions' => ['users.manage'],
        ])->assertSessionHasErrors('permissions.0');
        $this->assertSame(UserType::Operator, $operator->fresh()->user_type);
    }

    public function test_last_admin_and_disabled_operator_are_protected(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $operator = User::factory()->create(['user_type' => UserType::Operator]);
        $operator->permissions()->create(['permission' => Permissions::LINES_VIEW]);

        $this->actingAs($admin)->put('/users/'.$admin->id, [
            'name' => 'Admin', 'role' => 'operator', 'enabled' => '1',
        ])->assertSessionHasErrors('role');

        $this->actingAs($admin)->put('/users/'.$operator->id, [
            'name' => 'Operator', 'role' => 'operator', 'enabled' => '0',
            'permissions' => [Permissions::LINES_VIEW],
        ])->assertRedirect('/users');
        $this->actingAs($operator->fresh())->get('/setup/lines')->assertForbidden();
    }

    public function test_admin_sees_user_management_and_can_use_the_wizard(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->actingAs($admin)->get('/users')->assertOk()->assertSee('کاربران و دسترسی‌ها');
        $this->actingAs($admin)->get('/setup/provider')->assertOk()->assertSee('مراحل راه‌اندازی');
        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertSee('دروازه‌های SIP');
    }

    public function test_dashboard_only_operator_does_not_see_line_details_or_links(): void
    {
        $tenant = app(BlucomOwner::class)->get();
        $number = SipNumber::factory()->for($tenant)->create([
            'number' => '982191093499',
            'normalized_number' => '+982191093499',
        ]);
        $operator = User::factory()->create([
            'user_type' => UserType::Operator,
            'tenant_id' => $tenant->id,
        ]);
        $operator->permissions()->create(['permission' => Permissions::DASHBOARD_VIEW]);

        $this->actingAs($operator)->get('/dashboard')->assertOk()
            ->assertDontSee($number->normalized_number)
            ->assertDontSee('href="'.route('customer.setup.lines').'"', false)
            ->assertDontSee('href="'.route('users.index').'"', false);
        $this->actingAs($operator)->get('/setup/lines')->assertForbidden();
    }
}

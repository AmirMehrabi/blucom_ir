<?php

namespace Tests\Feature;

use App\Contracts\OtpProvider;
use App\Enums\UserType;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateCustomerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_customer_with_own_active_tenant_without_preverifying_mobile(): void
    {
        $this->artisan('customer:create', [
            'mobile' => '09123456789',
            '--name' => 'Ali',
            '--business' => 'Ali Company',
        ])->assertExitCode(0);

        $user = User::query()->where('mobile', '+989123456789')->firstOrFail();
        $tenant = Tenant::query()->findOrFail($user->tenant_id);

        $this->assertSame(UserType::Operator, $user->user_type);
        $this->assertSame('Ali', $user->name);
        $this->assertNull($user->mobile_verified_at);
        $this->assertSame('Blucom', $tenant->name);
        $this->assertNull($tenant->owner_user_id);
        $this->assertSame('active', $tenant->status);
    }

    public function test_refuses_existing_mobile_and_does_not_create_tenant(): void
    {
        User::factory()->create(['mobile' => '+989123456789', 'user_type' => UserType::Admin]);

        $this->artisan('customer:create', ['mobile' => '09123456789'])->assertExitCode(1);

        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, Tenant::query()->count());
    }

    public function test_created_customer_can_request_and_verify_otp_on_customer_host(): void
    {
        $delivery = new class implements OtpProvider
        {
            public ?string $code = null;

            public function send(string $mobile, string $code): void
            {
                $this->code = $code;
            }
        };
        $this->app->instance(OtpProvider::class, $delivery);

        $this->artisan('customer:create', ['mobile' => '09123456789'])->assertExitCode(0);

        $challenge = $this->postJson('http://hub.blucom.local/auth/otp/request', [
            'mobile' => '09123456789',
        ])->assertOk()->json('challenge_id');

        $this->postJson('http://hub.blucom.local/auth/otp/verify', [
            'mobile' => '09123456789',
            'code' => $delivery->code,
            'challenge_id' => $challenge,
        ])->assertOk()->assertJsonPath('redirect', '/dashboard');

        $user = User::query()->where('mobile', '+989123456789')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->mobile_verified_at);
    }

    public function test_operator_command_creates_user_with_default_permissions(): void
    {
        $this->artisan('operator:create', [
            'mobile' => '09120000001',
            '--name' => 'Operator',
        ])->assertExitCode(0);

        $user = User::query()->where('mobile', '+989120000001')->firstOrFail();
        $this->assertSame(UserType::Operator, $user->user_type);
        $this->assertTrue($user->hasPermission(Permissions::PHONES_MANAGE));
    }
}

<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\User;
use App\Services\RateLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AdminUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_admin_user_with_normalized_mobile(): void
    {
        $this->artisan('admin:user', [
            'action' => 'create',
            'identifier' => '09111111111',
            '--name' => 'Boss',
        ])->assertExitCode(0);

        $user = User::query()->where('mobile', '+989111111111')->firstOrFail();

        $this->assertSame(UserType::Admin, $user->user_type);
        $this->assertSame('Boss', $user->name);
        $this->assertNotNull($user->mobile_verified_at);
        $this->assertNull($user->disabled_at);
    }

    public function test_lists_and_promotes_users(): void
    {
        $customer = User::factory()->create([
            'user_type' => UserType::Operator,
            'mobile' => '+98912345678',
            'name' => 'Cust',
        ]);

        $this->artisan('admin:user', ['action' => 'list'])->assertExitCode(0);

        $this->artisan('admin:user', [
            'action' => 'promote',
            'identifier' => (string) $customer->id,
        ])->assertExitCode(0);

        $this->assertSame(UserType::Admin, $customer->fresh()->user_type);
    }

    public function test_disables_and_enables_admin(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::Admin,
            'mobile' => '+98912345679',
        ]);
        $other = User::factory()->create([
            'user_type' => UserType::Admin,
            'mobile' => '+98912345670',
        ]);

        $this->artisan('admin:user', [
            'action' => 'disable',
            'identifier' => (string) $admin->id,
        ])->assertExitCode(0);

        $this->assertNotNull($admin->fresh()->disabled_at);
        $this->assertNull($other->fresh()->disabled_at);

        $this->artisan('admin:user', [
            'action' => 'enable',
            'identifier' => (string) $admin->id,
        ])->assertExitCode(0);

        $this->assertNull($admin->fresh()->disabled_at);
    }

    public function test_refuses_to_demote_last_admin(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::Admin,
            'mobile' => '+98912345671',
        ]);

        $this->artisan('admin:user', [
            'action' => 'demote',
            'identifier' => (string) $admin->id,
        ])->assertExitCode(1);

        $this->assertSame(UserType::Admin, $admin->fresh()->user_type);
    }

    public function test_rate_limit_status_disable_enable(): void
    {
        config(['rate_limiting.enabled' => true]);
        $service = app(RateLimitService::class);
        $service->enable();

        $this->assertTrue($service->isEnabled());

        $this->artisan('rate-limit', ['action' => 'disable', '--minutes' => 30])
            ->assertExitCode(0);

        $this->assertFalse($service->isEnabled());
        $this->assertTrue($service->isTemporarilyDisabled());

        $this->artisan('rate-limit', ['action' => 'status'])->assertExitCode(0);

        $this->artisan('rate-limit', ['action' => 'enable'])->assertExitCode(0);
        $this->assertTrue($service->isEnabled());
    }

    public function test_rate_limit_disable_without_minutes_is_forever_until_enable(): void
    {
        config(['rate_limiting.enabled' => true]);
        $service = app(RateLimitService::class);
        $service->enable();

        $this->artisan('rate-limit', ['action' => 'disable'])->assertExitCode(0);
        $this->assertFalse($service->isEnabled());

        $this->artisan('rate-limit', ['action' => 'enable'])->assertExitCode(0);
        $this->assertTrue($service->isEnabled());
        $this->assertNull(Cache::get(RateLimitService::CACHE_KEY));
    }

    public function test_env_hard_disable_wins_over_enable(): void
    {
        config(['rate_limiting.enabled' => false]);
        $service = app(RateLimitService::class);

        $service->enable();

        $this->assertFalse($service->isEnabled());
        $this->assertFalse($service->status()['env']);
    }

    public function test_otp_request_is_not_throttled_while_temporarily_disabled(): void
    {
        $service = app(RateLimitService::class);
        $service->disable(30);

        $token = 'test-csrf';
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-CSRF-TOKEN' => $token,
        ];

        // Session-based CSRF: use withSession via postJson pipeline with encrypted token is heavy;
        // assert middleware path by hitting the named limiter directly through HTTP with CSRF disabled in testing via token.
        $this->withSession(['_token' => $token]);

        $statuses = [];
        for ($i = 0; $i < 8; $i++) {
            $response = $this->postJson('/auth/otp/request', [
                'mobile' => '0912000000'.($i % 10),
            ], $headers + ['X-CSRF-TOKEN' => $token]);
            $statuses[] = $response->status();
        }

        // With limits on, 6th+ in a minute would 429 for same IP; with disable, no 429 from throttle.
        $this->assertNotContains(429, $statuses, 'Expected no 429 while rate limiting is disabled.');
    }
}

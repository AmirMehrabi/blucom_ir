<?php

namespace Tests\Feature;

use App\Contracts\OtpProvider;
use App\Enums\CustomerRole;
use App\Models\Customer;
use App\Models\CustomerRegistrationChallenge;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CustomerAccountService;
use App\Services\CustomerRegistrationService;
use App\Support\CustomerMobile;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://my.blucom.ir';

    private OtpProvider $delivery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->delivery = new class implements OtpProvider
        {
            public string $code = '';

            public bool $fail = false;

            public function send(string $mobile, string $code): void
            {
                if ($this->fail) {
                    throw new \RuntimeException('Sensitive provider failure');
                }
                $this->code = $code;
            }
        };
        $this->app->instance(OtpProvider::class, $this->delivery);
    }

    private function requestCode(array $overrides = []): string
    {
        return $this->postJson(self::HOST.'/auth/register/request', array_merge([
            'name' => 'Customer Owner', 'business' => 'Customer Business', 'mobile' => '09123456789',
        ], $overrides))->assertOk()->json('challenge_id');
    }

    private function verifyCode(string $id, ?string $code = null, array $overrides = [])
    {
        return $this->postJson(self::HOST.'/auth/register/verify', array_merge([
            'mobile' => '+989123456789', 'code' => $code ?? $this->delivery->code, 'challenge_id' => $id,
        ], $overrides));
    }

    public function test_registration_page_has_fields_login_link_and_valid_javascript(): void
    {
        $html = $this->get(self::HOST.'/register')->assertOk()
            ->assertSee('ثبت‌نام مشتریان بلوکام')->assertSee('name="business"', false)
            ->assertSee(self::HOST.'/login', false)->getContent();
        preg_match_all('/<script(?![^>]*src)[^>]*>(.*?)<\/script>/s', $html, $scripts);
        $this->assertNotEmpty($scripts[1]);
        if (trim((string) shell_exec('command -v node')) === '') {
            return;
        }
        foreach ($scripts[1] as $script) {
            $file = tempnam(sys_get_temp_dir(), 'registration-js-');
            file_put_contents($file, $script);
            exec('node --check '.escapeshellarg($file).' 2>&1', $output, $exitCode);
            unlink($file);
            $this->assertSame(0, $exitCode, implode("\n", $output));
        }
    }

    public function test_verification_creates_one_owned_tenant_and_only_a_customer_session(): void
    {
        User::factory()->create(['mobile' => '+989123456789']);
        $id = $this->requestCode(['tenant_id' => 99, 'role' => 'admin', 'permissions' => ['infrastructure']]);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('tenants', 1);
        $challenge = CustomerRegistrationChallenge::query()->findOrFail($id);
        $this->assertNotSame($this->delivery->code, $challenge->code_hash);
        $this->assertArrayNotHasKey('code_hash', $challenge->toArray());

        $this->verifyCode($id, overrides: ['name' => 'Forged Name', 'business' => 'Forged Business', 'tenant_id' => 99, 'role' => 'admin'])
            ->assertOk()->assertJsonPath('redirect', '/setup/lines');
        $customer = Customer::query()->sole();
        $tenant = Tenant::query()->whereNull('system_key')->sole();
        $this->assertSame('Customer Owner', $customer->name);
        $this->assertSame('Customer Business', $tenant->name);
        $this->assertSame(CustomerRole::Owner, $customer->role);
        $this->assertSame($tenant->id, $customer->tenant_id);
        $this->assertSame($customer->id, $tenant->owner_customer_id);
        $this->assertNotNull($customer->mobile_verified_at);
        $this->assertEqualsCanonicalizing(Permissions::CUSTOMER_OWNER_DEFAULTS, $customer->permissions->pluck('permission')->all());
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertGuest('web');
        $this->get(self::HOST.'/setup/lines')->assertOk();
        $this->get('https://admin.blucom.ir/dashboard')->assertRedirect('https://admin.blucom.ir/login');
        $this->verifyCode($id)->assertUnprocessable();
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('tenants', 2);
    }

    public function test_challenge_is_bound_to_session_and_mobile_and_separate_from_login(): void
    {
        $id = $this->requestCode();
        $code = $this->delivery->code;
        $this->verifyCode($id, $code, ['mobile' => '09120000000'])->assertUnprocessable();
        $this->postJson(self::HOST.'/auth/otp/verify', ['challenge_id' => $id, 'code' => $code, 'mobile' => '09123456789'])->assertUnprocessable();
        session()->forget('customer_registration_challenge_id');
        $this->verifyCode($id, $code)->assertUnprocessable();
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('tenants', 1);
    }

    public function test_invalid_codes_exhaust_attempts_and_expired_codes_do_not_create_accounts(): void
    {
        $id = $this->requestCode();
        for ($i = 0; $i < config('auth.otp.max_attempts'); $i++) {
            $this->verifyCode($id, '000000')->assertUnprocessable();
        }
        $this->assertSame(config('auth.otp.max_attempts'), CustomerRegistrationChallenge::query()->findOrFail($id)->attempts);
        $this->verifyCode($id)->assertUnprocessable();
        $this->travel(config('auth.otp.resend_cooldown_seconds') + 1)->seconds();
        $id = $this->requestCode();
        $this->travel(config('auth.otp.expires_seconds') + 1)->seconds();
        $this->verifyCode($id)->assertUnprocessable();
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_resend_invalidates_previous_code_and_cannot_bypass_cooldown_by_number_format(): void
    {
        $old = $this->requestCode();
        $oldCode = $this->delivery->code;
        session()->forget('customer_registration_challenge_id');
        $this->postJson(self::HOST.'/auth/register/request', [
            'name' => 'Owner', 'business' => 'Business', 'mobile' => '+989123456789',
        ])->assertStatus(429);
        $this->travel(config('auth.otp.resend_cooldown_seconds') + 1)->seconds();
        $new = $this->requestCode();
        $this->assertNotSame($old, $new);
        $this->assertDatabaseMissing('customer_registration_challenges', ['id' => $old]);
        $this->verifyCode($old, $oldCode)->assertUnprocessable();
        $this->verifyCode($new)->assertOk();
    }

    public function test_existing_disabled_or_staff_accounts_cannot_register_again(): void
    {
        $customer = app(CustomerAccountService::class)->createOwner('Owner', '+989123456789', 'Existing Business');
        $staff = app(CustomerAccountService::class)->createStaff($customer->tenant, 'Staff', '+989120000001', Permissions::CUSTOMER_STAFF_DEFAULTS);
        $customer->update(['disabled_at' => now()]);
        foreach ([$customer->mobile, $staff->mobile] as $mobile) {
            $this->postJson(self::HOST.'/auth/register/request', ['name' => 'Owner', 'business' => 'New Business', 'mobile' => $mobile])
                ->assertUnprocessable()->assertJsonValidationErrors('mobile');
        }
        $this->assertDatabaseCount('customers', 2);
        $this->assertDatabaseCount('tenants', 2);
        $this->assertDatabaseCount('customer_registration_challenges', 0);
    }

    public function test_admin_creation_between_request_and_verify_does_not_create_an_extra_tenant(): void
    {
        $id = $this->requestCode();
        app(CustomerAccountService::class)->createOwner('Admin-created', '+989123456789', 'Existing Business');
        $this->verifyCode($id)->assertUnprocessable()->assertJsonValidationErrors('mobile');
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('tenants', 2);
        $this->assertGuest('customer');
    }

    public function test_registration_rolls_back_tenant_if_customer_creation_fails(): void
    {
        $id = $this->requestCode();
        Customer::creating(fn () => throw new \RuntimeException('Synthetic failure'));
        try {
            app(CustomerRegistrationService::class)->register($id, '+989123456789', $this->delivery->code);
            $this->fail('Registration should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic failure', $exception->getMessage());
        } finally {
            Customer::flushEventListeners();
        }
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('tenants', 1);
        $this->assertNull(CustomerRegistrationChallenge::query()->findOrFail($id)->verified_at);
    }

    public function test_delivery_failure_leaves_no_account_and_logs_no_provider_message_or_code(): void
    {
        $this->delivery->fail = true;
        Log::shouldReceive('error')->once()->with('Customer registration OTP delivery failed', ['exception_class' => \RuntimeException::class]);
        $this->postJson(self::HOST.'/auth/register/request', ['name' => 'Owner', 'business' => 'Business', 'mobile' => '09123456789'])
            ->assertStatus(502)->assertDontSee('Sensitive provider failure');
        $this->assertDatabaseCount('customer_registration_challenges', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('tenants', 1);
    }

    public function test_public_registration_throttles_remain_active_when_internal_limits_are_disabled(): void
    {
        config(['rate_limiting.enabled' => false]);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson(self::HOST.'/auth/register/request', [])->assertUnprocessable();
        }
        $this->postJson(self::HOST.'/auth/register/request', [])->assertStatus(429);
    }

    public function test_mobile_formats_share_one_canonical_identity(): void
    {
        foreach (['09123456789', '+989123456789', '989123456789', '00989123456789', '۰۹۱۲۳۴۵۶۷۸۹', '٠٩١٢٣٤٥٦٧٨٩', '0912 345 6789'] as $mobile) {
            $this->assertSame('+989123456789', CustomerMobile::normalize($mobile));
        }
        $this->expectException(ValidationException::class);
        CustomerMobile::normalize('not a mobile');
    }
}

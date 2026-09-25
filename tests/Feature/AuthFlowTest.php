<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Contracts\OtpProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_on_customer_host_sees_marketing_home(): void
    {
        $this->get('http://hub.blucom.local/')
            ->assertOk()
            ->assertSee('بلوکام');
    }

    public function test_guest_on_admin_host_is_redirected_to_login(): void
    {
        $this->get('http://admin.blucom.local/')
            ->assertRedirect('http://admin.blucom.local/login');
    }

    public function test_admin_user_on_admin_host_root_goes_to_dashboard(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::Admin,
            'mobile' => '+989123450001',
        ]);

        $this->actingAs($admin)
            ->get('http://admin.blucom.local/')
            ->assertRedirect('http://admin.blucom.local/admin');
    }

    public function test_customer_on_admin_host_root_goes_to_login_not_homepage(): void
    {
        $customer = User::factory()->create([
            'user_type' => UserType::Customer,
            'mobile' => '+989123450002',
        ]);

        $this->actingAs($customer)
            ->get('http://admin.blucom.local/')
            ->assertRedirect('http://admin.blucom.local/login');
    }

    public function test_authenticated_admin_visiting_login_is_sent_to_dashboard(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::Admin,
            'mobile' => '+989123450003',
        ]);

        $this->actingAs($admin)
            ->get('http://admin.blucom.local/login')
            ->assertRedirect('http://admin.blucom.local/admin');
    }

    public function test_authenticated_customer_is_sent_to_setup(): void
    {
        $customer = User::factory()->create([
            'user_type' => UserType::Customer,
            'mobile' => '+989123450004',
        ]);

        $this->actingAs($customer)
            ->get('http://hub.blucom.local/login')
            ->assertRedirect('http://hub.blucom.local/setup/provider');
    }

    public function test_login_page_renders_otp_inputs_and_branding(): void
    {
        $response = $this->get('http://hub.blucom.local/login');

        $response->assertOk()
            ->assertSee('otp-input', false)
            ->assertSee('ارسال کد تأیید')
            ->assertSee('پنل مشتری');

        $this->assertInlineScriptParses($response->getContent());
    }

    public function test_admin_login_page_shows_admin_branding(): void
    {
        $response = $this->get('http://admin.blucom.local/login');

        $response->assertOk()
            ->assertSee('پنل مدیریت')
            ->assertSee('ورود مدیر بلوکام');

        $this->assertInlineScriptParses($response->getContent());
    }

    public function test_customer_otp_request_returns_challenge_for_existing_customer(): void
    {
        $customer = User::factory()->create([
            'user_type' => UserType::Customer,
            'mobile' => '+989123456789',
        ]);
        $this->app->instance(OtpProvider::class, new class implements OtpProvider {
            public function send(string $mobile, string $code): void {}
        });

        $response = $this->postJson('http://hub.blucom.local/auth/otp/request', [
            'mobile' => '09123456789',
        ]);

        $response->assertOk()->assertJsonStructure(['challenge_id', 'cooldown_seconds', 'expires_seconds']);
        $this->assertDatabaseHas('otp_challenges', [
            'id' => $response->json('challenge_id'),
            'user_id' => $customer->id,
            'mobile' => $customer->mobile,
        ]);
    }

    public function test_wrong_portal_returns_error_instead_of_success_without_challenge(): void
    {
        User::factory()->create([
            'user_type' => UserType::Admin,
            'mobile' => '+989123456789',
        ]);

        $this->postJson('http://hub.blucom.local/auth/otp/request', [
            'mobile' => '09123456789',
        ])->assertUnprocessable()->assertJsonValidationErrors('mobile');

        $this->postJson('http://admin.blucom.local/auth/otp/request', [
            'mobile' => '09120000000',
        ])->assertUnprocessable()->assertJsonValidationErrors('mobile');

        $this->assertDatabaseCount('otp_challenges', 0);
    }

    private function assertInlineScriptParses(string $html): void
    {
        preg_match_all('/<script(?![^>]*src)[^>]*>(.*?)<\/script>/s', $html, $matches);

        $this->assertNotEmpty($matches[1], 'Expected inline scripts on login page.');

        foreach ($matches[1] as $i => $script) {
            $this->assertStringNotContainsString(
                '&quot;',
                $script,
                "Inline script #{$i} contains HTML-escaped quotes and will fail to parse."
            );

            if (! str_contains($script, 'otp-form')) {
                continue;
            }

            if (trim((string) shell_exec('command -v node')) === '') {
                continue;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'otpjs');
            file_put_contents($tmp, $script);
            exec('node --check '.escapeshellarg($tmp).' 2>&1', $output, $code);
            unlink($tmp);

            $this->assertSame(0, $code, "OTP script failed to parse:\n".implode("\n", $output));
        }
    }

    public function test_guest_cannot_open_admin_dashboard(): void
    {
        $this->get('http://admin.blucom.local/admin')
            ->assertRedirect('http://admin.blucom.local/login');
    }
}

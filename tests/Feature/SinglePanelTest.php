<?php

namespace Tests\Feature;

use App\Contracts\OtpProvider;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class SinglePanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_customer_and_admin_domains_keep_their_own_pages_with_cached_routes(): void
    {
        $original = app('router')->getRoutes();
        $compiled = $original->compile();
        foreach ([false, true] as $cached) {
            $cached ? app('router')->setCompiledRoutes($compiled) : app('router')->setRoutes($original);
            $this->get('https://blucom.ir/')->assertOk()->assertSee('https://my.blucom.ir/login', false)
                ->assertSee('https://my.blucom.ir/register', false)->assertDontSee('https://admin.blucom.ir/login', false);
            $this->get('https://blucom.ir/plans')->assertOk();
            $this->get('https://blucom.ir/contact')->assertOk();
            $this->get('https://blucom.ir/login')->assertRedirect('https://my.blucom.ir/login');
            $this->get('https://blucom.ir/register')->assertRedirect('https://my.blucom.ir/register');
            $this->get('https://admin.blucom.ir/')->assertRedirect('https://admin.blucom.ir/login');
            $this->get('https://admin.blucom.ir/login')->assertOk()->assertSee('ورود به بلوکام')->assertDontSee('ثبت‌نام مشتریان');
            $this->get('https://my.blucom.ir/')->assertRedirect('https://my.blucom.ir/login');
            $this->get('https://my.blucom.ir/login')->assertOk()->assertSee('ورود مشتریان بلوکام')->assertSee('https://my.blucom.ir/register', false);
            $this->get('https://my.blucom.ir/register')->assertOk();
            $this->get('https://admin.blucom.ir/register')->assertNotFound();
            $this->get('https://my.blucom.ir/admin/customers')->assertNotFound();
            foreach (['admin', 'my'] as $portal) {
                $this->get('https://'.$portal.'.blucom.ir/plans')->assertRedirect('https://blucom.ir/plans');
                $this->get('https://'.$portal.'.blucom.ir/contact')->assertRedirect('https://blucom.ir/contact');
            }
            foreach (['blucom.ir', 'admin.blucom.ir'] as $host) {
                $this->postJson('https://'.$host.'/auth/register/request', [])->assertNotFound();
                $this->postJson('https://'.$host.'/auth/register/verify', [])->assertNotFound();
            }
            $this->postJson('https://blucom.ir/auth/otp/request', [])->assertNotFound();
            $this->postJson('https://blucom.ir/admin/customers', [])->assertStatus(405);
            $this->get('https://other.example/dashboard')->assertNotFound();
            $this->postJson('https://other.example/dashboard/preference', [])->assertNotFound();
        }
    }

    public function test_public_home_stays_public_for_authenticated_staff(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $this->actingAs($admin)->get('https://blucom.ir/')->assertOk()->assertSee('تماس‌های کاری');
        $this->get('https://admin.blucom.ir/')->assertRedirect('https://admin.blucom.ir/dashboard');
        $admin->update(['disabled_at' => now()]);
        $this->get('https://blucom.ir/')->assertOk();
    }

    public function test_old_panel_bookmarks_redirect_but_mutations_are_not_forwarded(): void
    {
        $this->get('https://blucom.ir/admin/customers?page=2')->assertRedirect('https://admin.blucom.ir/admin/customers?page=2');
        $this->get('https://blucom.ir/dashboard')->assertRedirect('https://admin.blucom.ir/dashboard');
        $this->get('https://blucom.ir/sip-gateways')->assertRedirect('https://admin.blucom.ir/sip-gateways');
        $this->postJson('https://blucom.ir/dashboard/preference', [])->assertNotFound();
        $this->get('https://admin.blucom.ir/admin/customers')->assertRedirect('https://admin.blucom.ir/login');
        $this->get('https://my.blucom.ir/dashboard')->assertRedirect('https://my.blucom.ir/login');
    }

    public function test_admin_login_returns_to_requested_admin_page_and_rejects_external_redirects(): void
    {
        config(['rate_limiting.enabled' => false]);
        $user = User::factory()->create(['user_type' => UserType::Admin, 'mobile' => '+989123456789']);
        $provider = new class implements OtpProvider
        {
            public string $code = '';

            public function send(string $mobile, string $code): void
            {
                $this->code = $code;
            }
        };
        $this->app->instance(OtpProvider::class, $provider);
        foreach (['https://admin.blucom.ir/admin/customers', 'https://my.blucom.ir/dashboard', '//other.example', '/\\other.example', 'https://other.example'] as $intended) {
            Auth::guard('web')->logout();
            $this->withSession(['url.intended' => $intended]);
            $id = $this->postJson('https://admin.blucom.ir/auth/otp/request', ['mobile' => $user->mobile])->assertOk()->json('challenge_id');
            $this->postJson('https://admin.blucom.ir/auth/otp/verify', ['mobile' => $user->mobile, 'challenge_id' => $id, 'code' => $provider->code])
                ->assertOk()->assertJsonPath('redirect', $intended === 'https://admin.blucom.ir/admin/customers' ? $intended : '/dashboard');
            $this->travel(config('auth.otp.resend_cooldown_seconds') + 1)->seconds();
        }
    }
}

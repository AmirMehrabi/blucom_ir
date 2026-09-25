<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SinglePanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_hosts_use_the_same_login_page(): void
    {
        foreach (['hub.blucom.local', 'admin.blucom.local'] as $host) {
            $this->get('http://'.$host.'/')->assertRedirect('http://'.$host.'/login');
            $this->get('http://'.$host.'/login')->assertOk()->assertSee('ورود به بلوکام');
        }
    }

    public function test_admin_from_either_host_uses_the_same_dashboard(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        foreach (['hub.blucom.local', 'admin.blucom.local'] as $host) {
            $this->actingAs($admin)->get('http://'.$host.'/')->assertRedirect('http://'.$host.'/dashboard');
        }
    }
}

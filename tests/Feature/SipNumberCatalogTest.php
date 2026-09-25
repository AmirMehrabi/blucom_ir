<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\SipNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SipNumberCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['user_type' => UserType::Admin]);
    }

    public function test_admin_creates_normalized_did_and_can_disable_it(): void
    {
        $this->actingAs($this->admin())->post('/admin/sip-numbers', [
            'number' => '00982191093464',
            'label' => 'Main Tehran',
            'enabled' => 1,
            'inbound_enabled' => 1,
            'outbound_enabled' => 1,
        ])->assertRedirect();

        $number = SipNumber::query()->firstOrFail();
        $this->assertSame('+982191093464', $number->normalized_number);
        $this->assertSame('Main Tehran', $number->label);
        $this->assertSame(SipNumber::STATUS_ASSIGNED, $number->status);

        $this->actingAs($this->admin())->put('/admin/sip-numbers/'.$number->id, [
            'enabled' => 0,
            'inbound_enabled' => 0,
            'outbound_enabled' => 1,
        ])->assertRedirect();

        $this->assertFalse($number->fresh()->enabled);
        $this->assertFalse($number->fresh()->inbound_enabled);
    }

    public function test_duplicate_normalized_did_is_rejected(): void
    {
        $admin = $this->admin();
        $base = ['enabled' => 1, 'inbound_enabled' => 1, 'outbound_enabled' => 1];

        $this->actingAs($admin)->post('/admin/sip-numbers', $base + ['number' => '982191093464'])->assertRedirect();
        $this->actingAs($admin)->post('/admin/sip-numbers', $base + ['number' => '+982191093464'])
            ->assertSessionHasErrors('normalized_number');

        $this->assertDatabaseCount('sip_numbers', 1);
    }

    public function test_customer_cannot_create_did(): void
    {
        $customer = User::factory()->create(['user_type' => UserType::Operator]);

        $this->actingAs($customer)->post('/admin/sip-numbers', [
            'number' => '982191093464',
            'enabled' => 1,
            'inbound_enabled' => 1,
            'outbound_enabled' => 1,
        ])->assertForbidden();
        $this->assertDatabaseCount('sip_numbers', 0);
    }
}

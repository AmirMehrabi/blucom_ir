<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\PaymentGateway;
use App\Models\PaymentGatewayVersion;
use App\Models\User;
use App\Services\Commerce\PaymentGatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\CheckoutFixtures;
use Tests\TestCase;

class PaymentGatewaySettingsTest extends TestCase
{
    use CheckoutFixtures, RefreshDatabase;

    private function fields(): array
    {
        return ['revision' => 0, 'enabled' => 1, 'amount_unit_confirmed' => 1,
            'merchant_terminal_id' => '123456', 'merchant_username' => 'synthetic-merchant', 'merchant_password' => '  synthetic <&> password  '];
    }

    public function test_admin_stores_encrypted_credentials_without_exposing_or_flashing_them(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $this->actingAs($admin)->put('/admin/settings/payment-gateways/mellat', $this->fields())->assertRedirect()->assertSessionHasNoErrors();
        $gateway = PaymentGateway::query()->firstOrFail();
        $this->assertTrue($gateway->enabled);
        $this->assertSame('  synthetic <&> password  ', $gateway->currentVersion->credentials['password']);
        $stored = DB::table('payment_gateway_versions')->first()->credentials;
        foreach (['123456', 'synthetic-merchant', 'synthetic'] as $value) {
            $this->assertStringNotContainsString($value, $stored);
        }
        $this->assertArrayNotHasKey('credentials', $gateway->currentVersion->toArray());
        $response = $this->get('/admin/settings/payment-gateways')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertDontSee('123456')->assertDontSee('synthetic-merchant')->assertDontSee('synthetic &lt;');
        $this->assertDatabaseHas('commerce_audit_events', ['actor_user_id' => $admin->id, 'event' => 'payment_gateway.updated']);
        $events = json_encode(DB::table('commerce_audit_events')->get());
        $this->assertStringNotContainsString('synthetic-merchant', $events);
        $this->assertStringNotContainsString('123456', $events);
        $this->put('/admin/settings/payment-gateways/mellat', [...$this->fields(), 'revision' => -1])
            ->assertSessionHasErrors('revision')->assertSessionMissing('_old_input.merchant_password')
            ->assertSessionMissing('_old_input.merchant_username')->assertSessionMissing('_old_input.merchant_terminal_id');
    }

    public function test_disable_and_blank_fields_preserve_credentials_and_rotation_retains_versions(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $service = app(PaymentGatewayService::class);
        $service->update($admin, 'mellat', $this->fields());
        $gateway = PaymentGateway::query()->firstOrFail();
        $original = $gateway->currentVersion;
        $service->update($admin, 'mellat', ['revision' => 1, 'enabled' => false, 'amount_unit_confirmed' => true, 'merchant_password' => '']);
        $this->assertFalse($gateway->fresh()->enabled);
        $this->assertSame($original->id, $gateway->fresh()->current_version_id);
        $service->update($admin, 'mellat', ['revision' => 2, 'enabled' => true, 'amount_unit_confirmed' => true, 'merchant_password' => 'synthetic-rotated']);
        $new = $gateway->fresh()->currentVersion;
        $this->assertNotSame($original->id, $new->id);
        $this->assertSame($original->account_key, $new->account_key);
        $this->assertSame('  synthetic <&> password  ', $original->fresh()->credentials['password']);
        $service->update($admin, 'mellat', ['revision' => 3, 'enabled' => true, 'amount_unit_confirmed' => true, 'merchant_terminal_id' => '654321']);
        $this->assertNotSame($new->account_key, $gateway->fresh()->currentVersion->account_key);
        $this->assertDatabaseCount('payment_gateway_versions', 3);
    }

    public function test_stale_settings_and_unconfirmed_amount_units_fail_closed(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $this->actingAs($admin)->put('/admin/settings/payment-gateways/mellat', [...$this->fields(), 'amount_unit_confirmed' => 0])
            ->assertSessionHasErrors('gateway');
        $this->assertFalse(PaymentGateway::query()->firstOrFail()->enabled);
        $this->put('/admin/settings/payment-gateways/mellat', $this->fields())->assertSessionHasNoErrors();
        $this->put('/admin/settings/payment-gateways/mellat', $this->fields())->assertSessionHasErrors('gateway');
        $this->assertDatabaseCount('payment_gateway_versions', 1);
        $this->put('/admin/settings/payment-gateways/unknown', $this->fields())->assertNotFound();
    }

    public function test_only_admins_on_admin_host_can_manage_payment_gateways(): void
    {
        $this->get('/admin/settings/payment-gateways')->assertRedirect('https://admin.blucom.ir/login');
        $operator = User::factory()->create(['user_type' => UserType::Operator]);
        $this->actingAs($operator)->get('/admin/settings/payment-gateways')->assertForbidden();
        $buyer = $this->buyer();
        $this->actingAs($buyer, 'customer')->get('https://my.blucom.ir/admin/settings/payment-gateways')->assertNotFound();
        $this->put('https://my.blucom.ir/admin/settings/payment-gateways/mellat', $this->fields())->assertNotFound();
    }

    public function test_credential_version_cannot_be_modified_or_deleted(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        app(PaymentGatewayService::class)->update($admin, 'mellat', $this->fields());
        $version = PaymentGatewayVersion::query()->firstOrFail();
        $this->expectException(ValidationException::class);
        $version->update(['credentials' => ['password' => 'synthetic-replacement']]);
    }

    public function test_migration_rollback_retains_stored_merchant_versions(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        app(PaymentGatewayService::class)->update($admin, 'mellat', $this->fields());
        $migration = require database_path('migrations/2026_10_07_000003_create_payment_gateway_settings.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('explicit retention plan');
        $migration->down();
    }
}

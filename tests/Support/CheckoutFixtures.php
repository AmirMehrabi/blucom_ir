<?php

namespace Tests\Support;

use App\Enums\UserType;
use App\Models\Customer;
use App\Models\SipGateway;
use App\Models\User;
use App\Services\Commerce\NumberInventoryService;
use App\Services\Commerce\NumberOfferService;
use App\Services\Commerce\PlanService;
use App\Services\CustomerAccountService;

trait CheckoutFixtures
{
    private function buyer(string $suffix = '001'): Customer
    {
        return app(CustomerAccountService::class)->createOwner('Synthetic buyer', '+989120000'.$suffix, 'Synthetic business');
    }

    private function offer(): array
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $gateway = SipGateway::factory()->create(['tenant_id' => null, 'verification_status' => 'approved', 'approved_for_outbound' => true]);
        $number = app(NumberInventoryService::class)->create($admin, [
            'number' => '+982155509876', 'provider_gateway_id' => $gateway->id, 'label' => 'Synthetic pilot number',
            'enabled' => true, 'inbound_enabled' => true, 'outbound_enabled' => true, 'destination_prefixes' => ['+989'],
        ]);
        app(NumberInventoryService::class)->review($admin, $number->id, 1, 'Synthetic review for checkout test');
        $plans = app(PlanService::class);
        $plan = $plans->create($admin, 'Synthetic monthly plan');
        $version = $plans->version($admin, $plan->id, ['extensions' => 5, 'queues' => 2, 'ivr_menus' => 2]);
        $plans->publish($admin, $version->id);
        $offer = app(NumberOfferService::class)->publish($admin, $number->id, $number->fresh()->inventory_revision, $version->id, 250000);

        return [$offer, $number->fresh(), $admin, $gateway, $version->fresh()];
    }
}

<?php

namespace Database\Factories;

use App\Models\OutboundRoute;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutboundRoute>
 */
class OutboundRouteFactory extends Factory
{
    protected $model = OutboundRoute::class;

    public function definition(): array
    {
        $tenant = Tenant::factory();

        return [
            'tenant_id' => $tenant,
            'sip_number_id' => SipNumber::factory()->for($tenant),
            'gateway_id' => SipGateway::factory(),
            'enabled' => true,
        ];
    }
}

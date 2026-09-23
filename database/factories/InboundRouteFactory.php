<?php

namespace Database\Factories;

use App\Models\InboundRoute;
use App\Models\SipExtension;
use App\Models\SipNumber;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboundRoute>
 */
class InboundRouteFactory extends Factory
{
    protected $model = InboundRoute::class;

    public function definition(): array
    {
        $tenant = Tenant::factory();

        return [
            'tenant_id' => $tenant,
            'sip_number_id' => SipNumber::factory()->for($tenant),
            'destination_type' => 'extension',
            'destination_id' => SipExtension::factory()->for($tenant),
            'enabled' => true,
        ];
    }
}

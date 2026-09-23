<?php

namespace Database\Factories;

use App\Models\SipNumber;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SipNumber>
 */
class SipNumberFactory extends Factory
{
    protected $model = SipNumber::class;

    public function definition(): array
    {
        $number = '9821'.fake()->unique()->numerify('#######');

        return [
            'tenant_id' => Tenant::factory(),
            'number' => $number,
            'normalized_number' => '+'.$number,
            'provider_gateway_id' => null,
            'status' => 'active',
            'inbound_enabled' => true,
            'outbound_enabled' => true,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\User;
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
            'requested_by_user_id' => null,
            'number' => $number,
            'normalized_number' => '+'.$number,
            'provider_gateway_id' => null,
            'status' => SipNumber::STATUS_ASSIGNED,
            'inbound_enabled' => true,
            'outbound_enabled' => true,
        ];
    }

    public function available(): static
    {
        return $this->state([
            'tenant_id' => null,
            'requested_by_user_id' => null,
            'status' => SipNumber::STATUS_AVAILABLE,
        ]);
    }

    public function pending(?User $user = null): static
    {
        return $this->state([
            'tenant_id' => null,
            'requested_by_user_id' => $user?->id ?? User::factory(),
            'status' => SipNumber::STATUS_PENDING,
        ]);
    }
}

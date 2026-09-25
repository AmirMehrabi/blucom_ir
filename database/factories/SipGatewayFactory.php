<?php

namespace Database\Factories;

use App\Models\SipGateway;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SipGateway>
 */
class SipGatewayFactory extends Factory
{
    protected $model = SipGateway::class;

    public function definition(): array
    {
        return [
            'name' => 'gw-'.fake()->unique()->numerify('####'),
            'host' => fake()->ipv4(),
            'port' => 5060,
            'transport' => 'udp',
            'username' => null,
            'password_encrypted' => null,
            'profile' => 'external',
            'context' => 'public',
            'enabled' => true,
            'register' => false,
            'approved_for_outbound' => false,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}

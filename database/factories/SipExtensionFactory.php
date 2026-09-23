<?php

namespace Database\Factories;

use App\Models\SipExtension;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SipExtension>
 */
class SipExtensionFactory extends Factory
{
    protected $model = SipExtension::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'extension' => fake()->unique()->numerify('1###'),
            'password_encrypted' => Str::random(16),
            'display_name' => fake()->name(),
            'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}

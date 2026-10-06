<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\PlanVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlanVersion> */
class PlanVersionFactory extends Factory
{
    protected $model = PlanVersion::class;

    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(), 'version' => 1, 'billing_interval' => 'monthly',
            'features' => ['extensions', 'schedules', 'ivr', 'queues'],
            'limits' => ['extensions' => 5, 'queues' => 2, 'ivr_menus' => 2], 'limit_scope' => 'tenant',
        ];
    }

    public function published(): static
    {
        return $this->state(['published_at' => now()]);
    }
}

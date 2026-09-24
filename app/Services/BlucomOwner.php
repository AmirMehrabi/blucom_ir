<?php

namespace App\Services;

use App\Models\Tenant;

class BlucomOwner
{
    public function get(): Tenant
    {
        return Tenant::query()->firstOrCreate(
            ['system_key' => 'blucom'],
            ['name' => 'Blucom', 'status' => 'active'],
        );
    }
}

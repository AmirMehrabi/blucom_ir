<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'archived', 'slug', 'marketing'])]
class Plan extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['archived' => 'boolean', 'marketing' => 'array'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PlanVersion::class);
    }
}

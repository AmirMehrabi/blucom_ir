<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NumberRecordingSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['announcement_enabled' => 'boolean'];
    }
}

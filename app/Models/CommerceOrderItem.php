<?php

namespace App\Models;

class CommerceOrderItem extends CommerceRecord
{
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'amount' => 'integer'];
    }
}

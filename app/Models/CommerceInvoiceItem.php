<?php

namespace App\Models;

class CommerceInvoiceItem extends CommerceRecord
{
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'amount' => 'integer'];
    }
}

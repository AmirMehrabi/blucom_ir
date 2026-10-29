<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletEntry extends CommerceRecord
{
    protected function casts(): array
    {
        return ['amount' => 'integer', 'balance_after' => 'integer'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CommerceInvoice::class, 'commerce_invoice_id');
    }
}

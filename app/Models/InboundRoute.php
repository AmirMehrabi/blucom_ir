<?php

namespace App\Models;

use Database\Factories\InboundRouteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'tenant_id',
    'sip_number_id',
    'destination_type',
    'destination_id',
    'enabled',
])]
class InboundRoute extends Model
{
    /** @use HasFactory<InboundRouteFactory> */
    use HasFactory;

    public const DESTINATION_EXTENSION = 'extension';

    /** @return array<string, string> */
    public static function availableDestinations(): array
    {
        return [self::DESTINATION_EXTENSION => 'داخلی'];
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sipNumber(): BelongsTo
    {
        return $this->belongsTo(SipNumber::class);
    }

    public function destination(): MorphTo
    {
        return $this->morphTo();
    }

    public function destinationLabel(): string
    {
        if ($this->destination_type === self::DESTINATION_EXTENSION && $this->destination instanceof SipExtension) {
            return $this->destination->display_name
                ? $this->destination->display_name.' · '.$this->destination->extension
                : 'داخلی '.$this->destination->extension;
        }

        return 'مقصد در دسترس نیست';
    }
}

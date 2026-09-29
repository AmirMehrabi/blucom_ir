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

    public const DESTINATION_QUEUE = 'queue';

    public const DESTINATION_IVR = 'ivr';

    /** @return array<string, string> */
    public static function availableDestinations(): array
    {
        return [self::DESTINATION_EXTENSION => 'یک نفر', self::DESTINATION_QUEUE => 'یک تیم', self::DESTINATION_IVR => 'منوی تماس'];
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

        if ($this->destination_type === self::DESTINATION_QUEUE && $this->destination instanceof CallQueue) {
            return 'تیم '.$this->destination->name;
        }

        if ($this->destination_type === self::DESTINATION_IVR && $this->destination instanceof IvrMenu) {
            return 'منوی '.$this->destination->name;
        }

        return 'مقصد در دسترس نیست';
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudioCall extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['audio_url', 'request_key', 'request_hash'];

    protected function casts(): array
    {
        return [
            'audio_url' => 'encrypted',
            'dispatched_at' => 'immutable_datetime',
            'answered_at' => 'immutable_datetime',
            'playback_started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function resource(): array
    {
        return [
            'id' => $this->id,
            'to' => $this->destination,
            'caller_id_id' => $this->caller_id_id,
            'caller_id' => $this->caller_id,
            'audio_id' => $this->audio_id,
            'status' => $this->status,
            'outcome' => $this->outcome,
            'created_at' => $this->created_at->toISOString(),
            'answered_at' => $this->answered_at?->toISOString(),
            'playback_started_at' => $this->playback_started_at?->toISOString(),
            'finished_at' => $this->finished_at?->toISOString(),
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'requester_type', 'requester_id', 'filters', 'snapshot_at', 'max_call_id', 'metric_version', 'status', 'path', 'failure_code', 'row_count', 'expires_at'])]
class ReportExport extends Model
{
    protected function casts(): array
    {
        return ['filters' => 'array', 'snapshot_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }
}

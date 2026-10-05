<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPermission extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'permission';

    protected $keyType = 'string';

    protected $fillable = ['customer_id', 'permission'];
}

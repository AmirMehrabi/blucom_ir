<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'permission'])]
class UserPermission extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'permission';

    protected $keyType = 'string';
}

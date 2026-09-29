<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'created_by_user_id', 'answer_type', 'sip_gateway_id', 'sip_number_id'])]
class LineSetupWizard extends Model
{
    public const ANSWER_PERSON = 'person';

    public const ANSWER_TEAM = 'team';

    public const ANSWER_MENU = 'menu';
}

<?php

namespace App\Enums;

enum CustomerRole: string
{
    case Owner = 'owner';
    case Staff = 'staff';
}

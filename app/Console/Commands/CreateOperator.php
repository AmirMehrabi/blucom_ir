<?php

namespace App\Console\Commands;

class CreateOperator extends CreateCustomer
{
    protected $signature = 'operator:create
        {mobile : Iranian mobile number, e.g. 09123456789}
        {--name= : Operator name}';

    protected $description = 'Create an operator with the default permissions for OTP login';
}

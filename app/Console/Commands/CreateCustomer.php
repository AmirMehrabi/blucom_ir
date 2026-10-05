<?php

namespace App\Console\Commands;

use App\Services\CustomerAccountService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class CreateCustomer extends Command
{
    protected $signature = 'customer:create
        {mobile : Iranian mobile number}
        {--name= : Customer contact name}
        {--business= : Customer business name}';

    protected $description = 'Create a Customer owner and an isolated business for the customer portal';

    public function handle(CustomerAccountService $accounts): int
    {
        $mobile = preg_replace('/[\s\-()]/', '', (string) $this->argument('mobile'));
        if (str_starts_with($mobile, '09')) {
            $mobile = '+98'.substr($mobile, 1);
        } elseif (str_starts_with($mobile, '989')) {
            $mobile = '+'.$mobile;
        }
        $name = trim((string) $this->option('name')) ?: 'Customer';
        $business = trim((string) $this->option('business')) ?: $name;
        try {
            $customer = $accounts->createOwner($name, $mobile, $business);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                $this->error(implode(' ', $messages));
            }

            return self::FAILURE;
        }
        $this->info("Created customer #{$customer->id} in business #{$customer->tenant_id}.");
        $this->line('Customer login: https://'.config('portal.customer_domain').'/login');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Enums\UserType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateCustomer extends Command
{
    protected $signature = 'customer:create
        {mobile : Iranian mobile number, e.g. 09123456789}
        {--name= : Customer contact name}
        {--business= : Business or tenant name}';

    protected $description = 'Create a customer account and its tenant for OTP login';

    public function handle(): int
    {
        $mobile = $this->normalizeMobile((string) $this->argument('mobile'));
        if ($mobile === null) {
            $this->error('Invalid Iranian mobile number. Use 09xxxxxxxxx or +989xxxxxxxxx.');

            return self::FAILURE;
        }

        if (User::query()->where('mobile', $mobile)->exists()) {
            $this->error('An account already uses this mobile number. Each mobile can belong to only one account.');

            return self::FAILURE;
        }

        $name = trim((string) $this->option('name'));
        $business = trim((string) $this->option('business'));
        if ($name === '') {
            $name = 'مشتری '.substr($mobile, -4);
        }
        if ($business === '') {
            $business = $name;
        }

        [$user, $tenant] = DB::transaction(function () use ($mobile, $name, $business): array {
            $user = User::query()->create([
                'name' => $name,
                'mobile' => $mobile,
                'user_type' => UserType::Customer,
            ]);
            $tenant = Tenant::query()->create([
                'name' => $business,
                'owner_user_id' => $user->id,
                'status' => 'active',
            ]);
            $user->update(['tenant_id' => $tenant->id]);

            return [$user, $tenant];
        });

        $this->info("Created customer #{$user->id} ({$mobile}) in tenant #{$tenant->id} ({$tenant->name}).");
        $this->line('The customer can request an OTP at the customer login page.');

        return self::SUCCESS;
    }

    private function normalizeMobile(string $value): ?string
    {
        $value = preg_replace('/[\s\-()]/', '', $value);

        if (str_starts_with($value, '09')) {
            $value = '+98'.substr($value, 1);
        } elseif (str_starts_with($value, '989')) {
            $value = '+'.$value;
        }

        return preg_match('/^\+989\d{9}$/', $value) ? $value : null;
    }
}

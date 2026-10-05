<?php

namespace App\Console\Commands;

use App\Enums\UserType;
use App\Models\User;
use App\Services\BlucomOwner;
use App\Support\Permissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateOperator extends Command
{
    protected $signature = 'operator:create
        {mobile : Iranian mobile number, e.g. 09123456789}
        {--name= : Customer contact name}';

    protected $description = 'Create an internal operator account for OTP login';

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
        if ($name === '') {
            $name = 'اپراتور '.substr($mobile, -4);
        }

        [$user, $tenant] = DB::transaction(function () use ($mobile, $name): array {
            $user = User::query()->create([
                'name' => $name,
                'mobile' => $mobile,
                'user_type' => UserType::Operator,
            ]);
            $tenant = app(BlucomOwner::class)->get();
            $user->update(['tenant_id' => $tenant->id]);
            foreach (Permissions::OPERATOR_DEFAULTS as $permission) {
                $user->permissions()->create(['permission' => $permission]);
            }

            return [$user, $tenant];
        });

        $this->info("Created operator #{$user->id} ({$mobile}) in workspace #{$tenant->id} ({$tenant->name}).");
        $this->line('The operator can request an OTP at the shared login page.');

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

<?php

namespace App\Console\Commands;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AdminUserCommand extends Command
{
    protected $signature = 'admin:user
        {action : create|list|show|promote|demote|enable|disable|delete}
        {identifier? : User id or mobile (E.164 or 09…)}
        {--name= : Display name for create}
        {--email= : Optional email for create}
        {--force : Skip confirmation on delete}';

    protected $description = 'Create and manage admin users';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'create' => $this->create(),
            'list' => $this->listAdmins(),
            'show' => $this->show(),
            'promote' => $this->setType(UserType::Admin),
            'demote' => $this->setType(UserType::Customer),
            'enable' => $this->setEnabled(true),
            'disable' => $this->setEnabled(false),
            'delete' => $this->delete(),
            default => $this->invalid($action),
        };
    }

    private function create(): int
    {
        $mobile = $this->normalizeMobile((string) $this->argument('identifier'));
        if ($mobile === null) {
            $this->error('Mobile is required. Example: php artisan admin:user create 09111111111 --name="Admin"');

            return self::FAILURE;
        }

        if (User::query()->where('mobile', $mobile)->exists()) {
            $this->error("User already exists for {$mobile}. Use promote instead.");

            return self::FAILURE;
        }

        $user = DB::transaction(fn () => User::create([
            'name' => $this->option('name') ?: 'Admin',
            'email' => $this->option('email') ?: null,
            'mobile' => $mobile,
            'user_type' => UserType::Admin,
            'mobile_verified_at' => now(),
            'disabled_at' => null,
        ]));

        $this->info("Created admin #{$user->id} {$mobile}".($user->name ? " ({$user->name})" : ''));

        return self::SUCCESS;
    }

    private function listAdmins(): int
    {
        $users = User::query()
            ->where('user_type', UserType::Admin)
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'mobile', 'user_type', 'disabled_at', 'created_at']);

        if ($users->isEmpty()) {
            $this->warn('No admin users found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Mobile', 'Email', 'Status', 'Created'],
            $users->map(fn (User $user) => [
                $user->id,
                $user->name ?? '—',
                $user->mobile ?? '—',
                $user->email ?? '—',
                $user->disabled_at ? 'disabled' : 'active',
                optional($user->created_at)->format('Y-m-d H:i'),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function show(): int
    {
        $user = $this->resolveUser();
        if (! $user) {
            return self::FAILURE;
        }

        $this->table(
            ['Field', 'Value'],
            [
                ['id', (string) $user->id],
                ['name', $user->name ?? '—'],
                ['mobile', $user->mobile ?? '—'],
                ['email', $user->email ?? '—'],
                ['user_type', $user->user_type?->value ?? '—'],
                ['status', $user->disabled_at ? 'disabled' : 'active'],
                ['mobile_verified_at', optional($user->mobile_verified_at)->format('Y-m-d H:i:s') ?? '—'],
                ['created_at', optional($user->created_at)->format('Y-m-d H:i:s') ?? '—'],
            ],
        );

        return self::SUCCESS;
    }

    private function setType(UserType $type): int
    {
        $user = $this->resolveUser();
        if (! $user) {
            return self::FAILURE;
        }

        if ($type === UserType::Admin && $user->user_type === UserType::Admin) {
            $this->warn("User #{$user->id} is already an admin.");

            return self::SUCCESS;
        }

        if ($type === UserType::Customer && $this->isLastAdmin($user)) {
            $this->error('Refusing to demote the last admin user.');

            return self::FAILURE;
        }

        if ($type === UserType::Admin && ! $user->mobile) {
            $this->error('Cannot promote a user without a mobile number (OTP login requires it).');

            return self::FAILURE;
        }

        $user->update(['user_type' => $type]);

        $this->info("User #{$user->id} is now {$type->value}.");

        return self::SUCCESS;
    }

    private function setEnabled(bool $enabled): int
    {
        $user = $this->resolveUser();
        if (! $user) {
            return self::FAILURE;
        }

        if (! $enabled && $user->user_type === UserType::Admin && $this->isLastAdmin($user)) {
            $this->error('Refusing to disable the last active admin user.');

            return self::FAILURE;
        }

        $user->update(['disabled_at' => $enabled ? null : now()]);

        $this->info($enabled ? "User #{$user->id} enabled." : "User #{$user->id} disabled.");

        return self::SUCCESS;
    }

    private function delete(): int
    {
        $user = $this->resolveUser();
        if (! $user) {
            return self::FAILURE;
        }

        if ($user->user_type === UserType::Admin && $this->isLastAdmin($user)) {
            $this->error('Refusing to delete the last admin user.');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $confirmed = $this->confirm("Delete user #{$user->id} ({$user->mobile})?");
            if (! $confirmed) {
                $this->comment('Aborted.');

                return self::SUCCESS;
            }
        }

        $user->delete();
        $this->info("Deleted user #{$user->id}.");

        return self::SUCCESS;
    }

    private function invalid(string $action): int
    {
        $this->error("Unknown action [{$action}]. Use: create|list|show|promote|demote|enable|disable|delete");

        return self::FAILURE;
    }

    private function resolveUser(): ?User
    {
        $identifier = $this->argument('identifier');
        if (! is_string($identifier) || $identifier === '') {
            $this->error('User identifier is required (id or mobile).');

            return null;
        }

        $user = ctype_digit($identifier)
            ? User::query()->find((int) $identifier)
            : User::query()->where('mobile', $this->normalizeMobile($identifier) ?? $identifier)->first();

        if (! $user) {
            $this->error("User not found: {$identifier}");

            return null;
        }

        return $user;
    }

    private function isLastAdmin(User $user): bool
    {
        if ($user->user_type !== UserType::Admin || $user->disabled_at) {
            return false;
        }

        return User::query()
            ->where('user_type', UserType::Admin)
            ->whereNull('disabled_at')
            ->where('id', '!=', $user->id)
            ->doesntExist();
    }

    private function normalizeMobile(string $value): ?string
    {
        $value = preg_replace('/[\s\-()]/', '', $value);

        if (str_starts_with($value, '09')) {
            $value = '+98'.substr($value, 1);
        } elseif (str_starts_with($value, '989')) {
            $value = '+'.$value;
        } elseif (preg_match('/^9\d{9}$/', $value)) {
            $value = '+98'.$value;
        }

        if (! preg_match('/^\+989\d{9}$/', $value)) {
            return null;
        }

        return $value;
    }
}

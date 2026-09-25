<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BlucomOwner;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    public function index(): View
    {
        return view('admin.users.index', [
            'users' => User::query()->with('permissions')->orderBy('name')->get(),
            'permissionLabels' => Permissions::LABELS,
        ]);
    }

    public function store(Request $request, BlucomOwner $owner): RedirectResponse
    {
        $data = $this->validated($request);
        $mobile = $this->mobile($data['mobile']);
        if (User::query()->where('mobile', $mobile)->exists()) {
            throw ValidationException::withMessages(['mobile' => 'این شماره قبلاً ثبت شده است.']);
        }

        DB::transaction(function () use ($data, $mobile, $owner): void {
            $user = User::query()->create([
                'name' => $data['name'],
                'mobile' => $mobile,
                'user_type' => UserType::from($data['role']),
                'tenant_id' => $data['role'] === 'operator' ? $owner->get()->id : null,
            ]);
            $this->setPermissions($user, $data['role'] === 'operator' ? ($data['permissions'] ?? []) : []);
        });

        return redirect()->route('users.index')->with('status', 'کاربر ساخته شد. ورود با کد پیامکی انجام می‌شود.');
    }

    public function update(Request $request, User $user, BlucomOwner $owner): RedirectResponse
    {
        $data = $this->validated($request, false);
        $role = $data['role'];
        if ($user->id === $request->user()->id && ($role !== 'admin' || ! $data['enabled'])) {
            throw ValidationException::withMessages(['role' => 'نمی‌توانید دسترسی مدیر خودتان را حذف یا حساب خود را غیرفعال کنید.']);
        }
        DB::transaction(function () use ($user, $data, $role, $owner): void {
            $activeAdminIds = User::query()->where('user_type', UserType::Admin)
                ->whereNull('disabled_at')->orderBy('id')->lockForUpdate()->pluck('id');
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($user->isAdmin() && ($role !== 'admin' || ! $data['enabled'])) {
                if ($activeAdminIds->count() === 1 && $activeAdminIds->contains($user->id)) {
                    throw ValidationException::withMessages(['role' => 'آخرین مدیر فعال را نمی‌توان غیرفعال یا تبدیل کرد.']);
                }
            }
            $user->update([
                'name' => $data['name'],
                'user_type' => UserType::from($role),
                'disabled_at' => $data['enabled'] ? null : now(),
                'tenant_id' => $role === 'operator' ? ($user->tenant_id ?: $owner->get()->id) : $user->tenant_id,
            ]);
            $this->setPermissions($user, $role === 'operator' ? ($data['permissions'] ?? []) : []);
        });

        return redirect()->route('users.index')->with('status', 'دسترسی‌های کاربر ذخیره شد.');
    }

    private function validated(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'mobile' => [$creating ? 'required' : 'prohibited', 'string', 'max:20'],
            'role' => ['required', Rule::in(['admin', 'operator'])],
            'enabled' => [$creating ? 'sometimes' : 'required', 'boolean'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(Permissions::OPERATOR_ASSIGNABLE)],
        ]);
    }

    private function mobile(string $value): string
    {
        $value = preg_replace('/[\s\-()]/', '', $value);
        if (str_starts_with($value, '09')) {
            $value = '+98'.substr($value, 1);
        } elseif (str_starts_with($value, '989')) {
            $value = '+'.$value;
        }
        if (! preg_match('/^\+989\d{9}$/', $value)) {
            throw ValidationException::withMessages(['mobile' => 'شماره موبایل معتبر نیست.']);
        }

        return $value;
    }

    private function setPermissions(User $user, array $permissions): void
    {
        if (array_intersect($permissions, [Permissions::PROVIDERS_MANAGE, Permissions::NUMBERS_MANAGE, Permissions::PHONES_MANAGE])) {
            $permissions[] = Permissions::LINES_VIEW;
        }
        $user->permissions()->delete();
        foreach (array_unique($permissions) as $permission) {
            $user->permissions()->create(['permission' => $permission]);
        }
    }
}

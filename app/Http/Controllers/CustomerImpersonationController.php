<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CustomerImpersonationController extends Controller
{
    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $admin = $request->user('web');
        abort_unless($admin instanceof User && $admin->isAdmin() && ! $admin->isDisabled(), 403);
        $this->authorizeCustomer($customer);
        $token = Str::random(64);
        DB::table('customer_impersonation_tokens')->where('expires_at', '<=', now())->delete();
        DB::table('customer_impersonation_tokens')->insert([
            'token_hash' => hash('sha256', $token), 'admin_id' => $admin->id,
            'customer_id' => $customer->id, 'expires_at' => now()->addMinute(),
        ]);

        return redirect()->away('https://'.config('portal.customer_domain').'/auth/impersonate/'.$token)
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function consume(Request $request, string $token): RedirectResponse
    {
        abort_unless(preg_match('/^[a-zA-Z0-9]{64}$/D', $token), 403);
        [$customer, $adminId] = DB::transaction(function () use ($token): array {
            $query = DB::table('customer_impersonation_tokens')->where('token_hash', hash('sha256', $token));
            $handoff = (clone $query)->lockForUpdate()->first();
            abort_unless($handoff && now()->lt($handoff->expires_at), 403);
            $admin = User::query()->find($handoff->admin_id);
            abort_unless($admin && $admin->isAdmin() && ! $admin->isDisabled(), 403);
            $customer = Customer::query()->findOrFail($handoff->customer_id);
            $this->authorizeCustomer($customer);
            $query->delete();

            return [$customer, $admin->id];
        });
        // Discard any previous customer's session data before changing identity.
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::guard('customer')->login($customer);
        $request->session()->put('impersonator_id', $adminId);
        Log::info('Customer impersonation started', [
            'admin_id' => $adminId, 'customer_id' => $customer->id, 'tenant_id' => $customer->tenant_id,
        ]);

        return redirect($customer->homePath())->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    private function authorizeCustomer(Customer $customer): void
    {
        $tenant = $customer->tenant;
        abort_unless($tenant && $customer->canAccessTenant($tenant), 403);
    }
}

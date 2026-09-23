<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminTenantController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        return view('admin.tenants.index', [
            'mode' => 'admin',
            'tenants' => Tenant::query()
                ->with('owner:id,name,mobile,tenant_id')
                ->withCount(['sipNumbers', 'sipExtensions'])
                ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
                ->orderBy('name')
                ->paginate(50)
                ->withQueryString(),
        ]);
    }

    public function show(int $tenant): View
    {
        $model = Tenant::query()
            ->with([
                'owner:id,name,mobile',
                'sipNumbers.providerGateway',
                'sipNumbers.inboundRoute.destination',
                'sipNumbers.outboundRoute.gateway',
                'sipExtensions',
                'inboundRoutes.sipNumber',
                'inboundRoutes.destination',
                'outboundRoutes.sipNumber',
                'outboundRoutes.gateway',
            ])
            ->findOrFail($tenant);

        return view('admin.tenants.show', [
            'mode' => 'admin',
            'tenant' => $model,
        ]);
    }

    public function update(Request $request, int $tenant): RedirectResponse
    {
        $model = Tenant::query()->findOrFail($tenant);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:active,disabled'],
            'name' => ['sometimes', 'string', 'max:255'],
        ]);

        $model->update($data);

        return back()->with('status', 'حساب سازمانی به‌روزرسانی شد.');
    }
}

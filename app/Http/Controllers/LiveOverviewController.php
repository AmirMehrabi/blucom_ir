<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\LiveOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LiveOverviewController extends Controller
{
    public function __construct(private readonly LiveOverviewService $overview) {}

    public function index(Request $request): View
    {
        $request->validate(['organization' => ['nullable', 'integer']]);
        $tenant = $this->overview->tenant($request->user(), $request->query('organization'));

        return view('live-overview.index', [
            'snapshot' => $this->overview->snapshot($request->user(), $tenant),
            'organizations' => $request->user()->isAdmin() ? Tenant::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    public function state(Request $request): JsonResponse
    {
        $request->validate(['organization' => ['nullable', 'integer']]);
        $tenant = $this->overview->tenant($request->user(), $request->query('organization'));

        return response()->json($this->overview->snapshot($request->user(), $tenant))->header('Cache-Control', 'private, no-store');
    }
}

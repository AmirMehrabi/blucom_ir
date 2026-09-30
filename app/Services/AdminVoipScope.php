<?php

namespace App\Services;

use App\Models\CallQueue;
use App\Models\IvrMenu;
use App\Models\SipNumber;
use App\Models\Tenant;
use Illuminate\Http\Request;

class AdminVoipScope
{
    public function tenant(Request $request): Tenant
    {
        if ($request->filled('tenant_id')) {
            return Tenant::query()->findOrFail($request->integer('tenant_id'));
        }
        if ($request->isMethod('get') && $request->filled('sip_number_id')) {
            $tenant = SipNumber::query()->findOrFail($request->integer('sip_number_id'))->tenant;
            abort_if($tenant === null, 404);

            return $tenant;
        }

        return app(BlucomOwner::class)->get();
    }

    public function destinations(Tenant $tenant): array
    {
        return [
            'extensions' => $tenant->sipExtensions()->where('enabled', true)->orderBy('display_name')->get(),
            'queues' => config('voip.queues_enabled')
                ? CallQueue::query()->whereBelongsTo($tenant)->where('enabled', true)
                    ->whereHas('members', fn ($query) => $query->where('enabled', true))->orderBy('name')->get() : collect(),
            'menus' => IvrMenu::query()->whereBelongsTo($tenant)->where('enabled', true)->whereNotNull('published_config')->orderBy('name')->get(),
        ];
    }
}

<?php

namespace App\Http\Controllers\FreeSwitch;

use App\Http\Controllers\Controller;
use App\Models\SipExtension;
use App\Models\Tenant;
use App\Services\FreeSwitchDialplanService;
use App\Services\FreeSwitchDirectoryService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class XmlController extends Controller
{
    public function __construct(
        private readonly FreeSwitchDirectoryService $directories,
        private readonly FreeSwitchDialplanService $dialplans,
    ) {}

    /**
     * POST /internal/freeswitch/xml
     *
     * Thin transport for mod_xml_curl. Lookup and XML generation live in services.
     */
    public function __invoke(Request $request): Response
    {
        $section = (string) $request->input('section', '');

        $xml = match ($section) {
            'directory' => $this->directory($request),
            'dialplan' => $this->dialplan($request),
            default => '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<document type="freeswitch/xml"/>'."\n",
        };

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function directory(Request $request): string
    {
        $user = $request->input('user');
        $user = is_string($user) && $user !== '' ? $user : null;
        $requestedDomain = $request->input('domain');
        $requestedDomain = is_string($requestedDomain) && $requestedDomain !== '' ? $requestedDomain : null;

        return $this->directories->build($this->resolveTenant($request, $user), $user, $requestedDomain);
    }

    private function dialplan(Request $request): string
    {
        return $this->dialplans->build((string) $request->input('context', 'default'), $request->all());
    }

    private function resolveTenant(Request $request, ?string $user): ?Tenant
    {
        if ($user !== null) {
            return SipExtension::query()
                ->where('extension', $user)
                ->where('enabled', true)
                ->first()
                ?->tenant;
        }

        $tenantId = $request->input('variable_tenant_id') ?? $request->input('tenant_id');

        if (is_scalar($tenantId) && $tenantId !== '') {
            return Tenant::query()->find((int) $tenantId);
        }

        return null;
    }
}

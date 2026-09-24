<?php

namespace App\Http\Controllers\FreeSwitch;

use App\Http\Controllers\Controller;
use App\Models\SipExtension;
use App\Services\BlucomOwner;
use App\Services\FreeSwitchDialplanService;
use App\Services\FreeSwitchDirectoryService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class XmlController extends Controller
{
    public function __construct(
        private readonly FreeSwitchDirectoryService $directories,
        private readonly FreeSwitchDialplanService $dialplans,
        private readonly BlucomOwner $owner,
    ) {}

    /**
     * POST /internal/freeswitch/xml
     *
     * Thin transport for mod_xml_curl. Lookup and XML generation live in services.
     */
    public function __invoke(Request $request): Response
    {
        try {
            $xml = match ((string) $request->input('section', '')) {
                'directory' => $this->directory($request),
                'dialplan' => $this->dialplan($request),
                default => '<?xml version="1.0" encoding="UTF-8"?><document type="freeswitch/xml"/>',
            };
        } catch (\Throwable $exception) {
            Log::error('FreeSWITCH XML lookup failed', ['exception_class' => $exception::class]);
            $xml = '<?xml version="1.0" encoding="UTF-8"?><document type="freeswitch/xml"/>';
        }

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
        $domain = (string) config('voip.directory_domain');
        if ($user === null || ($requestedDomain !== null && $requestedDomain !== $domain)) {
            return $this->directories->build(null, $user, $domain);
        }

        $tenant = $this->owner->get();
        if (! SipExtension::query()->whereBelongsTo($tenant)->where('extension', $user)->where('enabled', true)->exists()) {
            $tenant = null;
        }

        return $this->directories->build($tenant, $user, $domain);
    }

    private function dialplan(Request $request): string
    {
        return $this->dialplans->build((string) $request->input('context', 'default'), $request->all());
    }
}

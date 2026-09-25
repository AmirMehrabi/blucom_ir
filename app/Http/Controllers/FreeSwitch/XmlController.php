<?php

namespace App\Http\Controllers\FreeSwitch;

use App\Http\Controllers\Controller;
use App\Services\FreeSwitchDialplanService;
use App\Services\FreeSwitchDirectoryService;
use App\Services\FreeSwitchGatewayDirectoryService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class XmlController extends Controller
{
    public function __construct(
        private readonly FreeSwitchDirectoryService $directories,
        private readonly FreeSwitchDialplanService $dialplans,
        private readonly FreeSwitchGatewayDirectoryService $gateways,
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
                default => $this->directories->notFound(),
            };
        } catch (\Throwable $exception) {
            Log::error('FreeSWITCH XML lookup failed', ['exception_class' => $exception::class]);

            return response($this->directories->notFound(), 503, [
                'Content-Type' => 'application/xml; charset=UTF-8',
                'Cache-Control' => 'no-store',
            ]);
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
        $requestedDomain = $request->input('domain', $request->input('key_value'));
        if ($requestedDomain === '') {
            $requestedDomain = null;
        }
        $domain = (string) config('voip.directory_domain');
        if ($request->input('purpose') === 'gateways') {
            if (! config('voip.gateway_xml_enabled') || $request->input('profile') !== 'external' || $user !== null
                || ($requestedDomain !== null && $requestedDomain !== $domain)
                || ! in_array($request->input('tag_name'), [null, '', 'domain'], true)
                || ! in_array($request->input('key_name'), [null, '', 'name'], true)) {
                return $this->directories->notFound();
            }

            return $this->gateways->build($domain);
        }
        if ($request->filled('purpose')) {
            return $this->directories->notFound();
        }
        if (($requestedDomain !== null && $requestedDomain !== $domain)
            || ($request->has('tag_name') && $request->input('tag_name') !== 'domain')
            || ($request->has('key_name') && $request->input('key_name') !== 'name')) {
            return $this->directories->notFound();
        }

        return $this->directories->buildAll($user, $domain);
    }

    private function dialplan(Request $request): string
    {
        // A live mod_dialplan_xml lookup has empty tag/key fields and sends
        // the caller profile's context as Hunt-Context. xml_locate instead
        // supplies context/name through the lookup key fields.
        if (! in_array($request->input('tag_name'), [null, '', 'context'], true)
            || ! in_array($request->input('key_name'), [null, '', 'name'], true)) {
            return $this->directories->notFound();
        }

        $contexts = [];
        foreach (['Hunt-Context', 'context', 'key_value'] as $field) {
            $value = $request->input($field);
            if ($value === null || $value === '') {
                continue;
            }

            if (! is_string($value) || ! in_array($value, ['public', 'default'], true)) {
                return $this->directories->notFound();
            }

            $contexts[] = $value;
        }

        if ($contexts === [] || count(array_unique($contexts)) !== 1) {
            return $this->directories->notFound();
        }

        $context = $contexts[0];

        return $this->dialplans->build($context, $request->all());
    }
}

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
        $domain = (string) config('voip.directory_domain');
        if (($requestedDomain !== null && $requestedDomain !== $domain)
            || ($request->has('tag_name') && $request->input('tag_name') !== 'domain')
            || ($request->has('key_name') && $request->input('key_name') !== 'name')) {
            return $this->directories->notFound();
        }

        $tenant = $this->owner->get();
        if ($user !== null && ! SipExtension::query()->whereBelongsTo($tenant)->where('extension', $user)->where('enabled', true)->exists()) {
            $tenant = null;
        }

        return $this->directories->build($tenant, $user, $domain);
    }

    private function dialplan(Request $request): string
    {
        $context = $request->input('context', $request->input('key_value'));

        if (! is_string($context) || ! in_array($context, ['public', 'default'], true)
            || ($request->has('tag_name') && $request->input('tag_name') !== 'context')
            || ($request->has('key_name') && $request->input('key_name') !== 'name')) {
            return $this->directories->notFound();
        }

        return $this->dialplans->build($context, $request->all());
    }
}

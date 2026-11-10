<?php

namespace App\Http\Controllers;

use App\Models\CallMonitorSession;
use App\Services\Monitoring\CallMonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CallMonitoringController extends Controller
{
    public function __construct(private CallMonitoringService $monitoring) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization' => ['nullable', 'integer'], 'extension_id' => ['required', 'integer'],
            'call_id' => ['required', 'uuid'], 'mode' => ['required', 'in:extension,web'],
            'supervisor_extension_id' => ['required_if:mode,extension', 'nullable', 'integer'],
        ]);

        return $this->json($this->monitoring->prepare($request->user(), $data));
    }

    public function current(Request $request): JsonResponse
    {
        return $this->json(['session' => $this->monitoring->current($request->user())]);
    }

    public function connect(Request $request, CallMonitorSession $monitor): JsonResponse
    {
        return $this->json($this->monitoring->connect($request->user(), $monitor));
    }

    public function heartbeat(Request $request, CallMonitorSession $monitor): JsonResponse
    {
        return $this->json($this->monitoring->heartbeat($request->user(), $monitor));
    }

    public function stop(Request $request, CallMonitorSession $monitor): JsonResponse
    {
        return $this->json($this->monitoring->stop($request->user(), $monitor));
    }

    private function json(mixed $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}

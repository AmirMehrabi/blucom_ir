<?php

namespace App\Http\Controllers;

use App\Services\InboundAudioService;
use App\Services\OutboundCalling\AudioAssetService;
use App\Services\TenantService;
use Illuminate\Http\Request;

class InboundAudioController extends Controller
{
    public function index(Request $request, TenantService $tenants, InboundAudioService $audio)
    {
        return view('inbound-audio.index', ['assets' => $audio->assets($tenants->forUser($request->user()))]);
    }

    public function store(Request $request, TenantService $tenants, AudioAssetService $assets)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'audio' => ['required', 'file', 'max:10240', 'mimes:wav,mp3,mpga'],
        ]);
        $assets->store($tenants->forUser($request->user()), $validated['name'], $validated['audio'], strict: true, inbound: true);

        return back()->with('status', 'اعلان صوتی ذخیره شد.');
    }

    public function audio(Request $request, string $asset, TenantService $tenants, InboundAudioService $audio)
    {
        $path = $audio->announcement($tenants->forUser($request->user())->id, 'announcement:'.$asset);
        abort_unless($path, 404);

        return response()->file($path, ['Content-Type' => 'audio/wav', 'Cache-Control' => 'private, no-store']);
    }
}

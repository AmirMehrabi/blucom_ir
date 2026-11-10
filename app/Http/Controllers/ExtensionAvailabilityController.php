<?php

namespace App\Http\Controllers;

use App\Services\ExtensionAvailabilityService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ExtensionAvailabilityController extends Controller
{
    public function show(Request $request, int $extension, ExtensionAvailabilityService $availability)
    {
        return response()->json($availability->state($request->user(), $extension))->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, int $extension, ExtensionAvailabilityService $availability)
    {
        $data = $request->validate(['paused' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:0']]);
        try {
            $phone = $availability->pause($request->user(), $extension, (bool) $data['paused'], (int) $data['version']);
        } catch (HttpException $exception) {
            if (! $request->expectsJson() && in_array($exception->getStatusCode(), [409, 422], true)) {
                return back()->withErrors(['availability' => $exception->getMessage()]);
            }
            throw $exception;
        }
        if ($request->expectsJson()) {
            return response()->json(['paused' => $phone->paused_at !== null, 'version' => $phone->availability_version])->header('Cache-Control', 'private, no-store');
        }

        return back()->with('status', $phone->paused_at ? 'دریافت تماس جدید متوقف شد. وضعیت تیم‌ها پس از همگام‌سازی تأیید می‌شود.' : 'دریافت تماس از سر گرفته شد. استراحت تیمی، اگر فعال باشد، حفظ می‌شود.');
    }
}

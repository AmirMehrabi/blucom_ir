<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\LineConnectionRequest;
use App\Services\Commerce\CommerceAudit;
use App\Services\NumberNormalizer;
use App\Services\Portal\CustomerJourney;
use App\Support\Permissions;
use Illuminate\Http\Request;

class JourneyController extends Controller
{
    public function __invoke(Request $request, CustomerJourney $journey)
    {
        return response()->view('customer.journey.start', ['journey' => $journey->summary($request->user('customer'))])
            ->header('Cache-Control', 'private, no-store');
    }

    public function existing(Request $request)
    {
        $customer = $request->user('customer');
        abort_unless($customer->hasPermission(Permissions::LINES_VIEW) && $customer->hasPermission(Permissions::PHONES_MANAGE), 403);

        return response()->view('customer.journey.existing', ['requests' => LineConnectionRequest::query()->where('tenant_id', $customer->tenant_id)->latest()->get()])->header('Cache-Control', 'private, no-store');
    }

    public function requestConnection(Request $request, NumberNormalizer $numbers)
    {
        $customer = $request->user('customer');
        abort_unless($customer->hasPermission(Permissions::LINES_VIEW) && $customer->hasPermission(Permissions::PHONES_MANAGE), 403);
        $data = $request->validate(['number' => ['required', 'string', 'max:32'], 'provider_name' => ['required', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $normalized = $numbers->normalizeOrFail($data['number']);
        validator(['number' => $normalized], ['number' => ['regex:/^\+[1-9][0-9]{3,14}$/D']])->validate();
        $record = LineConnectionRequest::query()->firstOrCreate(['tenant_id' => $customer->tenant_id, 'normalized_number' => $normalized], [
            'customer_id' => $customer->id, 'provider_name' => $data['provider_name'], 'notes' => $data['notes'] ?? null,
        ]);

        if ($record->wasRecentlyCreated) {
            app(CommerceAudit::class)->customer($customer, 'connection.requested', 'line_connection_request', $record->id);
        }

        return redirect()->route('customer.start.existing')->with('status', 'درخواست اتصال شماره ثبت شده است؛ نتیجه بررسی را همین‌جا ببینید.');
    }
}

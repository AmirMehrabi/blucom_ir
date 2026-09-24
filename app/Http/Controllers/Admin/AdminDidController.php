<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DidRequest;
use App\Models\SipNumber;
use App\Services\BlucomOwner;
use App\Services\NumberNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class AdminDidController extends Controller
{
    public function __construct(private readonly BlucomOwner $owner, private readonly NumberNormalizer $numbers) {}

    public function index(): View
    {
        $tenant = $this->owner->get();

        return view('admin.sip-numbers.index', [
            'mode' => 'admin',
            'numbers' => SipNumber::query()->whereBelongsTo($tenant)->orderBy('normalized_number')->paginate(50),
        ]);
    }

    public function store(DidRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $normalized = $this->numbers->normalizeOrFail($data['number']);
        validator(['normalized_number' => $normalized], [
            'normalized_number' => ['unique:sip_numbers,normalized_number'],
        ])->validate();

        $number = SipNumber::query()->create([
            'tenant_id' => $this->owner->get()->id,
            'number' => $data['number'],
            'normalized_number' => $normalized,
            'label' => $data['label'] ?? null,
            'status' => SipNumber::STATUS_ASSIGNED,
            'enabled' => $data['enabled'],
            'inbound_enabled' => $data['inbound_enabled'],
            'outbound_enabled' => $data['outbound_enabled'],
        ]);
        Log::info('DID created', ['sip_number_id' => $number->id]);

        return back()->with('status', 'شماره ثبت شد.');
    }

    public function update(DidRequest $request, int $sipNumber): RedirectResponse
    {
        $number = SipNumber::query()->whereBelongsTo($this->owner->get())->findOrFail($sipNumber);
        $data = $request->validated();
        $number->update($data);
        Log::info('DID updated', ['sip_number_id' => $number->id]);

        return back()->with('status', 'شماره به‌روزرسانی شد.');
    }

    public function destroy(int $sipNumber): RedirectResponse
    {
        $number = SipNumber::query()->whereBelongsTo($this->owner->get())->findOrFail($sipNumber);

        if ($number->inboundRoute()->exists() || $number->outboundRoutes()->exists()) {
            return back()->withErrors(['number' => 'ابتدا مسیرهای وابسته به این شماره را حذف کنید.']);
        }

        $number->delete();
        Log::info('DID deleted', ['sip_number_id' => $number->id]);

        return back()->with('status', 'شماره حذف شد.');
    }
}

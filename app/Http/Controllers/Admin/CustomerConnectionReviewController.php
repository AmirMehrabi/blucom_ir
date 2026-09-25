<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SipGateway;
use App\Models\SipNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class CustomerConnectionReviewController extends Controller
{
    public function index(): View
    {
        return view('admin.customer-connections.index', [
            'gateways' => SipGateway::query()->whereNotNull('tenant_id')->with('tenant')->orderByDesc('id')->get(),
            'numbers' => SipNumber::query()->whereNotNull('tenant_id')->whereHas('providerGateway', fn ($query) => $query->whereNotNull('tenant_id'))
                ->with(['tenant', 'providerGateway'])->orderByDesc('id')->get(),
        ]);
    }

    public function approveGateway(int $gateway): RedirectResponse
    {
        $record = SipGateway::query()->whereNotNull('tenant_id')->findOrFail($gateway);
        if ($record->verification_status !== SipGateway::STATUS_PENDING || ! $record->tenant?->isActive()) {
            return back()->withErrors(['gateway' => 'این اتصال در انتظار بررسی نیست.']);
        }
        $record->update([
            'verification_status' => SipGateway::STATUS_APPROVED,
            'enabled' => true,
            'approved_for_outbound' => true,
        ]);
        Log::info('Customer provider approved', ['tenant_id' => $record->tenant_id, 'gateway_id' => $record->id]);

        return back()->with('status', 'اتصال تأیید شد. فعال‌سازی آن در FreeSWITCH باید جداگانه بررسی شود.');
    }

    public function rejectGateway(int $gateway): RedirectResponse
    {
        $record = SipGateway::query()->whereNotNull('tenant_id')->findOrFail($gateway);
        $record->update([
            'verification_status' => SipGateway::STATUS_REJECTED,
            'enabled' => false,
            'approved_for_outbound' => false,
        ]);
        Log::info('Customer provider rejected', ['tenant_id' => $record->tenant_id, 'gateway_id' => $record->id]);

        return back()->with('status', 'اتصال رد شد.');
    }

    public function approveNumber(int $number): RedirectResponse
    {
        $record = SipNumber::query()->whereNotNull('tenant_id')->with('providerGateway')->findOrFail($number);
        if ($record->status !== SipNumber::STATUS_PENDING
            || $record->providerGateway?->tenant_id !== $record->tenant_id
            || $record->providerGateway?->verification_status !== SipGateway::STATUS_APPROVED
            || ! $record->providerGateway?->enabled
            || ! $record->tenant?->isActive()) {
            return back()->withErrors(['number' => 'ابتدا اتصال ارائه‌دهنده را تأیید کنید.']);
        }
        $record->update(['status' => SipNumber::STATUS_ASSIGNED]);
        Log::info('Customer number approved', ['tenant_id' => $record->tenant_id, 'sip_number_id' => $record->id]);

        return back()->with('status', 'شماره تأیید شد.');
    }

    public function rejectNumber(int $number): RedirectResponse
    {
        $record = SipNumber::query()->whereNotNull('tenant_id')->findOrFail($number);
        if ($record->status !== SipNumber::STATUS_PENDING) {
            return back()->withErrors(['number' => 'فقط شماره در انتظار بررسی قابل رد است.']);
        }
        $record->update(['status' => SipNumber::STATUS_DISABLED, 'enabled' => false]);
        Log::info('Customer number rejected', ['tenant_id' => $record->tenant_id, 'sip_number_id' => $record->id]);

        return back()->with('status', 'شماره رد شد.');
    }
}

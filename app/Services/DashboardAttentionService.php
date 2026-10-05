<?php

namespace App\Services;

use App\Models\CallRecording;
use App\Models\Customer;
use App\Models\RecordingStorageSetting;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\User;
use App\Support\Permissions;

class DashboardAttentionService
{
    public function summarize(User|Customer $user, ?int $tenantId): array
    {
        $alerts = [];
        $scope = fn ($query) => $query->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId));
        if ($user->isAdmin()) {
            $pending = SipGateway::query()->where('verification_status', SipGateway::STATUS_PENDING)->count();
            if ($pending > 0) {
                $alerts[] = ['title' => "{$pending} اتصال در انتظار بررسی", 'description' => 'درخواست‌های ارائه‌دهنده را بررسی و تعیین تکلیف کنید.',
                    'url' => route('admin.customer-connections.index'), 'action' => 'بررسی درخواست‌ها', 'tone' => 'amber'];
            }
        }
        if ($user->hasPermission(Permissions::LINES_VIEW)) {
            $missing = $scope(SipNumber::query())->where('enabled', true)->where('status', SipNumber::STATUS_ASSIGNED)
                ->where('inbound_enabled', true)->whereDoesntHave('inboundRoute', function ($route) {
                    $route->where('enabled', true)->whereColumn('inbound_routes.tenant_id', 'sip_numbers.tenant_id')
                        ->where(function ($destinations) {
                            foreach (['extension' => 'sip_extensions', 'queue' => 'call_queues', 'ivr' => 'ivr_menus'] as $type => $table) {
                                $destinations->orWhere(fn ($destination) => $destination->where('destination_type', $type)
                                    ->whereExists(fn ($target) => $target->selectRaw('1')->from($table)
                                        ->whereColumn($table.'.id', 'inbound_routes.destination_id')
                                        ->whereColumn($table.'.tenant_id', 'inbound_routes.tenant_id')->where($table.'.enabled', true)));
                            }
                        });
                })->count();
            if ($missing > 0) {
                $alerts[] = ['title' => "{$missing} خط بدون مقصد ورودی فعال", 'description' => 'مسیر ورودی یا پاسخ‌گوی این خط‌ها نیاز به بررسی دارد.',
                    'url' => route($user->isAdmin() ? 'admin.sip-numbers.index' : 'customer.setup.lines'), 'action' => 'بررسی خط‌ها', 'tone' => 'amber'];
            }
        }
        $storage = null;
        if ($user->hasPermission(Permissions::RECORDINGS_VIEW)) {
            $recordings = $scope(CallRecording::query());
            $failed = (clone $recordings)->where('status', 'failed')->where('expires_at', '>', now())->count();
            if ($failed > 0) {
                $alerts[] = ['title' => "{$failed} ضبط ناموفق", 'description' => 'جزئیات ضبط‌های ناموفق را بررسی کنید.',
                    'url' => route('recordings.index', ['status' => 'failed']), 'action' => 'بررسی ضبط‌ها', 'tone' => 'red'];
            }
            $delayed = (clone $recordings)->whereIn('status', ['recording', 'processing'])
                ->whereHas('callRecord', fn ($call) => $call->whereNotNull('ended_at')->where('ended_at', '<', now()->subMinutes(3)))->count();
            if ($delayed > 0) {
                $alerts[] = ['title' => "{$delayed} ضبط با تأخیر در آماده‌سازی", 'description' => 'بیش از ۳ دقیقه از پایان تماس گذشته است؛ ضبط‌های فعال در این هشدار شمرده نمی‌شوند.',
                    'url' => route('recordings.index'), 'action' => 'بررسی آماده‌سازی', 'tone' => 'amber'];
            }
            $usage = (clone $recordings)->selectRaw('tenant_id, SUM(bytes) AS used, SUM(reserved_bytes) AS reserved')->groupBy('tenant_id')->get();
            $settings = RecordingStorageSetting::query()->whereIn('tenant_id', $usage->pluck('tenant_id'))->get()->keyBy('tenant_id');
            $nearCapacity = 0;
            foreach ($usage as $item) {
                $setting = $settings->get($item->tenant_id);
                $quota = min($setting?->quota_mb ?? config('voip.recordings.quota_mb'), config('voip.recordings.max_quota_mb')) * 1048576;
                $percent = $quota > 0 ? round(($item->used + $item->reserved) * 100 / $quota, 1) : 0;
                if ($percent >= 80) {
                    $nearCapacity++;
                }
                if ($tenantId !== null) {
                    $storage = ['used' => (int) $item->used, 'reserved' => (int) $item->reserved, 'quota' => $quota, 'percent' => $percent];
                }
            }
            if ($tenantId !== null && $storage === null) {
                $setting = RecordingStorageSetting::query()->where('tenant_id', $tenantId)->first();
                $storage = ['used' => 0, 'reserved' => 0, 'quota' => min($setting?->quota_mb ?? config('voip.recordings.quota_mb'), config('voip.recordings.max_quota_mb')) * 1048576, 'percent' => 0];
            }
            if ($nearCapacity > 0) {
                $canManage = $user->hasPermission(Permissions::RECORDINGS_MANAGE);
                $alerts[] = ['title' => $tenantId === null ? "فضای ضبط {$nearCapacity} مشتری رو به اتمام است" : 'فضای ضبط رو به اتمام است',
                    'description' => 'مصرف و فضای رزروشده به ۸۰٪ ظرفیت یا بیشتر رسیده است.',
                    'url' => route($canManage ? 'recordings.settings' : 'recordings.index'), 'action' => $canManage ? 'مدیریت فضای ضبط' : 'مشاهده ضبط‌ها', 'tone' => 'amber'];
            }
        }

        return ['alerts' => $alerts, 'storage' => $storage];
    }
}

<?php

namespace App\Services;

use App\Models\CallQueue;
use App\Models\SipExtension;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FollowMeService
{
    public const MAX_TARGETS = 3;

    public const PRIMARY_SECONDS = 15;

    public function save(Request $request, SipExtension|CallQueue $source): void
    {
        $allowed = Rule::exists('sip_extensions', 'id')->where('tenant_id', $source->tenant_id);
        if ($source instanceof SipExtension) {
            $allowed->whereNot('id', $source->id);
        }

        $data = $request->validate([
            'follow_me_enabled' => ['required', 'boolean'],
            'follow_me_targets' => ['nullable', 'array', 'list', 'max:'.self::MAX_TARGETS],
            'follow_me_targets.*' => ['array:id,seconds'],
            'follow_me_targets.*.id' => ['nullable', 'integer', 'distinct', $allowed],
            'follow_me_targets.*.seconds' => ['required_with:follow_me_targets.*.id', 'nullable', 'integer', 'between:5,30'],
        ], [
            'follow_me_targets.*.id.distinct' => 'هر داخلی را فقط یک بار انتخاب کنید.',
            'follow_me_targets.*.id.exists' => 'یک داخلی دیگر از همین مجموعه انتخاب کنید.',
            'follow_me_targets.*.seconds.required_with' => 'زمان زنگ مقصد انتخاب‌شده را وارد کنید.',
            'follow_me_targets.*.seconds.between' => 'زمان زنگ هر داخلی باید بین ۵ تا ۳۰ ثانیه باشد.',
        ]);

        $targets = [];
        foreach ($data['follow_me_targets'] ?? [] as $target) {
            if (! empty($target['id'])) {
                $targets[] = ['id' => (int) $target['id'], 'seconds' => (int) $target['seconds']];
            }
        }

        if ($data['follow_me_enabled'] && $targets === []) {
            throw ValidationException::withMessages(['follow_me_targets' => 'برای فعال‌سازی، دست‌کم یک داخلی پشتیبان انتخاب کنید.']);
        }

        $source->forceFill(['follow_me' => ['enabled' => (bool) $data['follow_me_enabled'], 'targets' => $targets]])->save();
        Log::info('Follow Me settings updated', ['type' => $source->getTable(), 'id' => $source->id, 'tenant_id' => $source->tenant_id]);
    }

    /** @return Collection<int, array{extension: SipExtension, seconds: int}> */
    public function targets(SipExtension|CallQueue $source): Collection
    {
        $configured = $source->follow_me['targets'] ?? [];
        $targets = collect(is_array($configured) ? array_slice($configured, 0, self::MAX_TARGETS) : [])
            ->filter(fn ($target) => is_array($target) && is_int($target['id'] ?? null)
                && is_int($target['seconds'] ?? null) && $target['seconds'] >= 5 && $target['seconds'] <= 30)
            ->unique('id');

        $extensions = SipExtension::query()->where('tenant_id', $source->tenant_id)
            ->when($source instanceof SipExtension, fn ($query) => $query->whereKeyNot($source->id))
            ->where('enabled', true)->whereNull('paused_at')->whereIn('id', $targets->pluck('id'))->get()->keyBy('id');

        return $targets->filter(fn ($target) => $extensions->has($target['id']))
            ->map(fn ($target) => ['extension' => $extensions[$target['id']], 'seconds' => $target['seconds']]);
    }
}

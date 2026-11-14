<?php

namespace App\Services;

use App\Models\CallQueue;
use App\Models\IvrMenu;
use App\Models\SipExtension;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class FollowMeService
{
    public const MAX_TARGETS = 10;

    public const MAX_DEPTH = 5;

    public const MAX_ATTEMPTS = 25;

    public const MAX_SECONDS = 900;

    public const PRIMARY_SECONDS = 15;

    public function catalog(int $tenantId): Collection
    {
        return collect([
            ...SipExtension::query()->where('tenant_id', $tenantId)->orderBy('extension')->get(),
            ...CallQueue::query()->where('tenant_id', $tenantId)->orderBy('name')->get(),
            ...IvrMenu::query()->where('tenant_id', $tenantId)->orderBy('name')->get(),
        ])->keyBy($this->key(...));
    }

    public function key(SipExtension|CallQueue|IvrMenu $destination): string
    {
        $type = match (true) {
            $destination instanceof SipExtension => 'extension',
            $destination instanceof CallQueue => 'queue',
            default => 'ivr',
        };

        return $type.':'.$destination->id;
    }

    public function label(SipExtension|CallQueue|IvrMenu $destination): string
    {
        return $destination instanceof SipExtension
            ? trim(($destination->display_name ?: 'داخلی').' · '.$destination->extension)
            : $destination->name;
    }

    public function available(SipExtension|CallQueue|IvrMenu $destination): bool
    {
        return $destination->enabled && match (true) {
            $destination instanceof IvrMenu => $destination->isPublished(),
            $destination instanceof CallQueue => (bool) config('voip.queues_enabled'),
            default => true,
        };
    }

    public function save(Request $request, SipExtension|CallQueue $source): void
    {
        $data = $request->validate([
            'follow_me_enabled' => ['required', 'boolean'],
            'follow_me_targets' => ['nullable', 'array', 'list', 'max:'.self::MAX_TARGETS],
            'follow_me_targets.*' => ['array:id,destination,seconds'],
            'follow_me_targets.*.id' => ['nullable', 'integer'],
            'follow_me_targets.*.destination' => ['nullable', 'string', 'regex:/^(extension|queue|ivr):[1-9][0-9]{0,17}$/D'],
            'follow_me_targets.*.seconds' => ['nullable', 'integer', 'between:5,30'],
        ], [
            'follow_me_targets.max' => 'حداکثر ۱۰ مقصد پشتیبان انتخاب کنید.',
            'follow_me_targets.*.seconds.between' => 'زمان زنگ هر داخلی باید بین ۵ تا ۳۰ ثانیه باشد.',
            'follow_me_targets.*.destination.regex' => 'یک مقصد معتبر انتخاب کنید.',
        ]);

        DB::transaction(function () use ($source, $data): void {
            Tenant::query()->lockForUpdate()->findOrFail($source->tenant_id);
            $source->refresh();
            $catalog = $this->catalog($source->tenant_id);
            $targets = [];
            $savedKeys = $this->steps($source)->map($this->targetKey(...));
            foreach ($data['follow_me_targets'] ?? [] as $index => $target) {
                $key = $this->targetKey($target);
                if ($key === null) {
                    continue;
                }
                $legacy = empty($target['destination']);
                $field = 'follow_me_targets.'.$index.($legacy ? '.id' : '.destination');
                if (isset($targets[$key])) {
                    throw ValidationException::withMessages([$field => $legacy ? 'هر داخلی را فقط یک بار انتخاب کنید.' : 'هر مقصد را فقط یک بار انتخاب کنید.']);
                }
                $destination = $catalog->get($key);
                if ($key === $this->key($source) || (! $destination && ! $savedKeys->contains($key))
                    || ($destination && ! $this->available($destination) && ! $savedKeys->contains($key))) {
                    throw ValidationException::withMessages([$field => 'یک مقصد فعال دیگر از همین مجموعه انتخاب کنید.']);
                }
                $isExtension = str_starts_with($key, 'extension:');
                if ($isExtension && ! isset($target['seconds'])) {
                    throw ValidationException::withMessages(['follow_me_targets.'.$index.'.seconds' => 'زمان زنگ داخلی را وارد کنید.']);
                }
                $targets[$key] = $legacy ? ['id' => (int) $target['id'], 'seconds' => (int) $target['seconds']]
                    : ['destination' => $key] + ($isExtension ? ['seconds' => (int) $target['seconds']] : []);
            }
            if ($data['follow_me_enabled'] && $targets === []) {
                throw ValidationException::withMessages(['follow_me_targets' => 'برای فعال‌سازی، دست‌کم یک مقصد پشتیبان انتخاب کنید.']);
            }

            $source->forceFill(['follow_me' => ['enabled' => (bool) $data['follow_me_enabled'], 'targets' => array_values($targets)]]);
            $this->assertGraph($source, 'follow_me_targets', $catalog);
            $source->save();
        }, 3);

        Log::info('Follow Me settings updated', ['type' => $source->getTable(), 'id' => $source->id, 'tenant_id' => $source->tenant_id]);
    }

    public function targetKey(mixed $target): ?string
    {
        if (! is_array($target)) {
            return null;
        }
        $key = $target['destination'] ?? (isset($target['id']) && is_numeric($target['id']) ? 'extension:'.$target['id'] : null);

        return is_string($key) && preg_match('/^(extension|queue|ivr):[1-9][0-9]{0,17}$/D', $key) ? $key : null;
    }

    public function steps(SipExtension|CallQueue $source): Collection
    {
        $targets = $source->follow_me['targets'] ?? [];

        return collect(is_array($targets) ? array_slice($targets, 0, self::MAX_TARGETS) : [])
            ->filter(fn ($target) => $this->targetKey($target) !== null)
            ->unique($this->targetKey(...))->values();
    }

    public function edges(SipExtension|CallQueue|IvrMenu $destination): array
    {
        if ($destination instanceof IvrMenu) {
            $config = $destination->published_config ?? [];
            $edges = collect($config['choices'] ?? [])->pluck('destination')->all();
            $edges[] = $config['fallback'] ?? null;
            if ($config['direct_dial_enabled'] ?? false) {
                foreach ($config['direct_dial_extension_ids'] ?? [] as $id) {
                    $edges[] = 'extension:'.$id;
                }
            }

            return array_values(array_unique(array_filter($edges, fn ($key) => is_string($key) && $this->targetKey(['destination' => $key]))));
        }
        if ($destination->follow_me['enabled'] ?? false) {
            return $this->steps($destination)->map($this->targetKey(...))->all();
        }

        return $destination instanceof CallQueue && $destination->fallback_extension_id
            ? ['extension:'.$destination->fallback_extension_id] : [];
    }

    public function assertGraph(SipExtension|CallQueue|IvrMenu $source, string $field, ?Collection $catalog = null): void
    {
        $catalog ??= $this->catalog($source->tenant_id);
        $catalog->put($this->key($source), $source);
        $longest = [];
        $walk = function (string $key, array $path) use (&$walk, &$longest, $catalog, $field): int {
            $node = $catalog->get($key);
            if (! $node) {
                return 0;
            }
            if (in_array($key, $path, true)) {
                $labels = array_map(fn ($item) => $this->label($catalog[$item]), [...$path, $key]);
                throw ValidationException::withMessages([$field => 'این مسیر تکرار می‌شود: '.implode(' ← ', $labels)]);
            }
            if (isset($longest[$key])) {
                return $longest[$key];
            }
            if (count($path) >= self::MAX_DEPTH + 1) {
                throw ValidationException::withMessages([$field => 'مسیر پشتیبان بیش از حد تو در تو است. یک مرحله را حذف کنید.']);
            }
            $length = 1;
            foreach ($this->edges($node) as $edge) {
                $length = max($length, 1 + $walk($edge, [...$path, $key]));
            }
            if ($length > self::MAX_DEPTH + 1) {
                throw ValidationException::withMessages([$field => 'مسیر پشتیبان بیش از حد تو در تو است. یک مرحله را حذف کنید.']);
            }

            return $longest[$key] = $length;
        };
        foreach ($catalog->keys() as $key) {
            $walk($key, []);
        }
    }

    public function claimAttempt(string $uuid, string $destination, int $deadline): bool
    {
        $key = 'voip:follow-me:'.$uuid;
        $expires = now()->addSeconds(max(1, $deadline - time()));
        if (! Cache::add($key.':'.$destination, true, $expires)) {
            return false;
        }
        Cache::add($key.':attempts', 0, $expires);
        $attempts = Cache::increment($key.':attempts');

        return is_int($attempts) && $attempts <= self::MAX_ATTEMPTS;
    }

    public function resolve(int $tenantId, string $key): SipExtension|CallQueue|IvrMenu|null
    {
        if ($this->targetKey(['destination' => $key]) === null) {
            return null;
        }
        [$type, $id] = explode(':', $key);
        $model = match ($type) {
            'extension' => SipExtension::class,
            'queue' => CallQueue::class,
            'ivr' => IvrMenu::class,
        };
        $target = $model::query()->where('tenant_id', $tenantId)->find($id);

        return $target && $this->available($target) ? $target : null;
    }
}

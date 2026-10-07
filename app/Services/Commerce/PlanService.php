<?php

namespace App\Services\Commerce;

use App\Models\NumberOffer;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlanService
{
    public function __construct(private CommerceAudit $audit) {}

    public function create(User $actor, string $name): Plan
    {
        return DB::transaction(function () use ($actor, $name) {
            $plan = Plan::query()->create(['name' => $name]);
            $this->audit->record($actor, 'plan.created', 'plan', $plan->id, 'Plan created');

            return $plan;
        });
    }

    public function version(User $actor, int $id, array $limits): PlanVersion
    {
        return DB::transaction(function () use ($actor, $id, $limits) {
            $plan = Plan::query()->lockForUpdate()->findOrFail($id);
            if ($plan->archived || count($limits) !== 3 || array_diff(['extensions', 'queues', 'ivr_menus'], array_keys($limits)) !== []
                || count(array_filter($limits, fn ($limit) => filter_var($limit, FILTER_VALIDATE_INT) !== false && $limit >= 1 && $limit <= 10000)) !== 3) {
                throw ValidationException::withMessages(['plan' => 'پلن فعال و سقف‌های صحیح و مثبت لازم است.']);
            }
            $version = $plan->versions()->create([
                'version' => ($plan->versions()->max('version') ?? 0) + 1,
                'features' => ['extensions', 'schedules', 'ivr', 'queues'],
                'limits' => array_map('intval', $limits), 'limit_scope' => 'tenant', 'billing_interval' => 'monthly',
            ]);
            $this->audit->record($actor, 'plan.version_created', 'plan_version', $version->id, 'Inclusive monthly plan draft created');

            return $version;
        });
    }

    public function editDraft(User $actor, int $id, array $limits): void
    {
        DB::transaction(function () use ($actor, $id, $limits) {
            $planId = PlanVersion::query()->findOrFail($id)->plan_id;
            $plan = Plan::query()->lockForUpdate()->findOrFail($planId);
            $version = PlanVersion::query()->lockForUpdate()->findOrFail($id);
            if ($plan->archived || $version->published_at !== null || count($limits) !== 3
                || array_diff(['extensions', 'queues', 'ivr_menus'], array_keys($limits)) !== []
                || count(array_filter($limits, fn ($limit) => filter_var($limit, FILTER_VALIDATE_INT) !== false && $limit >= 1 && $limit <= 10000)) !== 3) {
                throw ValidationException::withMessages(['plan' => 'فقط نسخه پیش‌نویس با سقف‌های صحیح و مثبت قابل ویرایش است.']);
            }
            $version->update(['limits' => array_map('intval', $limits)]);
            $this->audit->record($actor, 'plan.draft_updated', 'plan_version', $id, 'Draft limits edited');
        });
    }

    public function publish(User $actor, int $id, ?string $fingerprint = null): void
    {
        $planId = PlanVersion::query()->findOrFail($id)->plan_id;
        DB::transaction(function () use ($actor, $id, $planId, $fingerprint) {
            $plan = Plan::query()->lockForUpdate()->findOrFail($planId);
            $version = PlanVersion::query()->lockForUpdate()->findOrFail($id);
            if ($plan->archived || $version->published_at !== null) {
                throw ValidationException::withMessages(['plan' => 'این نسخه قابل انتشار نیست.']);
            }
            if ($fingerprint !== null && ! hash_equals(hash('sha256', json_encode($version->limits)), $fingerprint)) {
                throw ValidationException::withMessages(['plan' => 'پیش‌نویس تغییر کرده است؛ به صفحه پلن برگردید و دوباره مرور کنید.']);
            }
            $version->update(['published_at' => now()]);
            $this->audit->record($actor, 'plan.published', 'plan_version', $id, 'Plan version published');
        });
    }

    public function archive(User $actor, int $id, string $reason): void
    {
        DB::transaction(function () use ($actor, $id, $reason) {
            $plan = Plan::query()->lockForUpdate()->findOrFail($id);
            if (NumberOffer::query()->whereIn('plan_version_id', $plan->versions()->select('id'))->whereNull('withdrawn_at')->exists()) {
                throw ValidationException::withMessages(['plan' => 'ابتدا پیشنهادهای منتشرشده این پلن را بردارید.']);
            }
            $plan->update(['archived' => true]);
            $this->audit->record($actor, 'plan.archived', 'plan', $id, $reason);
        });
    }
}

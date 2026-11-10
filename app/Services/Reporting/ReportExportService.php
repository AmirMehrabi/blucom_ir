<?php

namespace App\Services\Reporting;

use App\Models\CallRecord;
use App\Models\Customer;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\CallOutcomeClassifier;
use App\Services\CallReportFilterService;
use App\Services\TenantService;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ReportExportService
{
    public function __construct(private CallReportFilterService $filters, private TenantService $tenants, private ExcelReportWriter $writer) {}

    public function ownerType(User|Customer $user): string
    {
        return $user instanceof Customer ? 'customer' : 'user';
    }

    public function request(User|Customer $user, array $filters): ReportExport
    {
        abort_if($user->isAdmin(), 403);
        abort_unless($user->hasPermission(Permissions::CALLS_VIEW) && $user->hasPermission(Permissions::REPORTS_EXPORT), 403);
        $tenant = $this->tenants->forUser($user);
        $this->filters->validateOwnership($tenant->id, $filters);
        // Validate hour/date combinations before accepting a background request.
        $this->filters->applyWindow(CallRecord::query(), $filters);

        return DB::transaction(function () use ($user, $tenant, $filters) {
            $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $active = ReportExport::query()->where('requester_type', $this->ownerType($user))->where('requester_id', $user->id)
                ->whereIn('status', ['pending', 'processing'])->where('expires_at', '>', now())->count();
            if ($active >= 3) {
                throw ValidationException::withMessages(['export' => 'سه خروجی در حال آماده‌سازی دارید. پس از آماده شدن آن‌ها دوباره تلاش کنید.']);
            }
            $export = ReportExport::query()->create([
                'tenant_id' => $tenant->id, 'requester_type' => $this->ownerType($user), 'requester_id' => $user->id,
                'filters' => $filters, 'snapshot_at' => now(), 'metric_version' => CallOutcomeClassifier::VERSION,
                'max_call_id' => CallRecord::query()->where('tenant_id', $tenant->id)->max('id') ?? 0,
                'expires_at' => now()->addDay(),
            ]);
            Log::info('Call report export requested', ['export_id' => $export->id, 'tenant_id' => $tenant->id]);

            return $export;
        });
    }

    public function process(ReportExport $export): void
    {
        $path = $export->tenant_id.'/'.Str::uuid().'.xlsx';
        if ($export->expires_at->isPast() || ! ReportExport::query()->whereKey($export->id)->where('status', 'pending')->update(['status' => 'processing', 'path' => $path, 'updated_at' => now()])) {
            return;
        }
        $disk = Storage::disk('report_exports');
        try {
            $model = match ($export->requester_type) {
                'customer' => Customer::class, 'user' => User::class, default => null
            };
            $user = $model ? $model::find($export->requester_id) : null;
            abort_unless($user && ! $user->isAdmin() && $user->hasPermission(Permissions::CALLS_VIEW)
                && $user->hasPermission(Permissions::REPORTS_EXPORT) && $this->tenants->forUser($user)->id === $export->tenant_id, 403);
            $this->filters->validateOwnership($export->tenant_id, $export->filters);
            $query = $this->filters->applyWindow($this->filters->apply(CallRecord::query(), $export->tenant_id, $export->filters), $export->filters, $export->snapshot_at)
                ->where('id', '<=', $export->max_call_id);
            $disk->makeDirectory((string) $export->tenant_id);
            $rowCount = 0;
            $timezone = config('voip.display_timezone', 'Asia/Tehran');
            $outcomes = (clone $query)->selectRaw(CallOutcomeClassifier::expression().' AS result, COUNT(*) AS total')->groupBy('result')->pluck('total', 'result');
            if ($outcomes->sum() > 1048575) {
                throw new \RangeException('Excel row limit');
            }
            $metadata = [
                ['گزارش تماس‌های بلوکام', 'مقدار'],
                ['زمان درخواست · تهران', $export->snapshot_at->setTimezone($timezone)->format('Y-m-d H:i:s')],
                ['منطقه زمانی', $timezone], ['نسخه تعریف نتیجه', $export->metric_version],
                ['محدوده داده', 'تماس‌های ثبت‌شده تا زمان درخواست؛ تغییرات بعدی اطلاعات ممکن است منعکس شود.'],
                ['مدت پاسخ شبکه', 'شامل پاسخ خودکار می‌شود؛ معادل مکالمه انسانی نیست.'],
            ];
            $filterLabels = ['range' => 'بازه', 'from' => 'از تاریخ', 'to' => 'تا تاریخ', 'hour' => 'ساعت', 'phone' => 'مخاطب', 'number' => 'شناسه خط', 'team' => 'شناسه تیم', 'extension' => 'شناسه داخلی', 'direction' => 'جهت', 'status' => 'وضعیت سابق', 'outcome' => 'نتیجه'];
            foreach ($export->filters as $key => $value) {
                if ($value !== null && $value !== '') {
                    $metadata[] = [$filterLabels[$key] ?? $key, (string) $value];
                }
            }
            $counts = [];
            $rows = function () use ($query, $timezone, &$rowCount, &$counts) {
                yield ['زمان شروع · تهران', 'جهت', 'مبدأ', 'مقصد', 'نتیجه نهایی', 'شواهد پاسخ', 'داخلی', 'خط', 'مدت کل · ثانیه', 'مدت پاسخ شبکه · ثانیه', 'انتظار صف · ثانیه'];
                foreach ($query->with(['sipExtension:id,tenant_id,extension', 'sipNumber:id,tenant_id,number'])->orderBy('id')->lazyById(500) as $call) {
                    $rowCount++;
                    $key = $call->reportingOutcome();
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                    yield [$call->started_at->setTimezone($timezone)->format('Y-m-d H:i:s'), $call->direction === 'inbound' ? 'ورودی' : 'خروجی',
                        $call->source_number ?? '', $call->destination_number ?? '', $call->outcomeLabel(), $call->answerEvidenceLabel(),
                        $call->sipExtension?->tenant_id === $call->tenant_id ? (string) $call->sipExtension->extension : '',
                        $call->sipNumber?->tenant_id === $call->tenant_id ? (string) $call->sipNumber->number : '',
                        $call->duration_seconds, $call->billable_seconds, $call->queue_wait_seconds ?? ''];
                }
            };
            $sheets = function () use ($rows, $metadata, &$counts, &$rowCount) {
                yield 'تماس‌ها' => $rows();
                // Summary is derived from the exported rows, even if facts change during generation.
                $summary = $metadata;
                $summary[] = ['کل تماس‌ها', $rowCount];
                foreach (CallOutcomeClassifier::LABELS as $key => $label) {
                    $summary[] = [$label, $counts[$key] ?? 0];
                }
                yield 'خلاصه و فیلترها' => $summary;
            };
            $this->writer->write($disk->path($path), $sheets());
            $completed = ReportExport::query()->whereKey($export->id)->where('status', 'processing')->where('path', $path)
                ->where('expires_at', '>', now())->update(['status' => 'ready', 'row_count' => $rowCount, 'updated_at' => now()]);
            if (! $completed) {
                $disk->delete($path);

                return;
            }
            Log::info('Call report export ready', ['export_id' => $export->id, 'rows' => $rowCount]);
        } catch (Throwable $exception) {
            $disk->delete($path);
            $export->update(['status' => 'failed', 'path' => null, 'failure_code' => $exception instanceof \RangeException ? 'too_large' : ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 403 ? 'access_changed' : 'generation_failed')]);
            // Never include the exception message, query bindings or call payloads.
            Log::warning('Call report export failed', ['export_id' => $export->id, 'error_type' => $exception::class]);
        }
    }
}

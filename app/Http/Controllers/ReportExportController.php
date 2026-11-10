<?php

namespace App\Http\Controllers;

use App\Models\ReportExport;
use App\Services\CallReportFilterService;
use App\Services\Reporting\ReportExportService;
use App\Services\TenantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ReportExportController extends Controller
{
    private function scope(Request $request, ReportExportService $service, TenantService $tenants)
    {
        abort_if($request->user()->isAdmin(), 403);

        return ReportExport::query()->where('tenant_id', $tenants->forUser($request->user())->id)
            ->where('requester_type', $service->ownerType($request->user()))->where('requester_id', $request->user()->id);
    }

    public function index(Request $request, ReportExportService $service, TenantService $tenants)
    {
        $exports = $this->scope($request, $service, $tenants)->orderByDesc('id')->paginate(10);
        if ($request->expectsJson()) {
            return response()->json(['state' => $exports->map(fn ($export) => [$export->id, $export->status, $export->expires_at->isPast()])->all()])->header('Cache-Control', 'private, no-store');
        }

        return view('reports.exports', ['exports' => $exports]);
    }

    public function store(Request $request, ReportExportService $service, CallReportFilterService $filters)
    {
        $service->request($request->user(), $filters->validated($request));

        return redirect()->route('reports.exports.index')->with('status', 'درخواست خروجی اکسل ثبت شد. می‌توانید این صفحه را ببندید؛ فایل پس از آماده شدن تا ۲۴ ساعت از زمان درخواست در دسترس است.');
    }

    public function download(Request $request, int $export, ReportExportService $service, TenantService $tenants)
    {
        $record = $this->scope($request, $service, $tenants)->findOrFail($export);
        abort_if($record->expires_at->isPast(), 410);
        abort_unless($record->status === 'ready' && $record->path && Storage::disk('report_exports')->exists($record->path), 404);
        Log::info('Call report export downloaded', ['export_id' => $record->id, 'tenant_id' => $record->tenant_id]);

        return response()->download(Storage::disk('report_exports')->path($record->path), 'blucom-calls-'.$record->id.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

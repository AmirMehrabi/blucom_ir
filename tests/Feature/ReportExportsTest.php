<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\CallRecord;
use App\Models\ReportExport;
use App\Models\SipExtension;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CustomerAccountService;
use App\Services\Reporting\ReportExportService;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class ReportExportsTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        foreach ([Permissions::CALLS_VIEW, Permissions::REPORTS_EXPORT] as $permission) {
            $user->permissions()->create(['permission' => $permission]);
        }

        return $user;
    }

    private function recordCall(Tenant $tenant, array $data = []): CallRecord
    {
        return CallRecord::query()->create($data + ['tenant_id' => $tenant->id, 'freeswitch_uuid' => (string) Str::uuid(),
            'direction' => 'inbound', 'status' => 'failed', 'outcome' => 'busy', 'answer_evidence' => 'none', 'outcome_version' => 1,
            'source_number' => '+989121234567', 'destination_number' => '001234567890', 'started_at' => now()->subMinute(),
            'duration_seconds' => 10, 'billable_seconds' => 0]);
    }

    public function test_background_xlsx_is_filtered_private_and_uses_literal_phone_cells(): void
    {
        Storage::fake('report_exports');
        $tenant = Tenant::factory()->create();
        $user = $this->viewer($tenant);
        $this->recordCall($tenant, ['source_number' => '=HYPERLINK("https://example.invalid")']);
        $this->recordCall($tenant, ['outcome' => 'no_answer']);
        $this->recordCall(Tenant::factory()->create(), ['source_number' => 'FOREIGN_SECRET']);
        $this->actingAs($user)->post('/reports/exports', ['range' => 'all', 'outcome' => 'busy'])->assertRedirect(route('reports.exports.index'));
        $export = ReportExport::query()->sole();
        $this->assertSame('pending', $export->status);
        // New arrivals must not appear in an already requested export.
        $this->recordCall($tenant, ['source_number' => 'AFTER_SNAPSHOT']);
        $this->artisan('reports:process-exports')->assertSuccessful();
        $export->refresh();
        $this->assertSame('ready', $export->status);
        $this->assertSame(1, $export->row_count);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('report_exports')->path($export->path)));
        foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/styles.xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml'] as $part) {
            $xml = $zip->getFromName($part);
            $this->assertNotFalse($xml);
            $this->assertNotFalse(simplexml_load_string($xml));
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('=HYPERLINK', $sheet);
        $this->assertStringContainsString('t="inlineStr"', $sheet);
        $this->assertStringContainsString('001234567890', $sheet);
        $this->assertStringNotContainsString('<f', $sheet);
        $this->assertStringNotContainsString('FOREIGN_SECRET', $sheet);
        $this->assertStringNotContainsString('AFTER_SNAPSHOT', $sheet);
        $this->assertStringContainsString('مشغول', $sheet);
        $this->assertStringContainsString('فیلترها', $zip->getFromName('xl/workbook.xml'));
        $zip->close();
        $this->get('/reports/exports')->assertOk()->assertSee('آماده دانلود');
        $this->get('/reports/exports/'.$export->id.'/download')->assertOk()->assertDownload('blucom-calls-'.$export->id.'.xlsx');
    }

    public function test_download_and_processing_recheck_owner_and_permissions(): void
    {
        Storage::fake('report_exports');
        $tenant = Tenant::factory()->create();
        $user = $this->viewer($tenant);
        $this->recordCall($tenant);
        $service = app(ReportExportService::class);
        $export = $service->request($user, ['range' => 'all']);
        $user->permissions()->where('permission', Permissions::REPORTS_EXPORT)->delete();
        $service->process($export);
        $this->assertSame('failed', $export->fresh()->status);
        $this->actingAs($user)->get('/reports/exports/'.$export->id.'/download')->assertForbidden();
        $other = $this->viewer(Tenant::factory()->create());
        $this->actingAs($other)->get('/reports/exports/'.$export->id.'/download')->assertNotFound();
        $this->get('/reports/exports')->assertOk()->assertViewHas('exports', fn ($exports) => $exports->total() === 0);
    }

    public function test_customer_guard_export_does_not_collide_with_legacy_user_id(): void
    {
        Storage::fake('report_exports');
        $customer = app(CustomerAccountService::class)->createOwner('Owner', '+989120000091', 'Report customer');
        $legacy = User::query()->find($customer->id) ?? User::factory()->create(['id' => $customer->id]);
        $legacy->update(['tenant_id' => Tenant::factory()->create()->id, 'user_type' => UserType::Operator]);
        foreach ([Permissions::CALLS_VIEW, Permissions::REPORTS_EXPORT] as $permission) {
            $legacy->permissions()->firstOrCreate(['permission' => $permission]);
        }
        $this->assertSame($customer->id, $legacy->id);
        $this->recordCall($customer->tenant);
        $this->actingAs($customer, 'customer')->post('https://my.blucom.ir/reports/exports', ['range' => 'all'])->assertRedirect();
        $export = ReportExport::query()->sole();
        $this->assertSame('customer', $export->requester_type);
        app(ReportExportService::class)->process($export);
        $this->assertSame('ready', $export->fresh()->status);
        $this->get('https://my.blucom.ir/reports/exports/'.$export->id.'/download')->assertOk();
        $this->actingAs($legacy, 'web')->get('https://admin.blucom.ir/reports/exports/'.$export->id.'/download')->assertNotFound();
    }

    public function test_exports_expire_and_files_are_deleted(): void
    {
        Storage::fake('report_exports');
        $tenant = Tenant::factory()->create();
        $user = $this->viewer($tenant);
        $export = app(ReportExportService::class)->request($user, ['range' => 'all']);
        app(ReportExportService::class)->process($export);
        $path = $export->fresh()->path;
        $this->travel(25)->hours();
        $this->actingAs($user)->get('/reports/exports/'.$export->id.'/download')->assertGone();
        $this->artisan('reports:process-exports')->assertSuccessful();
        Storage::disk('report_exports')->assertMissing($path);
        $this->assertSame('expired', $export->fresh()->status);
    }

    public function test_export_cannot_forge_owned_filters_or_exceed_pending_limit(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->viewer($tenant);
        $foreign = SipExtension::factory()->create();
        $this->actingAs($user)->post('/reports/exports', ['range' => 'all', 'extension' => $foreign->id])->assertNotFound();
        $this->postJson('/reports/exports', ['range' => 'all', 'hour' => 2])->assertUnprocessable();
        for ($i = 0; $i < 3; $i++) {
            $this->post('/reports/exports', ['range' => 'all'])->assertRedirect();
        }
        $this->postJson('/reports/exports', ['range' => 'all'])->assertUnprocessable();
        $this->assertDatabaseCount('report_exports', 3);
    }

    public function test_relative_period_is_frozen_when_export_runs_after_midnight(): void
    {
        Storage::fake('report_exports');
        $this->travelTo(CarbonImmutable::parse('2026-11-10 23:59:00', 'Asia/Tehran'));
        $tenant = Tenant::factory()->create();
        $user = $this->viewer($tenant);
        $this->recordCall($tenant);
        $export = app(ReportExportService::class)->request($user, ['range' => '1d']);
        $this->travel(2)->minutes();
        app(ReportExportService::class)->process($export);
        $this->assertSame('ready', $export->fresh()->status);
        $this->assertSame(1, $export->fresh()->row_count);
        $colleague = $this->viewer($tenant);
        $this->actingAs($colleague)->get('/reports/exports/'.$export->id.'/download')->assertNotFound();
    }

    public function test_stale_job_cleanup_removes_partial_workbooks_and_worksheets(): void
    {
        Storage::fake('report_exports');
        $tenant = Tenant::factory()->create();
        $user = $this->viewer($tenant);
        $export = app(ReportExportService::class)->request($user, ['range' => 'all']);
        $path = $tenant->id.'/partial.xlsx';
        Storage::disk('report_exports')->put($path, 'partial archive');
        Storage::disk('report_exports')->put($path.'.sheet-test', 'partial worksheet');
        $export->update(['status' => 'processing', 'path' => $path]);
        $this->travel(31)->minutes();
        $this->artisan('reports:process-exports')->assertSuccessful();
        $this->assertSame('failed', $export->fresh()->status);
        $this->assertSame('timed_out', $export->fresh()->failure_code);
        Storage::disk('report_exports')->assertMissing($path);
        Storage::disk('report_exports')->assertMissing($path.'.sheet-test');
    }

    public function test_outcome_filter_overview_and_list_reconcile(): void
    {
        $tenant = Tenant::factory()->create();
        $this->recordCall($tenant);
        $this->recordCall($tenant, ['outcome' => 'answered', 'status' => 'answered']);
        $this->recordCall(Tenant::factory()->create());
        $this->actingAs($this->viewer($tenant))->get('/reports?range=all&outcome=busy')->assertOk()
            ->assertViewHas('stats', fn ($stats) => (int) $stats->total === 1)
            ->assertViewHas('outcomeCounts', fn ($counts) => (int) $counts['busy'] === 1 && $counts->sum() === 1);
        $this->get('/calls?range=all&outcome=busy')->assertOk()->assertViewHas('calls', fn ($calls) => $calls->total() === 1);
    }
}

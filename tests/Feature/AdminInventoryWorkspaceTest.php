<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\NumberOffer;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\User;
use App\Services\Commerce\NumberInventoryService;
use App\Services\Commerce\NumberOfferService;
use App\Services\Commerce\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AdminInventoryWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function prepare(): array
    {
        config(['commerce.separated_billing_enabled' => false]);
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $gateway = SipGateway::factory()->create(['tenant_id' => null, 'verification_status' => SipGateway::STATUS_APPROVED, 'approved_for_outbound' => true]);
        $settings = ['provider_gateway_id' => $gateway->id, 'enabled' => true, 'inbound_enabled' => true, 'outbound_enabled' => true, 'destination_prefixes' => ['+989'], 'destination_prefixes_text' => '+989'];
        $plans = app(PlanService::class);
        $plan = $plans->create($admin, 'Inventory plan');
        $version = $plans->version($admin, $plan->id, ['extensions' => 5, 'queues' => 2, 'ivr_menus' => 2]);
        $plans->publish($admin, $version->id);
        $this->actingAs($admin);

        return [$admin, $settings, $version];
    }

    private function stock(User $admin, array $settings, string $number = '+982155501234'): SipNumber
    {
        $number = app(NumberInventoryService::class)->create($admin, $settings + ['number' => $number]);
        app(NumberInventoryService::class)->review($admin, $number->id, $number->inventory_revision, 'Realistic test verification evidence');

        return $number->fresh();
    }

    private function applyBulk(array $data)
    {
        $this->post('/admin/inventory/bulk-preview', $data)->assertOk();

        return $this->post('/admin/inventory/bulk', ['preview_token' => session('inventory_bulk_preview.token')]);
    }

    public function test_import_preview_normalizes_and_marks_duplicates_without_writing(): void
    {
        [$admin, $settings] = $this->prepare();
        $existing = $this->stock($admin, $settings);
        $response = $this->post('/admin/inventory/import-preview', $settings + ['numbers_text' => "number,label\n۰۲۱۵۵۵۰۱۲۳۵,جدید\n+982155501235,duplicate\ninvalid\n{$existing->normalized_number}"]);
        $response->assertOk()->assertSee('+982155501235')->assertSee('تکراری در همین فهرست')->assertSee('قبلاً ثبت شده است')->assertSee('شماره معتبر نیست');
        $this->assertDatabaseCount('sip_numbers', 1);
        $this->assertCount(4, session('inventory_import.rows'));
    }

    public function test_import_creates_only_selected_valid_rows_and_preserves_draft_price(): void
    {
        [$admin, $settings, $version] = $this->prepare();
        $this->post('/admin/inventory/import-preview', $settings + ['numbers_text' => "+982155501234\ninvalid\n+982155501235", 'amount' => 300000, 'plan_version_id' => $version->id])->assertOk();
        $token = session('inventory_import.token');
        $this->post('/admin/inventory/import', ['token' => $token, 'rows' => [0, 1], 'intent' => 'draft'])->assertRedirect(route('admin.inventory.index'));
        $number = SipNumber::query()->sole();
        $this->assertSame('draft', $number->inventory_state);
        $this->assertSame(300000, $number->inventory_sale_settings['amount']);
        $this->assertNull($number->tenant_id);
        $this->assertNull($number->current_offer_id);
        $this->post('/admin/inventory/import', ['token' => $token, 'rows' => [0], 'intent' => 'draft'])->assertRedirect();
        $this->assertDatabaseCount('sip_numbers', 1);
    }

    public function test_import_publish_is_atomic_and_needs_real_review_and_ready_settings(): void
    {
        [$admin, $settings, $version] = $this->prepare();
        $this->post('/admin/inventory/import-preview', $settings + ['numbers_text' => '+982155501234', 'amount' => 300000, 'plan_version_id' => $version->id])->assertOk();
        $data = ['token' => session('inventory_import.token'), 'rows' => [0], 'intent' => 'publish'];
        $this->post('/admin/inventory/import', $data)->assertSessionHasErrors('evidence');
        $this->assertDatabaseCount('sip_numbers', 0);
        $this->post('/admin/inventory/import', $data + ['evidence' => 'Technical validation for selected numbers'])->assertRedirect();
        $this->assertDatabaseCount('number_offers', 1);
        $this->assertSame('available', SipNumber::query()->sole()->inventory_state);
        $this->post('/admin/inventory/import-preview', array_replace($settings, ['numbers_text' => '+982155501235', 'destination_prefixes_text' => '', 'amount' => 300000, 'plan_version_id' => $version->id]))->assertOk();
        $this->post('/admin/inventory/import', ['token' => session('inventory_import.token'), 'rows' => [0], 'intent' => 'publish', 'evidence' => 'Technical validation for selected numbers'])->assertRedirect()->assertSessionHas('inventory_results.0.error');
        $this->assertDatabaseCount('sip_numbers', 1);
        $this->assertDatabaseCount('number_offers', 1);
    }

    public function test_bulk_preview_is_read_only_and_commit_rechecks_revision(): void
    {
        [$admin, $settings, $version] = $this->prepare();
        $number = $this->stock($admin, $settings);
        $data = ['selection' => [$number->id => $number->inventory_revision], 'action' => 'publish', 'amount' => 300000, 'plan_version_id' => $version->id];
        $this->post('/admin/inventory/bulk-preview', $data)->assertOk()->assertSee('قابل انجام');
        $this->assertDatabaseCount('number_offers', 0);
        app(NumberInventoryService::class)->change($admin, $number->id, $number->inventory_revision, 'update', $settings + ['label' => 'Changed concurrently']);
        $this->post('/admin/inventory/bulk', ['preview_token' => session('inventory_bulk_preview.token')])->assertRedirect()->assertSessionHas('inventory_results.0.error');
        $this->assertDatabaseCount('number_offers', 0);
    }

    public function test_bulk_handles_mixed_readiness_and_preserves_failed_selection(): void
    {
        [$admin, $settings, $version] = $this->prepare();
        $ready = $this->stock($admin, $settings);
        $draft = app(NumberInventoryService::class)->create($admin, $settings + ['number' => '+982155501235']);
        $data = ['selection' => [$ready->id => $ready->inventory_revision, $draft->id => $draft->inventory_revision], 'action' => 'publish', 'amount' => 300000, 'plan_version_id' => $version->id];
        $this->applyBulk($data)->assertRedirect()->assertSessionHas('inventory_selection.'.$draft->id);
        $this->assertNotNull($ready->fresh()->current_offer_id);
        $this->assertNull($draft->fresh()->current_offer_id);
        $this->assertDatabaseCount('number_offers', 1);
    }

    public function test_bulk_withdraw_and_republish_use_saved_price_without_new_review(): void
    {
        [$admin, $settings, $version] = $this->prepare();
        $number = $this->stock($admin, $settings);
        $offers = app(NumberOfferService::class);
        $first = $offers->publish($admin, $number->id, $number->inventory_revision, $version->id, 300000);
        $number->refresh();
        $fingerprint = $number->readiness_fingerprint;
        $this->applyBulk(['selection' => [$number->id => $number->inventory_revision], 'action' => 'withdraw', 'reason' => 'Temporarily unavailable'])->assertSessionHasNoErrors()->assertSessionHas('inventory_results', fn ($rows) => $rows[0]['error'] === null);
        $number->refresh();
        $this->assertNull($number->current_offer_id);
        $this->assertSame($fingerprint, $number->readiness_fingerprint);
        $this->applyBulk(['selection' => [$number->id => $number->inventory_revision], 'action' => 'publish'])->assertSessionHasNoErrors()->assertSessionHas('inventory_results', fn ($rows) => $rows[0]['error'] === null);
        $this->assertNotSame($first->id, $number->fresh()->current_offer_id);
        $this->assertSame(300000, $number->fresh()->currentOffer->monthly_amount);
        $this->assertNotNull($first->fresh()->withdrawn_at);
    }

    public function test_cosmetic_and_price_changes_preserve_review_but_technical_changes_invalidate_it(): void
    {
        [$admin, $settings, $version] = $this->prepare();
        $number = $this->stock($admin, $settings);
        $fingerprint = $number->readiness_fingerprint;
        $this->applyBulk(['selection' => [$number->id => $number->inventory_revision], 'action' => 'label', 'label' => 'New label'])->assertSessionHasNoErrors()->assertSessionHas('inventory_results', fn ($rows) => $rows[0]['error'] === null);
        $number->refresh();
        $this->assertSame($fingerprint, $number->readiness_fingerprint);
        $this->applyBulk(['selection' => [$number->id => $number->inventory_revision], 'action' => 'price', 'amount' => 400000, 'plan_version_id' => $version->id])->assertSessionHasNoErrors()->assertSessionHas('inventory_results', fn ($rows) => $rows[0]['error'] === null);
        $number->refresh();
        $this->assertSame($fingerprint, $number->readiness_fingerprint);
        $this->put('/admin/inventory/'.$number->id, array_replace($settings, ['revision' => $number->inventory_revision, 'destination_prefixes_text' => '+9821']))->assertSessionHasNoErrors();
        $this->assertNull($number->fresh()->readiness_fingerprint);
    }

    public function test_hold_is_atomic_and_reserved_or_assigned_numbers_cannot_be_changed(): void
    {
        [$admin, $settings, $version] = $this->prepare();
        $number = $this->stock($admin, $settings);
        $offer = app(NumberOfferService::class)->publish($admin, $number->id, $number->inventory_revision, $version->id, 300000);
        $number->refresh();
        $this->applyBulk(['selection' => [$number->id => $number->inventory_revision], 'action' => 'hold', 'reason' => 'Provider maintenance'])->assertSessionHasNoErrors()->assertSessionHas('inventory_results', fn ($rows) => $rows[0]['error'] === null);
        $this->assertSame('disabled', $number->fresh()->inventory_state);
        $this->assertNotNull($offer->fresh()->withdrawn_at);
        $this->assertNull($number->fresh()->current_offer_id);
        foreach (['reserved', 'assigned', 'quarantined'] as $state) {
            $number->update(['inventory_state' => $state]);
            $this->applyBulk(['selection' => [$number->id => $number->inventory_revision], 'action' => 'restore', 'reason' => 'Must stay protected'])->assertSessionHas('inventory_results.0.error');
            $this->assertSame($state, $number->fresh()->inventory_state);
        }
    }

    public function test_inventory_filters_export_and_annual_price_are_consistent(): void
    {
        [$admin, $settings, $version] = $this->prepare();
        $number = $this->stock($admin, $settings + ['label' => '=Dangerous formula']);
        app(NumberOfferService::class)->publish($admin, $number->id, $number->inventory_revision, $version->id, 300000);
        // Existing annual offers must display their stored billing period even in legacy mode.
        NumberOffer::query()->where('id', $number->fresh()->current_offer_id)->update(['annual_amount' => 3600000, 'billing_model' => 'annual_number']);
        $number->update(['inventory_sale_settings' => null]);
        $this->get('/admin/inventory?status=available&q=55501234')->assertOk()->assertSee('3,600,000 تومان')->assertSee('سالانه');
        $this->get('/admin/inventory?status=unpublished')->assertOk()->assertDontSee($number->normalized_number);
        $csv = $this->get('/admin/inventory/export?status=available')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=Dangerous formula", $csv);
        $this->assertStringContainsString('3600000,year', $csv);
        $this->get('/admin/sip-numbers?scope=stock')->assertRedirect(route('admin.inventory.index', ['status' => 'all']));
    }

    public function test_number_page_saves_and_publishes_once_and_rolls_back_unreviewed_technical_edits(): void
    {
        [$admin, $settings, $version] = $this->prepare();
        $number = $this->stock($admin, $settings);
        $this->put('/admin/inventory/'.$number->id, $settings + ['revision' => $number->inventory_revision, 'intent' => 'publish', 'label' => 'Ready for sale', 'amount' => 500000, 'plan_version_id' => $version->id])->assertSessionHasNoErrors();
        $this->assertSame('Ready for sale', $number->fresh()->label);
        $this->assertSame(500000, $number->fresh()->currentOffer->monthly_amount);
        $this->assertDatabaseCount('number_offers', 1);
        $other = $this->stock($admin, $settings, '+982155501235');
        $this->put('/admin/inventory/'.$other->id, array_replace($settings, ['revision' => $other->inventory_revision, 'intent' => 'publish', 'amount' => 500000, 'plan_version_id' => $version->id, 'label' => 'Must roll back', 'destination_prefixes_text' => '+9821']))->assertSessionHasErrors('publication');
        $this->assertNull($other->fresh()->current_offer_id);
        $this->assertNull($other->fresh()->label);
        $this->assertSame(['+989'], $other->fresh()->destination_prefixes);
        $this->assertDatabaseCount('number_offers', 1);
    }

    public function test_csv_import_accepts_header_and_500_rows_with_a_trailing_newline(): void
    {
        [, $settings] = $this->prepare();
        $lines = ['number,label'];
        for ($i = 0; $i < 500; $i++) {
            $lines[] = '+9821555'.str_pad((string) $i, 5, '0', STR_PAD_LEFT).',Batch';
        }
        $csv = UploadedFile::fake()->createWithContent('numbers.csv', implode("\n", $lines)."\n");
        $this->post('/admin/inventory/import-preview', $settings + ['csv' => $csv])->assertOk();
        $this->assertCount(500, session('inventory_import.rows'));
        $this->assertDatabaseCount('sip_numbers', 0);
        $this->post('/admin/inventory/import', ['token' => 'invalid', 'rows' => [0], 'intent' => 'draft'])->assertStatus(419);
    }

    public function test_bulk_commit_applies_only_reviewed_eligible_rows_and_token_cannot_replay(): void
    {
        [$admin, $settings, $version] = $this->prepare();
        $ready = $this->stock($admin, $settings);
        $blocked = app(NumberInventoryService::class)->create($admin, $settings + ['number' => '+982155501235']);
        $this->post('/admin/inventory/bulk-preview', ['action' => 'publish', 'selection' => [$ready->id => $ready->inventory_revision, $blocked->id => $blocked->inventory_revision], 'amount' => 300000, 'plan_version_id' => $version->id])->assertOk();
        $token = session('inventory_bulk_preview.token');
        app(NumberInventoryService::class)->review($admin, $blocked->id, $blocked->inventory_revision, 'Reviewed while the bulk preview is open');
        $this->post('/admin/inventory/bulk', ['preview_token' => $token])->assertRedirect();
        $this->assertNotNull($ready->fresh()->current_offer_id);
        $this->assertNull($blocked->fresh()->current_offer_id);
        $this->post('/admin/inventory/bulk', ['preview_token' => $token])->assertStatus(419);
    }

    public function test_switching_back_to_draft_ignores_review_and_correction_keeps_latest_input(): void
    {
        [, $settings] = $this->prepare();
        $this->post('/admin/inventory/import-preview', $settings + ['numbers_text' => '+982155501234'])->assertOk();
        $this->post('/admin/inventory/import', ['token' => session('inventory_import.token'), 'rows' => [0], 'intent' => 'draft', 'evidence' => 'short'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sip_numbers', 1);
        $this->from('/admin/inventory/create')->post('/admin/inventory/import-preview', $settings + ['numbers_text' => '+982155501235', 'amount' => -1])->assertSessionHasErrors('amount');
        $this->get('/admin/inventory/create')->assertOk()->assertSee('+982155501235')->assertDontSee('+982155501234');
    }

    public function test_nonadmins_and_customer_host_cannot_access_workspace_or_mutations(): void
    {
        $this->prepare();
        $operator = User::factory()->create(['user_type' => UserType::Operator]);
        $this->actingAs($operator);
        foreach (['/admin/inventory', '/admin/inventory/create', '/admin/inventory/export'] as $url) {
            $this->get($url)->assertForbidden();
        }
        foreach (['/admin/inventory/import-preview', '/admin/inventory/import', '/admin/inventory/bulk-preview', '/admin/inventory/bulk'] as $url) {
            $this->post('https://admin.blucom.ir'.$url)->assertForbidden();
            $this->post('https://my.blucom.ir'.$url)->assertNotFound();
        }
    }
}

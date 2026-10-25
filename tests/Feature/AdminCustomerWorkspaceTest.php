<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\LineConnectionRequest;
use App\Models\SipNumber;
use App\Models\User;
use App\Services\CustomerAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCustomerWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_scopes_every_tab_to_its_business_and_queue_links_resolve(): void
    {
        $accounts = app(CustomerAccountService::class);
        $first = $accounts->createOwner('First owner', '+989120001221', 'First business');
        $other = $accounts->createOwner('Other owner', '+989120001222', 'Other business');
        SipNumber::factory()->create(['tenant_id' => $other->tenant_id, 'number' => '+982155501299', 'normalized_number' => '+982155501299']);
        $record = LineConnectionRequest::query()->create(['tenant_id' => $first->tenant_id, 'customer_id' => $first->id, 'normalized_number' => '+982155501234', 'provider_name' => 'Demo provider']);
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $this->actingAs($admin);
        foreach (['overview', 'lines', 'billing', 'orders', 'activity', 'access'] as $tab) {
            $this->get('https://admin.blucom.ir/admin/customers/'.$first->id.'?tab='.$tab)->assertOk()->assertDontSee('Other owner')->assertDontSee('+982155501299');
        }
        $this->get('https://admin.blucom.ir/admin/work?type=connection')->assertOk()->assertSee('Demo provider');
        $this->post('https://admin.blucom.ir/admin/work/connection/'.$record->id.'/resolve', ['resolution' => 'Connection reviewed; customer instructions recorded', 'confirmed' => 1])->assertRedirect();
        $this->assertSame('resolved', $record->fresh()->status);
        $this->assertDatabaseCount('sip_gateways', 0);
        $this->post('https://admin.blucom.ir/admin/work/connection/'.$record->id.'/resolve', ['resolution' => 'Duplicate resolution should not overwrite', 'confirmed' => 1])->assertStatus(409);
        $this->assertDatabaseHas('commerce_audit_events', ['event' => 'request.resolved', 'resource_id' => $record->id]);
    }

    public function test_customers_and_operators_cannot_open_or_resolve_admin_work(): void
    {
        $operator = User::factory()->create(['user_type' => UserType::Operator]);
        $this->actingAs($operator)->get('https://admin.blucom.ir/admin/work')->assertForbidden();
        $this->post('https://admin.blucom.ir/admin/work/connection/1/resolve')->assertForbidden();
        $this->get('https://my.blucom.ir/admin/work')->assertNotFound();
    }
}

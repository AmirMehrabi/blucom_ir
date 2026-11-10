<?php

namespace Tests\Feature;

use App\Models\CallRecord;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Services\CallOutcomeClassifier;
use App\Services\CallRecordImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CallOutcomesTest extends TestCase
{
    use RefreshDatabase;

    public function test_classifier_uses_positive_bridge_evidence_and_precise_failure_causes(): void
    {
        $classifier = app(CallOutcomeClassifier::class);
        foreach (['USER_BUSY' => 'busy', 'NO_ANSWER' => 'no_answer', 'NO_USER_RESPONSE' => 'no_answer', 'ORIGINATOR_CANCEL' => 'abandoned',
            'USER_NOT_REGISTERED' => 'unreachable', 'NO_ROUTE_DESTINATION' => 'unreachable', 'UNALLOCATED_NUMBER' => 'unreachable',
            'NORMAL_TEMPORARY_FAILURE' => 'failed', 'NETWORK_OUT_OF_ORDER' => 'failed', 'NORMAL_CLEARING' => 'unknown', 'CALL_REJECTED' => 'unknown'] as $cause => $expected) {
            $this->assertSame($expected, $classifier->classify(['hangup_cause' => $cause])['outcome'], $cause);
        }
        $this->assertSame('answered', $classifier->classify(['hangup_cause' => 'USER_BUSY', 'originate_disposition' => 'success'])['outcome']);
        $this->assertSame('answered', $classifier->classify(['hangup_cause' => 'NORMAL_TEMPORARY_FAILURE', 'bridge_confirmed' => true])['outcome']);
        $this->assertSame('answered', $classifier->classify(['queue_outcome' => 'answered'])['outcome']);
        $this->assertSame('unknown', $classifier->classify(['queue_outcome' => 'cancel', 'answered_at' => now()])['outcome']);
        $this->assertSame('busy', $classifier->classify(['hangup_cause' => 'NORMAL_CLEARING', 'originate_disposition' => 'USER_BUSY', 'answered_at' => now()])['outcome']);
        $this->assertSame('system_only', $classifier->classify(['ivr_menu_id' => 1, 'answered_at' => now()])['outcome']);
        $this->assertSame('unknown', $classifier->classify(['answered_at' => now()])['outcome']);
        $this->assertSame('abandoned', $classifier->classify(['ivr_menu_id' => 1, 'answered_at' => now(), 'hangup_cause' => 'ORIGINATOR_CANCEL'])['outcome']);
    }

    public function test_legacy_sql_and_model_classification_agree_without_guessing_human_answers(): void
    {
        $tenant = Tenant::factory()->create();
        foreach (['USER_BUSY', 'NORMAL_CLEARING', 'NO_ANSWER', 'NORMAL_TEMPORARY_FAILURE'] as $cause) {
            foreach ([null, now()] as $answered) {
                $call = CallRecord::query()->create(['tenant_id' => $tenant->id, 'freeswitch_uuid' => (string) Str::uuid(),
                    'direction' => 'inbound', 'status' => $answered ? 'answered' : 'failed', 'hangup_cause' => $cause, 'started_at' => now(), 'answered_at' => $answered]);
                $result = CallRecord::query()->whereKey($call->id)->selectRaw(CallOutcomeClassifier::expression().' AS result')->first();
                $this->assertSame($call->reportingOutcome(), $result->result);
            }
        }
    }

    public function test_replay_enriches_only_reporting_fields_and_keeps_fallback_success(): void
    {
        $tenant = Tenant::factory()->create();
        SipNumber::factory()->for($tenant)->create(['number' => '982112345678', 'normalized_number' => '+982112345678']);
        $uuid = (string) Str::uuid();
        $old = CallRecord::query()->create(['tenant_id' => $tenant->id, 'freeswitch_uuid' => $uuid, 'direction' => 'inbound', 'status' => 'answered', 'source_number' => 'original', 'started_at' => now()]);
        $row = array_fill(0, 31, '');
        foreach ([1 => '+989121234567', 2 => '982112345678', 3 => 'public', 4 => '2026-11-01 10:00:00', 5 => '2026-11-01 10:00:10', 6 => '2026-11-01 10:00:30', 7 => '30', 8 => '20', 9 => 'USER_BUSY', 10 => $uuid, 16 => 'external', 17 => 'inbound', 25 => 'success'] as $i => $value) {
            $row[$i] = $value;
        }
        $importer = app(CallRecordImporter::class);
        $importer->refreshOwnership();
        $this->assertFalse($importer->import($row));
        $old->refresh();
        $this->assertSame('answered', $old->outcome);
        $this->assertSame('bridge_connected', $old->answer_evidence);
        $this->assertSame('original', $old->source_number);
        $this->assertSame($tenant->id, $old->tenant_id);
        $this->assertDatabaseCount('call_records', 1);
    }
}

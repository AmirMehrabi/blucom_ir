<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sip_extensions', function (Blueprint $table) {
            $table->string('sip_username', 64)->nullable()->unique();
        });
        // Preserve every existing endpoint's authentication identity.
        DB::table('sip_extensions')->update(['sip_username' => DB::raw('extension')]);
        Schema::table('sip_extensions', fn (Blueprint $table) => $table->dropUnique('sip_extensions_extension_unique'));
        Schema::create('tenant_calling_settings', function (Blueprint $table) {
            $table->foreignId('tenant_id')->primary()->constrained()->restrictOnDelete();
            $table->json('settings');
            $table->timestamps();
        });
        Schema::create('tenant_audio_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('storage_key');
            $table->unsignedInteger('duration_seconds');
            $table->timestamps();
        });
        Schema::create('outbound_call_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('initiated_by_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('sip_extension_id')->constrained()->restrictOnDelete();
            $table->foreignId('sip_number_id')->constrained()->restrictOnDelete();
            $table->foreignId('outbound_route_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 80);
            $table->string('request_hash', 64);
            $table->string('customer_number', 16);
            $table->string('caller_id', 16);
            $table->string('agent_label', 120);
            $table->json('configuration');
            $table->uuid('agent_uuid')->unique();
            $table->uuid('customer_uuid')->unique();
            $table->uuid('job_uuid')->unique();
            $table->string('dispatch_status', 16)->default('pending');
            $table->string('status', 32)->default('created');
            $table->string('failure_reason', 64)->nullable();
            $table->string('hangup_cause', 64)->nullable();
            foreach (['dispatch_started_at', 'agent_answered_at', 'agent_ready_at', 'customer_dialed_at', 'customer_answered_at', 'bridged_at', 'ended_at', 'cancel_requested_at', 'cleanup_completed_at', 'deadline_at'] as $column) {
                $table->timestamp($column)->nullable();
            }
            $table->unsignedInteger('conversation_seconds')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['status', 'created_at']);
        });
        Schema::create('outbound_call_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('outbound_call_session_id');
            $table->foreign('outbound_call_session_id')->references('id')->on('outbound_call_sessions')->cascadeOnDelete();
            $table->string('event_key', 64);
            $table->string('status', 32);
            $table->string('reason', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->unique(['outbound_call_session_id', 'event_key']);
        });
        Schema::table('call_records', function (Blueprint $table) {
            $table->uuid('outbound_call_session_id')->nullable()->unique();
        });
        Schema::table('call_recordings', function (Blueprint $table) {
            $table->uuid('outbound_call_session_id')->nullable()->unique();
        });
        // Owners receive the new capability; staff need an explicit grant.
        foreach (DB::table('customers')->where('role', 'owner')->pluck('id') as $id) {
            foreach (['outbound.initiate', 'outbound.manage'] as $permission) {
                DB::table('customer_permissions')->insertOrIgnore(['customer_id' => $id, 'permission' => $permission]);
            }
        }
    }

    public function down(): void
    {
        if (DB::table('sip_extensions')->whereColumn('sip_username', '!=', 'extension')->exists()) {
            throw new RuntimeException('SIP identities are in use; retain the identity-aware schema and disable the feature instead.');
        }
        Schema::table('call_recordings', fn (Blueprint $table) => $table->dropColumn('outbound_call_session_id'));
        Schema::table('call_records', fn (Blueprint $table) => $table->dropColumn('outbound_call_session_id'));
        Schema::dropIfExists('outbound_call_events');
        Schema::dropIfExists('outbound_call_sessions');
        Schema::dropIfExists('tenant_audio_assets');
        Schema::dropIfExists('tenant_calling_settings');
        Schema::table('sip_extensions', function (Blueprint $table) {
            $table->dropColumn('sip_username');
            $table->unique('extension');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_monitor_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id');
            $table->foreignId('target_extension_id')->constrained('sip_extensions')->restrictOnDelete();
            $table->foreignId('supervisor_extension_id')->nullable()->constrained('sip_extensions')->restrictOnDelete();
            $table->uuid('target_uuid');
            $table->uuid('listener_uuid')->unique();
            $table->string('mode', 16);
            $table->string('status', 16)->default('prepared');
            $table->string('sip_identity', 64)->nullable()->unique();
            $table->text('password_encrypted')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['actor_type', 'actor_id', 'ended_at']);
        });
        foreach (DB::table('customers')->where('role', 'owner')->pluck('id') as $id) {
            DB::table('customer_permissions')->insertOrIgnore(['customer_id' => $id, 'permission' => 'calls.monitor']);
        }
    }

    public function down(): void
    {
        DB::table('customer_permissions')->where('permission', 'calls.monitor')->delete();
        Schema::dropIfExists('call_monitor_sessions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sip_extensions', function (Blueprint $table) {
            if (! Schema::hasColumn('sip_extensions', 'paused_at')) {
                $table->timestamp('paused_at')->nullable();
            }
            if (! Schema::hasColumn('sip_extensions', 'availability_version')) {
                $table->unsignedInteger('availability_version')->default(0);
            }
            if (! Schema::hasColumn('sip_extensions', 'queue_sync_version')) {
                $table->unsignedInteger('queue_sync_version')->default(0);
            }
            if (! Schema::hasColumn('sip_extensions', 'queue_synced_at')) {
                $table->timestamp('queue_synced_at')->nullable();
            }
        });
        if (! Schema::hasTable('extension_availability_events')) {
            Schema::create('extension_availability_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('sip_extension_id')->nullable()->constrained()->nullOnDelete();
                $table->string('actor_type', 16);
                $table->unsignedBigInteger('actor_id');
                $table->string('action', 24);
                $table->unsignedInteger('version');
                $table->timestamp('occurred_at');
            });
        }
        $columns = ['tenant_id', 'sip_extension_id', 'occurred_at'];
        if (! collect(Schema::getIndexes('extension_availability_events'))->contains(fn ($index) => $index['columns'] === $columns)) {
            Schema::table('extension_availability_events', fn (Blueprint $table) => $table->index($columns, 'extension_availability_tenant_extension_time_idx'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('extension_availability_events');
        Schema::table('sip_extensions', fn (Blueprint $table) => $table->dropColumn(['paused_at', 'availability_version', 'queue_sync_version', 'queue_synced_at']));
    }
};

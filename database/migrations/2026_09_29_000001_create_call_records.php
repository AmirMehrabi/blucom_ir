<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sip_number_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sip_extension_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('freeswitch_uuid')->unique();
            $table->string('direction', 12);
            $table->string('source_number', 32)->nullable();
            $table->string('destination_number', 32)->nullable();
            $table->string('status', 16);
            $table->string('hangup_cause', 64)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedInteger('billable_seconds')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'started_at']);
            $table->index(['tenant_id', 'direction', 'started_at']);
            $table->index(['tenant_id', 'status', 'started_at']);
        });

        Schema::create('call_record_import_cursors', function (Blueprint $table) {
            $table->string('source_key', 100)->primary();
            $table->string('path');
            $table->unsignedBigInteger('offset')->default(0);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_record_import_cursors');
        Schema::dropIfExists('call_records');
    }
};

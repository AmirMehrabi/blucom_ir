<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_recording_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('sip_number_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('directions', 16)->default('off');
            $table->string('coverage', 16)->default('conversation');
            $table->boolean('announcement_enabled')->default(false);
            $table->string('announcement_path')->nullable();
            $table->unsignedSmallInteger('retention_days')->default(30);
            $table->unsignedSmallInteger('max_minutes')->default(60);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('recording_storage_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quota_mb')->default(1024);
            $table->string('overflow', 16)->default('stop');
            $table->timestamps();
        });
        Schema::create('call_recordings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('sip_number_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('call_record_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('freeswitch_uuid')->unique();
            $table->string('direction', 16);
            $table->string('status', 16)->default('recording');
            $table->json('policy');
            $table->string('storage_key')->nullable();
            $table->unsignedBigInteger('bytes')->default(0);
            $table->unsignedBigInteger('reserved_bytes')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('failure_reason', 64)->nullable();
            $table->string('deletion_reason', 16)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_recordings');
        Schema::dropIfExists('recording_storage_settings');
        Schema::dropIfExists('number_recording_settings');
    }
};

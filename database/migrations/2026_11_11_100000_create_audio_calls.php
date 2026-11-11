<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_audio_assets', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
        });
        Schema::create('audio_call_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->char('digest', 64)->unique();
            $table->timestamps();
        });
        Schema::create('audio_calls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('key_id')->nullable();
            $table->unsignedBigInteger('caller_id_id');
            $table->unsignedBigInteger('assignment_id')->nullable();
            $table->string('caller_id', 16);
            $table->string('destination', 16);
            $table->uuid('audio_id')->nullable();
            $table->text('audio_url')->nullable();
            $table->char('request_key', 64);
            $table->char('request_hash', 64);
            $table->string('status', 32);
            $table->string('outcome', 32)->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('playback_started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'request_key']);
            $table->index(['finished_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_calls');
        Schema::dropIfExists('audio_call_keys');
        Schema::table('tenant_audio_assets', fn (Blueprint $table) => $table->dropColumn('archived_at'));
    }
};

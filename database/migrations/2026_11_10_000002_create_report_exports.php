<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('requester_type', 16);
            $table->unsignedBigInteger('requester_id');
            $table->json('filters');
            $table->timestamp('snapshot_at');
            $table->unsignedBigInteger('max_call_id')->default(0);
            $table->unsignedSmallInteger('metric_version');
            $table->string('status', 16)->default('pending');
            $table->string('path')->nullable();
            $table->string('failure_code', 24)->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['tenant_id', 'requester_type', 'requester_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};

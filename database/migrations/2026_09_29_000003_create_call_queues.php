<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_queues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('strategy', 32)->default('longest-idle-agent');
            $table->unsignedSmallInteger('max_wait_seconds')->default(90);
            $table->foreignId('fallback_extension_id')->nullable()->constrained('sip_extensions')->nullOnDelete();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('waiting_count')->default(0);
            $table->unsignedInteger('available_count')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('call_queue_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_queue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sip_extension_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['call_queue_id', 'sip_extension_id']);
        });

        Schema::table('sip_extensions', function (Blueprint $table) {
            $table->string('queue_status', 20)->default('Available');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('sip_extension_id')->nullable()->unique()->constrained('sip_extensions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('sip_extension_id'));
        Schema::table('sip_extensions', fn (Blueprint $table) => $table->dropColumn('queue_status'));
        Schema::dropIfExists('call_queue_members');
        Schema::dropIfExists('call_queues');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_records', function (Blueprint $table) {
            $table->foreignId('call_queue_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('queue_wait_seconds')->nullable();
            $table->string('queue_outcome', 20)->nullable();
            $table->index(['call_queue_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('call_records', function (Blueprint $table) {
            $table->dropIndex(['call_queue_id', 'started_at']);
            $table->dropConstrainedForeignId('call_queue_id');
            $table->dropColumn(['queue_wait_seconds', 'queue_outcome']);
        });
    }
};

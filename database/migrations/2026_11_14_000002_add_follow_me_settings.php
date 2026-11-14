<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sip_extensions', 'call_queues'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->json('follow_me')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['sip_extensions', 'call_queues'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('follow_me');
            });
        }
    }
};

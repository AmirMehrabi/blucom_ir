<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['call_queues', 'sip_extensions'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->json('inbound_announcement')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['call_queues', 'sip_extensions'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('inbound_announcement');
            });
        }
    }
};

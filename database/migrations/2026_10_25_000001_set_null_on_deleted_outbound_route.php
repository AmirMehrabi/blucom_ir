<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_call_sessions', function (Blueprint $table) {
            $table->dropForeign(['outbound_route_id']);
            $table->foreignId('outbound_route_id')->nullable()->change();
            $table->foreign('outbound_route_id')->references('id')->on('outbound_routes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('outbound_call_sessions')->whereNull('outbound_route_id')->exists()) {
            throw new RuntimeException('Deleted outbound routes are retained in call history; keep the nullable route reference.');
        }

        Schema::table('outbound_call_sessions', function (Blueprint $table) {
            $table->dropForeign(['outbound_route_id']);
            $table->foreignId('outbound_route_id')->nullable(false)->change();
            $table->foreign('outbound_route_id')->references('id')->on('outbound_routes')->restrictOnDelete();
        });
    }
};

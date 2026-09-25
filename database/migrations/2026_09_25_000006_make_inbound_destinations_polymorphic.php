<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_routes', function (Blueprint $table) {
            $table->dropForeign(['destination_id']);
            $table->index(['destination_type', 'destination_id']);
        });
    }

    public function down(): void
    {
        Schema::table('inbound_routes', function (Blueprint $table) {
            $table->dropIndex(['destination_type', 'destination_id']);
            $table->foreign('destination_id')->references('id')->on('sip_extensions')->cascadeOnDelete();
        });
    }
};

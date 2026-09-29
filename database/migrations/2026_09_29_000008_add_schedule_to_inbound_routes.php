<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_routes', function (Blueprint $table) {
            $table->json('schedule')->nullable();
            $table->string('closed_destination_type', 30)->nullable();
            $table->unsignedBigInteger('closed_destination_id')->nullable();
            $table->string('closed_announcement_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inbound_routes', function (Blueprint $table) {
            $table->dropColumn(['schedule', 'closed_destination_type', 'closed_destination_id', 'closed_announcement_path']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_routes', function (Blueprint $table) {
            $table->unsignedBigInteger('destination_id')->nullable()->change();
            $table->string('audio_destination', 64)->nullable();
            $table->string('unanswered_audio_destination', 64)->nullable();
        });
        Schema::table('call_queues', fn (Blueprint $table) => $table->string('fallback_audio_destination', 64)->nullable());
    }

    public function down(): void
    {
        Schema::table('call_queues', fn (Blueprint $table) => $table->dropColumn('fallback_audio_destination'));
        // Keep the column nullable so audio-only route rows can survive a schema rollback.
        Schema::table('inbound_routes', fn (Blueprint $table) => $table->dropColumn(['audio_destination', 'unanswered_audio_destination']));
    }
};

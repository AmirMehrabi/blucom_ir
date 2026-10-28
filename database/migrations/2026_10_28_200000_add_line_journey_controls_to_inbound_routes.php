<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_routes', function (Blueprint $table) {
            $table->unsignedSmallInteger('ring_seconds')->default(30);
            $table->string('unanswered_type', 30)->nullable();
            $table->unsignedBigInteger('unanswered_id')->nullable();
            $table->string('unanswered_phone', 20)->nullable();
            $table->timestamp('temporary_closed_until')->nullable();
        });
        Schema::table('sip_extensions', function (Blueprint $table) {
            $table->text('voicemail_secret_encrypted')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sip_extensions', fn (Blueprint $table) => $table->dropColumn('voicemail_secret_encrypted'));
        Schema::table('inbound_routes', fn (Blueprint $table) => $table->dropColumn(['ring_seconds', 'unanswered_type', 'unanswered_id', 'unanswered_phone', 'temporary_closed_until']));
    }
};

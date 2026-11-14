<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['call_queues', 'ivr_menus', 'sip_extensions'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('recording_mode', 8)->default('inherit'));
        }

        Schema::table('call_recordings', function (Blueprint $table) {
            $table->unsignedInteger('segment')->default(0);
            $table->unique(['freeswitch_uuid', 'segment']);
            $table->dropUnique(['freeswitch_uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('call_recordings', function (Blueprint $table) {
            $table->unique('freeswitch_uuid');
            $table->dropUnique(['freeswitch_uuid', 'segment']);
            $table->dropColumn('segment');
        });

        foreach (['call_queues', 'ivr_menus', 'sip_extensions'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('recording_mode'));
        }
    }
};

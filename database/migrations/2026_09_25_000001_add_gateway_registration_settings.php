<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sip_gateways', function (Blueprint $table) {
            $table->boolean('register')->default(false);
            $table->string('auth_username')->nullable();
            $table->string('realm')->nullable();
            $table->boolean('approved_for_outbound')->default(false);
        });

        DB::table('sip_gateways')->where('name', 'provider-trunk')->update(['approved_for_outbound' => true]);
    }

    public function down(): void
    {
        Schema::table('sip_gateways', function (Blueprint $table) {
            $table->dropColumn(['register', 'auth_username', 'realm', 'approved_for_outbound']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sip_extensions', function (Blueprint $table): void {
            $table->unsignedTinyInteger('max_registrations')->default(2)->change();
        });

        DB::table('sip_extensions')->where('max_registrations', '<', 2)->update(['max_registrations' => 2]);
    }

    public function down(): void
    {
        Schema::table('sip_extensions', function (Blueprint $table): void {
            $table->unsignedTinyInteger('max_registrations')->default(1)->change();
        });
    }
};

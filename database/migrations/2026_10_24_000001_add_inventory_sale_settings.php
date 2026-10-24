<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sip_numbers', fn (Blueprint $table) => $table->json('inventory_sale_settings')->nullable());
    }

    public function down(): void
    {
        Schema::table('sip_numbers', fn (Blueprint $table) => $table->dropColumn('inventory_sale_settings'));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_records', function (Blueprint $table) {
            $table->foreignId('ivr_menu_id')->nullable()->constrained('ivr_menus')->nullOnDelete();
            $table->string('ivr_digit', 1)->nullable();
            $table->index(['ivr_menu_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('call_records', function (Blueprint $table) {
            $table->dropIndex(['ivr_menu_id', 'started_at']);
            $table->dropConstrainedForeignId('ivr_menu_id');
            $table->dropColumn('ivr_digit');
        });
    }
};

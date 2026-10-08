<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('number_assignments', function (Blueprint $table) {
            $table->text('cancellation_reason')->nullable();
            $table->string('refund_decision', 30)->nullable();
            $table->timestamp('returned_to_stock_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('number_assignments')->whereNotNull('cancellation_reason')->exists()) {
            throw new RuntimeException('Cannot discard recorded service cancellations.');
        }
        Schema::table('number_assignments', fn (Blueprint $table) => $table->dropColumn(['cancellation_reason', 'refund_decision', 'returned_to_stock_at']));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->boolean('active')->default(false)->after('enabled');
        });
        DB::table('payment_gateways')->where('provider', 'mellat')->update(['active' => true]);
        DB::table('payment_gateways')->insert([
            'provider' => 'zibal', 'enabled' => false, 'active' => false, 'revision' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (DB::table('payment_attempts')->where('provider', 'zibal')->exists()
            || DB::table('payment_gateway_versions')->whereIn('payment_gateway_id', function ($query) {
                $query->select('id')->from('payment_gateways')->where('provider', 'zibal');
            })->exists()) {
            throw new RuntimeException('Zibal payment history requires an explicit retention plan before rollback.');
        }
        DB::table('payment_gateways')->where('provider', 'zibal')->delete();
        Schema::table('payment_gateways', fn (Blueprint $table) => $table->dropColumn('active'));
    }
};

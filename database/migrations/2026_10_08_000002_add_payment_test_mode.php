<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_gateway_versions', fn (Blueprint $table) => $table->boolean('is_test')->default(false));
        Schema::table('payment_attempts', fn (Blueprint $table) => $table->boolean('is_test')->default(false));
        DB::table('payment_gateway_versions')->whereIn('payment_gateway_id',
            DB::table('payment_gateways')->where('provider', 'zibal')->select('id'))->orderBy('id')->chunkById(100, function ($versions): void {
                foreach ($versions as $version) {
                    $credentials = json_decode(Crypt::decryptString($version->credentials), true, flags: JSON_THROW_ON_ERROR);
                    if (($credentials['merchant'] ?? null) === 'zibal') {
                        DB::table('payment_gateway_versions')->where('id', $version->id)->update(['is_test' => true]);
                        DB::table('payment_attempts')->where('payment_gateway_version_id', $version->id)->update(['is_test' => true]);
                    }
                }
            });
    }

    public function down(): void
    {
        if (DB::table('payment_attempts')->where('is_test', true)->exists()
            || DB::table('payment_gateway_versions')->where('is_test', true)->exists()) {
            throw new RuntimeException('Test payment history requires an explicit retention plan before rollback.');
        }
        Schema::table('payment_attempts', fn (Blueprint $table) => $table->dropColumn('is_test'));
        Schema::table('payment_gateway_versions', fn (Blueprint $table) => $table->dropColumn('is_test'));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve call viewers' existing ability to retrieve their own call data.
        foreach (['user_permissions' => 'user_id', 'customer_permissions' => 'customer_id'] as $table => $key) {
            DB::table($table)->where('permission', 'calls.view')->orderBy($key)->chunk(200, function ($rows) use ($table, $key) {
                DB::table($table)->insertOrIgnore($rows->map(fn ($row) => [$key => $row->$key, 'permission' => 'reports.export'])->all());
            });
        }
    }

    public function down(): void
    {
        foreach (['user_permissions', 'customer_permissions'] as $table) {
            DB::table($table)->where('permission', 'reports.export')->delete();
        }
    }
};

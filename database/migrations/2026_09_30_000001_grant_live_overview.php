<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('user_permissions')->where('permission', Permissions::DASHBOARD_VIEW)->pluck('user_id') as $userId) {
            DB::table('user_permissions')->insertOrIgnore(['user_id' => $userId, 'permission' => Permissions::LIVE_VIEW]);
        }
    }

    public function down(): void
    {
        DB::table('user_permissions')->where('permission', Permissions::LIVE_VIEW)->delete();
    }
};

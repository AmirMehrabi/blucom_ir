<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $users = DB::table('user_permissions')
            ->where('permission', Permissions::DASHBOARD_VIEW)
            ->pluck('user_id');

        foreach ($users as $userId) {
            DB::table('user_permissions')->insertOrIgnore([
                'user_id' => $userId,
                'permission' => Permissions::CALLS_VIEW,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('user_permissions')->where('permission', Permissions::CALLS_VIEW)->delete();
    }
};

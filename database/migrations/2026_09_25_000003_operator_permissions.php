<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 64);
            $table->primary(['user_id', 'permission']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('user_type', 20)->default('operator')->change();
        });

        DB::table('users')->where('user_type', 'customer')->update(['user_type' => 'operator']);
        DB::table('users')->where('user_type', 'operator')->select('id')->orderBy('id')->chunkById(100, function ($users): void {
            $rows = [];
            foreach ($users as $user) {
                foreach (Permissions::OPERATOR_DEFAULTS as $permission) {
                    $rows[] = ['user_id' => $user->id, 'permission' => $permission];
                }
            }
            DB::table('user_permissions')->insertOrIgnore($rows);
        });
    }

    public function down(): void
    {
        DB::table('users')->where('user_type', 'operator')->update(['user_type' => 'customer']);
        Schema::table('users', function (Blueprint $table) {
            $table->string('user_type', 20)->default('customer')->change();
        });
        Schema::dropIfExists('user_permissions');
    }
};

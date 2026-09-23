<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sip_numbers', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->change();
            $table->foreignId('requested_by_user_id')->nullable()->after('tenant_id')->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('available')->change();
        });

        DB::table('sip_numbers')->where('status', 'active')->update(['status' => 'assigned']);
    }

    public function down(): void
    {
        DB::table('sip_numbers')->where('status', 'assigned')->update(['status' => 'active']);
        DB::table('sip_numbers')->whereIn('status', ['available', 'pending'])->delete();

        Schema::table('sip_numbers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by_user_id');
            $table->foreignId('tenant_id')->nullable(false)->change();
            $table->string('status', 20)->default('active')->change();
        });
    }
};

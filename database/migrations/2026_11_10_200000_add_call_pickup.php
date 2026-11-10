<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('pickup_enabled')->default(false);
            $table->boolean('pickup_directed')->default(true);
            $table->boolean('pickup_group')->default(true);
        });
        Schema::create('pickup_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->timestamps();
        });
        Schema::table('sip_extensions', function (Blueprint $table) {
            $table->foreignId('pickup_group_id')->nullable()->constrained('pickup_groups')->nullOnDelete();
            $table->boolean('pickup_allowed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('sip_extensions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pickup_group_id');
            $table->dropColumn('pickup_allowed');
        });
        Schema::dropIfExists('pickup_groups');
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn(['pickup_enabled', 'pickup_directed', 'pickup_group']));
    }
};

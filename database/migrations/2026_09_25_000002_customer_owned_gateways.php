<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sip_gateways', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained('tenants')->restrictOnDelete();
            $table->string('display_name')->nullable()->after('name');
            $table->string('provider_name')->nullable()->after('display_name');
            $table->string('connection_method', 20)->default('credentials')->after('provider_name');
            $table->string('verification_status', 20)->default('approved')->after('approved_for_outbound')->index();
            $table->index(['tenant_id', 'verification_status']);
        });
    }

    public function down(): void
    {
        Schema::table('sip_gateways', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'verification_status']);
            $table->dropColumn(['display_name', 'provider_name', 'connection_method', 'verification_status']);
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};

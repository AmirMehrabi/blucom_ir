<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'system_key')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->string('system_key')->nullable()->unique();
            });
        }

        if (! Schema::hasColumn('sip_numbers', 'label')) {
            Schema::table('sip_numbers', function (Blueprint $table) {
                $table->string('label')->nullable();
            });
        }
        if (! Schema::hasColumn('sip_numbers', 'enabled')) {
            Schema::table('sip_numbers', function (Blueprint $table) {
                $table->boolean('enabled')->default(true);
            });
        }

        if (! Schema::hasIndex('outbound_routes', 'outbound_routes_tenant_id_mvp_index')) {
            Schema::table('outbound_routes', function (Blueprint $table) {
                $table->index('tenant_id', 'outbound_routes_tenant_id_mvp_index');
            });
        }
        if (Schema::hasIndex('outbound_routes', 'outbound_routes_tenant_id_sip_number_id_unique')) {
            Schema::table('outbound_routes', function (Blueprint $table) {
                $table->dropUnique(['tenant_id', 'sip_number_id']);
            });
        }
        if (! Schema::hasColumn('outbound_routes', 'sip_extension_id')) {
            Schema::table('outbound_routes', function (Blueprint $table) {
                $table->foreignId('sip_extension_id')->nullable()->constrained('sip_extensions')->restrictOnDelete();
                $table->unique('sip_extension_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('outbound_routes', function (Blueprint $table) {
            $table->dropUnique(['sip_extension_id']);
            $table->dropConstrainedForeignId('sip_extension_id');
            $table->unique(['tenant_id', 'sip_number_id']);
            $table->dropIndex('outbound_routes_tenant_id_mvp_index');
        });
        Schema::table('sip_numbers', function (Blueprint $table) {
            $table->dropColumn(['label', 'enabled']);
        });
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['system_key']);
            $table->dropColumn('system_key');
        });
    }
};

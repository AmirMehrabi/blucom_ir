<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('user_type')->constrained('tenants')->nullOnDelete();
            $table->string('password')->nullable()->change();
        });

        Schema::create('sip_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('host');
            $table->unsignedInteger('port')->default(5060);
            $table->string('transport', 10)->default('udp');
            $table->string('username')->nullable();
            $table->text('password_encrypted')->nullable();
            $table->string('profile', 50)->default('external');
            $table->string('context', 50)->default('public');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('sip_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('number', 32);
            $table->string('normalized_number', 32);
            $table->foreignId('provider_gateway_id')->nullable()->constrained('sip_gateways')->nullOnDelete();
            $table->string('status', 20)->default('active')->index();
            $table->boolean('inbound_enabled')->default(true);
            $table->boolean('outbound_enabled')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'normalized_number']);
            $table->unique(['normalized_number']);
        });

        Schema::create('sip_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('extension', 16);
            $table->text('password_encrypted');
            $table->string('display_name')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['extension']);
            $table->unique(['tenant_id', 'extension']);
        });

        Schema::create('inbound_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('sip_number_id')->constrained('sip_numbers')->cascadeOnDelete();
            $table->string('destination_type', 30)->default('extension');
            $table->foreignId('destination_id')->constrained('sip_extensions')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['sip_number_id']);
        });

        Schema::create('outbound_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('sip_number_id')->constrained('sip_numbers')->cascadeOnDelete();
            $table->foreignId('gateway_id')->constrained('sip_gateways')->restrictOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'sip_number_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_routes');
        Schema::dropIfExists('inbound_routes');
        Schema::dropIfExists('sip_extensions');
        Schema::dropIfExists('sip_numbers');
        Schema::dropIfExists('sip_gateways');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
            $table->string('password')->nullable(false)->change();
        });

        Schema::dropIfExists('tenants');
    }
};

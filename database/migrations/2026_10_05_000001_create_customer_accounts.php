<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('mobile', 16)->unique();
            $table->string('role', 20)->default('staff');
            $table->string('dashboard_period', 16)->default('daily');
            $table->foreignId('sip_extension_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamp('mobile_verified_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('owner_customer_id')->nullable()->unique()->constrained('customers')->restrictOnDelete();
        });
        Schema::create('customer_permissions', function (Blueprint $table) {
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 64);
            $table->primary(['customer_id', 'permission']);
        });
        Schema::create('customer_otp_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('mobile', 16)->index();
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_otp_challenges');
        Schema::dropIfExists('customer_permissions');
        Schema::table('tenants', fn (Blueprint $table) => $table->dropConstrainedForeignId('owner_customer_id'));
        Schema::dropIfExists('customers');
    }
};

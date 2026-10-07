<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30)->unique();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
        });
        Schema::create('payment_gateway_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_gateway_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->uuid('account_key');
            $table->text('credentials');
            $table->string('gateway_unit', 10);
            $table->boolean('amount_unit_confirmed');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['payment_gateway_id', 'version']);
        });
        Schema::table('payment_gateways', fn (Blueprint $table) => $table->foreignId('current_version_id')->nullable()->constrained('payment_gateway_versions')->restrictOnDelete());
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->uuid('public_id')->nullable()->unique();
            $table->foreignId('payment_gateway_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('candidate_sale_reference', 19)->nullable();
            $table->uuid('operation_token')->nullable();
            $table->timestamp('operation_expires_at')->nullable();
            $table->string('last_code', 10)->nullable();
            $table->boolean('reversal_requested')->default(false);
            $table->index(['status', 'operation_expires_at']);
        });
        Schema::table('commerce_invoices', function (Blueprint $table) {
            $table->foreignId('current_payment_attempt_id')->nullable()->unique()->constrained('payment_attempts')->restrictOnDelete();
            $table->foreignId('paid_payment_attempt_id')->nullable()->unique()->constrained('payment_attempts')->restrictOnDelete();
            $table->timestamp('paid_at')->nullable();
        });
        // RefId is case-sensitive according to the provider contract.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE payment_attempts MODIFY ref_id VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL');
        }
        DB::table('payment_gateways')->insert(['provider' => 'mellat', 'enabled' => false, 'revision' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (DB::table('payment_gateway_versions')->exists() || DB::table('payment_attempts')->whereNotNull('payment_gateway_version_id')->exists()) {
            throw new RuntimeException('Payment credentials/history require an explicit retention plan before rollback.');
        }
        Schema::table('commerce_invoices', function (Blueprint $table) {
            $table->dropForeign(['current_payment_attempt_id']);
            $table->dropForeign(['paid_payment_attempt_id']);
        });
        Schema::table('commerce_invoices', function (Blueprint $table) {
            $table->dropUnique(['current_payment_attempt_id']);
            $table->dropUnique(['paid_payment_attempt_id']);
        });
        Schema::table('commerce_invoices', fn (Blueprint $table) => $table->dropColumn(['current_payment_attempt_id', 'paid_payment_attempt_id', 'paid_at']));
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropForeign(['payment_gateway_version_id']);
            $table->dropUnique(['public_id']);
            $table->dropIndex(['status', 'operation_expires_at']);
        });
        Schema::table('payment_attempts', fn (Blueprint $table) => $table->dropColumn(['public_id', 'payment_gateway_version_id', 'candidate_sale_reference', 'operation_token', 'operation_expires_at', 'last_code', 'reversal_requested']));
        Schema::table('payment_gateways', fn (Blueprint $table) => $table->dropConstrainedForeignId('current_version_id'));
        Schema::dropIfExists('payment_gateway_versions');
        Schema::dropIfExists('payment_gateways');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('commerce_invoice_id')->unique()->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->bigInteger('amount');
            $table->unsignedBigInteger('balance_after');
            $table->string('description');
            $table->timestamps();
            $table->index(['tenant_id', 'id']);
        });
        Schema::table('commerce_invoices', function (Blueprint $table) {
            $table->foreignId('wallet_target_invoice_id')->nullable()->constrained('commerce_invoices')->restrictOnDelete();
            $table->uuid('wallet_request_key')->nullable()->unique();
        });
        Schema::table('number_assignments', fn (Blueprint $table) => $table->unsignedBigInteger('payment_attempt_id')->nullable()->change());
        Schema::table('number_offers', fn (Blueprint $table) => $table->unsignedBigInteger('purchase_amount')->nullable());
        Schema::table('number_terms', function (Blueprint $table) {
            $table->string('billing_model', 20)->default('annual_number');
            $table->unsignedBigInteger('purchase_amount')->nullable();
        });
        Schema::table('tenant_subscriptions', function (Blueprint $table) {
            $table->boolean('wallet_auto_renew')->default(false);
            $table->timestamp('reactivation_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('number_offers')->where('billing_model', 'one_time_number')->exists() || DB::table('wallet_entries')->exists() || DB::table('commerce_invoices')->where('kind', 'wallet_topup')->exists()
            || DB::table('number_terms')->where('billing_model', 'one_time_number')->exists()) {
            throw new RuntimeException('Wallet history requires an explicit retention plan before rollback.');
        }
        Schema::table('tenant_subscriptions', fn (Blueprint $table) => $table->dropColumn(['wallet_auto_renew', 'reactivation_requested_at']));
        Schema::table('number_terms', fn (Blueprint $table) => $table->dropColumn(['billing_model', 'purchase_amount']));
        Schema::table('number_offers', fn (Blueprint $table) => $table->dropColumn('purchase_amount'));
        Schema::table('number_assignments', fn (Blueprint $table) => $table->unsignedBigInteger('payment_attempt_id')->nullable(false)->change());
        Schema::table('commerce_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wallet_target_invoice_id');
            $table->dropColumn('wallet_request_key');
        });
        Schema::dropIfExists('wallet_entries');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('monthly_price')->nullable();
        });
        Schema::table('number_offers', function (Blueprint $table) {
            $table->unsignedBigInteger('annual_amount')->nullable();
            $table->string('billing_model', 20)->default('legacy_monthly');
        });
        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_version_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('monthly_amount');
            $table->string('currency', 3)->default('IRT');
            $table->string('status', 24)->default('pending_activation');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('period_starts_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('cancel_at')->nullable();
            $table->foreignId('pending_plan_version_id')->nullable()->constrained('plan_versions')->restrictOnDelete();
            $table->string('pending_change_source', 20)->nullable();
            $table->timestamp('pending_effective_at')->nullable();
            $table->timestamp('price_notice_sent_at')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedTinyInteger('anchor_day')->nullable();
            $table->timestamps();
            $table->unique('tenant_id');
            $table->index(['status', 'period_ends_at']);
        });
        Schema::create('number_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('number_assignment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('annual_amount');
            $table->string('currency', 3)->default('IRT');
            $table->string('status', 24)->default('pending_activation');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('period_starts_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('release_review_at')->nullable();
            $table->unsignedTinyInteger('anchor_day')->nullable();
            $table->unsignedTinyInteger('anchor_month')->nullable();
            $table->unsignedBigInteger('pending_annual_amount')->nullable();
            $table->timestamp('pending_price_effective_at')->nullable();
            $table->timestamp('price_notice_sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'period_ends_at']);
        });
        Schema::table('commerce_invoices', function (Blueprint $table) {
            $table->foreignId('commerce_order_id')->nullable()->change();
            $table->foreignId('tenant_subscription_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('number_term_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('plan_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('period_starts_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('applied_period_starts_at')->nullable();
            $table->timestamp('applied_period_ends_at')->nullable();
            $table->timestamp('notice_sent_at')->nullable();
            $table->timestamp('due_notice_sent_at')->nullable();
            $table->timestamp('final_notice_sent_at')->nullable();
            $table->unique(['tenant_subscription_id', 'kind', 'period_starts_at'], 'plan_invoice_period_unique');
            $table->unique(['number_term_id', 'kind', 'period_starts_at'], 'number_invoice_period_unique');
        });
        Schema::table('commerce_invoice_items', function (Blueprint $table) {
            $table->dropUnique(['commerce_invoice_id']);
            $table->foreignId('commerce_order_item_id')->nullable()->change();
            $table->string('kind', 30)->default('legacy_number_plan');
            $table->index('commerce_invoice_id');
        });
        Schema::table('commerce_order_items', function (Blueprint $table) {
            $table->string('billing_model', 20)->default('legacy_monthly');
        });
        Schema::create('billing_fulfillment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commerce_order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('choice', 20);
            $table->string('status', 20)->default('open');
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('tenant_subscriptions')->exists() || DB::table('number_terms')->exists()
            || DB::table('commerce_invoices')->whereNotNull('period_starts_at')->exists()) {
            throw new RuntimeException('Billing history needs an explicit retention plan before rollback.');
        }
        Schema::dropIfExists('billing_fulfillment_requests');
        Schema::table('commerce_order_items', fn (Blueprint $table) => $table->dropColumn('billing_model'));
        Schema::table('commerce_invoice_items', function (Blueprint $table) {
            $table->dropIndex(['commerce_invoice_id']);
            $table->dropColumn('kind');
            $table->foreignId('commerce_order_item_id')->nullable(false)->change();
            $table->unique('commerce_invoice_id');
        });
        Schema::table('commerce_invoices', function (Blueprint $table) {
            $table->dropUnique('plan_invoice_period_unique');
            $table->dropUnique('number_invoice_period_unique');
            $table->dropConstrainedForeignId('tenant_subscription_id');
            $table->dropConstrainedForeignId('number_term_id');
            $table->dropConstrainedForeignId('plan_version_id');
            $table->dropColumn(['period_starts_at', 'period_ends_at', 'due_at', 'grace_ends_at', 'applied_at', 'applied_period_starts_at', 'applied_period_ends_at', 'notice_sent_at', 'due_notice_sent_at', 'final_notice_sent_at']);
            $table->foreignId('commerce_order_id')->nullable(false)->change();
        });
        Schema::dropIfExists('number_terms');
        Schema::dropIfExists('tenant_subscriptions');
        Schema::table('number_offers', fn (Blueprint $table) => $table->dropColumn(['annual_amount', 'billing_model']));
        Schema::table('plan_versions', fn (Blueprint $table) => $table->dropColumn('monthly_price'));
    }
};

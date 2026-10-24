<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // These checks let a deploy safely finish a migration that previously
        // stopped after one or more MySQL DDL statements had already committed.
        if (! Schema::hasColumn('plan_versions', 'monthly_price')) {
            Schema::table('plan_versions', fn (Blueprint $table) => $table->unsignedBigInteger('monthly_price')->nullable());
        }
        if (! Schema::hasColumn('number_offers', 'annual_amount')) {
            Schema::table('number_offers', fn (Blueprint $table) => $table->unsignedBigInteger('annual_amount')->nullable());
        }
        if (! Schema::hasColumn('number_offers', 'billing_model')) {
            Schema::table('number_offers', fn (Blueprint $table) => $table->string('billing_model', 20)->default('legacy_monthly'));
        }
        if (! Schema::hasTable('tenant_subscriptions')) {
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
        }
        if (! Schema::hasTable('number_terms')) {
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
        }
        $invoiceIndexes = collect(Schema::getIndexes('commerce_invoices'))->pluck('name')->all();
        Schema::table('commerce_invoices', function (Blueprint $table) use ($invoiceIndexes) {
            if (! $this->columnIsNullable('commerce_invoices', 'commerce_order_id')) {
                $table->foreignId('commerce_order_id')->nullable()->change();
            }
            foreach ([
                'tenant_subscription_id' => fn () => $table->foreignId('tenant_subscription_id')->nullable()->constrained()->restrictOnDelete(),
                'number_term_id' => fn () => $table->foreignId('number_term_id')->nullable()->constrained()->restrictOnDelete(),
                'plan_version_id' => fn () => $table->foreignId('plan_version_id')->nullable()->constrained()->restrictOnDelete(),
                'period_starts_at' => fn () => $table->timestamp('period_starts_at')->nullable(),
                'period_ends_at' => fn () => $table->timestamp('period_ends_at')->nullable(),
                'due_at' => fn () => $table->timestamp('due_at')->nullable(),
                'grace_ends_at' => fn () => $table->timestamp('grace_ends_at')->nullable(),
                'applied_at' => fn () => $table->timestamp('applied_at')->nullable(),
                'applied_period_starts_at' => fn () => $table->timestamp('applied_period_starts_at')->nullable(),
                'applied_period_ends_at' => fn () => $table->timestamp('applied_period_ends_at')->nullable(),
                'notice_sent_at' => fn () => $table->timestamp('notice_sent_at')->nullable(),
                'due_notice_sent_at' => fn () => $table->timestamp('due_notice_sent_at')->nullable(),
                'final_notice_sent_at' => fn () => $table->timestamp('final_notice_sent_at')->nullable(),
            ] as $column => $addColumn) {
                if (! Schema::hasColumn('commerce_invoices', $column)) $addColumn();
            }
            if (! in_array('plan_invoice_period_unique', $invoiceIndexes, true)) {
                $table->unique(['tenant_subscription_id', 'kind', 'period_starts_at'], 'plan_invoice_period_unique');
            }
            if (! in_array('number_invoice_period_unique', $invoiceIndexes, true)) {
                $table->unique(['number_term_id', 'kind', 'period_starts_at'], 'number_invoice_period_unique');
            }
        });
        $invoiceItemIndexes = collect(Schema::getIndexes('commerce_invoice_items'))->pluck('name')->all();
        Schema::table('commerce_invoice_items', function (Blueprint $table) use ($invoiceItemIndexes) {
            if (in_array('commerce_invoice_items_commerce_invoice_id_unique', $invoiceItemIndexes, true)) {
                $table->dropUnique('commerce_invoice_items_commerce_invoice_id_unique');
            }
            if (! $this->columnIsNullable('commerce_invoice_items', 'commerce_order_item_id')) {
                $table->foreignId('commerce_order_item_id')->nullable()->change();
            }
            if (! Schema::hasColumn('commerce_invoice_items', 'kind')) {
                $table->string('kind', 30)->default('legacy_number_plan');
            }
            if (! in_array('commerce_invoice_items_commerce_invoice_id_index', $invoiceItemIndexes, true)) {
                $table->index('commerce_invoice_id');
            }
        });
        if (! Schema::hasColumn('commerce_order_items', 'billing_model')) {
            Schema::table('commerce_order_items', fn (Blueprint $table) => $table->string('billing_model', 20)->default('legacy_monthly'));
        }
        if (! Schema::hasTable('billing_fulfillment_requests')) Schema::create('billing_fulfillment_requests', function (Blueprint $table) {
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

    private function columnIsNullable(string $table, string $column): bool
    {
        $definition = collect(Schema::getColumns($table))->firstWhere('name', $column);

        if (! $definition) {
            throw new RuntimeException("Missing expected {$table}.{$column} column.");
        }

        return (bool) $definition['nullable'];
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

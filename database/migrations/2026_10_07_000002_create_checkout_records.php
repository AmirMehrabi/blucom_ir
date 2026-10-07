<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->char('request_fingerprint', 64);
            $table->string('status', 30)->default('reserved');
            $table->unsignedBigInteger('total_amount');
            $table->string('currency', 3);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['customer_id', 'idempotency_key']);
        });
        Schema::create('commerce_order_items', function (Blueprint $table) {
            $table->id();
            // Initial checkout purchases exactly one DID, including its monthly plan.
            $table->foreignId('commerce_order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('sip_number_id')->constrained()->restrictOnDelete();
            $table->foreignId('number_offer_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_version_id')->constrained()->restrictOnDelete();
            $table->json('snapshot');
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->timestamps();
        });
        Schema::create('number_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sip_number_id')->constrained()->restrictOnDelete();
            $table->foreignId('commerce_order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('held');
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
        });
        Schema::create('commerce_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('invoice_number', 50)->unique();
            $table->foreignId('commerce_order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20)->default('proforma');
            $table->string('status', 30)->default('issued');
            $table->unsignedBigInteger('total_amount');
            $table->string('currency', 3);
            $table->json('buyer_snapshot');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::create('commerce_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commerce_invoice_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('commerce_order_item_id')->unique()->constrained()->restrictOnDelete();
            $table->json('snapshot');
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->timestamps();
        });
        Schema::create('payment_attempts', function (Blueprint $table) {
            // This durable unique ID will also be the local bank order ID.
            $table->id();
            $table->foreignId('commerce_invoice_id')->constrained()->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->string('provider', 30);
            $table->string('account_key', 64);
            $table->string('ref_id', 100)->nullable();
            $table->string('sale_reference', 100)->nullable();
            $table->unsignedBigInteger('business_amount');
            $table->string('business_currency', 3);
            $table->unsignedBigInteger('gateway_amount');
            $table->string('gateway_unit', 10);
            $table->string('status', 30)->default('created');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->unique(['commerce_invoice_id', 'idempotency_key']);
            $table->unique(['provider', 'account_key', 'ref_id']);
            $table->unique(['provider', 'account_key', 'sale_reference']);
        });
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_attempt_id')->constrained()->restrictOnDelete();
            $table->uuid('event_key')->unique();
            $table->string('type', 40);
            $table->json('evidence');
            $table->timestamp('created_at');
        });
        Schema::create('number_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sip_number_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('commerce_order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payment_attempt_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });
        Schema::create('number_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('number_assignment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_version_id')->constrained()->restrictOnDelete();
            $table->json('snapshot');
            $table->unsignedBigInteger('monthly_amount');
            $table->string('currency', 3);
            $table->string('status', 30)->default('pending_activation');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('period_starts_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();
            $table->timestamps();
        });
        Schema::table('sip_numbers', function (Blueprint $table) {
            $table->foreignId('current_reservation_id')->nullable()->unique()->constrained('number_reservations')->restrictOnDelete();
            $table->foreignId('current_assignment_id')->nullable()->unique()->constrained('number_assignments')->restrictOnDelete();
        });
        Schema::table('commerce_audit_events', function (Blueprint $table) {
            $table->unsignedBigInteger('actor_user_id')->nullable()->change();
            $table->foreignId('actor_customer_id')->nullable()->constrained('customers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Never silently discard financial or customer audit history during a rollback.
        if (DB::table('commerce_orders')->exists()
            || DB::table('commerce_audit_events')->whereNotNull('actor_customer_id')->exists()) {
            throw new RuntimeException('Commerce records require an explicit retention plan before rollback.');
        }
        Schema::table('commerce_audit_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('actor_customer_id');
            $table->unsignedBigInteger('actor_user_id')->nullable(false)->change();
        });
        Schema::table('sip_numbers', function (Blueprint $table) {
            $table->dropForeign(['current_reservation_id']);
            $table->dropForeign(['current_assignment_id']);
        });
        Schema::table('sip_numbers', function (Blueprint $table) {
            $table->dropUnique(['current_reservation_id']);
            $table->dropUnique(['current_assignment_id']);
        });
        Schema::table('sip_numbers', function (Blueprint $table) {
            $table->dropColumn(['current_reservation_id', 'current_assignment_id']);
        });
        foreach (['number_subscriptions', 'number_assignments', 'payment_events', 'payment_attempts', 'commerce_invoice_items', 'commerce_invoices', 'number_reservations', 'commerce_order_items', 'commerce_orders'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

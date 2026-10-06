<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sip_gateways', fn (Blueprint $table) => $table->unsignedInteger('commerce_revision')->default(0));
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('archived')->default(false);
            $table->timestamps();
        });
        Schema::create('plan_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('billing_interval', 20)->default('monthly');
            $table->json('features');
            $table->json('limits');
            $table->string('limit_scope', 20)->default('tenant');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'version']);
        });
        Schema::table('sip_numbers', function (Blueprint $table) {
            // Null distinguishes untouched legacy resources from commerce-managed stock.
            $table->string('inventory_state', 20)->nullable()->index();
            $table->unsignedInteger('inventory_revision')->default(0);
            $table->json('destination_prefixes')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('readiness_evidence')->nullable();
            $table->string('readiness_fingerprint', 64)->nullable();
        });
        Schema::create('number_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sip_number_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_version_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('monthly_amount');
            $table->string('currency', 3)->default('IRT');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
        });
        Schema::table('sip_numbers', function (Blueprint $table) {
            $table->foreignId('current_offer_id')->nullable()->constrained('number_offers')->restrictOnDelete();
        });
        Schema::create('commerce_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('event');
            $table->string('resource_type');
            $table->unsignedBigInteger('resource_id');
            $table->text('reason');
            $table->json('metadata');
            $table->timestamp('created_at');
        });
        // Deliberately no ownership, routing, or gateway data changes.
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_audit_events');
        Schema::table('sip_numbers', fn (Blueprint $table) => $table->dropConstrainedForeignId('current_offer_id'));
        Schema::dropIfExists('number_offers');
        Schema::table('sip_numbers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropColumn(['inventory_state', 'inventory_revision', 'destination_prefixes', 'reviewed_at', 'readiness_evidence', 'readiness_fingerprint']);
        });
        Schema::dropIfExists('plan_versions');
        Schema::dropIfExists('plans');
        Schema::table('sip_gateways', fn (Blueprint $table) => $table->dropColumn('commerce_revision'));
    }
};

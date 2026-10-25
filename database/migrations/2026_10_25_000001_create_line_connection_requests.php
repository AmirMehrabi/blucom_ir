<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('line_connection_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->string('normalized_number', 32);
            $table->string('provider_name', 100);
            $table->text('notes')->nullable();
            $table->string('status')->default('open');
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'normalized_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('line_connection_requests');
    }
};

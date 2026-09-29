<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('line_setup_wizards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('answer_type', 16)->nullable();
            $table->foreignId('sip_gateway_id')->nullable()->constrained('sip_gateways')->nullOnDelete();
            $table->foreignId('sip_number_id')->nullable()->constrained('sip_numbers')->nullOnDelete();
            $table->unique(['tenant_id', 'created_by_user_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('line_setup_wizards');
    }
};

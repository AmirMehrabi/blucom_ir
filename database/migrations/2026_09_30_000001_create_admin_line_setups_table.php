<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_line_setups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->json('data')->nullable();
            $table->unsignedTinyInteger('step')->default(2);
            $table->foreignId('sip_number_id')->nullable()->constrained()->nullOnDelete();
            $table->string('announcement_upload')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['created_by_user_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_line_setups');
    }
};

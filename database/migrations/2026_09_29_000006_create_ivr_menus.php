<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ivr_menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->json('draft_config')->nullable();
            $table->json('published_config')->nullable();
            $table->json('previous_config')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ivr_menus');
    }
};

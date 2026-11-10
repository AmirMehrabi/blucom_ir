<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_records', function (Blueprint $table) {
            $table->string('outcome', 24)->nullable();
            $table->string('answer_evidence', 32)->nullable();
            $table->unsignedSmallInteger('outcome_version')->nullable();
            $table->string('originate_disposition', 64)->nullable();
            $table->index(['tenant_id', 'outcome', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('call_records', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'outcome', 'started_at']);
            $table->dropColumn(['outcome', 'answer_evidence', 'outcome_version', 'originate_disposition']);
        });
    }
};

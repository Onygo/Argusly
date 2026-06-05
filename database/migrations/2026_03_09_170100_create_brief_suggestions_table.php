<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brief_suggestions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('brief_id');
            $table->string('suggestion_type', 64);
            $table->longText('original_value')->nullable();
            $table->longText('suggested_value');
            $table->text('rationale')->nullable();
            $table->string('status', 24)->default('pending');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['brief_id', 'status'], 'brief_suggestions_brief_status_idx');
            $table->index(['brief_id', 'suggestion_type'], 'brief_suggestions_brief_type_idx');
            $table->index(['brief_id', 'created_at'], 'brief_suggestions_brief_created_idx');

            $table->foreign('brief_id')->references('id')->on('briefs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brief_suggestions');
    }
};

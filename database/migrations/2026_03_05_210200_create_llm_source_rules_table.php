<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_source_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 32);
            $table->string('domain_pattern', 255);
            $table->unsignedInteger('priority')->default(100);
            $table->timestamps();

            $table->index(['priority'], 'llm_source_rules_priority_idx');
            $table->unique(['domain_pattern', 'type'], 'llm_source_rules_pattern_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_source_rules');
    }
};

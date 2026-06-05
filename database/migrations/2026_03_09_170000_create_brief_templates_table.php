<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brief_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('name', 191);
            $table->string('content_type', 64)->nullable();
            $table->json('default_values');
            $table->json('required_fields')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['workspace_id', 'is_active'], 'brief_templates_workspace_active_idx');
            $table->index(['workspace_id', 'content_type'], 'brief_templates_workspace_type_idx');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brief_templates');
    }
};

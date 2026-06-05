<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_usage', function (Blueprint $table) {
            $table->id();
            $table->uuid('workspace_id');
            $table->string('year_month', 7); // YYYY-MM
            $table->unsignedInteger('briefs_count')->default(0);
            $table->unsignedInteger('drafts_count')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'year_month']);
            $table->index(['year_month']);
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_usage');
    }
};

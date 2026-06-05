<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('workspace_id')->unique();
            $table->boolean('default_internal_linking_enabled')->default(true);
            $table->boolean('external_suggestions_enabled')->default(false);
            $table->unsignedInteger('max_outbound_links_per_article')->default(6);
            $table->unsignedInteger('max_cross_domain_links_per_month')->default(20);
            $table->decimal('min_similarity_threshold', 4, 2)->default(0.70);
            $table->decimal('min_audience_overlap_threshold', 4, 2)->default(0.60);
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_profiles');
    }
};

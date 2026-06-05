<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the image_presets table for organization-scoped image generation presets.
 *
 * Each organization can have multiple presets with custom styling instructions.
 * Only one preset per organization can be marked as default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_presets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->string('name', 255);
            $table->text('instructions');
            $table->boolean('is_default')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['organization_id', 'is_default'], 'image_presets_org_default_idx');
            $table->index(['organization_id', 'name'], 'image_presets_org_name_idx');

            // Foreign keys
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_presets');
    }
};

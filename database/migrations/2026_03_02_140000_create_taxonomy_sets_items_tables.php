<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxonomy_sets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 140);
            $table->string('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('taxonomy_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taxonomy_set_id')->constrained('taxonomy_sets')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('name', 140);
            $table->string('slug', 140);
            $table->foreignId('parent_id')->nullable()->constrained('taxonomy_items')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['taxonomy_set_id', 'slug']);
            $table->index('type');
            $table->index('parent_id');
            $table->index('is_active');
        });

        Schema::create('taxonomy_set_tenant', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taxonomy_set_id')->constrained('taxonomy_sets')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('organizations')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['taxonomy_set_id', 'tenant_id']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_set_tenant');
        Schema::dropIfExists('taxonomy_items');
        Schema::dropIfExists('taxonomy_sets');
    }
};


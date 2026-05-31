<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topics', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('status')->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'brand_id', 'slug'], 'topics_scope_slug_unique');
            $table->index(['account_id', 'brand_id', 'status'], 'topics_scope_status_index');
        });

        Schema::create('topic_clusters', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'brand_id', 'name'], 'topic_clusters_scope_name_unique');
            $table->index(['account_id', 'brand_id'], 'topic_clusters_scope_index');
        });

        Schema::create('topic_relationships', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('parent_topic_id')->constrained('topics')->cascadeOnDelete();
            $table->foreignId('child_topic_id')->constrained('topics')->cascadeOnDelete();
            $table->string('relationship_type')->index();
            $table->timestamps();

            $table->unique(['parent_topic_id', 'child_topic_id', 'relationship_type'], 'topic_relationship_unique');
        });

        Schema::create('brand_topics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('priority')->default(0);
            $table->decimal('importance_score', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['brand_id', 'topic_id']);
            $table->index(['brand_id', 'priority']);
        });

        Schema::create('topic_cluster_topics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('topic_cluster_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['topic_cluster_id', 'topic_id'], 'topic_cluster_topic_unique');
        });

        Schema::create('topicables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->morphs('topicable');
            $table->string('relationship_type')->default('primary')->index();
            $table->decimal('relevance_score', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['topic_id', 'topicable_type', 'topicable_id', 'relationship_type'], 'topicables_unique');
            $table->index(['account_id', 'brand_id', 'topic_id'], 'topicables_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topicables');
        Schema::dropIfExists('topic_cluster_topics');
        Schema::dropIfExists('brand_topics');
        Schema::dropIfExists('topic_relationships');
        Schema::dropIfExists('topic_clusters');
        Schema::dropIfExists('topics');
    }
};

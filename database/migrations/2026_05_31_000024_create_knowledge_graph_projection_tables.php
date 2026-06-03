<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('graph_nodes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('node_type', 64)->index();
            $table->string('source_type', 160);
            $table->unsignedBigInteger('source_id');
            $table->string('label');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'source_type', 'source_id'], 'graph_nodes_source_unique');
            $table->index(['account_id', 'brand_id', 'node_type'], 'graph_nodes_tenant_type_index');
            $table->index(['source_type', 'source_id'], 'graph_nodes_source_index');
        });

        Schema::create('graph_edges', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('source_node_id')->constrained('graph_nodes')->cascadeOnDelete();
            $table->foreignId('target_node_id')->constrained('graph_nodes')->cascadeOnDelete();
            $table->string('relationship_type', 64)->index();
            $table->decimal('strength', 5, 2)->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'brand_id', 'source_node_id', 'target_node_id', 'relationship_type'], 'graph_edges_unique');
            $table->index(['account_id', 'brand_id', 'relationship_type'], 'graph_edges_tenant_type_index');
            $table->index(['target_node_id', 'relationship_type'], 'graph_edges_target_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graph_edges');
        Schema::dropIfExists('graph_nodes');
    }
};

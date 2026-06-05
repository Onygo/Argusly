<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_batch_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch_id')->index();
            $table->string('subkeyword', 255);
            $table->string('angle', 255)->nullable();
            $table->string('intent', 120)->nullable();
            $table->string('status', 32)->default('pending');
            $table->uuid('brief_id')->nullable()->index();
            $table->uuid('draft_id')->nullable()->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'sort_order'], 'content_batch_items_batch_sort_idx');
            $table->index(['batch_id', 'status'], 'content_batch_items_batch_status_idx');

            $table->foreign('batch_id')->references('id')->on('content_batches')->cascadeOnDelete();
            $table->foreign('brief_id')->references('id')->on('briefs')->nullOnDelete();
            $table->foreign('draft_id')->references('id')->on('drafts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_batch_items');
    }
};


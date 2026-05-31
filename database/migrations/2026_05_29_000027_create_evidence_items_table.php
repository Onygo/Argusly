<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('evidence_type')->index();
            $table->string('title')->nullable();
            $table->string('url', 2048)->nullable();
            $table->text('snippet')->nullable();
            $table->json('raw_payload')->nullable();
            $table->unsignedTinyInteger('confidence_score')->nullable();
            $table->timestamp('captured_at')->nullable()->index();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'evidence_items_subject_index');
            $table->index(['account_id', 'brand_id', 'evidence_type', 'captured_at'], 'evidence_items_tenant_type_index');
            $table->index(['source_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_items');
    }
};

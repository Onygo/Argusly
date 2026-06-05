<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('type', 40);
            $table->text('source_url');
            $table->text('final_url')->nullable();
            $table->string('source_domain', 191)->nullable();
            $table->string('source_title')->nullable();
            $table->string('source_language', 8)->nullable();
            $table->string('extraction_status', 40)->default('pending');
            $table->timestamp('fetched_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->longText('extracted_text')->nullable();
            $table->json('extracted_outline_json')->nullable();
            $table->json('analysis_json')->nullable();
            $table->json('generated_payload_json')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'type']);
            $table->index(['workspace_id', 'extraction_status']);
        });

        Schema::table('briefs', function (Blueprint $table): void {
            $table->uuid('content_source_id')->nullable()->after('content_id');
            $table->index('content_source_id');
        });
    }

    public function down(): void
    {
        Schema::table('briefs', function (Blueprint $table): void {
            $table->dropIndex(['content_source_id']);
            $table->dropColumn('content_source_id');
        });

        Schema::dropIfExists('content_sources');
    }
};

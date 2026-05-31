<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_media_assets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->index();
            $table->string('type')->index();
            $table->string('status')->default('draft')->index();
            $table->string('file_path')->nullable();
            $table->string('external_asset_urn')->nullable()->index();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'provider']);
            $table->index(['social_post_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_media_assets');
    }
};

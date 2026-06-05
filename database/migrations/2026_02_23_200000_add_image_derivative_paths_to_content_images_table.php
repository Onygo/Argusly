<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_images', function (Blueprint $table): void {
            $table->string('original_path')->nullable()->after('image_path');
            $table->string('medium_path')->nullable()->after('original_path');
            $table->string('thumbnail_path')->nullable()->after('medium_path');

            $table->string('original_webp_path')->nullable()->after('thumbnail_path');
            $table->string('medium_webp_path')->nullable()->after('original_webp_path');
            $table->string('thumbnail_webp_path')->nullable()->after('medium_webp_path');

            $table->unsignedInteger('width')->nullable()->after('credit_cost');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->unsignedBigInteger('file_size')->nullable()->after('height');
        });
    }

    public function down(): void
    {
        Schema::table('content_images', function (Blueprint $table): void {
            $table->dropColumn([
                'original_path',
                'medium_path',
                'thumbnail_path',
                'original_webp_path',
                'medium_webp_path',
                'thumbnail_webp_path',
                'width',
                'height',
                'file_size',
            ]);
        });
    }
};

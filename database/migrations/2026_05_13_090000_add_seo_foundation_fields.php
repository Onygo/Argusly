<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_images', function (Blueprint $table): void {
            if (! Schema::hasColumn('content_images', 'alt_text')) {
                $table->string('alt_text', 500)->nullable()->after('image_url');
            }
        });

        Schema::table('contents', function (Blueprint $table): void {
            if (! Schema::hasColumn('contents', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('content_images', function (Blueprint $table): void {
            if (Schema::hasColumn('content_images', 'alt_text')) {
                $table->dropColumn('alt_text');
            }
        });

        Schema::table('contents', function (Blueprint $table): void {
            if (Schema::hasColumn('contents', 'reviewed_at')) {
                $table->dropColumn('reviewed_at');
            }
        });
    }
};

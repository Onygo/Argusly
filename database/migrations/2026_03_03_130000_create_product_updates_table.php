<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_updates', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('summary', 500);
            $table->longText('body_markdown');
            $table->string('version')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('is_public')->default(false);
            $table->dateTime('published_at');
            $table->timestamps();

            $table->index(['is_public', 'published_at']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            Schema::table('product_updates', function (Blueprint $table): void {
                $table->fullText(['title', 'summary', 'body_markdown'], 'product_updates_fulltext_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_updates');
    }
};

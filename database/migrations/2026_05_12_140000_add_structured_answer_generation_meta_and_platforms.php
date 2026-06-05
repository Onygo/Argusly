<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            if (! Schema::hasColumn('contents', 'answer_block_generation_meta')) {
                $table->json('answer_block_generation_meta')->nullable()->after('answer_block_generation_last_warning');
            }
        });

        Schema::table('structured_answer_blocks', function (Blueprint $table): void {
            if (! Schema::hasColumn('structured_answer_blocks', 'platforms')) {
                $table->json('platforms')->nullable()->after('entities');
            }
        });
    }

    public function down(): void
    {
        Schema::table('structured_answer_blocks', function (Blueprint $table): void {
            if (Schema::hasColumn('structured_answer_blocks', 'platforms')) {
                $table->dropColumn('platforms');
            }
        });

        Schema::table('contents', function (Blueprint $table): void {
            if (Schema::hasColumn('contents', 'answer_block_generation_meta')) {
                $table->dropColumn('answer_block_generation_meta');
            }
        });
    }
};

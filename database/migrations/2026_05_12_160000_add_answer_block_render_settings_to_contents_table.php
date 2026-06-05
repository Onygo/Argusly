<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            if (! Schema::hasColumn('contents', 'answer_block_render_mode')) {
                $table->string('answer_block_render_mode', 32)->nullable()->after('answer_block_generation_meta');
            }

            if (! Schema::hasColumn('contents', 'answer_block_max_visible')) {
                $table->unsignedTinyInteger('answer_block_max_visible')->nullable()->after('answer_block_render_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $columns = [];

            if (Schema::hasColumn('contents', 'answer_block_max_visible')) {
                $columns[] = 'answer_block_max_visible';
            }

            if (Schema::hasColumn('contents', 'answer_block_render_mode')) {
                $columns[] = 'answer_block_render_mode';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};

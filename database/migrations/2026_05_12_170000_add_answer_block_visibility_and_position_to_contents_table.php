<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            if (! Schema::hasColumn('contents', 'answer_block_visibility')) {
                $table->string('answer_block_visibility', 32)->nullable()->after('answer_block_render_mode');
            }

            if (! Schema::hasColumn('contents', 'answer_block_position')) {
                $table->string('answer_block_position', 32)->nullable()->after('answer_block_visibility');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $columns = [];

            if (Schema::hasColumn('contents', 'answer_block_position')) {
                $columns[] = 'answer_block_position';
            }

            if (Schema::hasColumn('contents', 'answer_block_visibility')) {
                $columns[] = 'answer_block_visibility';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};

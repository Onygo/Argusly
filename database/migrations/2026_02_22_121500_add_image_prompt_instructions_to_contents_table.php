<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            if (! Schema::hasColumn('contents', 'image_prompt_instructions')) {
                $table->text('image_prompt_instructions')->nullable()->after('actual_word_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            if (Schema::hasColumn('contents', 'image_prompt_instructions')) {
                $table->dropColumn('image_prompt_instructions');
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('draft_analyses', function (Blueprint $table): void {
            $table->string('status', 20)->default('completed')->after('id');
            $table->longText('raw_response')->nullable()->after('suggestions');
            $table->json('parser_errors')->nullable()->after('raw_response');
            $table->json('validation_errors')->nullable()->after('parser_errors');

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('draft_analyses', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'raw_response', 'parser_errors', 'validation_errors']);
        });
    }
};

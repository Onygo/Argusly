<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_automation_run_items', function (Blueprint $table): void {
            $table->uuid('source_run_item_id')->nullable()->after('automation_id');
            $table->string('item_type', 32)->default('source')->after('chain_index');
            $table->string('source_locale', 12)->nullable()->after('locale');
            $table->boolean('is_source_locale')->default(true)->after('source_locale');
            $table->uuid('content_family_id')->nullable()->after('content_id');
            $table->string('generation_status', 32)->nullable()->after('title');
            $table->string('translation_status', 32)->nullable()->after('generation_status');
            $table->string('delivery_status', 32)->nullable()->after('translation_status');
            $table->string('publication_status', 32)->nullable()->after('delivery_status');

            $table->index(['automation_run_id', 'item_type'], 'automation_run_items_run_type_idx');
            $table->index(['automation_run_id', 'locale'], 'automation_run_items_run_locale_idx');
            $table->index(['source_run_item_id'], 'automation_run_items_source_item_idx');
            $table->index(['content_family_id'], 'automation_run_items_family_idx');

            $table->foreign('source_run_item_id')
                ->references('id')
                ->on('content_automation_run_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('content_automation_run_items', function (Blueprint $table): void {
            $table->dropForeign(['source_run_item_id']);
            $table->dropIndex('automation_run_items_run_type_idx');
            $table->dropIndex('automation_run_items_run_locale_idx');
            $table->dropIndex('automation_run_items_source_item_idx');
            $table->dropIndex('automation_run_items_family_idx');

            $table->dropColumn([
                'source_run_item_id',
                'item_type',
                'source_locale',
                'is_source_locale',
                'content_family_id',
                'generation_status',
                'translation_status',
                'delivery_status',
                'publication_status',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_publications', function (Blueprint $table): void {
            if (! Schema::hasColumn('content_publications', 'scheduled_publish_at')) {
                $table->timestamp('scheduled_publish_at')->nullable()->after('last_delivered_at');
                $table->index('scheduled_publish_at', 'content_publications_scheduled_publish_idx');
            }
        });

        Schema::table('programmatic_publication_plan_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('programmatic_publication_plan_items', 'content_publication_id')) {
                $table->uuid('content_publication_id')->nullable()->after('publication_readiness_id');
                $table->index('content_publication_id', 'prog_pub_plan_item_pub_idx');
                $table->foreign('content_publication_id', 'prog_pub_plan_item_pub_fk')
                    ->references('id')
                    ->on('content_publications')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('programmatic_publication_plan_items', function (Blueprint $table): void {
            if (Schema::hasColumn('programmatic_publication_plan_items', 'content_publication_id')) {
                $table->dropForeign('prog_pub_plan_item_pub_fk');
                $table->dropIndex('prog_pub_plan_item_pub_idx');
                $table->dropColumn('content_publication_id');
            }
        });

        Schema::table('content_publications', function (Blueprint $table): void {
            if (Schema::hasColumn('content_publications', 'scheduled_publish_at')) {
                $table->dropIndex('content_publications_scheduled_publish_idx');
                $table->dropColumn('scheduled_publish_at');
            }
        });
    }
};

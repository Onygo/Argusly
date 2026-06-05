<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_publish_targets', function (Blueprint $table) {
            $table->uuid('content_destination_id')->nullable()->after('client_site_id');

            $table->index(['content_destination_id', 'target_type'], 'content_publish_targets_destination_type_idx');
            $table->index(['content_id', 'content_destination_id', 'target_type'], 'content_publish_targets_content_destination_type_idx');

            $table->foreign('content_destination_id', 'cpt_destination_id_fk')
                ->references('id')
                ->on('content_destinations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('content_publish_targets', function (Blueprint $table) {
            $table->dropForeign('cpt_destination_id_fk');
            $table->dropIndex('content_publish_targets_destination_type_idx');
            $table->dropIndex('content_publish_targets_content_destination_type_idx');
            $table->dropColumn('content_destination_id');
        });
    }
};

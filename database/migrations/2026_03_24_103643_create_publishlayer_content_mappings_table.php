<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('publishlayer_content_mappings')) {
            return;
        }

        Schema::create('publishlayer_content_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('site_key', 128);
            $table->string('mapping_type', 64)->default('content');
            $table->string('publishlayer_content_id', 128)->nullable();
            $table->string('publishlayer_draft_id', 128)->nullable();
            $table->string('external_type', 191)->nullable();
            $table->string('external_id', 128)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['site_key', 'mapping_type', 'publishlayer_content_id'],
                'publishlayer_content_map_site_type_content_uidx'
            );
            $table->index(['site_key', 'publishlayer_draft_id'], 'publishlayer_content_map_site_draft_idx');
            $table->index(['site_key', 'external_type', 'external_id'], 'publishlayer_content_map_external_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publishlayer_content_mappings');
    }
};

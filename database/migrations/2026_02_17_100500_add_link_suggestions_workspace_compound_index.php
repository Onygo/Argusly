<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('link_suggestions')) {
            return;
        }

        /** @var Builder $schema */
        $schema = Schema::getConnection()->getSchemaBuilder();

        if ($schema->hasIndex('link_suggestions', 'ls_src_tgt_created_idx')) {
            return;
        }

        Schema::table('link_suggestions', function (Blueprint $table) {
            $table->index(
                ['source_workspace_id', 'target_workspace_id', 'created_at'],
                'ls_src_tgt_created_idx'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('link_suggestions')) {
            return;
        }

        /** @var Builder $schema */
        $schema = Schema::getConnection()->getSchemaBuilder();

        if (! $schema->hasIndex('link_suggestions', 'ls_src_tgt_created_idx')) {
            return;
        }

        Schema::table('link_suggestions', function (Blueprint $table) {
            $table->dropIndex('ls_src_tgt_created_idx');
        });
    }
};

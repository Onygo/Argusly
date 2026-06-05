<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'content_indexation_health';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            if ($this->tableMatchesExpectedShape()) {
                return;
            }

            Schema::dropIfExists(self::TABLE);
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('content_id');
            $table->boolean('indexed')->nullable();
            $table->boolean('canonical_accepted')->nullable();
            $table->boolean('duplicate_detected')->default(false);
            $table->boolean('redirect_issue')->default(false);
            $table->boolean('crawled_not_indexed')->default(false);
            $table->boolean('noindex_detected')->default(false);
            $table->string('sitemap_status')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedTinyInteger('health_score')->nullable();
            $table->string('canonical_url')->nullable();
            $table->string('google_selected_canonical')->nullable();
            $table->json('issues_json')->nullable();
            $table->json('discovered_urls_json')->nullable();
            $table->timestamps();

            $table->unique('content_id', 'cih_content_uidx');

            $table->foreign('content_id', 'cih_content_fk')
                ->references('id')
                ->on('contents')
                ->cascadeOnDelete();

            $table->index(['indexed', 'canonical_accepted'], 'cih_index_canon_idx');
            $table->index(['duplicate_detected', 'redirect_issue'], 'cih_dup_redirect_idx');
            $table->index('last_checked_at', 'cih_content_checked_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function tableMatchesExpectedShape(): bool
    {
        foreach ([
            'id',
            'content_id',
            'indexed',
            'canonical_accepted',
            'duplicate_detected',
            'redirect_issue',
            'crawled_not_indexed',
            'noindex_detected',
            'sitemap_status',
            'last_checked_at',
            'health_score',
            'canonical_url',
            'google_selected_canonical',
            'issues_json',
            'discovered_urls_json',
            'created_at',
            'updated_at',
        ] as $column) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                return false;
            }
        }

        return true;
    }
};

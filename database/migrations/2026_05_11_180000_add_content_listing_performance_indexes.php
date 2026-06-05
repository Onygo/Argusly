<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('contents', 'cnt_ws_stage_upd_idx', function (Blueprint $table): void {
            $table->index(['workspace_id', 'lifecycle_stage', 'updated_at', 'deleted_at'], 'cnt_ws_stage_upd_idx');
        });

        $this->addIndexIfMissing('contents', 'cnt_ws_site_del_idx', function (Blueprint $table): void {
            $table->index(['workspace_id', 'client_site_id', 'deleted_at'], 'cnt_ws_site_del_idx');
        });

        $this->addIndexIfMissing('contents', 'cnt_ws_lang_del_idx', function (Blueprint $table): void {
            $table->index(['workspace_id', 'language', 'deleted_at'], 'cnt_ws_lang_del_idx');
        });

        $this->addIndexIfMissing('contents', 'cnt_ws_pub_del_idx', function (Blueprint $table): void {
            $table->index(['workspace_id', 'publish_status', 'deleted_at'], 'cnt_ws_pub_del_idx');
        });

        $this->addIndexIfMissing('contents', 'cnt_ws_health_ai_idx', function (Blueprint $table): void {
            $table->index(['workspace_id', 'content_health_score', 'ai_visibility_score'], 'cnt_ws_health_ai_idx');
        });

        $this->addIndexIfMissing('contents', 'cnt_family_lang_idx', function (Blueprint $table): void {
            $table->index(['family_id', 'language'], 'cnt_family_lang_idx');
        });

        $this->addIndexIfMissing('contents', 'cnt_org_sort_idx', function (Blueprint $table): void {
            $table->index(['workspace_id', 'created_at', 'id'], 'cnt_org_sort_idx');
        });

        $this->addIndexIfMissing('contents', 'cnt_site_pub_idx', function (Blueprint $table): void {
            $table->index(['client_site_id', 'publish_status', 'first_published_at'], 'cnt_site_pub_idx');
        });

        $this->addIndexIfMissing('contents', 'cnt_series_idx', function (Blueprint $table): void {
            $table->index(['series_id', 'created_at'], 'cnt_series_idx');
        });

        $this->addIndexIfMissing('contents', 'cnt_auto_idx', function (Blueprint $table): void {
            $table->index(['automation_id', 'created_at'], 'cnt_auto_idx');
        });

        $this->addIndexIfMissing('content_ai_visibility_snapshots', 'cavs_cid_cap_idx', function (Blueprint $table): void {
            $table->index(['content_id', 'captured_at'], 'cavs_cid_cap_idx');
        });

        $this->addIndexIfMissing('content_recommendations', 'crec_cid_st_cr_idx', function (Blueprint $table): void {
            $table->index(['content_id', 'status', 'created_at'], 'crec_cid_st_cr_idx');
        });

        $this->addIndexIfMissing('content_translations', 'ctr_ct_st_upd_idx', function (Blueprint $table): void {
            $table->index(['content_id', 'status', 'updated_at'], 'ctr_ct_st_upd_idx');
        });

        $this->addIndexIfMissing('content_translations', 'ctr_tgt_loc_idx', function (Blueprint $table): void {
            $table->index(['target_content_id', 'target_locale'], 'ctr_tgt_loc_idx');
        });

        $this->addIndexIfMissing('content_translations', 'ctr_job_uuid_idx', function (Blueprint $table): void {
            $table->index(['processing_job_uuid'], 'ctr_job_uuid_idx');
        });

        $this->addIndexIfMissing('content_translations', 'ctr_st_upd_idx', function (Blueprint $table): void {
            $table->index(['status', 'updated_at'], 'ctr_st_upd_idx');
        });

        $this->addIndexIfMissing('content_publications', 'cp_ct_deliv_idx', function (Blueprint $table): void {
            $table->index(['content_id', 'delivery_status', 'last_delivered_at'], 'cp_ct_deliv_idx');
        });

        $this->addIndexIfMissing('content_publications', 'cp_site_deliv_idx', function (Blueprint $table): void {
            $table->index(['client_site_id', 'delivery_status', 'created_at'], 'cp_site_deliv_idx');
        });

        $this->addIndexIfMissing('jobs', 'jobs_q_res_cr_idx', function (Blueprint $table): void {
            $table->index(['queue', 'reserved_at', 'created_at'], 'jobs_q_res_cr_idx');
        });

        $this->addIndexIfMissing('failed_jobs', 'fj_queue_fail_idx', function (Blueprint $table): void {
            $table->index(['queue', 'failed_at'], 'fj_queue_fail_idx');
        });
    }

    public function down(): void
    {
        $this->dropIndexIfExists('failed_jobs', 'fj_queue_fail_idx');
        $this->dropIndexIfExists('jobs', 'jobs_q_res_cr_idx');
        $this->dropIndexIfExists('content_publications', 'cp_site_deliv_idx');
        $this->dropIndexIfExists('content_publications', 'cp_ct_deliv_idx');
        $this->dropIndexIfExists('content_translations', 'ctr_st_upd_idx');
        $this->dropIndexIfExists('content_translations', 'ctr_job_uuid_idx');
        $this->dropIndexIfExists('content_translations', 'ctr_tgt_loc_idx');
        $this->dropIndexIfExists('content_translations', 'ctr_ct_st_upd_idx');
        $this->dropIndexIfExists('content_recommendations', 'crec_cid_st_cr_idx');
        $this->dropIndexIfExists('content_ai_visibility_snapshots', 'cavs_cid_cap_idx');
        $this->dropIndexIfExists('contents', 'cnt_auto_idx');
        $this->dropIndexIfExists('contents', 'cnt_series_idx');
        $this->dropIndexIfExists('contents', 'cnt_site_pub_idx');
        $this->dropIndexIfExists('contents', 'cnt_org_sort_idx');
        $this->dropIndexIfExists('contents', 'cnt_family_lang_idx');
        $this->dropIndexIfExists('contents', 'cnt_ws_health_ai_idx');
        $this->dropIndexIfExists('contents', 'cnt_ws_pub_del_idx');
        $this->dropIndexIfExists('contents', 'cnt_ws_lang_del_idx');
        $this->dropIndexIfExists('contents', 'cnt_ws_site_del_idx');
        $this->dropIndexIfExists('contents', 'cnt_ws_stage_upd_idx');
    }

    /**
     * @param  callable(Blueprint):void  $callback
     */
    private function addIndexIfMissing(string $table, string $name, callable $callback): void
    {
        if ($this->hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($callback): void {
            $callback($blueprint);
        });
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (! $this->hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name): void {
            $blueprint->dropIndex($name);
        });
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => (string) ($index['name'] ?? '') === $name);
    }
};

# Performance Notes

## Optimized

- Tightened the main content index query path to keep filtering, sorting, and pagination in SQL and switched the list to `simplePaginate(20)`.
- Reduced list-page relation payloads by selecting only the fields used in the content index and lifecycle dashboard.
- Replaced full draft relation loading on the content index with a precomputed `pending_drafts_count`.
- Cached organization-scoped content index filter counts, lifecycle stage summaries, lifecycle operations cards, and filter lookup lists with versioned keys.
- Batched translation admin page inspections so the default queue admin page only inspects the current page of translation rows instead of the full table.
- Paginated the sites list instead of loading every site in a workspace into memory.
- Cached expensive admin dashboard counters and onboarding summary cards.
- Limited lifecycle AI visibility snapshot loading to the latest two snapshots per provider per content item.

## Indexes Added

- `contents`
  - `cnt_ws_stage_upd_idx`
  - `cnt_ws_site_del_idx`
  - `cnt_ws_lang_del_idx`
  - `cnt_ws_pub_del_idx`
  - `cnt_ws_health_ai_idx`
  - `cnt_family_lang_idx`
  - `cnt_org_sort_idx`
  - `cnt_site_pub_idx`
  - `cnt_series_idx`
  - `cnt_auto_idx`
- `content_translations`
  - `ctr_ct_st_upd_idx`
  - `ctr_tgt_loc_idx`
  - `ctr_job_uuid_idx`
  - `ctr_st_upd_idx`
- `content_publications`
  - `cp_ct_deliv_idx`
  - `cp_site_deliv_idx`
- `content_ai_visibility_snapshots`
  - `cavs_cid_cap_idx`
- `content_recommendations`
  - `crec_cid_st_cr_idx`
- `jobs`
  - `jobs_q_res_cr_idx`
- `failed_jobs`
  - `fj_queue_fail_idx`

## Cache Keys

- Organization-scoped list and dashboard caches now use versioned keys via `App\Services\Performance\PerformanceCacheService`.
- The org content cache version is bumped when content, content publications, or content translations change.
- Admin dashboard aggregate caches use a separate lightweight version key.

## Pages Expected To Improve

- `/content`
- `/content/lifecycle`
- `/sites`
- `/admin/dashboard`
- `/admin/queues` translation section

## Verify Locally

1. Run the content index with `?debug_queries=1` locally and inspect `storage/logs/laravel.log` for `query_profile.content.index`.
2. Run the lifecycle dashboard with `?debug_queries=1` and inspect `query_profile.content.lifecycle`.
3. Compare query counts before and after opening:
   - content index with filters
   - lifecycle dashboard
   - admin queue page with translation rows
4. Confirm cached dashboards refresh after editing content status, lifecycle stage, publication state, or translation state.

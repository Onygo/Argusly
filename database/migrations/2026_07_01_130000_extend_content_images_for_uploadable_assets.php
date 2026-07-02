<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_images', function (Blueprint $table): void {
            if (Schema::hasColumn('content_images', 'content_id')) {
                $table->uuid('content_id')->nullable()->change();
            }

            if (! Schema::hasColumn('content_images', 'workspace_id')) {
                $table->uuid('workspace_id')->nullable()->after('id')->index();
            }
            if (! Schema::hasColumn('content_images', 'campaign_id')) {
                $table->uuid('campaign_id')->nullable()->after('content_id')->index();
            }
            if (! Schema::hasColumn('content_images', 'social_publication_id')) {
                $table->uuid('social_publication_id')->nullable()->after('campaign_id')->index();
            }
            if (! Schema::hasColumn('content_images', 'social_post_variant_id')) {
                $table->uuid('social_post_variant_id')->nullable()->after('social_publication_id')->index();
            }
            if (! Schema::hasColumn('content_images', 'source')) {
                $table->string('source', 32)->default('generated')->after('type')->index();
            }
            if (! Schema::hasColumn('content_images', 'original_filename')) {
                $table->string('original_filename')->nullable()->after('image_url');
            }
            if (! Schema::hasColumn('content_images', 'mime_type')) {
                $table->string('mime_type', 120)->nullable()->after('original_filename');
            }
            if (! Schema::hasColumn('content_images', 'uploaded_by')) {
                $table->unsignedBigInteger('uploaded_by')->nullable()->after('created_by')->index();
            }

            foreach ([
                'display_on_website',
                'display_as_featured_image',
                'use_as_meta_image',
                'use_as_social_image',
                'use_for_linkedin',
            ] as $column) {
                if (! Schema::hasColumn('content_images', $column)) {
                    $table->boolean($column)->default(false)->after('is_active')->index();
                }
            }
        });

        $this->backfillExistingImages();
    }

    public function down(): void
    {
        Schema::table('content_images', function (Blueprint $table): void {
            foreach ([
                'display_on_website',
                'display_as_featured_image',
                'use_as_meta_image',
                'use_as_social_image',
                'use_for_linkedin',
                'uploaded_by',
                'mime_type',
                'original_filename',
                'source',
                'social_post_variant_id',
                'social_publication_id',
                'campaign_id',
                'workspace_id',
            ] as $column) {
                if (Schema::hasColumn('content_images', $column)) {
                    $table->dropColumn($column);
                }
            }

            if (Schema::hasColumn('content_images', 'content_id')) {
                $table->uuid('content_id')->nullable(false)->change();
            }
        });
    }

    private function backfillExistingImages(): void
    {
        DB::table('content_images')
            ->select(['id', 'content_id'])
            ->whereNull('workspace_id')
            ->whereNotNull('content_id')
            ->orderBy('id')
            ->chunk(200, function ($rows): void {
                $contentIds = collect($rows)
                    ->pluck('content_id')
                    ->filter()
                    ->unique()
                    ->values();

                if ($contentIds->isEmpty()) {
                    return;
                }

                $workspaceIds = DB::table('contents')
                    ->whereIn('id', $contentIds)
                    ->pluck('workspace_id', 'id');

                foreach ($rows as $row) {
                    $workspaceId = $workspaceIds[(string) $row->content_id] ?? null;
                    if ($workspaceId) {
                        DB::table('content_images')
                            ->where('id', $row->id)
                            ->update(['workspace_id' => $workspaceId]);
                    }
                }
            });

        DB::table('content_images')
            ->where('type', 'featured')
            ->update([
                'source' => DB::raw("COALESCE(NULLIF(source, ''), 'generated')"),
                'display_on_website' => true,
                'display_as_featured_image' => true,
                'use_as_social_image' => true,
            ]);

        DB::table('content_images')
            ->where('type', 'og')
            ->update([
                'source' => DB::raw("COALESCE(NULLIF(source, ''), 'generated')"),
                'use_as_meta_image' => true,
                'use_as_social_image' => true,
                'use_for_linkedin' => true,
            ]);

        DB::table('content_images')
            ->where('type', 'inline')
            ->update([
                'source' => DB::raw("COALESCE(NULLIF(source, ''), 'generated')"),
                'display_on_website' => true,
            ]);

        DB::table('content_images')
            ->where('provider', 'unsplash')
            ->update(['source' => 'stock']);
    }
};

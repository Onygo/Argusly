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
            $table->boolean('is_active')->default(false)->after('status');
            $table->string('model', 128)->nullable()->after('provider');
            $table->json('metadata')->nullable()->after('error_message');
            $table->uuid('created_by')->nullable()->after('metadata');
            $table->softDeletes();

            $table->index(['content_id', 'type', 'is_active'], 'content_images_content_type_active_idx');
            $table->index(['created_by'], 'content_images_created_by_idx');
        });

        $pairs = DB::table('content_images')
            ->select('content_id', 'type')
            ->distinct()
            ->get();

        foreach ($pairs as $pair) {
            $activeId = DB::table('content_images')
                ->where('content_id', $pair->content_id)
                ->where('type', $pair->type)
                ->where('status', 'ready')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->value('id');

            if ($activeId) {
                DB::table('content_images')
                    ->where('id', $activeId)
                    ->update(['is_active' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('content_images', function (Blueprint $table): void {
            $table->dropIndex('content_images_content_type_active_idx');
            $table->dropIndex('content_images_created_by_idx');
            $table->dropColumn(['is_active', 'model', 'metadata', 'created_by', 'deleted_at']);
        });
    }
};

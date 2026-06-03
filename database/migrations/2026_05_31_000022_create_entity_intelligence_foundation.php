<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table): void {
            $table->foreignId('brand_id')->nullable()->after('account_id')->constrained()->cascadeOnDelete();
            $table->string('slug')->nullable()->after('name');
            $table->string('status')->default('active')->after('description')->index();
            $table->json('metadata')->nullable()->after('aliases');
        });

        DB::table('entities')->orderBy('id')->each(function (object $entity): void {
            DB::table('entities')
                ->where('id', $entity->id)
                ->update([
                    'slug' => Str::slug($entity->name).'-'.$entity->id,
                    'entity_type' => Str::of($entity->entity_type)->snake()->lower()->toString(),
                    'status' => 'active',
                ]);
        });

        Schema::table('entities', function (Blueprint $table): void {
            $table->dropUnique('entities_account_id_name_entity_type_unique');
            $table->string('slug')->nullable(false)->change();
            $table->foreignId('account_id')->nullable()->change();
            $table->unique(['account_id', 'brand_id', 'slug', 'entity_type'], 'entities_scope_slug_type_unique');
            $table->index(['account_id', 'brand_id', 'entity_type', 'status'], 'entities_scope_type_status_index');
        });

        Schema::create('entity_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entity_id')->constrained()->cascadeOnDelete();
            $table->string('alias');
            $table->timestamps();

            $table->unique(['entity_id', 'alias']);
        });

        Schema::table('entity_relationships', function (Blueprint $table): void {
            $table->unsignedInteger('strength')->nullable()->after('relationship_type');
            $table->json('metadata')->nullable()->after('strength');
        });

        Schema::create('entity_mentions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mention_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['entity_id', 'mention_id']);
        });

        Schema::create('entity_topics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('entity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['entity_id', 'topic_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_topics');
        Schema::dropIfExists('entity_mentions');

        Schema::table('entity_relationships', function (Blueprint $table): void {
            $table->dropColumn(['strength', 'metadata']);
        });

        Schema::dropIfExists('entity_aliases');

        Schema::table('entities', function (Blueprint $table): void {
            $table->dropUnique('entities_scope_slug_type_unique');
            $table->dropIndex('entities_scope_type_status_index');
            $table->foreignId('account_id')->nullable(false)->change();
            $table->dropConstrainedForeignId('brand_id');
            $table->dropColumn(['slug', 'status', 'metadata']);
            $table->unique(['account_id', 'name', 'entity_type']);
        });
    }
};

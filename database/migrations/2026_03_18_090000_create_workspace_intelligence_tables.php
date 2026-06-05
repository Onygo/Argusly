<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organization_profiles')) {
            Schema::create('organization_profiles', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('organization_id')->unique();
                $table->text('brand_summary')->nullable();
                $table->text('tone_of_voice')->nullable();
                $table->json('audience_profiles')->nullable();
                $table->json('offerings')->nullable();
                $table->json('differentiators')->nullable();
                $table->json('strategic_topics')->nullable();
                $table->json('seo_topics')->nullable();
                $table->json('visual_direction')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
                $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('personas')) {
            Schema::create('personas', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('organization_id')->index();
                $table->string('type', 64)->index();
                $table->string('name');
                $table->string('source_type', 64);
                $table->json('source_payload')->nullable();
                $table->json('profile_data')->nullable();
                $table->string('status', 32)->default('draft')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
                $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
                $table->index(['organization_id', 'type', 'status']);
            });
        }

        if (! Schema::hasTable('enrichment_runs')) {
            Schema::create('enrichment_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('organization_id')->index();
                $table->string('enrichable_type', 64);
                $table->unsignedBigInteger('enrichable_id')->nullable();
                $table->string('enrichment_type', 64)->index();
                $table->string('source_type', 64);
                $table->json('source_payload')->nullable();
                $table->json('extracted_payload')->nullable();
                $table->json('ai_payload')->nullable();
                $table->string('status', 32)->default('queued')->index();
                $table->float('progress')->default(0);
                $table->text('error_message')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();

                $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
                $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
                $table->index(['organization_id', 'enrichment_type', 'created_at'], 'enr_org_type_created_idx');
                $table->index(['organization_id', 'enrichable_type', 'enrichable_id'], 'enr_org_enrichable_idx');
            });
        } else {
            if (! Schema::hasIndex('enrichment_runs', 'enr_org_type_created_idx')) {
                Schema::table('enrichment_runs', function (Blueprint $table): void {
                    $table->index(['organization_id', 'enrichment_type', 'created_at'], 'enr_org_type_created_idx');
                });
            }

            if (! Schema::hasIndex('enrichment_runs', 'enr_org_enrichable_idx')) {
                Schema::table('enrichment_runs', function (Blueprint $table): void {
                    $table->index(['organization_id', 'enrichable_type', 'enrichable_id'], 'enr_org_enrichable_idx');
                });
            }
        }

        Schema::table('team_members', function (Blueprint $table): void {
            if (! Schema::hasColumn('team_members', 'title')) {
                $table->string('title')->nullable()->after('name');
            }
            if (! Schema::hasColumn('team_members', 'email')) {
                $table->string('email')->nullable()->after('title');
            }
            if (! Schema::hasColumn('team_members', 'public_profile_url')) {
                $table->string('public_profile_url', 2048)->nullable()->after('email');
            }
            if (! Schema::hasColumn('team_members', 'bio_source_text')) {
                $table->text('bio_source_text')->nullable()->after('public_profile_url');
            }
            if (! Schema::hasColumn('team_members', 'source_payload')) {
                $table->json('source_payload')->nullable()->after('bio_source_text');
            }
            if (! Schema::hasColumn('team_members', 'profile_data')) {
                $table->json('profile_data')->nullable()->after('source_payload');
            }
            if (! Schema::hasColumn('team_members', 'status')) {
                $table->string('status', 32)->default('approved')->after('profile_data');
            }
            if (! Schema::hasColumn('team_members', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('status');
            }
            if (! Schema::hasColumn('team_members', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
            }
        });

        Schema::table('team_members', function (Blueprint $table): void {
            if (Schema::hasColumn('team_members', 'created_by') && ! Schema::hasIndex('team_members', 'team_members_created_by_foreign')) {
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            }
            if (Schema::hasColumn('team_members', 'updated_by') && ! Schema::hasIndex('team_members', 'team_members_updated_by_foreign')) {
                $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            }
            if (! Schema::hasIndex('team_members', 'tm_org_status_idx')) {
                $table->index(['organization_id', 'status'], 'tm_org_status_idx');
            }
        });

        if (Schema::hasColumn('team_members', 'title') && Schema::hasColumn('team_members', 'status')) {
            DB::table('team_members')->update([
                'title' => DB::raw('COALESCE(title, role)'),
                'status' => 'approved',
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('team_members')) {
            Schema::table('team_members', function (Blueprint $table): void {
                if (Schema::hasIndex('team_members', 'team_members_created_by_foreign')) {
                    $table->dropForeign(['created_by']);
                }
                if (Schema::hasIndex('team_members', 'team_members_updated_by_foreign')) {
                    $table->dropForeign(['updated_by']);
                }
                if (Schema::hasIndex('team_members', 'tm_org_status_idx')) {
                    $table->dropIndex('tm_org_status_idx');
                }

                $columns = array_values(array_filter([
                    Schema::hasColumn('team_members', 'title') ? 'title' : null,
                    Schema::hasColumn('team_members', 'email') ? 'email' : null,
                    Schema::hasColumn('team_members', 'public_profile_url') ? 'public_profile_url' : null,
                    Schema::hasColumn('team_members', 'bio_source_text') ? 'bio_source_text' : null,
                    Schema::hasColumn('team_members', 'source_payload') ? 'source_payload' : null,
                    Schema::hasColumn('team_members', 'profile_data') ? 'profile_data' : null,
                    Schema::hasColumn('team_members', 'status') ? 'status' : null,
                    Schema::hasColumn('team_members', 'created_by') ? 'created_by' : null,
                    Schema::hasColumn('team_members', 'updated_by') ? 'updated_by' : null,
                ]));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        Schema::dropIfExists('enrichment_runs');
        Schema::dropIfExists('personas');
        Schema::dropIfExists('organization_profiles');
    }
};

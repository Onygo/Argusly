<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pl_inbox_briefs') && ! Schema::hasTable('publishlayer_inbox_briefs')) {
            Schema::rename('pl_inbox_briefs', 'publishlayer_inbox_briefs');
        }

        if (Schema::hasTable('pl_inbox_drafts') && ! Schema::hasTable('publishlayer_inbox_drafts')) {
            Schema::rename('pl_inbox_drafts', 'publishlayer_inbox_drafts');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('publishlayer_inbox_briefs') && ! Schema::hasTable('pl_inbox_briefs')) {
            Schema::rename('publishlayer_inbox_briefs', 'pl_inbox_briefs');
        }

        if (Schema::hasTable('publishlayer_inbox_drafts') && ! Schema::hasTable('pl_inbox_drafts')) {
            Schema::rename('publishlayer_inbox_drafts', 'pl_inbox_drafts');
        }
    }
};

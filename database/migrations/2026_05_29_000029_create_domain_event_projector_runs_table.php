<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_event_projector_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('domain_event_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_uuid');
            $table->string('projector');
            $table->string('status')->default('running')->index();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['event_uuid', 'projector'], 'domain_event_projector_unique');
            $table->index(['domain_event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_event_projector_runs');
    }
};

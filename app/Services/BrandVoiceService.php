<?php

namespace App\Services;

use App\Models\BrandVoice;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class BrandVoiceService
{
    public function setDefault(Workspace $workspace, string $brandVoiceId): BrandVoice
    {
        return DB::transaction(function () use ($workspace, $brandVoiceId): BrandVoice {
            $voice = BrandVoice::query()
                ->where('workspace_id', $workspace->id)
                ->where('id', $brandVoiceId)
                ->lockForUpdate()
                ->firstOrFail();

            BrandVoice::query()
                ->where('workspace_id', $workspace->id)
                ->update(['is_default' => false]);

            $voice->update(['is_default' => true]);

            return $voice;
        });
    }
}

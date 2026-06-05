<?php

namespace App\Services\SocialDistribution;

use App\Models\SocialAccount;
use App\Models\SocialDistributionAuditLog;
use App\Models\SocialPostVariant;
use App\Models\SocialPublication;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class SocialDistributionAuditLogger
{
    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     * @param array<string,mixed> $metadata
     */
    public function record(Model $subject, string $event, ?array $before = null, ?array $after = null, array $metadata = []): SocialDistributionAuditLog
    {
        return SocialDistributionAuditLog::query()->create(array_merge($this->context($subject), [
            'actor_id' => Auth::id(),
            'event' => $event,
            'subject_type' => $subject::class,
            'subject_id' => (string) $subject->getKey(),
            'before' => $before,
            'after' => $after,
            'metadata' => $metadata,
        ]));
    }

    /**
     * @return array<string,mixed>
     */
    private function context(Model $subject): array
    {
        $account = null;
        $variant = null;
        $publication = null;
        $workspaceId = null;
        $organizationId = null;

        if ($subject instanceof SocialAccount) {
            $account = $subject;
            $workspaceId = $subject->workspace_id;
            $organizationId = $subject->organization_id;
        } elseif ($subject instanceof SocialPostVariant) {
            $variant = $subject;
            $account = $subject->socialAccount;
            $workspaceId = $subject->workspace_id;
            $organizationId = $subject->organization_id;
        } elseif ($subject instanceof SocialPublication) {
            $publication = $subject;
            $variant = $subject->variant;
            $account = $subject->socialAccount;
            $workspaceId = $subject->workspace_id;
            $organizationId = $subject->organization_id;
        }

        return [
            'organization_id' => $organizationId,
            'workspace_id' => $workspaceId,
            'social_account_id' => $account?->id,
            'social_post_variant_id' => $variant?->id,
            'social_publication_id' => $publication?->id,
        ];
    }
}

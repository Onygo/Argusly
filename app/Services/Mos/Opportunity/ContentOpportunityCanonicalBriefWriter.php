<?php

namespace App\Services\Mos\Opportunity;

use App\Enums\OpportunityStatus;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\ContentOpportunity;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ContentOpportunityCanonicalBriefWriter
{
    public function __construct(
        private readonly ContentOpportunityBriefPayloadBuilder $payloadBuilder,
    ) {}

    public function dryRun(
        ContentOpportunity $contentOpportunity,
        ?Opportunity $canonical,
        ?ClientSite $site,
        string $mode = 'single',
        ?User $operator = null,
    ): ContentOpportunityCanonicalBriefWriteResult {
        return $this->run($contentOpportunity, $canonical, $site, $mode, $operator, false);
    }

    public function apply(
        ContentOpportunity $contentOpportunity,
        Opportunity $canonical,
        ClientSite $site,
        string $mode = 'single',
        ?User $operator = null,
        bool $markPlanned = false,
    ): ContentOpportunityCanonicalBriefWriteResult {
        return $this->run($contentOpportunity, $canonical, $site, $mode, $operator, true, $markPlanned);
    }

    private function run(
        ContentOpportunity $contentOpportunity,
        ?Opportunity $canonical,
        ?ClientSite $site,
        string $mode,
        ?User $operator,
        bool $apply,
        bool $markPlanned = false,
    ): ContentOpportunityCanonicalBriefWriteResult {
        $mode = in_array($mode, ['single', 'chained'], true) ? $mode : 'single';
        $contentOpportunity->loadMissing('workspace', 'site');

        $payload = $site
            ? $this->payloadBuilder->build(
                $contentOpportunity,
                $site,
                $mode,
                $operator?->id ? (int) $operator->id : null,
                $canonical,
                true,
            )
            : [];

        $missing = $this->missingFields($contentOpportunity, $canonical, $site, $payload);
        $duplicate = $site && $canonical
            ? $this->duplicateBrief($contentOpportunity, $canonical, $site, $mode)
            : null;
        $blocked = $this->blockedReasons($contentOpportunity, $canonical, $site, $missing, $duplicate);
        $safe = $blocked === [];

        if (! $apply || ! $safe) {
            return new ContentOpportunityCanonicalBriefWriteResult(
                applied: false,
                safe: $safe,
                status: $safe ? 'would_create' : 'blocked',
                brief: null,
                duplicateBrief: $duplicate,
                canonicalOpportunityId: $canonical?->id ? (string) $canonical->id : null,
                legacyContentOpportunityId: (string) $contentOpportunity->id,
                clientSiteId: $site?->id ? (string) $site->id : null,
                mode: $mode,
                missingFields: $missing,
                blockedReasons: $blocked,
                duplicateRisk: $duplicate !== null,
                payload: $payload,
            );
        }

        $brief = DB::transaction(function () use ($payload, $contentOpportunity, $canonical, $site, $mode, $markPlanned): Brief {
            $lockedDuplicate = $this->duplicateBrief($contentOpportunity, $canonical, $site, $mode);

            if ($lockedDuplicate) {
                return $lockedDuplicate;
            }

            $brief = Brief::query()->create($payload);

            if ($markPlanned) {
                $contentOpportunity->forceFill(['status' => ContentOpportunity::STATUS_PLANNED])->save();
                $canonical->forceFill(['status' => OpportunityStatus::PLANNED])->save();
            }

            return $brief;
        });

        $duplicateAfterLock = $brief->wasRecentlyCreated ? null : $brief;

        return new ContentOpportunityCanonicalBriefWriteResult(
            applied: $duplicateAfterLock === null,
            safe: $duplicateAfterLock === null,
            status: $duplicateAfterLock === null ? 'created' : 'duplicate',
            brief: $duplicateAfterLock === null ? $brief : null,
            duplicateBrief: $duplicateAfterLock,
            canonicalOpportunityId: (string) $canonical->id,
            legacyContentOpportunityId: (string) $contentOpportunity->id,
            clientSiteId: (string) $site->id,
            mode: $mode,
            missingFields: [],
            blockedReasons: $duplicateAfterLock ? ['duplicate_brief'] : [],
            duplicateRisk: $duplicateAfterLock !== null,
            payload: $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function missingFields(
        ContentOpportunity $contentOpportunity,
        ?Opportunity $canonical,
        ?ClientSite $site,
        array $payload,
    ): array {
        $missing = [];

        if (! $canonical) {
            $missing[] = 'canonical_opportunity_id';
        }

        if (! $site) {
            $missing[] = 'client_site_id';
        }

        foreach (['title', 'language', 'content_type', 'output_type', 'primary_keyword'] as $field) {
            if (blank($payload[$field] ?? null)) {
                $missing[] = $field;
            }
        }

        if ($this->sourceEvidence($contentOpportunity, $canonical) === []) {
            $missing[] = 'source_evidence';
        }

        return array_values(array_unique($missing));
    }

    /**
     * @param  array<int, string>  $missing
     * @return array<int, string>
     */
    private function blockedReasons(
        ContentOpportunity $contentOpportunity,
        ?Opportunity $canonical,
        ?ClientSite $site,
        array $missing,
        ?Brief $duplicate,
    ): array {
        $blocked = $missing;

        if ($canonical && (string) $canonical->content_opportunity_id !== (string) $contentOpportunity->id) {
            $blocked[] = 'canonical_legacy_link_mismatch';
        }

        if ($canonical && (int) $canonical->organization_id !== (int) $contentOpportunity->organization_id) {
            $blocked[] = 'organization_mismatch';
        }

        if ($site && (string) $site->workspace_id !== (string) $contentOpportunity->workspace_id) {
            $blocked[] = 'site_workspace_mismatch';
        }

        if ($duplicate) {
            $blocked[] = 'duplicate_brief';
        }

        return array_values(array_unique($blocked));
    }

    private function duplicateBrief(
        ContentOpportunity $contentOpportunity,
        Opportunity $canonical,
        ClientSite $site,
        string $mode,
    ): ?Brief {
        $signature = $this->payloadBuilder->sourceSignature($contentOpportunity, $canonical, $site, $mode);

        return Brief::query()
            ->where('client_site_id', (string) $site->id)
            ->where('source', 'content_opportunity')
            ->where(function (Builder $query) use ($contentOpportunity, $canonical, $mode, $signature): void {
                $query
                    ->where('client_refs->source_signature', $signature)
                    ->orWhere(function (Builder $query) use ($contentOpportunity, $canonical, $mode): void {
                        $query
                            ->where('client_refs->canonical_opportunity_id', (string) $canonical->id)
                            ->where('client_refs->content_opportunity_id', (string) $contentOpportunity->id)
                            ->where('client_refs->mode', $mode);
                    })
                    ->orWhere(function (Builder $query) use ($contentOpportunity): void {
                        $query->where('client_refs->content_opportunity->id', (string) $contentOpportunity->id);
                    });
            })
            ->oldest()
            ->first();
    }

    /**
     * @return array<int, mixed>
     */
    private function sourceEvidence(ContentOpportunity $opportunity, ?Opportunity $canonical): array
    {
        $evidence = is_array($canonical?->evidence) ? $canonical->evidence : [];
        $legacyEvidence = [
            'reasoning' => $opportunity->reasoning,
            'why_this_matters' => $opportunity->why_this_matters,
            'why_now' => $opportunity->why_now,
            'source_signals' => $opportunity->source_signals,
        ];

        if (collect($legacyEvidence)->contains(fn (mixed $value): bool => filled($value))) {
            $evidence[] = $legacyEvidence;
        }

        return array_values(array_filter($evidence));
    }
}

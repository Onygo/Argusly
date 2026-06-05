<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use App\Enums\SupportedLanguage;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Workspace extends Model
{
    use BelongsToOrganization;
    use HasFactory;
    use HasUuids;

    protected $attributes = [
        'default_content_language' => SupportedLanguage::EN->value,
    ];

    protected $fillable = [
        'name',
        'display_name',
        'organization_id',
        'visual_settings',
        'default_content_language',
        'enabled_content_languages',
        'low_credit_warning_state',
        'low_credit_warning_sent_at',
        'low_credit_warning_last_available',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'visual_settings' => 'array',
        'enabled_content_languages' => 'array',
        'default_content_language' => SupportedLanguage::class,
        'low_credit_warning_sent_at' => 'datetime',
        'low_credit_warning_last_available' => 'integer',
    ];

    public function getDisplayNameAttribute(?string $value): string
    {
        $display = trim((string) $value);
        if ($display !== '') {
            return $display;
        }

        return (string) ($this->attributes['name'] ?? '');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function clientSites()
    {
        return $this->hasMany(ClientSite::class);
    }

    public function siteTokens()
    {
        return $this->hasMany(SiteToken::class);
    }

    public function usageRows()
    {
        return $this->hasMany(WorkspaceUsage::class);
    }

    public function creditWallet()
    {
        return $this->hasOne(WorkspaceCreditWallet::class);
    }

    public function creditTransactions()
    {
        return $this->hasMany(WorkspaceCreditTransaction::class);
    }

    public function siteCreditAllocations()
    {
        return $this->hasMany(SiteCreditAllocation::class);
    }

    public function contents()
    {
        return $this->hasMany(Content::class);
    }

    public function contentLifecycleAnalyses()
    {
        return $this->hasMany(ContentLifecycleAnalysis::class);
    }

    public function contentRefreshTasks()
    {
        return $this->hasMany(ContentRefreshTask::class);
    }

    public function contentLearningProfiles()
    {
        return $this->hasMany(ContentLearningProfile::class);
    }

    public function campaignLearningProfiles()
    {
        return $this->hasMany(CampaignLearningProfile::class);
    }

    public function learningRecommendations()
    {
        return $this->hasMany(LearningRecommendation::class);
    }

    public function agenticMarketingWorkflowRules()
    {
        return $this->hasMany(AgenticMarketingWorkflowRule::class);
    }

    public function agenticMarketingWorkflowOverrides()
    {
        return $this->hasMany(AgenticMarketingWorkflowOverride::class);
    }

    public function linkProfile()
    {
        return $this->hasOne(LinkProfile::class);
    }

    public function companyProfile()
    {
        return $this->hasOne(CompanyProfile::class);
    }

    public function companyIntelligenceProfiles()
    {
        return $this->hasMany(CompanyIntelligenceProfile::class);
    }

    public function defaultCompanyIntelligenceProfile()
    {
        return $this->hasOne(CompanyIntelligenceProfile::class)->where('is_default', true);
    }

    public function brandVoices()
    {
        return $this->hasMany(BrandVoice::class);
    }

    public function brandContexts()
    {
        return $this->hasMany(BrandContext::class);
    }

    public function defaultBrandVoice()
    {
        return $this->hasOne(BrandVoice::class)->where('is_default', true);
    }

    public function writerProfiles()
    {
        return $this->hasMany(WriterProfile::class);
    }

    public function outgoingCrossLinkPermissions()
    {
        return $this->hasMany(CrossLinkPermission::class, 'from_workspace_id');
    }

    public function incomingCrossLinkPermissions()
    {
        return $this->hasMany(CrossLinkPermission::class, 'to_workspace_id');
    }

    public function licenseKeys()
    {
        return $this->hasMany(LicenseKey::class);
    }

    public function domains()
    {
        return $this->hasMany(WorkspaceDomain::class);
    }

    public function entitlements()
    {
        return $this->hasMany(WorkspaceEntitlement::class);
    }

    public function llmTrackingQueries()
    {
        return $this->hasMany(LlmTrackingQuery::class);
    }

    public function llmTrackingQuerySets()
    {
        return $this->hasMany(LlmTrackingQuerySet::class);
    }

    public function siteCompetitors()
    {
        return $this->hasMany(SiteCompetitor::class);
    }

    public function seoAudits()
    {
        return $this->hasMany(SeoAudit::class);
    }

    public function contentBatches()
    {
        return $this->hasMany(ContentBatch::class);
    }

    public function contentAutomations()
    {
        return $this->hasMany(ContentAutomation::class);
    }

    public function campaigns()
    {
        return $this->hasMany(Campaign::class);
    }

    public function distributionChannels()
    {
        return $this->hasMany(DistributionChannel::class);
    }

    public function socialAccounts()
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function socialPostVariants()
    {
        return $this->hasMany(SocialPostVariant::class);
    }

    public function socialPublications()
    {
        return $this->hasMany(SocialPublication::class);
    }

    public function opportunities()
    {
        return $this->hasMany(Opportunity::class);
    }

    public function opportunitySignals()
    {
        return $this->hasMany(OpportunitySignal::class);
    }

    public function campaignToneProfiles()
    {
        return $this->hasMany(CampaignToneProfile::class);
    }

    public function campaignCtaPresets()
    {
        return $this->hasMany(CampaignCtaPreset::class);
    }

    public function agenticMarketingExecutionSettings()
    {
        return $this->hasMany(AgenticMarketingExecutionSetting::class);
    }

    public function defaultAgenticMarketingExecutionSetting()
    {
        return $this->hasOne(AgenticMarketingExecutionSetting::class)->whereNull('brand_voice_id');
    }

    public function contentAutomationRuns()
    {
        return $this->hasMany(ContentAutomationRun::class);
    }

    public function researchProjects()
    {
        return $this->hasMany(ResearchProject::class);
    }

    public function contentClusters()
    {
        return $this->hasMany(ContentCluster::class);
    }

    public function linkOpportunities()
    {
        return $this->hasMany(LinkOpportunity::class);
    }

    public function briefTemplates()
    {
        return $this->hasMany(BriefTemplate::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function contentDestinations()
    {
        return $this->hasMany(ContentDestination::class);
    }

    public function apiKeys()
    {
        return $this->hasMany(ApiKey::class);
    }

    public function apiWebhooks()
    {
        return $this->hasMany(ApiWebhook::class);
    }

    public function apiRequestLogs()
    {
        return $this->hasMany(ApiRequestLog::class);
    }

    public function asyncOperations()
    {
        return $this->hasMany(AsyncOperationRun::class);
    }

    public function getEnabledContentLanguagesAttribute($value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (! is_array($value) || empty($value)) {
            return [SupportedLanguage::EN->value];
        }

        return array_filter($value, fn ($lang) => SupportedLanguage::tryFrom($lang) !== null);
    }

    public function getEnabledLanguagesAsEnums(): array
    {
        return array_filter(
            array_map(
                fn (string $code) => SupportedLanguage::tryFrom($code),
                $this->enabled_content_languages
            )
        );
    }

    public function defaultContentLanguageCode(): string
    {
        return $this->default_content_language instanceof SupportedLanguage
            ? $this->default_content_language->value
            : SupportedLanguage::fromStringOrDefault((string) $this->default_content_language)->value;
    }

    public function isLanguageEnabled(SupportedLanguage $language): bool
    {
        return in_array($language->value, $this->enabled_content_languages, true);
    }

    public function enableLanguage(SupportedLanguage $language): void
    {
        $enabled = $this->enabled_content_languages;

        if (! in_array($language->value, $enabled, true)) {
            $enabled[] = $language->value;
            $this->enabled_content_languages = $enabled;
            $this->save();
        }
    }

    public function disableLanguage(SupportedLanguage $language): void
    {
        if ($language === $this->default_content_language) {
            return;
        }

        $enabled = array_filter(
            $this->enabled_content_languages,
            fn (string $code) => $code !== $language->value
        );

        $this->enabled_content_languages = array_values($enabled);
        $this->save();
    }

    public function getTranslationTargetLanguages(SupportedLanguage $sourceLanguage): array
    {
        return array_filter(
            $this->getEnabledLanguagesAsEnums(),
            fn (SupportedLanguage $lang) => $lang !== $sourceLanguage
        );
    }
}

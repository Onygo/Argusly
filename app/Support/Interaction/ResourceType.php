<?php

namespace App\Support\Interaction;

use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

final class ResourceType
{
    public const CONTENT = 'content';
    public const DRAFT = 'draft';
    public const BRIEF = 'brief';
    public const CAMPAIGN = 'campaign';
    public const OPPORTUNITY = 'opportunity';
    public const RESEARCH_PROJECT = 'research_project';
    public const SIGNAL_DETECTION = 'signal_detection';
    public const COMPETITOR = 'competitor';
    public const LLM_TRACKING_QUERY = 'llm_tracking_query';
    public const SEO_AUDIT = 'seo_audit';
    public const SITE = 'site';
    public const ORGANIZATION = 'organization';
    public const WORKSPACE = 'workspace';
    public const USER = 'user';
    public const QUEUE_JOB = 'queue_job';
    public const FAILED_JOB = 'failed_job';

    private ?string $description = null;
    private ?string $icon = null;
    private ?string $modelClass = null;
    private ?string $primaryRouteName = null;
    private ?string $policyAbility = null;
    private array $metadata = [];

    public function __construct(
        public readonly string $key,
        public readonly string $label,
    ) {
        if ($key === '' || $label === '') {
            throw new InvalidArgumentException('Resource types require a non-empty key and label.');
        }
    }

    public static function make(string $key, string $label): self
    {
        return new self($key, $label);
    }

    /**
     * @return array<int, self>
     */
    public static function initialTypes(): array
    {
        return [
            self::make(self::CONTENT, 'Content')->icon('file-text')->model('App\\Models\\Content')->primaryRoute('app.content.show')->policy('view'),
            self::make(self::DRAFT, 'Draft')->icon('file-pen-line')->model('App\\Models\\Draft')->primaryRoute('app.drafts.show')->policy('view'),
            self::make(self::BRIEF, 'Brief')->icon('clipboard-list')->model('App\\Models\\Brief')->primaryRoute('app.briefs.show')->policy('view'),
            self::make(self::CAMPAIGN, 'Campaign')->icon('megaphone')->model('App\\Models\\Campaign')->primaryRoute('app.agentic-marketing.campaign-planner.index'),
            self::make(self::OPPORTUNITY, 'Opportunity')->icon('sparkles')->model('App\\Models\\Opportunity')->primaryRoute('app.opportunities.show')->policy('view'),
            self::make(self::RESEARCH_PROJECT, 'Research project')->icon('search-check')->model('App\\Models\\ResearchProject')->primaryRoute('app.research.show')->policy('view'),
            self::make(self::SIGNAL_DETECTION, 'Signal detection')->icon('radar')->model('App\\Models\\SignalDetection')->primaryRoute('app.signal-intelligence.detections.show')->policy('view'),
            self::make(self::COMPETITOR, 'Competitor')->icon('building-2')->model('App\\Models\\SiteCompetitor')->primaryRoute('app.sites.competitors.index'),
            self::make(self::LLM_TRACKING_QUERY, 'LLM tracking query')->icon('messages-square')->model('App\\Models\\LlmTrackingQuery')->primaryRoute('app.sites.llm-tracking.show'),
            self::make(self::SEO_AUDIT, 'SEO audit')->icon('scan-search')->model('App\\Models\\SeoAudit')->primaryRoute('app.sites.seo-audits.show'),
            self::make(self::SITE, 'Site')->icon('globe-2')->model('App\\Models\\ClientSite')->primaryRoute('app.sites.show'),
            self::make(self::ORGANIZATION, 'Organization')->icon('building')->model('App\\Models\\Organization')->primaryRoute('admin.organizations.show')->policy('view-organization'),
            self::make(self::WORKSPACE, 'Workspace')->icon('panel-top')->model('App\\Models\\Workspace')->primaryRoute('app.settings'),
            self::make(self::USER, 'User')->icon('user')->model('App\\Models\\User')->primaryRoute('admin.users.show'),
            self::make(self::QUEUE_JOB, 'Queue job')->icon('list-todo')->primaryRoute('admin.queues.pending.show')->policy('viewQueues'),
            self::make(self::FAILED_JOB, 'Failed job')->icon('circle-alert')->primaryRoute('admin.queues.show')->policy('viewQueues'),
        ];
    }

    public function description(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function icon(?string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function model(?string $modelClass): self
    {
        $this->modelClass = $modelClass;

        return $this;
    }

    public function primaryRoute(?string $name): self
    {
        $this->primaryRouteName = $name;

        return $this;
    }

    public function policy(?string $ability): self
    {
        $this->policyAbility = $ability;

        return $this;
    }

    public function metadata(array $metadata): self
    {
        $this->metadata = array_replace_recursive($this->metadata, $metadata);

        return $this;
    }

    public function routeExists(): bool
    {
        return $this->primaryRouteName === null || Route::has($this->primaryRouteName);
    }

    public function modelExists(): bool
    {
        return $this->modelClass === null || class_exists($this->modelClass);
    }

    public function mapsToExistingReference(): bool
    {
        return $this->modelExists()
            && $this->routeExists()
            && ($this->modelClass !== null || $this->primaryRouteName !== null || $this->policyAbility !== null);
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'icon' => $this->icon,
            'model' => $this->modelClass,
            'primary_route' => $this->primaryRouteName === null ? null : [
                'name' => $this->primaryRouteName,
                'exists' => Route::has($this->primaryRouteName),
            ],
            'policy' => $this->policyAbility === null ? null : [
                'ability' => $this->policyAbility,
            ],
            'metadata' => $this->metadata,
        ];
    }
}

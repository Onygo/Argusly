<?php

namespace App\Services\Graph;

use App\Models\Account;
use App\Models\Agent;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\Competitor;
use App\Models\Contact;
use App\Models\ContentAsset;
use App\Models\Entity;
use App\Models\GraphEdge;
use App\Models\GraphNode;
use App\Models\Mention;
use App\Models\Narrative;
use App\Models\Organization;
use App\Models\Recommendation;
use App\Models\Relationship;
use App\Models\SocialProfile;
use App\Models\Topic;
use App\Services\Graph\Projectors\BrandGraphProjector;
use App\Services\Graph\Projectors\CampaignGraphProjector;
use App\Services\Graph\Projectors\CreatorGraphProjector;
use App\Services\Graph\Projectors\EntityGraphProjector;
use App\Services\Graph\Projectors\MentionGraphProjector;
use App\Services\Graph\Projectors\NarrativeGraphProjector;
use App\Services\Graph\Projectors\RecommendationGraphProjector;
use App\Services\Graph\Projectors\RelationshipGraphProjector;
use App\Services\Graph\Projectors\TopicGraphProjector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GraphProjectionService
{
    public readonly BrandGraphProjector $brands;

    public readonly TopicGraphProjector $topics;

    public readonly EntityGraphProjector $entities;

    public readonly MentionGraphProjector $mentions;

    public readonly NarrativeGraphProjector $narratives;

    public readonly CampaignGraphProjector $campaigns;

    public readonly CreatorGraphProjector $creators;

    public readonly RelationshipGraphProjector $relationships;

    public readonly RecommendationGraphProjector $recommendations;

    public function __construct()
    {
        $this->brands = new BrandGraphProjector($this);
        $this->topics = new TopicGraphProjector($this);
        $this->entities = new EntityGraphProjector($this);
        $this->mentions = new MentionGraphProjector($this);
        $this->narratives = new NarrativeGraphProjector($this);
        $this->campaigns = new CampaignGraphProjector($this);
        $this->creators = new CreatorGraphProjector($this);
        $this->relationships = new RelationshipGraphProjector($this);
        $this->recommendations = new RecommendationGraphProjector($this);
    }

    public function project(Model $model): ?GraphNode
    {
        return match (true) {
            $model instanceof Brand => $this->brands->project($model),
            $model instanceof Topic => $this->topics->project($model),
            $model instanceof Entity => $this->entities->project($model),
            $model instanceof Mention => $this->mentions->project($model),
            $model instanceof Narrative => $this->narratives->project($model),
            $model instanceof Campaign => $this->campaigns->project($model),
            $model instanceof SocialProfile => $this->creators->project($model),
            $model instanceof Contact => $this->creators->projectContact($model),
            $model instanceof Organization => $this->creators->projectOrganization($model),
            $model instanceof Relationship => $this->relationships->project($model),
            $model instanceof Recommendation => $this->recommendations->project($model),
            $model instanceof Competitor => $this->competitors($model),
            $model instanceof ContentAsset => $this->content($model),
            $model instanceof Agent => $this->agent($model),
            default => null,
        };
    }

    public function rebuild(?Account $account = null, ?Brand $brand = null): array
    {
        DB::transaction(function () use ($account, $brand): void {
            $edgeQuery = GraphEdge::query();
            $nodeQuery = GraphNode::query();

            if ($account) {
                $edgeQuery->where('account_id', $account->id);
                $nodeQuery->where('account_id', $account->id);
            }

            if ($brand) {
                $edgeQuery->where('brand_id', $brand->id);
                $nodeQuery->where(fn ($query) => $query->whereNull('brand_id')->orWhere('brand_id', $brand->id));
            }

            $edgeQuery->delete();
            $nodeQuery->delete();
        });

        foreach ([Brand::class, Topic::class, Entity::class, Competitor::class, ContentAsset::class, Mention::class, Narrative::class, Campaign::class, SocialProfile::class, Contact::class, Organization::class, Relationship::class, Recommendation::class, Agent::class] as $class) {
            $query = $class::query()->orderBy('id');

            if ($account && $this->hasColumn($class, 'account_id')) {
                $query->where('account_id', $account->id);
            }

            if ($brand && $this->hasColumn($class, 'brand_id')) {
                $query->where(fn ($scope) => $scope->whereNull('brand_id')->orWhere('brand_id', $brand->id));
            }

            $query->chunkById(100, function (Collection $models): void {
                $models->each(fn (Model $model) => $this->project($model));
            });
        }

        return $this->verify($account, $brand);
    }

    public function verify(?Account $account = null, ?Brand $brand = null): array
    {
        $nodes = GraphNode::query()
            ->when($account, fn ($query) => $query->where('account_id', $account->id))
            ->when($brand, fn ($query) => $query->where(fn ($scope) => $scope->whereNull('brand_id')->orWhere('brand_id', $brand->id)))
            ->count();

        $edges = GraphEdge::query()
            ->when($account, fn ($query) => $query->where('account_id', $account->id))
            ->when($brand, fn ($query) => $query->where(fn ($scope) => $scope->whereNull('brand_id')->orWhere('brand_id', $brand->id)))
            ->count();

        $invalidEdges = GraphEdge::query()
            ->leftJoin('graph_nodes as source', 'source.id', '=', 'graph_edges.source_node_id')
            ->leftJoin('graph_nodes as target', 'target.id', '=', 'graph_edges.target_node_id')
            ->where(fn ($query) => $query
                ->whereNull('source.id')
                ->orWhereNull('target.id')
                ->orWhereColumn('source.account_id', '!=', 'graph_edges.account_id')
                ->orWhereColumn('target.account_id', '!=', 'graph_edges.account_id'))
            ->when($account, fn ($query) => $query->where('graph_edges.account_id', $account->id))
            ->count();

        return compact('nodes', 'edges', 'invalidEdges');
    }

    public function node(Model $source, string $nodeType, string $label, ?array $metadata = null, ?Brand $brand = null): GraphNode
    {
        $accountId = $this->accountId($source);
        $brandId = $brand?->id ?? $this->brandId($source);

        if ($accountId === null) {
            throw new InvalidArgumentException('Graph nodes require an account_id.');
        }

        return GraphNode::query()->updateOrCreate(
            [
                'account_id' => $accountId,
                'source_type' => $source::class,
                'source_id' => $source->getKey(),
            ],
            [
                'brand_id' => $brandId,
                'node_type' => $nodeType,
                'label' => $label,
                'metadata' => array_filter($metadata ?? [], fn ($value) => $value !== null),
            ],
        );
    }

    public function edge(GraphNode $source, GraphNode $target, string $relationshipType, ?float $strength = null, ?float $confidence = null, ?array $metadata = null, ?int $brandId = null): GraphEdge
    {
        if ($source->account_id !== $target->account_id) {
            throw new InvalidArgumentException('Graph edges cannot cross accounts.');
        }

        $edgeBrandId = $brandId ?? $source->brand_id ?? $target->brand_id;

        return GraphEdge::query()->updateOrCreate(
            [
                'account_id' => $source->account_id,
                'brand_id' => $edgeBrandId,
                'source_node_id' => $source->id,
                'target_node_id' => $target->id,
                'relationship_type' => $relationshipType,
            ],
            [
                'strength' => $strength,
                'confidence' => $confidence,
                'metadata' => array_filter($metadata ?? [], fn ($value) => $value !== null),
            ],
        );
    }

    public function brand(Brand $brand): GraphNode
    {
        return $this->node($brand, 'brand', $brand->name, [
            'slug' => $brand->slug,
            'domain' => $brand->domain,
            'market' => $brand->market,
        ], $brand);
    }

    public function competitors(Competitor $competitor): GraphNode
    {
        $node = $this->node($competitor, 'competitor', $competitor->name, [
            'website' => $competitor->website,
            'industry' => $competitor->industry,
            'status' => $competitor->status,
        ]);

        $brand = $competitor->brand;
        if ($brand) {
            $this->edge($this->brand($brand), $node, 'competes_with', 1, 1, ['source' => 'competitor'], $brand->id);
        }

        $this->projectTopics($competitor, $node, 'related_to');

        return $node;
    }

    public function content(ContentAsset $asset): GraphNode
    {
        $node = $this->node($asset, 'content', $asset->title, [
            'type' => $asset->type,
            'status' => $asset->status,
            'source_url' => $asset->source_url,
        ]);

        $this->projectTopics($asset, $node, 'covers');

        foreach ($asset->campaigns ?? [] as $campaign) {
            $campaignNode = $this->campaigns->project($campaign);
            $this->edge($node, $campaignNode, 'participates_in', null, null, ['source' => 'campaign_assets'], $asset->brand_id);
        }

        return $node;
    }

    public function agent(Agent $agent): ?GraphNode
    {
        $account = Account::query()->first();

        if (! $account) {
            return null;
        }

        $agent->setAttribute('account_id', $account->id);

        $node = $this->node($agent, 'agent', $agent->name, [
            'key' => $agent->key,
            'status' => $agent->status,
            'capabilities' => $agent->capabilities,
        ]);

        $this->projectTopics($agent, $node, 'targets');

        return $node;
    }

    public function projectTopics(Model $model, GraphNode $node, string $relationshipType = 'related_to'): void
    {
        if (! method_exists($model, 'topics')) {
            return;
        }

        foreach ($model->topics()->get() as $topic) {
            $topicNode = $this->topics->project($topic);
            $this->edge($node, $topicNode, $relationshipType, $topic->pivot?->relevance_score ? (float) $topic->pivot->relevance_score : null, null, ['source' => 'topicables'], $node->brand_id ?? $topic->brand_id);
        }
    }

    private function accountId(Model $model): ?int
    {
        return isset($model->account_id) ? (int) $model->account_id : null;
    }

    private function brandId(Model $model): ?int
    {
        return isset($model->brand_id) ? ($model->brand_id === null ? null : (int) $model->brand_id) : null;
    }

    private function hasColumn(string $class, string $column): bool
    {
        $model = new $class;

        return $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $column);
    }
}

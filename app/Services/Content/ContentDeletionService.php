<?php

namespace App\Services\Content;

use App\Models\Content;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ContentDeletionService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @return array{scope:string,count:int,content_ids:array<int,string>,family_id:string,contains_published:bool,contains_automation:bool}
     */
    public function deleteContent(Content $content, string $scope = 'single', ?User $actor = null, ?Request $request = null): array
    {
        $scope = $this->normalizeScope($scope);

        return DB::transaction(function () use ($content, $scope, $actor, $request): array {
            $records = $this->recordsForScope($content, $scope, includeDeleted: false);

            foreach ($records as $record) {
                $before = $this->snapshot($record, $scope);
                $record->delete();

                $this->auditLogService->log(
                    actor: $actor,
                    subject: $record,
                    action: $scope === 'family' ? 'content.family.soft_deleted' : 'content.soft_deleted',
                    before: $before,
                    after: array_merge($before, [
                        'deleted_at' => optional($record->deleted_at)->toIso8601String(),
                    ]),
                    request: $request,
                );
            }

            return $this->summary($content, $scope, $records);
        });
    }

    /**
     * @return array{count:int,content_id:string,family_id:string}
     */
    public function restoreContent(Content $content, ?User $actor = null, ?Request $request = null): array
    {
        return DB::transaction(function () use ($content, $actor, $request): array {
            $before = $this->snapshot($content, 'single');

            $content->restore();

            $this->auditLogService->log(
                actor: $actor,
                subject: $content,
                action: 'content.restored',
                before: $before,
                after: array_merge($before, [
                    'deleted_at' => null,
                ]),
                request: $request,
            );

            return [
                'count' => 1,
                'content_id' => (string) $content->id,
                'family_id' => $this->familyRootId($content),
            ];
        });
    }

    /**
     * @param  array<int, string>  $ids
     * @return array{scope:string,count:int,content_ids:array<int,string>,family_ids:array<int,string>}
     */
    public function bulkDelete(array $ids, string $scope = 'single', ?User $actor = null, ?Request $request = null): array
    {
        $scope = $this->normalizeScope($scope);

        return DB::transaction(function () use ($ids, $scope, $actor, $request): array {
            $records = Content::query()
                ->whereIn('id', collect($ids)->filter()->values()->all())
                ->get();

            $deletedIds = [];
            $familyIds = [];

            /** @var Collection<string,Content> $handled */
            $handled = collect();

            foreach ($records as $record) {
                $key = $scope === 'family' ? $this->familyRootId($record) : (string) $record->id;
                if ($handled->has($key)) {
                    continue;
                }

                $result = $this->deleteContent($record, $scope, $actor, $request);
                $handled->put($key, $record);
                $deletedIds = array_values(array_unique(array_merge($deletedIds, $result['content_ids'])));
                $familyIds[] = $result['family_id'];
            }

            return [
                'scope' => $scope,
                'count' => count($deletedIds),
                'content_ids' => $deletedIds,
                'family_ids' => array_values(array_unique($familyIds)),
            ];
        });
    }

    /**
     * @return Collection<int,Content>
     */
    public function recordsForScope(Content $content, string $scope = 'single', bool $includeDeleted = false): Collection
    {
        $scope = $this->normalizeScope($scope);

        $query = $includeDeleted ? Content::withTrashed() : Content::query();

        if ($scope === 'single') {
            return $query->whereKey((string) $content->id)->get();
        }

        return $query
            ->where('workspace_id', (string) $content->workspace_id)
            ->where(function ($familyQuery) use ($content): void {
                $familyId = $this->familyRootId($content);

                $familyQuery->where('family_id', $familyId)
                    ->orWhere('id', $familyId);
            })
            ->get();
    }

    private function normalizeScope(string $scope): string
    {
        $scope = trim(strtolower($scope));

        if (! in_array($scope, ['single', 'family'], true)) {
            throw new InvalidArgumentException('Unsupported content deletion scope.');
        }

        return $scope;
    }

    private function familyRootId(Content $content): string
    {
        return (string) ($content->family_id ?: $content->translation_source_content_id ?: $content->id);
    }

    /**
     * @param  Collection<int,Content>  $records
     * @return array{scope:string,count:int,content_ids:array<int,string>,family_id:string,contains_published:bool,contains_automation:bool}
     */
    private function summary(Content $content, string $scope, Collection $records): array
    {
        return [
            'scope' => $scope,
            'count' => $records->count(),
            'content_ids' => $records->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(),
            'family_id' => $this->familyRootId($content),
            'contains_published' => $records->contains(fn (Content $record): bool => (string) $record->publish_status === 'published' || (string) $record->status === 'published'),
            'contains_automation' => $records->contains(fn (Content $record): bool => filled($record->automation_id)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(Content $content, string $scope): array
    {
        return [
            'scope' => $scope,
            'family_id' => (string) ($content->family_id ?? ''),
            'language' => $content->localeCode(),
            'status' => (string) $content->status,
            'publish_status' => (string) ($content->publish_status ?? ''),
            'automation_id' => (string) ($content->automation_id ?? ''),
            'deleted_at' => optional($content->deleted_at)->toIso8601String(),
        ];
    }
}

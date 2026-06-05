<?php

namespace App\Agents\Data;

use App\Agents\Support\AgentTriggerType;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\Workspace;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use UnitEnum;

class AgentContext
{
    /**
     * @param array<int,string> $targetLocales
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public readonly ?int $organizationId = null,
        public readonly ?string $workspaceId = null,
        public readonly ?string $siteId = null,
        public readonly ?string $contentId = null,
        public readonly ?string $draftId = null,
        public readonly ?int $userId = null,
        public readonly ?string $sourceLocale = null,
        public readonly array $targetLocales = [],
        public readonly string $triggerType = AgentTriggerType::MANUAL->value,
        public readonly ?string $triggerSource = null,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public static function forDraft(Draft|string $draft, array $attributes = []): self
    {
        $draftId = $draft instanceof Draft ? $draft->getKey() : $draft;
        $draftContentId = $draft instanceof Draft ? $draft->content_id : null;
        $draftSiteId = $draft instanceof Draft ? $draft->client_site_id : null;

        return new self(
            organizationId: self::resolveOrganizationId($attributes),
            workspaceId: self::resolveWorkspaceId($attributes),
            siteId: self::resolveString($attributes, 'site_id', $draftSiteId),
            contentId: self::resolveString($attributes, 'content_id', $draftContentId),
            draftId: self::normalizeNullableString($draftId),
            userId: self::resolveUserId($attributes),
            sourceLocale: self::resolveString($attributes, 'source_locale', $draft instanceof Draft ? self::normalizeLocale($draft->language?->value ?? $draft->language) : null),
            targetLocales: self::resolveLocales($attributes['target_locales'] ?? []),
            triggerType: self::resolveTriggerType($attributes['trigger_type'] ?? null),
            triggerSource: self::resolveString($attributes, 'trigger_source'),
            metadata: self::resolveMetadata($attributes['metadata'] ?? []),
        );
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public static function forContent(Content|string $content, array $attributes = []): self
    {
        $contentId = $content instanceof Content ? $content->getKey() : $content;
        $workspaceId = $content instanceof Content ? $content->workspace_id : null;
        $siteId = $content instanceof Content ? $content->client_site_id : null;

        return new self(
            organizationId: self::resolveOrganizationId($attributes, $content instanceof Content ? self::organizationIdFromModel($content) : null),
            workspaceId: self::resolveString($attributes, 'workspace_id', $workspaceId),
            siteId: self::resolveString($attributes, 'site_id', $siteId),
            contentId: self::normalizeNullableString($contentId),
            draftId: self::resolveString($attributes, 'draft_id'),
            userId: self::resolveUserId($attributes),
            sourceLocale: self::resolveString($attributes, 'source_locale', $content instanceof Content ? self::normalizeLocale($content->language?->value ?? $content->language) : null),
            targetLocales: self::resolveLocales($attributes['target_locales'] ?? []),
            triggerType: self::resolveTriggerType($attributes['trigger_type'] ?? null),
            triggerSource: self::resolveString($attributes, 'trigger_source'),
            metadata: self::resolveMetadata($attributes['metadata'] ?? []),
        );
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public static function forSite(ClientSite|string $site, array $attributes = []): self
    {
        $siteId = $site instanceof ClientSite ? $site->getKey() : $site;
        $workspaceId = $site instanceof ClientSite ? $site->workspace_id : null;

        return new self(
            organizationId: self::resolveOrganizationId($attributes, $site instanceof ClientSite ? self::organizationIdFromModel($site) : null),
            workspaceId: self::resolveString($attributes, 'workspace_id', $workspaceId),
            siteId: self::normalizeNullableString($siteId),
            contentId: self::resolveString($attributes, 'content_id'),
            draftId: self::resolveString($attributes, 'draft_id'),
            userId: self::resolveUserId($attributes),
            sourceLocale: self::resolveString($attributes, 'source_locale'),
            targetLocales: self::resolveLocales($attributes['target_locales'] ?? []),
            triggerType: self::resolveTriggerType($attributes['trigger_type'] ?? null),
            triggerSource: self::resolveString($attributes, 'trigger_source'),
            metadata: self::resolveMetadata($attributes['metadata'] ?? []),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'workspace_id' => $this->workspaceId,
            'site_id' => $this->siteId,
            'content_id' => $this->contentId,
            'draft_id' => $this->draftId,
            'user_id' => $this->userId,
            'source_locale' => $this->sourceLocale,
            'target_locales' => $this->targetLocales,
            'trigger_type' => $this->triggerType,
            'trigger_source' => $this->triggerSource,
            'metadata' => $this->metadata,
        ];
    }

    private static function resolveOrganizationId(array $attributes, ?int $fallback = null): ?int
    {
        $value = $attributes['organization_id'] ?? $fallback;

        return is_numeric($value) ? (int) $value : null;
    }

    private static function resolveUserId(array $attributes): ?int
    {
        $value = $attributes['user_id'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private static function resolveWorkspaceId(array $attributes, ?string $fallback = null): ?string
    {
        return self::resolveString($attributes, 'workspace_id', $fallback);
    }

    private static function resolveString(array $attributes, string $key, mixed $fallback = null): ?string
    {
        return self::normalizeNullableString($attributes[$key] ?? $fallback);
    }

    private static function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    private static function normalizeLocale(mixed $value): ?string
    {
        return self::normalizeNullableString($value);
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private static function resolveLocales(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $locale): string => trim((string) $locale),
            $value
        ), static fn (string $locale): bool => $locale !== '')));
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>
     */
    private static function resolveMetadata(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return self::normalizeArray($value);
    }

    private static function resolveTriggerType(mixed $value): string
    {
        if ($value instanceof AgentTriggerType) {
            return $value->value;
        }

        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : AgentTriggerType::MANUAL->value;
    }

    /**
     * @param array<mixed> $values
     * @return array<mixed>
     */
    private static function normalizeArray(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            $normalized[$key] = self::normalizeValue($value);
        }

        return $normalized;
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return self::normalizeArray($value);
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof Arrayable) {
            return self::normalizeValue($value->toArray());
        }

        if ($value instanceof JsonSerializable) {
            return self::normalizeValue($value->jsonSerialize());
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return method_exists($value, '__toString')
            ? (string) $value
            : ['type' => $value::class];
    }

    private static function organizationIdFromWorkspaceRelation(?Workspace $workspace): ?int
    {
        if (! $workspace) {
            return null;
        }

        return is_numeric($workspace->organization_id) ? (int) $workspace->organization_id : null;
    }

    private static function organizationIdFromModel(Content|ClientSite $model): ?int
    {
        if (! $model->relationLoaded('workspace')) {
            return null;
        }

        $workspace = $model->getRelation('workspace');

        return $workspace instanceof Workspace
            ? self::organizationIdFromWorkspaceRelation($workspace)
            : null;
    }
}

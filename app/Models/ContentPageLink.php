<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\ContentPageLinkType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

class ContentPageLink extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'client_site_id',
        'content_id',
        'monitored_page_id',
        'link_type',
        'is_primary',
        'confidence_score',
        'metadata',
    ];

    protected $casts = [
        'link_type' => ContentPageLinkType::class,
        'is_primary' => 'boolean',
        'confidence_score' => 'decimal:2',
        'metadata' => 'array',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $link): void {
            $content = $link->content_id
                ? Content::query()->withoutGlobalScopes()->find((string) $link->content_id)
                : null;
            $page = $link->monitored_page_id
                ? MonitoredPage::query()->withoutGlobalScopes()->find((string) $link->monitored_page_id)
                : null;

            if ($content instanceof Content && $page instanceof MonitoredPage) {
                if ((string) $content->workspace_id !== (string) $page->workspace_id) {
                    throw new InvalidArgumentException('Content page links cannot cross workspace boundaries.');
                }

                if ($content->client_site_id && $page->client_site_id
                    && (string) $content->client_site_id !== (string) $page->client_site_id) {
                    throw new InvalidArgumentException('Content page links cannot cross client site boundaries.');
                }
            }

            $workspaceId = (string) ($link->workspace_id ?: $content?->workspace_id ?: $page?->workspace_id ?: '');
            if ($workspaceId === '') {
                throw new InvalidArgumentException('Content page links require a workspace.');
            }

            if ($content instanceof Content && (string) $content->workspace_id !== $workspaceId) {
                throw new InvalidArgumentException('Content page link workspace does not match content.');
            }

            if ($page instanceof MonitoredPage && (string) $page->workspace_id !== $workspaceId) {
                throw new InvalidArgumentException('Content page link workspace does not match monitored page.');
            }

            $link->workspace_id = $workspaceId;
            $link->client_site_id = $link->client_site_id
                ?: $content?->client_site_id
                ?: $page?->client_site_id;
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function monitoredPage(): BelongsTo
    {
        return $this->belongsTo(MonitoredPage::class);
    }
}

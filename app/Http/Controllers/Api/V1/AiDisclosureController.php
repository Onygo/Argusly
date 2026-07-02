<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Services\AiTransparency\AiTransparencyService;
use App\Services\Api\ApiScopes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class AiDisclosureController extends Controller
{
    use RespondsWithApi;

    public function __construct(private readonly AiTransparencyService $transparency) {}

    public function disclosure(Request $request, string $id): JsonResponse
    {
        [$workspace, $forbidden] = $this->authorizeRead($request);
        if ($forbidden) {
            return $forbidden;
        }

        $content = $this->findContentForWorkspace($workspace, $id);
        $record = $this->transparency->ensureForContent($content);

        return $this->success($this->transparency->disclosurePayload($record));
    }

    public function provenance(Request $request, string $id): JsonResponse
    {
        [$workspace, $forbidden] = $this->authorizeRead($request);
        if ($forbidden) {
            return $forbidden;
        }

        $content = $this->findContentForWorkspace($workspace, $id);
        $record = $this->transparency->ensureForContent($content);

        return $this->success($this->transparency->provenancePayload($record));
    }

    public function auditReport(Request $request, string $id): Response
    {
        [$workspace, $forbidden] = $this->authorizeRead($request);
        if ($forbidden) {
            return $forbidden;
        }

        $content = $this->findContentForWorkspace($workspace, $id);
        $record = $this->transparency->ensureForContent($content);
        $report = $this->transparency->generateAuditReport($record);
        $pdfBytes = Storage::disk('local')->get($report->path);
        $filename = 'argusly-ai-audit-' . $content->id . '.pdf';

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'X-Argusly-Audit-Report-Id' => $report->id,
            'X-Argusly-Audit-Checksum' => (string) $report->checksum,
        ]);
    }

    /**
     * @return array{0:mixed,1:JsonResponse|null}
     */
    private function authorizeRead(Request $request): array
    {
        $apiKey = $request->attributes->get('apiKey');
        $siteToken = $request->attributes->get('siteToken');
        $workspace = $request->attributes->get('workspace');

        if (! $workspace || (! $apiKey && ! $siteToken)) {
            return [null, $this->error('Forbidden', code: 'AUTH_FORBIDDEN', status: 403)];
        }

        $token = $apiKey ?: $siteToken;

        if (! $token->hasScope(ApiScopes::CONTENT_READ) && ! $token->hasScope(ApiScopes::DRAFTS_READ)) {
            return [null, $this->error('Forbidden', code: 'AUTH_FORBIDDEN', status: 403)];
        }

        return [$workspace, null];
    }

    private function findContentForWorkspace(mixed $workspace, string $id): Content
    {
        return Content::query()
            ->with([
                'workspace:id,organization_id,name',
                'clientSite:id,workspace_id,name,type',
                'clientSite.workspace:id,organization_id,name',
                'drafts' => fn ($query) => $query->latest('created_at'),
            ])
            ->whereKey($id)
            ->where(function (Builder $query) use ($workspace): void {
                $query->where('workspace_id', $workspace->id)
                    ->orWhereHas('clientSite', fn (Builder $siteQuery) => $siteQuery->where('workspace_id', $workspace->id));
            })
            ->firstOrFail();
    }
}

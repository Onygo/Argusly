<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Content;
use App\Models\ContentImage;
use App\Models\SocialPublication;
use App\Services\ContentImages\UploadedContentImageAssetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AppContentImageAssetController extends Controller
{
    public function storeForContent(
        Request $request,
        Content $content,
        UploadedContentImageAssetService $uploads
    ): RedirectResponse {
        $this->assertWorkspaceInUserOrganization($request, (string) $content->workspace_id);
        $this->authorize('update', $content);

        $data = $this->validatedPayload($request);

        try {
            $uploads->uploadForContent(
                $content,
                $this->uploadedFile($data),
                $this->usageFlags($data),
                $request->user(),
                Arr::get($data, 'alt_text')
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['image_upload' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Image asset uploaded.');
    }

    public function updateUsageForContent(
        Request $request,
        Content $content,
        ContentImage $imageVersion,
        UploadedContentImageAssetService $uploads
    ): RedirectResponse {
        $this->assertWorkspaceInUserOrganization($request, (string) $content->workspace_id);
        $this->authorize('update', $content);

        $data = $this->validatedUsagePayload($request);

        try {
            $uploads->assignUsageForContent($content, $imageVersion, $this->usageFlags($data));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['image_usage' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Image asset usage updated.');
    }

    public function reuseFromLinkedContent(
        Request $request,
        Content $content,
        ContentImage $imageVersion,
        UploadedContentImageAssetService $uploads
    ): RedirectResponse {
        $this->assertWorkspaceInUserOrganization($request, (string) $content->workspace_id);
        $this->authorize('update', $content);

        $data = $this->validatedUsagePayload($request);
        $usage = $this->usageFlags($data);

        if (! in_array(true, $usage, true)) {
            return back()->withErrors(['image_reuse' => 'Select at least one image usage.'])->withInput();
        }

        try {
            $this->assertReusableLinkedContentImage($content, $imageVersion);

            $copy = DB::transaction(function () use ($request, $content, $imageVersion): ContentImage {
                $sourceContent = $imageVersion->content;
                $metadata = is_array($imageVersion->metadata) ? $imageVersion->metadata : [];
                $metadata['reused_from'] = [
                    'content_id' => (string) $sourceContent?->id,
                    'content_title' => (string) ($sourceContent?->title ?? ''),
                    'locale' => $sourceContent?->localeCode(),
                    'image_id' => (string) $imageVersion->id,
                    'copied_at' => now()->toIso8601String(),
                ];

                return ContentImage::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => (string) $content->workspace_id,
                    'content_id' => (string) $content->id,
                    'type' => (string) ($imageVersion->type ?: 'featured'),
                    'source' => $imageVersion->source,
                    'prompt' => $imageVersion->prompt,
                    'provider' => $imageVersion->provider,
                    'model' => $imageVersion->model,
                    'image_path' => $imageVersion->image_path,
                    'image_url' => $imageVersion->image_url,
                    'original_filename' => $imageVersion->original_filename,
                    'mime_type' => $imageVersion->mime_type,
                    'alt_text' => $imageVersion->alt_text,
                    'original_path' => $imageVersion->original_path,
                    'medium_path' => $imageVersion->medium_path,
                    'thumbnail_path' => $imageVersion->thumbnail_path,
                    'original_webp_path' => $imageVersion->original_webp_path,
                    'medium_webp_path' => $imageVersion->medium_webp_path,
                    'thumbnail_webp_path' => $imageVersion->thumbnail_webp_path,
                    'credit_cost' => 0,
                    'width' => $imageVersion->width,
                    'height' => $imageVersion->height,
                    'file_size' => $imageVersion->file_size,
                    'status' => 'ready',
                    'is_active' => false,
                    'display_on_website' => false,
                    'display_as_featured_image' => false,
                    'use_as_meta_image' => false,
                    'use_as_social_image' => false,
                    'use_for_linkedin' => false,
                    'metadata' => $metadata,
                    'created_by' => (string) $request->user()->id,
                    'uploaded_by' => $request->user()->id,
                ]);
            });

            $uploads->assignUsageForContent($content, $copy, $usage);
            $content->forceFill(['updated_by' => $request->user()->id])->save();
        } catch (RuntimeException $exception) {
            return back()->withErrors(['image_reuse' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('app.content.show', ['content' => $content, 'tab' => 'images'])
            ->with('status', 'Linked locale image copied to this content item.');
    }

    public function storeForCampaign(
        Request $request,
        Campaign $campaign,
        UploadedContentImageAssetService $uploads
    ): RedirectResponse {
        $this->assertWorkspaceInUserOrganization($request, (string) $campaign->workspace_id);

        $data = $this->validatedPayload($request);

        try {
            $uploads->uploadForCampaign(
                $campaign,
                $this->uploadedFile($data),
                $this->usageFlags($data),
                $request->user(),
                Arr::get($data, 'alt_text')
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['image_upload' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Campaign image asset uploaded.');
    }

    public function updateUsageForCampaign(
        Request $request,
        Campaign $campaign,
        ContentImage $imageVersion,
        UploadedContentImageAssetService $uploads
    ): RedirectResponse {
        $this->assertWorkspaceInUserOrganization($request, (string) $campaign->workspace_id);

        $data = $this->validatedUsagePayload($request);

        try {
            $uploads->assignUsageForCampaign($campaign, $imageVersion, $this->usageFlags($data));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['image_usage' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Campaign image asset usage updated.');
    }

    public function storeForSocialPublication(
        Request $request,
        SocialPublication $socialPublication,
        UploadedContentImageAssetService $uploads
    ): RedirectResponse {
        $this->assertWorkspaceInUserOrganization($request, (string) $socialPublication->workspace_id);

        $data = $this->validatedPayload($request);

        try {
            $uploads->uploadForSocialPublication(
                $socialPublication,
                $this->uploadedFile($data),
                $this->usageFlags($data),
                $request->user(),
                Arr::get($data, 'alt_text')
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['image_upload' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Social image asset uploaded.');
    }

    /**
     * @return array<string,mixed>
     */
    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'image' => [
                'required',
                'file',
                'image',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:10240',
                'dimensions:min_width=1,min_height=1,max_width=8000,max_height=8000',
            ],
            'alt_text' => ['nullable', 'string', 'max:500'],
            'display_on_website' => ['nullable', 'boolean'],
            'display_as_featured_image' => ['nullable', 'boolean'],
            'use_as_meta_image' => ['nullable', 'boolean'],
            'use_as_social_image' => ['nullable', 'boolean'],
            'use_for_linkedin' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function validatedUsagePayload(Request $request): array
    {
        return $request->validate([
            'display_on_website' => ['nullable', 'boolean'],
            'display_as_featured_image' => ['nullable', 'boolean'],
            'use_as_meta_image' => ['nullable', 'boolean'],
            'use_as_social_image' => ['nullable', 'boolean'],
            'use_for_linkedin' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,bool>
     */
    private function usageFlags(array $data): array
    {
        return [
            'display_on_website' => (bool) ($data['display_on_website'] ?? false),
            'display_as_featured_image' => (bool) ($data['display_as_featured_image'] ?? false),
            'use_as_meta_image' => (bool) ($data['use_as_meta_image'] ?? false),
            'use_as_social_image' => (bool) ($data['use_as_social_image'] ?? false),
            'use_for_linkedin' => (bool) ($data['use_for_linkedin'] ?? false),
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    private function uploadedFile(array $data): UploadedFile
    {
        $file = $data['image'] ?? null;
        if (! $file instanceof UploadedFile) {
            throw new RuntimeException('Uploaded image is missing.');
        }

        return $file;
    }

    private function assertReusableLinkedContentImage(Content $content, ContentImage $image): void
    {
        $image->loadMissing('content');
        $sourceContent = $image->content;

        if (! $sourceContent instanceof Content) {
            throw new RuntimeException('Image asset is not linked to a content item.');
        }

        if ((string) $sourceContent->workspace_id !== (string) $content->workspace_id) {
            throw new RuntimeException('Image asset belongs to another workspace.');
        }

        if ((string) $sourceContent->id === (string) $content->id) {
            throw new RuntimeException('Use the existing image asset controls for this content item.');
        }

        $linkedIds = $content->normalizedLocalizationFamily()
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        if (! in_array((string) $sourceContent->id, $linkedIds, true)) {
            throw new RuntimeException('Image asset does not belong to a linked locale variant.');
        }

        if ((string) $image->status !== 'ready' || ! $image->hasOutput()) {
            throw new RuntimeException('Only ready image assets can be reused.');
        }
    }

    private function assertWorkspaceInUserOrganization(Request $request, string $workspaceId): void
    {
        $organizationId = (int) $request->user()->organization_id;

        $exists = \App\Models\Workspace::query()
            ->whereKey($workspaceId)
            ->where('organization_id', $organizationId)
            ->exists();

        if (! $exists) {
            abort(404);
        }
    }
}

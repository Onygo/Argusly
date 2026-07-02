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

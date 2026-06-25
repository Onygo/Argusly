<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\DistributionChannel;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AdminCampaignController extends Controller
{
    public function index(Request $request): View
    {
        $status = trim((string) $request->query('status', ''));

        $campaigns = Campaign::query()
            ->with(['workspace.organization', 'clientSite'])
            ->withCount(['contents', 'distributionPlans'])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('admin.campaigns.index', [
            'campaigns' => $campaigns,
            'status' => $status,
            'channelCount' => DistributionChannel::query()->count(),
        ]);
    }

    public function show(Campaign $campaign): View
    {
        $campaign->load([
            'workspace.organization',
            'clientSite',
            'toneProfile',
            'ctaPreset',
            'contents.content.publications',
            'distributionPlans.distributionChannel',
            'socialPostVariants.socialAccount',
            'socialPublications.socialAccount',
        ]);

        return view('admin.campaigns.show', [
            'campaign' => $campaign,
        ]);
    }
}

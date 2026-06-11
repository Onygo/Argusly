<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\Entitlements\FeatureGate;
use App\Services\Journey\FirstValueExperienceService;
use App\Services\Onboarding\FirstValueActivationService;
use App\Services\OpportunityReview\OpportunityReviewService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OpportunityReviewController extends Controller
{
    public function index(
        Request $request,
        FeatureGate $featureGate,
        OpportunityReviewService $review,
        FirstValueActivationService $activation,
        FirstValueExperienceService $firstValue,
    ): View {
        $workspace = $this->resolveWorkspace($request);
        $featureGate->assert($workspace, 'signal_intelligence');

        return view('app.opportunity-review.index', array_merge($review->summary($workspace), [
            'title' => 'Opportunity Review',
            'workspace' => $workspace,
            'workspaces' => Workspace::query()
                ->where('organization_id', $request->user()->organization_id)
                ->orderBy('created_at')
                ->get(),
            'activation' => $activation->forWorkspace($workspace),
            'firstValueCelebrations' => $firstValue->celebrations($workspace),
        ]));
    }

    private function resolveWorkspace(Request $request): Workspace
    {
        $workspace = Workspace::query()
            ->where('organization_id', $request->user()->organization_id)
            ->when($request->query('workspace'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('created_at')
            ->first();

        if (! $workspace) {
            throw new AuthorizationException('Workspace is not available for this user.');
        }

        return $workspace;
    }
}

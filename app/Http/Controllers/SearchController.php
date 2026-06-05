<?php

namespace App\Http\Controllers;

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function appIndex(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $organizationId = (int) ($request->user()?->organization_id ?? 0);
        $siteIds = $organizationId > 0
            ? ClientSite::query()->forOrganization($organizationId)->pluck('id')->all()
            : [];

        $contents = collect();
        $briefs = collect();
        $drafts = collect();
        $sites = collect();

        if ($q !== '' && ! empty($siteIds)) {
            $contents = Content::query()
                ->with('clientSite')
                ->whereIn('client_site_id', $siteIds)
                ->where('title', 'like', '%' . $q . '%')
                ->latest('updated_at')
                ->limit(20)
                ->get();

            $briefs = Brief::query()
                ->with('clientSite')
                ->whereIn('client_site_id', $siteIds)
                ->where('title', 'like', '%' . $q . '%')
                ->latest('updated_at')
                ->limit(20)
                ->get();

            $drafts = \App\Models\Draft::query()
                ->with('clientSite')
                ->whereIn('client_site_id', $siteIds)
                ->where('title', 'like', '%' . $q . '%')
                ->latest('updated_at')
                ->limit(20)
                ->get();

            $sites = ClientSite::query()
                ->forOrganization($organizationId)
                ->where(function ($query) use ($q): void {
                    $query->where('name', 'like', '%' . $q . '%')
                        ->orWhere('site_url', 'like', '%' . $q . '%')
                        ->orWhere('base_url', 'like', '%' . $q . '%');
                })
                ->latest('updated_at')
                ->limit(20)
                ->get();
        }

        return view('app.search.index', [
            'q' => $q,
            'contents' => $contents,
            'briefs' => $briefs,
            'drafts' => $drafts,
            'sites' => $sites,
        ]);
    }

    public function appSuggest(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['items' => []]);
        }

        $organizationId = (int) ($request->user()?->organization_id ?? 0);
        $siteIds = $organizationId > 0
            ? ClientSite::query()->forOrganization($organizationId)->pluck('id')->all()
            : [];

        if (empty($siteIds)) {
            return response()->json(['items' => []]);
        }

        $items = [];

        $contents = Content::query()
            ->with('clientSite')
            ->whereIn('client_site_id', $siteIds)
            ->where('title', 'like', '%' . $q . '%')
            ->latest('updated_at')
            ->limit(5)
            ->get();
        foreach ($contents as $content) {
            $items[] = [
                'label' => (string) $content->title,
                'subtitle' => 'Content · ' . (string) ($content->clientSite?->name ?? 'Site'),
                'type' => 'Content',
                'url' => route('app.content.show', $content),
            ];
        }

        $briefs = Brief::query()
            ->whereIn('client_site_id', $siteIds)
            ->where('title', 'like', '%' . $q . '%')
            ->latest('updated_at')
            ->limit(3)
            ->get();
        foreach ($briefs as $brief) {
            $items[] = [
                'label' => (string) $brief->title,
                'subtitle' => 'Brief',
                'type' => 'Brief',
                'url' => route('app.briefs.show', $brief),
            ];
        }

        $drafts = \App\Models\Draft::query()
            ->whereIn('client_site_id', $siteIds)
            ->where('title', 'like', '%' . $q . '%')
            ->latest('updated_at')
            ->limit(3)
            ->get();
        foreach ($drafts as $draft) {
            $items[] = [
                'label' => (string) $draft->title,
                'subtitle' => 'Draft',
                'type' => 'Draft',
                'url' => route('app.drafts.show', $draft),
            ];
        }

        $sites = ClientSite::query()
            ->forOrganization($organizationId)
            ->where(function ($query) use ($q): void {
                $query->where('name', 'like', '%' . $q . '%')
                    ->orWhere('site_url', 'like', '%' . $q . '%');
            })
            ->latest('updated_at')
            ->limit(3)
            ->get();
        foreach ($sites as $site) {
            $items[] = [
                'label' => (string) $site->name,
                'subtitle' => 'Site · ' . (string) ($site->base_url ?: $site->site_url),
                'type' => 'Site',
                'url' => route('app.sites.show', $site),
            ];
        }

        return response()->json(['items' => array_slice($items, 0, 12)]);
    }

    public function adminIndex(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $organizations = collect();
        $users = collect();
        $invoices = collect();
        $sites = collect();

        if ($q !== '') {
            $organizations = Organization::query()
                ->where(function ($query) use ($q): void {
                    $query->where('name', 'like', '%' . $q . '%')
                        ->orWhere('slug', 'like', '%' . $q . '%');
                })
                ->latest('updated_at')
                ->limit(20)
                ->get();

            $users = User::query()
                ->where(function ($query) use ($q): void {
                    $query->where('name', 'like', '%' . $q . '%')
                        ->orWhere('email', 'like', '%' . $q . '%');
                })
                ->latest('updated_at')
                ->limit(20)
                ->get();

            $invoices = Invoice::query()
                ->where(function ($query) use ($q): void {
                    $query->where('number', 'like', '%' . $q . '%')
                        ->orWhere('billing_company_name', 'like', '%' . $q . '%');
                })
                ->latest('updated_at')
                ->limit(20)
                ->get();

            $sites = ClientSite::query()
                ->where(function ($query) use ($q): void {
                    $query->where('name', 'like', '%' . $q . '%')
                        ->orWhere('site_url', 'like', '%' . $q . '%')
                        ->orWhere('base_url', 'like', '%' . $q . '%');
                })
                ->latest('updated_at')
                ->limit(20)
                ->get();
        }

        return view('admin.search.index', [
            'q' => $q,
            'organizations' => $organizations,
            'users' => $users,
            'invoices' => $invoices,
            'sites' => $sites,
        ]);
    }

    public function adminSuggest(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['items' => []]);
        }

        $items = [];

        $organizations = Organization::query()
            ->where(function ($query) use ($q): void {
                $query->where('name', 'like', '%' . $q . '%')
                    ->orWhere('slug', 'like', '%' . $q . '%');
            })
            ->latest('updated_at')
            ->limit(5)
            ->get();
        foreach ($organizations as $organization) {
            $items[] = [
                'label' => (string) $organization->name,
                'subtitle' => 'Organization',
                'type' => 'Organization',
                'url' => route('admin.organizations.show', $organization),
            ];
        }

        $users = User::query()
            ->where(function ($query) use ($q): void {
                $query->where('name', 'like', '%' . $q . '%')
                    ->orWhere('email', 'like', '%' . $q . '%');
            })
            ->latest('updated_at')
            ->limit(4)
            ->get();
        foreach ($users as $user) {
            $items[] = [
                'label' => (string) $user->name,
                'subtitle' => 'User · ' . (string) $user->email,
                'type' => 'User',
                'url' => route('admin.users', ['q' => $user->email]),
            ];
        }

        $invoices = Invoice::query()
            ->where(function ($query) use ($q): void {
                $query->where('number', 'like', '%' . $q . '%')
                    ->orWhere('billing_company_name', 'like', '%' . $q . '%');
            })
            ->latest('updated_at')
            ->limit(4)
            ->get();
        foreach ($invoices as $invoice) {
            $items[] = [
                'label' => (string) $invoice->number,
                'subtitle' => 'Invoice · ' . (string) ($invoice->billing_company_name ?: 'Unknown'),
                'type' => 'Invoice',
                'url' => route('admin.invoices.preview', $invoice),
            ];
        }

        $sites = ClientSite::query()
            ->where(function ($query) use ($q): void {
                $query->where('name', 'like', '%' . $q . '%')
                    ->orWhere('base_url', 'like', '%' . $q . '%');
            })
            ->latest('updated_at')
            ->limit(3)
            ->get();
        foreach ($sites as $site) {
            $items[] = [
                'label' => (string) $site->name,
                'subtitle' => 'Site · ' . (string) ($site->base_url ?: $site->site_url),
                'type' => 'Site',
                'url' => route('admin.sites', ['q' => $site->name]),
            ];
        }

        return response()->json(['items' => array_slice($items, 0, 12)]);
    }
}


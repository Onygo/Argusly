<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Mos\MosProviderRegistry;
use Illuminate\View\View;

class AdminMosProviderController extends Controller
{
    public function index(MosProviderRegistry $registry): View
    {
        return view('admin.mos-providers.index', [
            'providers' => $registry->diagnostics(),
            'opportunity_providers' => $registry->opportunityDiagnostics(),
            'duplicate_warnings' => $registry->duplicateWarnings(),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanyIntelligenceProfile;
use Illuminate\View\View;

class AdminCompanyIntelligenceController extends Controller
{
    public function index(): View
    {
        return view('admin.company-intelligence.index', [
            'profiles' => CompanyIntelligenceProfile::query()
                ->with(['organization', 'workspace', 'brandVoice'])
                ->latest()
                ->paginate(50),
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\LanguageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserLocaleController extends Controller
{
    public function __invoke(Request $request, LanguageService $languages): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in($languages->uiCodes())],
        ]);

        $request->user()->forceFill([
            'locale' => $validated['locale'],
        ])->save();

        return back();
    }
}

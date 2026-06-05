<?php

namespace App\Http\Controllers\Auth;

use App\Events\Onboarding\UserRegistered as UserRegisteredEvent;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\EmailCodeVerificationService;
use App\Support\OnboardingFee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;

class RegisterController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if ($blocked = $this->registrationBlockedResponse()) {
            return $blocked;
        }

        $plans = $this->publicMonthlyPlans();
        if ($plans->isEmpty()) {
            return redirect()
                ->route('pricing')
                ->withErrors(['plan' => 'No active plans are currently available.']);
        }

        $requestedSlug = trim((string) $request->query('plan', ''));
        $sessionSlug = $request->hasSession()
            ? trim((string) $request->session()->get('registration.plan_slug', ''))
            : '';
        $requestedSlug = $this->normalizePublicPlanSlug($requestedSlug);
        $sessionSlug = $this->normalizePublicPlanSlug($sessionSlug);
        $fallbackSlug = $plans->contains(fn (Plan $plan): bool => (string) $plan->slug === 'creator')
            ? 'creator'
            : (string) ($plans->first()?->slug ?? '');
        $selectedSlug = $requestedSlug !== '' ? $requestedSlug : ($sessionSlug !== '' ? $sessionSlug : $fallbackSlug);

        $selectedPlan = $plans->first(fn (Plan $plan): bool => (string) $plan->slug === $selectedSlug);
        if (! $selectedPlan) {
            $selectedPlan = $plans->first();
            $selectedSlug = (string) ($selectedPlan?->slug ?? '');
        }

        if ($request->hasSession()) {
            $request->session()->put('registration.plan_slug', $selectedSlug);
        }

        return view('auth.register', [
            'selectedPlan' => $selectedPlan,
            'plans' => $plans,
            'onboardingFeeWaived' => OnboardingFee::isWaived(),
        ]);
    }

    public function store(RegisterRequest $request, EmailCodeVerificationService $emailCodes): RedirectResponse
    {
        if ($blocked = $this->registrationBlockedResponse()) {
            return $blocked;
        }

        if ($silentBlock = $this->silentBlockHoneypot($request)) {
            return $silentBlock;
        }

        $validated = $request->validated();
        $planSlug = trim((string) ($validated['plan'] ?? ''));
        if ($planSlug === '' && $request->hasSession()) {
            $planSlug = trim((string) $request->session()->get('registration.plan_slug', ''));
        }

        $plan = $this->resolvePublicPlanBySlug($planSlug);
        if (! $plan) {
            return redirect()
                ->route('pricing')
                ->withErrors(['plan' => 'Select a valid plan before registration.']);
        }

        [$organization, $user] = DB::transaction(function () use ($validated) {
            $organization = Organization::create([
                'name' => $validated['company_name'],
                'slug' => $this->uniqueSlug($validated['company_name']),
                'status' => 'pending',
                'approved_at' => null,
            ]);

            Workspace::create([
                'name' => $organization->name,
                'organization_id' => $organization->id,
            ]);

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'organization_id' => $organization->id,
                'role' => 'owner',
                'active' => true,
                'approved_at' => now(),
                'is_admin' => false,
            ]);

            $organization->update([
                'primary_user_id' => $user->id,
            ]);

            return [$organization, $user];
        });

        UserRegisteredEvent::dispatch($user->id, (string) $organization->workspaces()->orderBy('created_at')->value('id'));

        Auth::login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
            $request->session()->forget('registration.plan_slug');
            $request->session()->put('billing.selected_plan_slug', (string) $plan->slug);
        }
        $code = $emailCodes->issueCode($user);
        $emailCodes->sendCode($user->fresh(), $code);

        return redirect()
            ->route('verify-code.show')
            ->with('status', sprintf('Account created. Enter the verification code sent to your email to continue with %s plan onboarding.', (string) $plan->name));
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base !== '' ? $base : Str::random(6);
        $suffix = 1;

        while (Organization::query()->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }

    private function resolvePublicPlanBySlug(string $slug): ?Plan
    {
        $slug = $this->normalizePublicPlanSlug($slug);

        if ($slug === '') {
            return null;
        }

        return $this->publicMonthlyPlans()
            ->first(fn (Plan $plan): bool => (string) $plan->slug === $slug);
    }

    /**
     * @return Collection<int,Plan>
     */
    private function publicMonthlyPlans(): Collection
    {
        return Plan::query()
            ->select([
                'id',
                'slug',
                'name',
                'interval',
                'currency',
                'price_monthly_cents',
                'monthly_price_cents',
                'price_cents',
                'has_required_onboarding',
                'onboarding_label',
                'onboarding_checkout_label',
                'onboarding_description',
                'onboarding_fee_cents',
                'onboarding_fee_currency',
                'limits',
                'sort_order',
            ])
            ->publiclyVisible()
            ->fixedBilling()
            ->where('interval', 'month')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    private function registrationBlockedResponse(): ?RedirectResponse
    {
        if ((bool) config('publishlayer.launch.public_registration_enabled', true)) {
            return null;
        }

        $mode = strtolower(trim((string) config('publishlayer.launch.registration_block_mode', 'redirect')));

        if (in_array($mode, ['403', 'forbidden'], true)) {
            abort(403);
        }

        if (in_array($mode, ['404', 'not_found', 'not-found'], true)) {
            abort(404);
        }

        return redirect()->route('public.early-access.show');
    }

    private function silentBlockHoneypot(Request $request): ?RedirectResponse
    {
        foreach ((array) config('security.registration.honeypot_fields', []) as $field) {
            $field = trim((string) $field);
            if ($field === '' || trim((string) $request->input($field, '')) === '') {
                continue;
            }

            Log::channel((string) config('security.logging.channel', 'security'))
                ->warning('registration_honeypot_tripped', [
                    'field' => $field,
                    'ip' => (string) ($request->ip() ?? 'unknown'),
                    'email_domain' => $this->emailDomain((string) $request->input('email', '')),
                    'user_agent' => mb_substr((string) ($request->userAgent() ?? ''), 0, 255),
                ]);

            return redirect()
                ->route('login')
                ->with('status', 'Account request received. Check your email for next steps.');
        }

        return null;
    }

    private function emailDomain(string $email): string
    {
        $domain = strtolower(trim((string) strrchr($email, '@'), '@'));

        return $domain !== '' ? $domain : 'unknown';
    }

    private function normalizePublicPlanSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));

        return match ($slug) {
            'starter' => 'creator',
            'pro' => 'growth',
            'agency' => 'scale',
            default => $slug,
        };
    }
}

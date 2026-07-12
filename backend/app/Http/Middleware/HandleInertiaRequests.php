<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\CompanyMembership;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'has_completed_tour' => $request->user()->product_tour_completed_at !== null,
                    'has_dismissed_checklist' => $request->user()->checklist_dismissed_at !== null,
                ] : null,
            ],
            // Lazy: HandleInertiaRequests is global 'web' middleware and runs
            // BEFORE route-level middleware like EnsureCompanyMembership, so
            // the 'company' request attribute isn't set yet when share() runs.
            // A closure defers evaluation until the response is built (after
            // the controller/middleware chain has run), when the attribute
            // is actually available.
            'company' => function () use ($request): ?array {
                /** @var Company|null $company */
                $company = $request->attributes->get('company');

                return $company ? [
                    'id' => $company->id,
                    'name' => $company->name,
                    'slug' => $company->slug ?? null,
                    'industry' => $company->industry ?? null,
                ] : null;
            },
            // All companies the user belongs to — drives the sidebar company
            // switcher (rendered only when there is more than one).
            // withoutGlobalScopes(): this listing is deliberately
            // cross-company (a user's own memberships, keyed by user_id, not
            // by the currently-bound tenant) — by the time this closure runs
            // (after EnsureCompanyMembership), current_company_id is already
            // bound to the active company, and without this the query would
            // incorrectly show only that one company instead of all of them.
            'companies' => fn (): array => $request->user()
                ? CompanyMembership::withoutGlobalScopes()
                    ->with('company')
                    ->where('user_id', $request->user()->id)
                    ->get()
                    ->map(fn (CompanyMembership $m): array => [
                        'id' => $m->company_id,
                        'name' => $m->company->name ?? '',
                    ])
                    ->values()
                    ->all()
                : [],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ]);
    }
}

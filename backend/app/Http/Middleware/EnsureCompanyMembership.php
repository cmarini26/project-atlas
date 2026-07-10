<?php

namespace App\Http\Middleware;

use App\Models\CompanyMembership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyMembership
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        // withoutGlobalScopes(): this middleware's whole job is figuring out
        // which company/companies this user belongs to — a lookup keyed by
        // user_id, not by whatever tenant (if any) happened to be bound on
        // a prior request in this process. It must see every membership
        // regardless of any already-bound current_company_id.
        $memberships = CompanyMembership::withoutGlobalScopes()
            ->with('company')
            ->where('user_id', $user->id)
            ->get();

        if ($memberships->isEmpty()) {
            return redirect()->route('onboarding');
        }

        if ($memberships->count() === 1) {
            $membership = $memberships->first();

            $request->attributes->set('company', $membership->company);
            $request->attributes->set('membership', $membership);
            app()->instance('current_company_id', $membership->company_id);

            return $next($request);
        }

        // Multiple memberships — use session-stored company ID or redirect to selector
        $selectedId = $request->session()->get('selected_company_id');
        $membership = $memberships->firstWhere('company_id', $selectedId);

        if (! $membership) {
            return redirect()->route('company.select');
        }

        $request->attributes->set('company', $membership->company);
        $request->attributes->set('membership', $membership);
        app()->instance('current_company_id', $membership->company_id);

        return $next($request);
    }
}

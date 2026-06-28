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

        $memberships = CompanyMembership::with('company')
            ->where('user_id', $user->id)
            ->get();

        if ($memberships->isEmpty()) {
            return redirect()->route('onboarding');
        }

        if ($memberships->count() === 1) {
            $request->attributes->set('company', $memberships->first()->company);
            $request->attributes->set('membership', $memberships->first());

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

        return $next($request);
    }
}

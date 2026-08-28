<?php

namespace App\Http\Middleware;

use App\Billing\BillingAccess;
use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks an action when the company's billing state does not permit it.
 * A pass-through while `billing.gate_enabled` is off (the beta default).
 * Route alias: `billing`. Apply only to "execute / spend" routes — never to
 * read routes, and never to the billing settings routes themselves.
 */
class RequireBillingAccess
{
    public function __construct(private readonly BillingAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Company|null $company */
        $company = $request->attributes->get('company');

        if ($company === null || $this->access->allows($company)) {
            return $next($request);
        }

        $reason = $this->access->deniedReason($company) ?? 'A subscription is required.';

        if ($request->expectsJson()) {
            return response()->json(['error' => $reason], Response::HTTP_PAYMENT_REQUIRED);
        }

        return redirect()->route('app.settings.billing')->with('error', $reason);
    }
}

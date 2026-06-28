<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Jobs\SyncIntegration;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Integration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        $integrations = Integration::where('company_id', $company->id)
            ->latest()
            ->get()
            ->map(fn (Integration $i) => [
                'id' => $i->id,
                'type' => $i->type,
                'name' => $i->name,
                'status' => $i->status,
                'next_run_at' => $i->next_run_at !== null ? (string) $i->next_run_at : null,
                'last_run_at' => $i->last_run_at !== null ? (string) $i->last_run_at : null,
            ]);

        /** @var CompanyMembership $membership */
        $membership = $request->attributes->get('membership');

        return Inertia::render('App/Settings', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'industry' => $company->industry,
                'website_url' => $company->website_url,
            ],
            'integrations' => $integrations->values()->all(),
            'membership_role' => $membership->role,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
        ]);

        $company->update($validated);

        return back()->with('success', 'Settings saved.');
    }

    public function syncIntegration(Request $request, Integration $integration): RedirectResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        abort_if($integration->company_id !== $company->id, 404);

        SyncIntegration::dispatch($integration);

        return back()->with('success', 'Sync started. Check back in a few minutes.');
    }
}

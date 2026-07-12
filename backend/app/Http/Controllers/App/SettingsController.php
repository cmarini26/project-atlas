<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Jobs\SyncIntegration;
use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\InstagramAccount;
use App\Models\Integration;
use App\Services\Observatory\IntegrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SettingsController extends Controller
{
    public function __construct(private readonly IntegrationService $integrationService) {}

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

        $instagramAccount = InstagramAccount::where('company_id', $company->id)->first();

        $metaCredentials = ChannelCredentials::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('channel_type', ['facebook', 'instagram'])
            ->where('status', '!=', 'revoked')
            ->get()
            ->keyBy('channel_type');

        $metaChannels = Channel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('type', ['facebook', 'instagram'])
            ->get()
            ->map(function (Channel $c) use ($metaCredentials) {
                $credentials = $metaCredentials->get($c->type);

                return $credentials === null ? null : [
                    'type' => $c->type,
                    'name' => $c->name,
                    'status' => $credentials->status,
                ];
            })
            ->filter()
            ->values();

        return Inertia::render('App/Settings', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'industry' => $company->industry,
                'website_url' => $company->website_url,
            ],
            'integrations' => $integrations->values()->all(),
            'membership_role' => $membership->role,
            'instagram_account' => $instagramAccount !== null ? [
                'username' => $instagramAccount->username,
                'display_name' => $instagramAccount->display_name,
                'profile_picture_url' => $instagramAccount->profile_picture_url,
                'bio' => $instagramAccount->bio,
                'website' => $instagramAccount->website,
                'follower_count' => $instagramAccount->follower_count,
                'following_count' => $instagramAccount->following_count,
                'last_synced_at' => $instagramAccount->last_synced_at !== null ? (string) $instagramAccount->last_synced_at : null,
            ] : null,
            'meta_channels' => $metaChannels->all(),
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

    /**
     * Connect (or reconnect) the company's Instagram account. Beta scope:
     * a manually-entered access token, one account per company — no OAuth
     * flow. See docs/plans (Milestone 12 Phase 1, Instagram Observation).
     */
    public function connectInstagram(Request $request): RedirectResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        $validated = $request->validate([
            'access_token' => ['required', 'string', 'max:2000'],
        ]);

        $integration = Integration::where('company_id', $company->id)
            ->where('type', 'instagram')
            ->first();

        if ($integration !== null) {
            $integration->update([
                'config' => ['access_token' => $validated['access_token']],
                'status' => 'active',
                'last_error' => null,
            ]);
        } else {
            $integration = $this->integrationService->create(
                $company,
                'instagram',
                ['access_token' => $validated['access_token']],
            );
        }

        try {
            SyncIntegration::dispatch($integration);
        } catch (Throwable $e) {
            $integration->markAsError($e->getMessage());

            return back()->with('error', 'Could not connect to Instagram: '.$e->getMessage());
        }

        return back()->with('success', 'Instagram connected. Syncing your profile now.');
    }
}

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
use App\Models\MarketingChannel;
use App\Services\MarketingPresence\MarketingPresenceService;
use App\Services\Observatory\IntegrationService;
use App\Services\Publishing\Email\EmailChannelService;
use App\Services\Publishing\WordPressPublisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SettingsController extends Controller
{
    public function __construct(
        private readonly IntegrationService $integrationService,
        private readonly MarketingPresenceService $marketingPresence,
        private readonly WordPressPublisher $wordPressPublisher,
        private readonly EmailChannelService $emailChannelService,
    ) {}

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
                'last_error' => $i->last_error,
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

        $wordPressChannel = Channel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('type', 'blog')
            ->first();

        $wordPressCredentials = $wordPressChannel === null ? null : ChannelCredentials::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('channel_type', 'blog')
            ->where('status', '!=', 'revoked')
            ->first();

        /** @var array<string, mixed> $wordPressChannelConfig */
        $wordPressChannelConfig = $wordPressChannel->config ?? [];

        $emailChannel = Channel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('type', 'email')
            ->first();

        $emailCredentials = $emailChannel === null ? null : ChannelCredentials::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('channel_type', 'email')
            ->where('status', '!=', 'revoked')
            ->first();

        /** @var array<string, mixed> $emailChannelConfig */
        $emailChannelConfig = $emailChannel->config ?? [];

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
            'wordpress_channel' => $wordPressCredentials === null ? null : [
                'name' => $wordPressChannel->name,
                'site_url' => (string) ($wordPressChannelConfig['site_url'] ?? ''),
                'status' => $wordPressCredentials->status,
            ],
            // Deliberately never includes `credentials` — the encrypted
            // Postmark server token never leaves the backend once stored.
            'email_channel' => $emailCredentials === null ? null : [
                'provider_type' => $emailCredentials->provider_type,
                'from_email' => (string) ($emailChannelConfig['from_email'] ?? ''),
                'from_name' => (string) ($emailChannelConfig['from_name'] ?? ''),
                'status' => $emailCredentials->status,
                'last_used_at' => $emailCredentials->last_used_at !== null ? (string) $emailCredentials->last_used_at : null,
            ],
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

        // Close the gap docs/specs/Business-Discovery-Onboarding.md §3.1
        // identifies: a declared Instagram MarketingChannel from onboarding
        // has no path to is_connected: true until the user connects for
        // real here — link it now so Business Discovery can find and resync
        // it as "already connected" on any future run.
        $declaredChannel = MarketingChannel::where('company_id', $company->id)
            ->where('type', 'instagram')
            ->first();

        if ($declaredChannel !== null) {
            $this->marketingPresence->linkIntegration($declaredChannel, $integration);
        }

        try {
            SyncIntegration::dispatch($integration);
        } catch (Throwable $e) {
            $integration->markAsError($e->getMessage());

            return back()->with('error', 'Could not connect to Instagram: '.$e->getMessage());
        }

        return back()->with('success', 'Instagram connected. Syncing your profile now.');
    }

    /**
     * Connect (or reconnect) the company's WordPress site for blog
     * publishing. WordPress Application Passwords (a native WP feature, no
     * app registration) authenticate via HTTP Basic Auth — no OAuth needed.
     */
    public function connectWordPress(Request $request): RedirectResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        $validated = $request->validate([
            'site_url' => ['required', 'url', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            'app_password' => ['required', 'string', 'max:255'],
        ]);

        $siteUrl = rtrim($validated['site_url'], '/');

        Channel::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'type' => 'blog'],
            ['name' => (string) parse_url($siteUrl, PHP_URL_HOST), 'config' => ['site_url' => $siteUrl], 'is_active' => true],
        );

        // Ping the site with the submitted credentials before ever reporting
        // "connected" — WordPressPublisher::ping() only needs company_id and
        // the raw credentials, not a persisted row, so we validate first and
        // decide the stored status from a real result instead of assuming
        // success (see docs/reviews/Channel-Publishing-Reality-Audit.md).
        $candidateCredentials = new ChannelCredentials([
            'company_id' => $company->id,
            'channel_type' => 'blog',
            'credentials' => json_encode([
                'username' => $validated['username'],
                'app_password' => $validated['app_password'],
            ]),
        ]);

        $ping = $this->wordPressPublisher->ping($candidateCredentials);

        ChannelCredentials::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'channel_type' => 'blog'],
            [
                'provider_type' => 'wordpress',
                'credentials' => $candidateCredentials->credentials,
                'status' => $ping->reachable ? 'active' : 'error',
                'expires_at' => null,
            ],
        );

        if (! $ping->reachable) {
            return back()->withErrors(['app_password' => "Couldn't connect to WordPress with those credentials: {$ping->error}"]);
        }

        return back()->with('success', 'WordPress connected.');
    }

    public function disconnectWordPress(Request $request): RedirectResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        ChannelCredentials::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('channel_type', 'blog')
            ->update(['status' => 'revoked']);

        return back()->with('success', 'WordPress disconnected.');
    }

    /**
     * Connect (or reconnect/rotate) the company's Postmark server for real
     * email sending. Business logic (ping-before-persist, Channel/
     * ChannelCredentials upsert, capability sync) lives in
     * EmailChannelService — this action only validates input and translates
     * the result into a response.
     */
    public function connectEmail(Request $request): RedirectResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        $validated = $request->validate([
            'api_token' => ['required', 'string', 'max:500'],
            'from_email' => ['required', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
        ]);

        $ping = $this->emailChannelService->connect(
            $company,
            $validated['api_token'],
            $validated['from_email'],
            $validated['from_name'] ?? null,
        );

        if (! $ping->reachable) {
            return back()->withErrors(['api_token' => "Couldn't connect to Postmark with that token: {$ping->error}"]);
        }

        return back()->with('success', 'Postmark connected.');
    }

    public function disconnectEmail(Request $request): RedirectResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        $this->emailChannelService->disconnect($company);

        return back()->with('success', 'Postmark disconnected.');
    }

    /**
     * Company-authorized test send, proving the connection can actually
     * deliver — not just that the token is valid. See
     * EmailChannelService::sendTest() for why no Execution/ContentAsset row
     * is created for this.
     */
    public function sendEmailTest(Request $request): RedirectResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        $validated = $request->validate([
            'to_email' => ['required', 'email', 'max:255'],
        ]);

        $result = $this->emailChannelService->sendTest($company, $validated['to_email']);

        return $result->success
            ? back()->with('success', $result->message)
            : back()->with('error', $result->message);
    }
}

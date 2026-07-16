<?php

namespace App\Http\Controllers\App;

use App\Domain\Publishing\ValueObjects\EmailPayload;
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
use App\Services\Publishing\ChannelCredentialsRepository;
use App\Services\Publishing\Email\EmailProviderRegistry;
use App\Services\Publishing\Exceptions\PublishingException;
use App\Services\Publishing\WordPressPublisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SettingsController extends Controller
{
    public function __construct(
        private readonly IntegrationService $integrationService,
        private readonly MarketingPresenceService $marketingPresence,
        private readonly WordPressPublisher $wordPressPublisher,
        private readonly EmailProviderRegistry $emailProviders,
        private readonly ChannelCredentialsRepository $credentialsRepository,
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
     * email sending. Postmark's Server API Token is the only credential its
     * API requires — PostmarkEmailProvider/PostmarkAnalyticsProvider both
     * read it as a bare string from ChannelCredentials.credentials (not a
     * JSON blob, unlike WordPress/Meta), so it's stored that way here too.
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

        // Ping Postmark with the submitted token before ever reporting
        // "connected" — same pattern as connectWordPress(): verify first,
        // decide the stored status from a real result, never assume
        // success. A bare, unsaved ChannelCredentials is enough for ping().
        $candidateCredentials = new ChannelCredentials([
            'company_id' => $company->id,
            'channel_type' => 'email',
            'credentials' => $validated['api_token'],
        ]);

        $provider = $this->emailProviders->for('postmark');
        $ping = $provider->ping($candidateCredentials);

        $emailChannel = Channel::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'type' => 'email'],
            [
                'name' => 'Email',
                'config' => [
                    'from_email' => $validated['from_email'],
                    'from_name' => $validated['from_name'] ?? '',
                ],
                'is_active' => true,
            ],
        );

        ChannelCredentials::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'channel_type' => 'email'],
            [
                'provider_type' => 'postmark',
                'credentials' => $candidateCredentials->credentials,
                'status' => $ping->reachable ? 'active' : 'error',
                'expires_at' => null,
            ],
        );

        $this->syncEmailPublishingCapability($company, $emailChannel, $ping->reachable);

        if (! $ping->reachable) {
            return back()->withErrors(['api_token' => "Couldn't connect to Postmark with that token: {$ping->error}"]);
        }

        return back()->with('success', 'Postmark connected.');
    }

    public function disconnectEmail(Request $request): RedirectResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        ChannelCredentials::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('channel_type', 'email')
            ->update(['status' => 'revoked']);

        $this->syncEmailPublishingCapability($company, null, false);

        return back()->with('success', 'Postmark disconnected.');
    }

    /**
     * Company-authorized test send, proving the connection can actually
     * deliver — not just that the token is valid. Reuses the exact same
     * credential resolution (ChannelCredentialsRepository) and provider
     * (EmailProviderRegistry → PostmarkEmailProvider) real campaign sends
     * use; a disconnected/revoked/errored company is rejected the same way
     * EmailPublisher::publish() would reject it, not by a second check.
     * No Execution/ContentAsset row is created — there is no real campaign
     * content here, and Execution.content_asset_id is a required unique FK,
     * so inventing one would pollute Campaigns/Publishing with fake rows.
     * Logged instead, to the same 'publishing' channel LogChannelPublisher/
     * LogEmailProvider already use, with no secret in the log line.
     */
    public function sendEmailTest(Request $request): RedirectResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        $validated = $request->validate([
            'to_email' => ['required', 'email', 'max:255'],
        ]);

        try {
            $credentials = $this->credentialsRepository->for($company->id, 'email');
        } catch (PublishingException $e) {
            return back()->with('error', $e->userMessage());
        }

        $emailChannel = Channel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('type', 'email')
            ->first();

        /** @var array<string, mixed> $emailChannelConfig */
        $emailChannelConfig = $emailChannel->config ?? [];
        $fromEmail = (string) ($emailChannelConfig['from_email'] ?? '');

        if ($fromEmail === '') {
            return back()->with('error', 'Connect your email channel with a sender address before sending a test.');
        }

        $payload = new EmailPayload(
            subject: 'Atlas test email',
            fromName: (string) ($emailChannelConfig['from_name'] ?? ''),
            fromEmail: $fromEmail,
            body: "This is a test email from Atlas for {$company->name}. If you received this, your email connection is working.",
            previewText: 'Atlas email connection test',
            toEmail: $validated['to_email'],
        );

        $provider = $this->emailProviders->for($credentials->provider_type ?? 'log');

        try {
            $messageId = $provider->send($payload, $credentials);
        } catch (PublishingException $e) {
            Log::channel('publishing')->error('SettingsController: email test send failed.', [
                'company_id' => $company->id,
                'to_email' => $validated['to_email'],
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', "Test email failed: {$e->getMessage()}");
        }

        Log::channel('publishing')->info('SettingsController: email test send succeeded.', [
            'company_id' => $company->id,
            'to_email' => $validated['to_email'],
            'platform_id' => $messageId,
        ]);

        return back()->with('success', "Test email sent to {$validated['to_email']}.");
    }

    /**
     * Keep the declared `email` MarketingChannel's supports_publishing flag
     * (the capability-truth signal resolveChannelCapability() reads) in
     * sync with the real connection state — the same link()/
     * markPublishingVerified() pair MetaOAuthController::
     * linkAndVerifyPublishing()/revoke() already use, not a new mechanism.
     * A company that never declared an `email` MarketingChannel (via
     * onboarding or /app/settings/marketing-presence) has nothing to link;
     * the real Channel/ChannelCredentials above remain the source of truth
     * for actual sending either way.
     */
    private function syncEmailPublishingCapability(Company $company, ?Channel $realChannel, bool $verified): void
    {
        $declared = MarketingChannel::where('company_id', $company->id)
            ->where('type', 'email')
            ->first();

        if ($declared === null) {
            return;
        }

        if ($realChannel !== null) {
            $this->marketingPresence->link($declared, $realChannel);
        }

        $this->marketingPresence->markPublishingVerified($declared, $verified);
    }
}

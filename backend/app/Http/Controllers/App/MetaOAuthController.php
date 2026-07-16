<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\MarketingChannel;
use App\Services\MarketingPresence\MarketingPresenceService;
use App\Services\Publishing\Exceptions\AuthenticationException;
use App\Services\Publishing\MetaOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Isolated from SettingsController deliberately: OAuth token acquisition
 * (PKCE, state validation, token exchange) is a higher-security-stakes
 * surface than the rest of Settings' CRUD, and keeping it in its own small,
 * auditable controller makes that surface easy to review in isolation.
 */
class MetaOAuthController extends Controller
{
    private const SESSION_STATE_KEY = 'meta_oauth_state';

    private const SESSION_VERIFIER_KEY = 'meta_oauth_code_verifier';

    public function __construct(
        private readonly MetaOAuthService $oauth,
        private readonly MarketingPresenceService $marketingPresence,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        $codeVerifier = Str::random(64);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $state = Str::random(40);

        $request->session()->put(self::SESSION_STATE_KEY, $state);
        $request->session()->put(self::SESSION_VERIFIER_KEY, $codeVerifier);

        return redirect()->away($this->oauth->buildAuthorizationUrl($state, $codeChallenge));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expectedState = $request->session()->get(self::SESSION_STATE_KEY);
        $codeVerifier = $request->session()->get(self::SESSION_VERIFIER_KEY);

        $request->session()->forget([self::SESSION_STATE_KEY, self::SESSION_VERIFIER_KEY]);

        $state = $request->query('state');
        $code = $request->query('code');

        if ($expectedState === null || $codeVerifier === null || $state !== $expectedState) {
            return redirect()->route('app.settings')->with('error', 'Meta connection failed — the request could not be verified. Please try again.');
        }

        if (! is_string($code) || $code === '') {
            return redirect()->route('app.settings')->with('error', 'Meta did not return an authorization code.');
        }

        /** @var Company $company */
        $company = $request->attributes->get('company');

        try {
            $shortLivedToken = $this->oauth->exchangeCodeForToken($code, (string) $codeVerifier);
            $longLivedToken = $this->oauth->exchangeForLongLivedToken($shortLivedToken);
            $pages = $this->oauth->fetchPages($longLivedToken);
        } catch (AuthenticationException $e) {
            report($e);

            return redirect()->route('app.settings')->with('error', 'Meta connection failed — please try again.');
        }

        if ($pages === []) {
            return redirect()->route('app.settings')->with('error', 'No Facebook Pages were found for this account. Connect an account that manages at least one Page.');
        }

        // Single-page connection for now — the first returned Page. Letting
        // a company pick among several Pages is a Settings UI concern for
        // later, not a blocker for the OAuth plumbing itself.
        $page = $pages[0];

        $facebookChannel = Channel::updateOrCreate(
            ['company_id' => $company->id, 'type' => 'facebook'],
            ['name' => $page['name'], 'is_active' => true],
        );

        // credentials stores both the access token and the Graph API ID it
        // publishes against — MetaChannelPublisher needs the target ID
        // (Page ID for Facebook, a *different* ID for Instagram) alongside
        // the token, so this is a small JSON blob rather than a bare token.
        ChannelCredentials::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'channel_type' => 'facebook'],
            [
                'provider_type' => 'meta',
                'credentials' => json_encode(['access_token' => $page['access_token'], 'target_id' => $page['id']]),
                'status' => 'active',
                'expires_at' => null,
            ],
        );

        // The OAuth token exchange above already is the verification — a
        // fake/expired code would have thrown before reaching this line —
        // so it's safe to mark the declared channel (if any) as genuinely
        // publishing-capable now, closing the gap MarketingPresenceService
        // ::link()'s docblock flagged as a deferred "Phase 6/12" upgrade.
        $this->linkAndVerifyPublishing($company, $facebookChannel, 'facebook');

        try {
            $instagramId = $this->oauth->fetchInstagramBusinessAccountId($page['id'], $page['access_token']);
        } catch (AuthenticationException $e) {
            report($e);
            $instagramId = null;
        }

        if ($instagramId !== null) {
            $instagramChannel = Channel::updateOrCreate(
                ['company_id' => $company->id, 'type' => 'instagram'],
                ['name' => $page['name'], 'is_active' => true],
            );

            ChannelCredentials::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $company->id, 'channel_type' => 'instagram'],
                [
                    'provider_type' => 'meta',
                    'credentials' => json_encode(['access_token' => $page['access_token'], 'target_id' => $instagramId]),
                    'status' => 'active',
                    'expires_at' => null,
                ],
            );

            $this->linkAndVerifyPublishing($company, $instagramChannel, 'instagram');
        }

        return redirect()->route('app.settings')->with('success', "Connected to {$page['name']} on Facebook.");
    }

    /**
     * Link the company's declared MarketingChannel (if any) for this type to
     * the real Channel just connected, and mark it publishing-verified. A
     * company that never declared this channel type (e.g. via onboarding or
     * /app/settings/marketing-presence) has nothing to link — that's fine,
     * the real Channel/ChannelCredentials above are the source of truth for
     * actual publishing either way; this only affects the capability badge
     * shown for a *declared* channel.
     */
    private function linkAndVerifyPublishing(Company $company, Channel $realChannel, string $marketingChannelType): void
    {
        $declared = MarketingChannel::where('company_id', $company->id)
            ->where('type', $marketingChannelType)
            ->first();

        if ($declared === null) {
            return;
        }

        $this->marketingPresence->link($declared, $realChannel);
        $this->marketingPresence->markPublishingVerified($declared, true);
    }

    public function revoke(Request $request): RedirectResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('company');

        MarketingChannel::where('company_id', $company->id)
            ->whereIn('type', ['facebook', 'instagram'])
            ->each(fn (MarketingChannel $declared) => $this->marketingPresence->markPublishingVerified($declared, false));

        ChannelCredentials::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('channel_type', ['facebook', 'instagram'])
            ->update(['status' => 'revoked']);

        return redirect()->route('app.settings')->with('success', 'Disconnected from Meta.');
    }
}

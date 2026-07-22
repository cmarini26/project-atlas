<?php

namespace App\Services\Publishing\Email;

use App\Domain\Publishing\ValueObjects\EmailPayload;
use App\Domain\Publishing\ValueObjects\EmailTestSendResult;
use App\Domain\Publishing\ValueObjects\PingResult;
use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\MarketingChannel;
use App\Services\MarketingPresence\MarketingPresenceService;
use App\Services\Publishing\ChannelCredentialsRepository;
use App\Services\Publishing\Exceptions\PublishingException;
use Illuminate\Support\Facades\Log;

/**
 * Company-facing Postmark connect/disconnect/test-send business logic —
 * kept out of SettingsController per this codebase's "thin controller,
 * business logic in services" principle. Mirrors the same verify-then-
 * persist shape connectWordPress() uses inline (WordPress has no
 * equivalent extraction yet; email got one first since this is the
 * channel Phase 1 of the production-readiness gap plan targets).
 */
class EmailChannelService
{
    public function __construct(
        private readonly EmailProviderRegistry $emailProviders,
        private readonly ChannelCredentialsRepository $credentialsRepository,
        private readonly MarketingPresenceService $marketingPresence,
    ) {}

    /**
     * Connect (or reconnect/rotate) the company's email provider. Current
     * real providers are Postmark and SendGrid; both use a single API token,
     * so ChannelCredentials.credentials stays a bare encrypted string here
     * rather than a JSON blob. Pings the selected provider before ever
     * persisting `status: 'active'` — never assume success, decide the stored
     * status from a real result.
     */
    public function connect(Company $company, string $providerType, string $apiToken, string $fromEmail, ?string $fromName): PingResult
    {
        $candidateCredentials = new ChannelCredentials([
            'company_id' => $company->id,
            'channel_type' => 'email',
            'credentials' => $apiToken,
        ]);

        $ping = $this->emailProviders->for($providerType)->ping($candidateCredentials);

        $emailChannel = Channel::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'type' => 'email'],
            [
                'name' => 'Email',
                'config' => [
                    'from_email' => $fromEmail,
                    'from_name' => $fromName ?? '',
                ],
                'is_active' => true,
            ],
        );

        ChannelCredentials::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'channel_type' => 'email'],
            [
                'provider_type' => $providerType,
                'credentials' => $candidateCredentials->credentials,
                'status' => $ping->reachable ? 'active' : 'error',
                'expires_at' => null,
            ],
        );

        $this->syncPublishingCapability($company, $emailChannel, $ping->reachable);

        return $ping;
    }

    public function disconnect(Company $company): void
    {
        ChannelCredentials::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('channel_type', 'email')
            ->update(['status' => 'revoked']);

        $this->syncPublishingCapability($company, null, false);
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
    public function sendTest(Company $company, string $toEmail): EmailTestSendResult
    {
        try {
            $credentials = $this->credentialsRepository->for($company->id, 'email');
        } catch (PublishingException $e) {
            return new EmailTestSendResult(false, $e->userMessage());
        }

        $emailChannel = Channel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('type', 'email')
            ->first();

        /** @var array<string, mixed> $emailChannelConfig */
        $emailChannelConfig = $emailChannel->config ?? [];
        $fromEmail = (string) ($emailChannelConfig['from_email'] ?? '');

        if ($fromEmail === '') {
            return new EmailTestSendResult(false, 'Connect your email channel with a sender address before sending a test.');
        }

        $payload = new EmailPayload(
            subject: 'Atlas test email',
            fromName: (string) ($emailChannelConfig['from_name'] ?? ''),
            fromEmail: $fromEmail,
            body: "This is a test email from Atlas for {$company->name}. If you received this, your email connection is working.",
            previewText: 'Atlas email connection test',
            toEmail: $toEmail,
        );

        $provider = $this->emailProviders->for($credentials->provider_type ?? 'log');

        try {
            $messageId = $provider->send($payload, $credentials);
        } catch (PublishingException $e) {
            Log::channel('publishing')->error('EmailChannelService: email test send failed.', [
                'company_id' => $company->id,
                'to_email' => $toEmail,
                'error' => $e->getMessage(),
            ]);

            return new EmailTestSendResult(false, "Test email failed: {$e->getMessage()}");
        }

        Log::channel('publishing')->info('EmailChannelService: email test send succeeded.', [
            'company_id' => $company->id,
            'to_email' => $toEmail,
            'platform_id' => $messageId,
        ]);

        return new EmailTestSendResult(true, "Test email sent to {$toEmail}.");
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
    private function syncPublishingCapability(Company $company, ?Channel $realChannel, bool $verified): void
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

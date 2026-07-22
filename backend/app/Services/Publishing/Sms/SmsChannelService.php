<?php

namespace App\Services\Publishing\Sms;

use App\Domain\Publishing\ValueObjects\PingResult;
use App\Domain\Publishing\ValueObjects\SmsTestSendResult;
use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Services\Publishing\ChannelCredentialsRepository;
use App\Services\Publishing\Exceptions\PublishingException;
use Illuminate\Support\Facades\Log;

class SmsChannelService
{
    public function __construct(
        private readonly SmsProviderRegistry $smsProviders,
        private readonly ChannelCredentialsRepository $credentialsRepository,
    ) {}

    public function connect(
        Company $company,
        string $providerType,
        string $accountSid,
        string $authToken,
        string $fromNumber,
        ?string $toNumber = null,
    ): PingResult
    {
        $candidateCredentials = new ChannelCredentials([
            'company_id' => $company->id,
            'channel_type' => 'sms',
            'credentials' => json_encode([
                'account_sid' => $accountSid,
                'auth_token' => $authToken,
            ], JSON_THROW_ON_ERROR),
        ]);

        $ping = $this->smsProviders->for($providerType)->ping($candidateCredentials);

        $channel = Channel::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'type' => 'sms'],
            [
                'name' => 'SMS',
                'config' => array_filter([
                    'from_number' => $fromNumber,
                    'to_number' => $toNumber,
                ], fn ($value) => $value !== null && $value !== ''),
                'is_active' => $ping->reachable,
            ],
        );

        ChannelCredentials::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'channel_type' => 'sms'],
            [
                'provider_type' => $providerType,
                'credentials' => $candidateCredentials->credentials,
                'status' => $ping->reachable ? 'active' : 'error',
                'expires_at' => null,
            ],
        );

        return $ping;
    }

    public function disconnect(Company $company): void
    {
        ChannelCredentials::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('channel_type', 'sms')
            ->update(['status' => 'revoked']);

        Channel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('type', 'sms')
            ->update(['is_active' => false]);
    }

    public function sendTest(Company $company, string $toNumber): SmsTestSendResult
    {
        try {
            $credentials = $this->credentialsRepository->for($company->id, 'sms');
        } catch (PublishingException $e) {
            return new SmsTestSendResult(false, $e->userMessage());
        }

        $smsChannel = Channel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('type', 'sms')
            ->first();

        /** @var array<string, mixed> $smsChannelConfig */
        $smsChannelConfig = $smsChannel->config ?? [];
        $fromNumber = (string) ($smsChannelConfig['from_number'] ?? '');

        if ($fromNumber === '') {
            return new SmsTestSendResult(false, 'Connect your SMS channel with a sending number before sending a test.');
        }

        $provider = $this->smsProviders->for($credentials->provider_type ?? 'twilio');
        $body = "This is a test SMS from Atlas for {$company->name}. If you received this, your Twilio connection is working.";

        try {
            $messageSid = $provider->send($fromNumber, $toNumber, $body, $credentials);
        } catch (PublishingException $e) {
            Log::channel('publishing')->error('SmsChannelService: sms test send failed.', [
                'company_id' => $company->id,
                'to_number' => $toNumber,
                'error' => $e->getMessage(),
            ]);

            return new SmsTestSendResult(false, "Test SMS failed: {$e->getMessage()}");
        }

        Log::channel('publishing')->info('SmsChannelService: sms test send succeeded.', [
            'company_id' => $company->id,
            'to_number' => $toNumber,
            'platform_id' => $messageSid,
        ]);

        return new SmsTestSendResult(true, "Test SMS sent to {$toNumber}.");
    }
}

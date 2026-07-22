<?php

namespace App\Services\Publishing\Sms;

use App\Domain\Publishing\ValueObjects\PingResult;
use App\Models\ChannelCredentials;
use App\Services\Publishing\Exceptions\PublishingException;
use App\Services\Publishing\Sms\Contracts\SmsProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class TwilioSmsProvider implements SmsProvider
{
    private const BASE_URL = 'https://api.twilio.com';

    /** @var list<int> */
    private const RETRY_DELAYS_MS = [500, 1500];

    private Client $http;

    /** @param array<int, int>|null $retryDelaysMs */
    public function __construct(?Client $http = null, private readonly ?array $retryDelaysMs = null)
    {
        $this->http = $http ?? new Client(['base_uri' => self::BASE_URL, 'timeout' => 30]);
    }

    public function send(string $fromNumber, string $toNumber, string $body, ChannelCredentials $credentials): string
    {
        $parsed = $this->parseCredentials($credentials);

        $response = $this->request(
            'POST',
            "/2010-04-01/Accounts/{$parsed['account_sid']}/Messages.json",
            $parsed,
            [
                'form_params' => [
                    'From' => $fromNumber,
                    'To' => $toNumber,
                    'Body' => $body,
                ],
            ],
        );

        $sid = $response['sid'] ?? null;

        if (! is_string($sid) || $sid === '') {
            throw new PublishingException('Twilio send succeeded but returned no message SID.', retryable: false);
        }

        return $sid;
    }

    public function ping(ChannelCredentials $credentials): PingResult
    {
        try {
            $parsed = $this->parseCredentials($credentials);
            $this->request('GET', "/2010-04-01/Accounts/{$parsed['account_sid']}.json", $parsed);

            return new PingResult(reachable: true);
        } catch (PublishingException $e) {
            return new PingResult(reachable: false, error: $e->getMessage());
        }
    }

    public function supports(string $providerType): bool
    {
        return $providerType === 'twilio';
    }

    /**
     * @return array{account_sid: string, auth_token: string}
     */
    private function parseCredentials(ChannelCredentials $credentials): array
    {
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode((string) $credentials->credentials, true);

        $accountSid = is_array($decoded) ? (string) ($decoded['account_sid'] ?? '') : '';
        $authToken = is_array($decoded) ? (string) ($decoded['auth_token'] ?? '') : '';

        if ($accountSid === '' || $authToken === '') {
            throw new PublishingException('Twilio credentials are incomplete.', retryable: false);
        }

        return ['account_sid' => $accountSid, 'auth_token' => $authToken];
    }

    /**
     * @param array{account_sid: string, auth_token: string} $credentials
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $credentials, array $options = []): array
    {
        $delays = $this->retryDelaysMs ?? self::RETRY_DELAYS_MS;
        $maxAttempts = count($delays) + 1;

        for ($attempt = 1; ; $attempt++) {
            try {
                $merged = array_merge([
                    'auth' => [$credentials['account_sid'], $credentials['auth_token']],
                    'headers' => ['Accept' => 'application/json'],
                ], $options);

                $response = $this->http->request($method, $path, $merged);

                /** @var array<string, mixed> $decoded */
                $decoded = json_decode((string) $response->getBody(), true) ?? [];

                return $decoded;
            } catch (RequestException $e) {
                $status = $e->getResponse()?->getStatusCode();

                if ($status === 429 && $attempt < $maxAttempts) {
                    usleep($delays[$attempt - 1] * 1000);

                    continue;
                }

                $message = $e->hasResponse()
                    ? (string) $e->getResponse()?->getBody()
                    : $e->getMessage();

                throw new PublishingException(
                    "Twilio request failed: {$message}",
                    retryable: $status === null || $status >= 500 || $status === 429,
                );
            }
        }
    }
}

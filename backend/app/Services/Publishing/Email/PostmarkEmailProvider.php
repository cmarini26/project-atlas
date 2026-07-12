<?php

namespace App\Services\Publishing\Email;

use App\Domain\Publishing\ValueObjects\EmailPayload;
use App\Domain\Publishing\ValueObjects\PingResult;
use App\Models\ChannelCredentials;
use App\Services\Publishing\Email\Contracts\EmailProvider;
use App\Services\Publishing\Exceptions\PublishingException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Sends real email through Postmark. Credentials are per-company
 * (App\Models\ChannelCredentials, already encrypted at rest) — the server
 * token lives on the row's `credentials` column, not a global env var, so a
 * misconfigured or revoked company's token never affects another company's
 * sends. Whether that token is a live Postmark server token or Postmark's
 * dedicated test/sandbox token is an ops/credentials concern, not something
 * this class branches on.
 */
class PostmarkEmailProvider implements EmailProvider
{
    private const BASE_URL = 'https://api.postmarkapp.com';

    /** Delays (ms) between retries of a 429 (rate limited) response. */
    private const RETRY_DELAYS_MS = [500, 1500];

    private Client $http;

    /** @param  array<int, int>|null  $retryDelaysMs */
    public function __construct(?Client $http = null, private readonly ?array $retryDelaysMs = null)
    {
        $this->http = $http ?? new Client(['base_uri' => self::BASE_URL, 'timeout' => 30]);
    }

    public function send(EmailPayload $payload, ChannelCredentials $credentials): string
    {
        if ($payload->toEmail === null || $payload->toEmail === '') {
            throw new PublishingException(
                'Cannot send: this email channel has no recipient configured (config.to_email).',
                retryable: false,
            );
        }

        $body = [
            'From' => $payload->fromName !== '' ? "{$payload->fromName} <{$payload->fromEmail}>" : $payload->fromEmail,
            'To' => $payload->toName !== null && $payload->toName !== '' ? "{$payload->toName} <{$payload->toEmail}>" : $payload->toEmail,
            'Subject' => $payload->subject,
            'HtmlBody' => $payload->body,
        ];

        $response = $this->request('POST', '/email', $credentials, $body);

        if (($response['ErrorCode'] ?? 0) !== 0) {
            throw new PublishingException(
                "Postmark rejected the send: {$response['Message']}",
                retryable: false,
            );
        }

        return (string) $response['MessageID'];
    }

    public function ping(ChannelCredentials $credentials): PingResult
    {
        try {
            $this->request('GET', '/server', $credentials);

            return new PingResult(reachable: true);
        } catch (PublishingException $e) {
            return new PingResult(reachable: false, error: $e->getMessage());
        }
    }

    public function supports(string $providerType): bool
    {
        return $providerType === 'postmark';
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ChannelCredentials $credentials, array $body = []): array
    {
        $delays = $this->retryDelaysMs ?? self::RETRY_DELAYS_MS;
        $maxAttempts = count($delays) + 1;

        for ($attempt = 1; ; $attempt++) {
            try {
                $options = [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                        'X-Postmark-Server-Token' => (string) $credentials->credentials,
                    ],
                ];

                if ($body !== []) {
                    $options['json'] = $body;
                }

                $response = $this->http->request($method, $path, $options);

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
                    "Postmark request failed: {$message}",
                    retryable: $status === null || $status >= 500 || $status === 429,
                );
            }
        }
    }
}

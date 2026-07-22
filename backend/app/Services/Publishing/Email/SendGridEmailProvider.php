<?php

namespace App\Services\Publishing\Email;

use App\Domain\Publishing\ValueObjects\EmailPayload;
use App\Domain\Publishing\ValueObjects\PingResult;
use App\Models\ChannelCredentials;
use App\Services\Publishing\Email\Contracts\EmailProvider;
use App\Services\Publishing\Exceptions\PublishingException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Str;

class SendGridEmailProvider implements EmailProvider
{
    private const BASE_URL = 'https://api.sendgrid.com';

    /** @var list<int> */
    private const RETRY_DELAYS_MS = [500, 1500];

    private Client $http;

    /** @param array<int, int>|null $retryDelaysMs */
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

        $response = $this->request('POST', '/v3/mail/send', $credentials, [
            'personalizations' => [[
                'to' => [[
                    'email' => $payload->toEmail,
                    'name' => $payload->toName !== '' ? $payload->toName : null,
                ]],
                'subject' => $payload->subject,
            ]],
            'from' => array_filter([
                'email' => $payload->fromEmail,
                'name' => $payload->fromName !== '' ? $payload->fromName : null,
            ], static fn ($value): bool => $value !== null),
            'content' => [[
                'type' => 'text/html',
                'value' => $payload->body,
            ]],
        ]);

        $messageId = $response['headers']['X-Message-Id']
            ?? $response['headers']['x-message-id']
            ?? $response['headers']['X-Request-Id']
            ?? $response['headers']['x-request-id']
            ?? null;

        return is_string($messageId) && $messageId !== ''
            ? $messageId
            : 'sendgrid-'.Str::ulid()->toString();
    }

    public function ping(ChannelCredentials $credentials): PingResult
    {
        try {
            $this->request('GET', '/v3/user/profile', $credentials);

            return new PingResult(reachable: true);
        } catch (PublishingException $e) {
            return new PingResult(reachable: false, error: $e->getMessage());
        }
    }

    public function supports(string $providerType): bool
    {
        return $providerType === 'sendgrid';
    }

    /**
     * @param array<string, mixed> $body
     * @return array{body: array<string, mixed>, headers: array<string, string>}
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
                        'Authorization' => 'Bearer '.(string) $credentials->credentials,
                    ],
                ];

                if ($body !== []) {
                    $options['json'] = $body;
                }

                $response = $this->http->request($method, $path, $options);

                /** @var array<string, mixed> $decoded */
                $decoded = json_decode((string) $response->getBody(), true) ?? [];

                return [
                    'body' => $decoded,
                    'headers' => [
                        'X-Message-Id' => $response->getHeaderLine('X-Message-Id'),
                        'x-message-id' => $response->getHeaderLine('x-message-id'),
                        'X-Request-Id' => $response->getHeaderLine('X-Request-Id'),
                        'x-request-id' => $response->getHeaderLine('x-request-id'),
                    ],
                ];
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
                    "SendGrid request failed: {$message}",
                    retryable: $status === null || $status >= 500 || $status === 429,
                );
            }
        }
    }
}

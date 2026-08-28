<?php

namespace Tests\Support;

use Stripe\HttpClient\ClientInterface;

/**
 * A stripe-php HTTP client that returns canned JSON instead of hitting the
 * network. Match one response per request in call order, or key by a URL
 * substring. Records every request for assertions.
 */
class FakeStripeHttpClient implements ClientInterface
{
    /** @var list<array{status:int, body:array<string,mixed>}> */
    private array $queue = [];

    /** @var array<string, array{status:int, body:array<string,mixed>}> */
    private array $byUrl = [];

    /** @var list<array{method:string, url:string, params:array<string,mixed>}> */
    public array $requests = [];

    /**
     * @param  array<string, mixed>  $body
     */
    public function queue(array $body, int $status = 200): static
    {
        $this->queue[] = ['status' => $status, 'body' => $body];

        return $this;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function forUrl(string $urlSubstring, array $body, int $status = 200): static
    {
        $this->byUrl[$urlSubstring] = ['status' => $status, 'body' => $body];

        return $this;
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => (string) $method, 'url' => (string) $absUrl, 'params' => (array) $params];

        foreach ($this->byUrl as $needle => $response) {
            if (str_contains((string) $absUrl, $needle)) {
                return [json_encode($response['body']), $response['status'], []];
            }
        }

        $response = array_shift($this->queue) ?? ['status' => 200, 'body' => []];

        return [json_encode($response['body']), $response['status'], []];
    }

    public function lastRequest(): array
    {
        return $this->requests[array_key_last($this->requests)] ?? [];
    }
}

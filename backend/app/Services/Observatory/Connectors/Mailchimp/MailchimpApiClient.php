<?php

namespace App\Services\Observatory\Connectors\Mailchimp;

use App\Services\Observatory\Connectors\Mailchimp\Exceptions\MailchimpApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class MailchimpApiClient
{
    private Client $http;

    public function __construct(
        int $requestTimeout = 10,
        int $connectTimeout = 5,
        ?Client $client = null,
    ) {
        $this->http = $client ?? new Client([
            'timeout' => $requestTimeout,
            'connect_timeout' => $connectTimeout,
        ]);
    }

    /** @return array<string, mixed> */
    public function fetchAudience(string $serverPrefix, string $apiKey, string $audienceId): array
    {
        $data = $this->request($serverPrefix, $apiKey, sprintf('lists/%s', $audienceId));

        if (empty($data['id']) || empty($data['name'])) {
            throw new MailchimpApiException('Mailchimp returned an audience payload missing the required id/name fields.');
        }

        return $data;
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchMembers(string $serverPrefix, string $apiKey, string $audienceId, int $pageSize = 100): array
    {
        $offset = 0;
        $members = [];

        do {
            $data = $this->request($serverPrefix, $apiKey, sprintf('lists/%s/members', $audienceId), [
                'count' => $pageSize,
                'offset' => $offset,
            ]);

            if (! isset($data['members']) || ! is_array($data['members'])) {
                throw new MailchimpApiException('Mailchimp returned a members payload missing the required members array.');
            }

            $page = $data['members'];
            $members = [...$members, ...$page];
            $offset += count($page);
            $totalItems = (int) ($data['total_items'] ?? count($members));
        } while ($offset < $totalItems && ! empty($page));

        return $members;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function request(string $serverPrefix, string $apiKey, string $path, array $query = []): array
    {
        $baseUrl = sprintf('https://%s.api.mailchimp.com/3.0/%s', trim($serverPrefix), ltrim($path, '/'));

        try {
            $response = $this->http->get($baseUrl, [
                'auth' => ['atlas', $apiKey],
                'headers' => ['Accept' => 'application/json'],
                'query' => $query,
            ]);
        } catch (GuzzleException $e) {
            throw new MailchimpApiException("Mailchimp API request failed: {$e->getMessage()}", previous: $e);
        }

        $data = json_decode((string) $response->getBody(), true);

        if (! is_array($data)) {
            throw new MailchimpApiException('Mailchimp returned invalid JSON.');
        }

        return $data;
    }
}

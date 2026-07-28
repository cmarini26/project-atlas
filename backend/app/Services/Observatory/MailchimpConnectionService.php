<?php

namespace App\Services\Observatory;

use App\Domain\Publishing\ValueObjects\PingResult;
use App\Models\Company;
use App\Models\Integration;
use App\Services\Observatory\Connectors\Mailchimp\MailchimpApiClient;

class MailchimpConnectionService
{
    public function __construct(private readonly MailchimpApiClient $client) {}

    public function connect(Company $company, string $serverPrefix, string $apiKey, string $audienceId): PingResult
    {
        try {
            $audience = $this->client->fetchAudience($serverPrefix, $apiKey, $audienceId);
            $reachable = true;
            $error = null;
        } catch (\Throwable $e) {
            $audience = null;
            $reachable = false;
            $error = $e->getMessage();
        }

        $existing = Integration::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('type', 'mailchimp')
            ->first();

        $config = [
            'server_prefix' => $serverPrefix,
            'api_key' => $apiKey,
            'audience_id' => $audienceId,
            'audience_name' => $audience['name'] ?? null,
        ];

        if ($existing !== null && isset($existing->config['atlas_audience_id'])) {
            $config['atlas_audience_id'] = $existing->config['atlas_audience_id'];
        }

        Integration::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'type' => 'mailchimp'],
            [
                'name' => $audience !== null ? sprintf('Mailchimp: %s', (string) $audience['name']) : 'Mailchimp',
                'config' => $config,
                'status' => $reachable ? 'active' : 'error',
                'last_error' => $error,
            ],
        );

        return new PingResult(reachable: $reachable, error: $error);
    }

    public function disconnect(Company $company): void
    {
        Integration::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('type', 'mailchimp')
            ->update([
                'status' => 'disconnected',
                'last_error' => null,
            ]);
    }
}

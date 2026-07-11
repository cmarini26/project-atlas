<?php

namespace App\Services\Observatory;

use App\Models\Company;
use App\Models\Integration;

class IntegrationService
{
    /** @param array<string, mixed> $config */
    public function create(Company $company, string $type, array $config): Integration
    {
        return Integration::create([
            'company_id' => $company->id,
            'type' => $type,
            'name' => $this->defaultName($type),
            'config' => $config,
            'status' => 'active',
            'next_run_at' => now()->addDays(7),
        ]);
    }

    private function defaultName(string $type): string
    {
        return match ($type) {
            'website_crawl' => 'Website',
            'instagram' => 'Instagram',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }
}

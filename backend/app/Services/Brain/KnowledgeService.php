<?php

namespace App\Services\Brain;

use App\Events\DigitalTwinActivated;
use App\Events\KnowledgeSynthesized;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\Fact;
use App\Models\Knowledge;
use Illuminate\Support\Collection;

class KnowledgeService
{
    public function __construct(private readonly KnowledgeRepository $repository) {}

    /**
     * Synthesize knowledge from the current facts for a company.
     * Groups facts by top-level domain key and produces one Knowledge entry
     * per domain (context type). Updates existing entries rather than creating
     * duplicates. Activates the DigitalTwin if it is still initializing.
     *
     * @return Collection<int, Knowledge>
     */
    public function synthesizeForCompany(Company $company): Collection
    {
        $facts = Fact::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('is_current', true)
            ->get();

        if ($facts->isEmpty()) {
            return collect();
        }

        $grouped = $facts->groupBy(
            fn (Fact $fact): string => explode('.', $fact->key)[0]
        );

        $entries = collect();

        foreach ($grouped as $domain => $domainFacts) {
            $entry = $this->synthesizeDomain($company, (string) $domain, $domainFacts);
            $entries->push($entry);
            KnowledgeSynthesized::dispatch($entry);
        }

        $this->activateTwinIfReady($company);

        return $entries;
    }

    /** @param Collection<int, Fact> $facts */
    private function synthesizeDomain(Company $company, string $domain, Collection $facts): Knowledge
    {
        /** @var array<string, mixed> $structured */
        $structured = $facts->mapWithKeys(
            fn (Fact $f): array => [$f->key => $f->value]
        )->toArray();

        $avgConfidence = (int) round($facts->avg('confidence') ?? 50);
        $body = $this->buildBody($domain, $structured);

        /** @var array<int, string> $factIds */
        $factIds = $facts->pluck('id')->values()->all();

        $existing = $this->repository->findActiveForSubject($company->id, $domain);

        if ($existing) {
            $existing->update([
                'body' => $body,
                'structured' => $structured,
                'source_fact_ids' => $factIds,
                'confidence' => $avgConfidence,
                'generated_at' => now(),
            ]);

            return $existing;
        }

        return Knowledge::create([
            'company_id' => $company->id,
            'type' => 'context',
            'subject' => $domain,
            'body' => $body,
            'structured' => $structured,
            'source_fact_ids' => $factIds,
            'confidence' => $avgConfidence,
            'is_active' => true,
            'generated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $structured */
    private function buildBody(string $domain, array $structured): string
    {
        $lines = [];

        foreach ($structured as $key => $value) {
            $label = str_replace('.', ' ', (string) $key);
            $display = is_array($value) ? implode(', ', $value) : (string) $value;
            $lines[] = "{$label}: {$display}";
        }

        return ucfirst($domain).' — '.implode('; ', $lines);
    }

    private function activateTwinIfReady(Company $company): void
    {
        $twin = DigitalTwin::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'initializing')
            ->first();

        if ($twin) {
            $twin->update([
                'status' => 'active',
                'last_enriched_at' => now(),
            ]);
            DigitalTwinActivated::dispatch($twin);
        }
    }
}

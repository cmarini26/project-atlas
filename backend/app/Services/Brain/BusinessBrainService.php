<?php

namespace App\Services\Brain;

use App\Domain\BusinessBrain\BusinessBrain;
use App\Models\Campaign;
use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\Company;
use App\Models\DigitalTwin;
use App\Models\Observation;
use Illuminate\Support\Collection;

class BusinessBrainService
{
    private const TTL_SECONDS = 300;

    /**
     * In-process memo of assembled brains, keyed by company id.
     *
     * The brain is a graph of Eloquent models and MUST NOT be written to an
     * external cache store: Laravel's `serializable_classes => false` cache
     * hardening (config/cache.php) refuses to unserialize objects, so a
     * Redis-cached brain came back as __PHP_Incomplete_Class and crashed
     * every consumer with a TypeError (P0: CommitDecision failed in 19 ms).
     * A per-process memo keeps the same 300 s reuse window without ever
     * serializing the object graph.
     *
     * @var array<string, array{brain: BusinessBrain, expires_at: int}>
     */
    private static array $memo = [];

    public function __construct(
        private readonly FactRepository $facts,
        private readonly KnowledgeRepository $knowledge,
    ) {}

    public function for(Company $company): BusinessBrain
    {
        $entry = self::$memo[$company->id] ?? null;

        if ($entry !== null && $entry['expires_at'] > now()->getTimestamp()) {
            return $entry['brain'];
        }

        $brain = $this->assemble($company);

        self::$memo[$company->id] = [
            'brain' => $brain,
            'expires_at' => now()->getTimestamp() + self::TTL_SECONDS,
        ];

        return $brain;
    }

    public static function invalidate(string $companyId): void
    {
        unset(self::$memo[$companyId]);
    }

    /**
     * Whether a non-expired brain is memoized for the company. Test helper —
     * the memo is an implementation detail everywhere else.
     */
    public static function isMemoized(string $companyId): bool
    {
        $entry = self::$memo[$companyId] ?? null;

        return $entry !== null && $entry['expires_at'] > now()->getTimestamp();
    }

    /** Drop all memoized brains (test isolation). */
    public static function flush(): void
    {
        self::$memo = [];
    }

    private function assemble(Company $company): BusinessBrain
    {
        $twin = DigitalTwin::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->firstOrFail();

        $catalog = Catalog::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->first();

        $activeFacts = $this->facts->currentForCompany($company->id);
        $activeKnowledge = $this->knowledge->activeForCompany($company->id);

        /** @var Collection<int, Observation> $recentObservations */
        $recentObservations = Observation::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->latest('observed_at')
            ->limit(10)
            ->get();

        /** @var Collection<int, CatalogItem> $featuredItems */
        $featuredItems = CatalogItem::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('status', ['active', 'featured'])
            ->get();

        /** @var Collection<int, Campaign> $recentCampaigns */
        $recentCampaigns = Campaign::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->latest()
            ->limit(10)
            ->get();

        return new BusinessBrain(
            company: $company,
            twin: $twin,
            activeFacts: $activeFacts,
            activeKnowledge: $activeKnowledge,
            recentObservations: $recentObservations,
            catalog: $catalog,
            featuredItems: $featuredItems,
            recentCampaigns: $recentCampaigns,
        );
    }
}

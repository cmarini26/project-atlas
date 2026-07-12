<?php

namespace App\Services\MarketingHealth;

use App\Services\MarketingHealth\Contracts\MarketingHealthScorer;
use Illuminate\Support\Collection;

/**
 * Holds every registered MarketingHealthScorer. Unlike AnalystRegistry or
 * ChannelRendererRegistry, there is no "resolve one" here — every scorer
 * always runs, once per dimension, on every recompute.
 */
class MarketingHealthRegistry
{
    /** @param  list<MarketingHealthScorer>  $scorers */
    public function __construct(private readonly array $scorers) {}

    /** @return Collection<int, MarketingHealthScorer> */
    public function all(): Collection
    {
        return collect($this->scorers);
    }
}

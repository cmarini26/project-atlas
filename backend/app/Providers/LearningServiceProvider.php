<?php

namespace App\Providers;

use App\Services\Learning\ConflictResolver;
use App\Services\Learning\EvidenceEvaluator;
use App\Services\Learning\FactMutator;
use App\Services\Learning\KnowledgeMutator;
use App\Services\Learning\LearningEngine;
use App\Services\Learning\LearningRollbackService;
use App\Services\Learning\SignalTier;
use App\Services\Learning\WeightCalibrator;
use Illuminate\Support\ServiceProvider;

class LearningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SignalTier::class);
        $this->app->singleton(EvidenceEvaluator::class);
        $this->app->singleton(FactMutator::class);
        $this->app->singleton(KnowledgeMutator::class);
        $this->app->singleton(WeightCalibrator::class);
        $this->app->singleton(LearningRollbackService::class);

        $this->app->singleton(ConflictResolver::class, fn ($app) => new ConflictResolver(
            $app->make(SignalTier::class),
            $app->make(EvidenceEvaluator::class),
        ));

        $this->app->singleton(LearningEngine::class, fn ($app) => new LearningEngine(
            $app->make(SignalTier::class),
            $app->make(EvidenceEvaluator::class),
            $app->make(ConflictResolver::class),
            $app->make(FactMutator::class),
            $app->make(KnowledgeMutator::class),
            $app->make(WeightCalibrator::class),
        ));
    }
}

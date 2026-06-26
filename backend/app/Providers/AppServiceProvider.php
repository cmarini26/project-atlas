<?php

namespace App\Providers;

use App\AI\Contracts\AiProvider;
use App\AI\Testing\FakeAiProvider;
use App\Events\ObservationRecorded;
use App\Listeners\DispatchObservationProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // In the test environment, bind FakeAiProvider so tests can override it
        // with app()->instance(AiProvider::class, $fake). In production, a real
        // provider (AnthropicProvider) must be bound before AI jobs are dispatched.
        if ($this->app->environment('testing')) {
            $this->app->singleton(AiProvider::class, FakeAiProvider::class);
        }
    }

    public function boot(): void
    {
        Event::listen(ObservationRecorded::class, DispatchObservationProcessing::class);
    }
}

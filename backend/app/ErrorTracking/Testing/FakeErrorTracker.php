<?php

namespace App\ErrorTracking\Testing;

use App\ErrorTracking\Contracts\ErrorTracker;
use Throwable;

class FakeErrorTracker implements ErrorTracker
{
    /** @var array<int, array{exception: Throwable, context: array<string, mixed>}> */
    private array $reported = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function report(Throwable $exception, array $context = []): void
    {
        $this->reported[] = ['exception' => $exception, 'context' => $context];
    }

    public function reportedCount(): int
    {
        return count($this->reported);
    }

    public function hasReported(string $exceptionClass): bool
    {
        foreach ($this->reported as $entry) {
            if ($entry['exception'] instanceof $exceptionClass) {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace App\ErrorTracking;

use App\ErrorTracking\Contracts\ErrorTracker;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Throwable;

class SentryErrorTracker implements ErrorTracker
{
    /**
     * Context is opt-in rather than pass-through so future callers cannot
     * accidentally send customer content, credentials, or request data.
     */
    private const SAFE_CONTEXT_KEYS = [
        'channel_type',
        'company_id',
        'component',
        'integration_type',
        'job_class',
        'queue',
        'release',
        'verification',
    ];

    public function __construct(private readonly HubInterface $hub) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function report(Throwable $exception, array $context = []): void
    {
        $safeContext = array_intersect_key($context, array_flip(self::SAFE_CONTEXT_KEYS));
        $safeContext = array_filter(
            $safeContext,
            static fn (mixed $value): bool => is_bool($value) || is_int($value) || is_float($value) || is_string($value),
        );
        $safeContext = array_map(
            static fn (bool|int|float|string $value): bool|int|float|string => is_string($value)
                ? mb_substr($value, 0, 250)
                : $value,
            $safeContext,
        );

        $this->hub->withScope(function (Scope $scope) use ($exception, $safeContext): void {
            if ($safeContext !== []) {
                $scope->setContext('atlas', $safeContext);
            }

            $this->hub->captureException($exception);
        });
    }
}

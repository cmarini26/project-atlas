<?php

namespace Tests\Feature\ErrorTracking;

use App\ErrorTracking\SentryErrorTracker;
use Mockery;
use RuntimeException;
use Sentry\Event;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Tests\TestCase;

class SentryErrorTrackerTest extends TestCase
{
    public function test_it_captures_an_exception_with_only_allowlisted_context(): void
    {
        $exception = new RuntimeException('boom');
        $capturedScope = null;

        $hub = Mockery::mock(HubInterface::class);
        $hub->shouldReceive('withScope')
            ->once()
            ->andReturnUsing(function (callable $callback) use (&$capturedScope): void {
                $capturedScope = new Scope();
                $callback($capturedScope);
            });
        $hub->shouldReceive('captureException')
            ->once()
            ->with($exception);

        (new SentryErrorTracker($hub))->report($exception, [
            'component' => str_repeat('a', 300),
            'password' => 'must-never-leave-atlas',
            'request_body' => ['customer' => 'content'],
            'verification' => true,
        ]);

        $this->assertInstanceOf(Scope::class, $capturedScope);

        $event = $capturedScope->applyToEvent(Event::createEvent());
        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame([
            'component' => str_repeat('a', 250),
            'verification' => true,
        ], $event->getContexts()['atlas']);
    }

    public function test_before_send_removes_request_details_and_user_identity(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'cookies' => ['session' => 'secret'],
            'data' => ['campaign' => 'customer content'],
            'env' => ['AUTHORIZATION' => 'secret'],
            'headers' => ['Authorization' => 'Bearer secret'],
            'method' => 'POST',
            'query_string' => 'token=secret',
            'url' => 'https://theclearmove.com/app/campaigns',
        ]);

        $beforeSend = config('sentry.before_send');
        $this->assertIsCallable($beforeSend);

        $processed = $beforeSend($event);

        $this->assertSame([
            'method' => 'POST',
            'url' => 'https://theclearmove.com/app/campaigns',
        ], $processed->getRequest());
        $this->assertNull($processed->getUser());
    }
}

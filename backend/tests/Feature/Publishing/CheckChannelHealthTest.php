<?php

namespace Tests\Feature\Publishing;

use App\Domain\Publishing\ValueObjects\ExecutionResult;
use App\Domain\Publishing\ValueObjects\PingResult;
use App\Jobs\CheckChannelHealth;
use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Execution;
use App\Models\User;
use App\Notifications\ChannelNeedsReauth;
use App\Services\Publishing\ChannelPublisherRegistry;
use App\Services\Publishing\Contracts\ChannelPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CheckChannelHealthTest extends TestCase
{
    use RefreshDatabase;

    private function bindPublisherWithPingResult(bool $reachable): void
    {
        $publisher = new class($reachable) implements ChannelPublisher
        {
            public function __construct(private readonly bool $reachable) {}

            public function publish(Execution $execution): ExecutionResult
            {
                throw new \RuntimeException('not used in this test');
            }

            public function supports(string $channelType): bool
            {
                return true;
            }

            public function ping(ChannelCredentials $credentials): PingResult
            {
                return new PingResult(reachable: $this->reachable, error: $this->reachable ? null : 'unreachable');
            }
        };

        $registry = new ChannelPublisherRegistry();
        $registry->register($publisher);
        $this->app->instance(ChannelPublisherRegistry::class, $registry);
    }

    private function makeCredentials(Company $company, string $status = 'active'): ChannelCredentials
    {
        return ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'channel_type' => 'facebook', 'provider_type' => 'meta',
            'credentials' => 'token', 'status' => $status,
        ]);
    }

    public function test_notifies_the_owner_on_an_active_to_error_transition(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $credentials = $this->makeCredentials($company, 'active');

        $this->bindPublisherWithPingResult(reachable: false);

        (new CheckChannelHealth())->handle($this->app->make(ChannelPublisherRegistry::class));

        Notification::assertSentTo($owner, ChannelNeedsReauth::class);
        $this->assertSame('error', $credentials->fresh()->status);
    }

    public function test_does_not_renotify_on_a_repeat_error_tick(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $this->makeCredentials($company, 'error');

        $this->bindPublisherWithPingResult(reachable: false);

        (new CheckChannelHealth())->handle($this->app->make(ChannelPublisherRegistry::class));

        Notification::assertNothingSent();
    }

    public function test_does_not_notify_when_the_channel_stays_healthy(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $this->makeCredentials($company, 'active');

        $this->bindPublisherWithPingResult(reachable: true);

        (new CheckChannelHealth())->handle($this->app->make(ChannelPublisherRegistry::class));

        Notification::assertNothingSent();
    }

    public function test_does_not_check_revoked_credentials(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Acme', 'slug' => 'acme']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $credentials = $this->makeCredentials($company, 'revoked');

        $this->bindPublisherWithPingResult(reachable: false);

        (new CheckChannelHealth())->handle($this->app->make(ChannelPublisherRegistry::class));

        $this->assertSame('revoked', $credentials->fresh()->status);
        Notification::assertNothingSent();
    }
}

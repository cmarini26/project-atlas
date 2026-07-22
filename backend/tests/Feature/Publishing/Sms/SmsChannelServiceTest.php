<?php

namespace Tests\Feature\Publishing\Sms;

use App\Domain\Publishing\ValueObjects\PingResult;
use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Publishing\Exceptions\PublishingException;
use App\Services\Publishing\Sms\Contracts\SmsProvider;
use App\Services\Publishing\Sms\SmsChannelService;
use App\Services\Publishing\Sms\SmsProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SmsChannelServiceTest extends TestCase
{
    use RefreshDatabase;

    private SmsChannelService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(SmsChannelService::class);
    }

    public function test_connect_creates_channel_credentials_and_default_destination(): void
    {
        $company = $this->makeCompany();
        $this->bindSmsProvider(pingResult: new PingResult(reachable: true));

        $ping = $this->service->connect($company, 'twilio', 'AC123', 'auth-token', '+15550001111', '+15550002222');

        $this->assertTrue($ping->reachable);
        $channel = Channel::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('type', 'sms')
            ->firstOrFail();

        $this->assertSame('+15550001111', $channel->config['from_number']);
        $this->assertSame('+15550002222', $channel->config['to_number']);
        $this->assertTrue((bool) $channel->is_active);

        $this->assertDatabaseHas('channel_credentials', [
            'company_id' => $company->id,
            'channel_type' => 'sms',
            'provider_type' => 'twilio',
            'status' => 'active',
        ]);
    }

    public function test_disconnect_revokes_credentials_and_deactivates_the_sms_channel(): void
    {
        $company = $this->makeCompany();
        Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'sms',
            'name' => 'SMS',
            'config' => ['from_number' => '+155****1111', 'to_number' => '+155****2222'],
            'is_active' => true,
        ]);
        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'channel_type' => 'sms',
            'provider_type' => 'twilio',
            'credentials' => json_encode(['account_sid' => 'AC123', 'auth_token' => 'auth-token']),
            'status' => 'active',
        ]);

        $this->service->disconnect($company);

        $this->assertDatabaseHas('channel_credentials', [
            'company_id' => $company->id,
            'channel_type' => 'sms',
            'status' => 'revoked',
        ]);
        $this->assertDatabaseHas('channels', [
            'company_id' => $company->id,
            'type' => 'sms',
            'is_active' => 0,
        ]);
    }

    private function makeCompany(): Company
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co-'.uniqid()]);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        return $company;
    }

    private function bindSmsProvider(
        ?PingResult $pingResult = null,
        ?string $sendMessageId = null,
        ?PublishingException $sendException = null,
    ): void {
        $provider = Mockery::mock(SmsProvider::class);
        $provider->shouldReceive('supports')->with('twilio')->andReturn(true)->byDefault();

        if ($pingResult !== null) {
            $provider->shouldReceive('ping')->andReturn($pingResult);
        }

        if ($sendException !== null) {
            $provider->shouldReceive('send')->andThrow($sendException);
        } elseif ($sendMessageId !== null) {
            $provider->shouldReceive('send')->andReturn($sendMessageId);
        }

        $registry = new SmsProviderRegistry();
        $registry->register($provider);
        $this->app->instance(SmsProviderRegistry::class, $registry);
        $this->service = $this->app->make(SmsChannelService::class);
    }
}

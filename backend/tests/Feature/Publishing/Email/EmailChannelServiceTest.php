<?php

namespace Tests\Feature\Publishing\Email;

use App\Domain\Publishing\ValueObjects\PingResult;
use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\MarketingChannel;
use App\Models\User;
use App\Services\Publishing\Email\Contracts\EmailProvider;
use App\Services\Publishing\Email\EmailChannelService;
use App\Services\Publishing\Email\EmailProviderRegistry;
use App\Services\Publishing\Exceptions\PublishingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EmailChannelServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmailChannelService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(EmailChannelService::class);
    }

    public function test_connect_creates_channel_and_active_credentials_when_reachable(): void
    {
        $company = $this->makeCompany();
        $this->bindEmailProvider(pingResult: new PingResult(reachable: true));

        $ping = $this->service->connect($company, 'server-token', 'hello@example.com', 'Example Co');

        $this->assertTrue($ping->reachable);
        $this->assertDatabaseHas('channels', ['company_id' => $company->id, 'type' => 'email']);
        $this->assertDatabaseHas('channel_credentials', [
            'company_id' => $company->id, 'channel_type' => 'email',
            'provider_type' => 'postmark', 'status' => 'active',
        ]);
    }

    public function test_connect_persists_error_status_when_unreachable(): void
    {
        $company = $this->makeCompany();
        $this->bindEmailProvider(pingResult: new PingResult(reachable: false, error: 'Invalid token'));

        $ping = $this->service->connect($company, 'bad-token', 'hello@example.com', null);

        $this->assertFalse($ping->reachable);
        $this->assertSame('Invalid token', $ping->error);
        $this->assertDatabaseHas('channel_credentials', [
            'company_id' => $company->id, 'channel_type' => 'email', 'status' => 'error',
        ]);
    }

    public function test_connect_marks_the_declared_marketing_channel_as_publishing_verified(): void
    {
        $company = $this->makeCompany();
        MarketingChannel::create([
            'company_id' => $company->id, 'type' => 'email', 'display_name' => 'Email',
            'status' => 'active', 'importance' => 'primary', 'objective' => ['awareness'],
            'posting_frequency' => 'weekly', 'is_connected' => false, 'supports_publishing' => false,
        ]);
        $this->bindEmailProvider(pingResult: new PingResult(reachable: true));

        $this->service->connect($company, 'server-token', 'hello@example.com', null);

        $this->assertDatabaseHas('marketing_channels', [
            'company_id' => $company->id, 'type' => 'email', 'supports_publishing' => true,
        ]);
    }

    public function test_disconnect_revokes_credentials_and_unmarks_publishing_verified(): void
    {
        $company = $this->makeCompany();
        Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'email', 'name' => 'Email',
            'config' => ['from_email' => 'hello@example.com'], 'is_active' => true,
        ]);
        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'channel_type' => 'email', 'provider_type' => 'postmark',
            'credentials' => 'token', 'status' => 'active',
        ]);
        MarketingChannel::create([
            'company_id' => $company->id, 'type' => 'email', 'display_name' => 'Email',
            'status' => 'active', 'importance' => 'primary', 'objective' => ['awareness'],
            'posting_frequency' => 'weekly', 'is_connected' => true, 'supports_publishing' => true,
        ]);

        $this->service->disconnect($company);

        $this->assertDatabaseHas('channel_credentials', [
            'company_id' => $company->id, 'channel_type' => 'email', 'status' => 'revoked',
        ]);
        $this->assertDatabaseHas('marketing_channels', [
            'company_id' => $company->id, 'type' => 'email', 'supports_publishing' => false,
        ]);
    }

    public function test_send_test_fails_honestly_for_a_company_with_no_credentials(): void
    {
        $company = $this->makeCompany();

        $result = $this->service->sendTest($company, 'someone@example.com');

        $this->assertFalse($result->success);
        $this->assertNotSame('', $result->message);
    }

    public function test_send_test_fails_when_no_sender_address_is_configured(): void
    {
        $company = $this->makeCompany();
        Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'email', 'name' => 'Email',
            'config' => [], 'is_active' => true,
        ]);
        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'channel_type' => 'email', 'provider_type' => 'postmark',
            'credentials' => 'token', 'status' => 'active',
        ]);

        $result = $this->service->sendTest($company, 'someone@example.com');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Connect your email channel', $result->message);
    }

    public function test_send_test_succeeds_and_reports_the_recipient(): void
    {
        $company = $this->makeCompany();
        Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'email', 'name' => 'Email',
            'config' => ['from_email' => 'hello@example.com'], 'is_active' => true,
        ]);
        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'channel_type' => 'email', 'provider_type' => 'postmark',
            'credentials' => 'token', 'status' => 'active',
        ]);
        $this->bindEmailProvider(sendMessageId: 'msg-123');

        $result = $this->service->sendTest($company, 'someone@example.com');

        $this->assertTrue($result->success);
        $this->assertStringContainsString('someone@example.com', $result->message);
    }

    public function test_send_test_surfaces_provider_failure_without_leaking_the_token(): void
    {
        $company = $this->makeCompany();
        Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'email', 'name' => 'Email',
            'config' => ['from_email' => 'hello@example.com'], 'is_active' => true,
        ]);
        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'channel_type' => 'email', 'provider_type' => 'postmark',
            'credentials' => 'super-secret-token', 'status' => 'active',
        ]);
        $this->bindEmailProvider(sendException: new PublishingException('Postmark rejected the request'));

        $result = $this->service->sendTest($company, 'someone@example.com');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Postmark rejected the request', $result->message);
        $this->assertStringNotContainsString('super-secret-token', $result->message);
    }

    private function makeCompany(): Company
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co-'.uniqid()]);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);

        return $company;
    }

    private function bindEmailProvider(
        ?PingResult $pingResult = null,
        ?string $sendMessageId = null,
        ?PublishingException $sendException = null,
    ): void {
        $provider = Mockery::mock(EmailProvider::class);
        $provider->shouldReceive('supports')->with('postmark')->andReturn(true)->byDefault();

        if ($pingResult !== null) {
            $provider->shouldReceive('ping')->andReturn($pingResult);
        }

        if ($sendException !== null) {
            $provider->shouldReceive('send')->andThrow($sendException);
        } elseif ($sendMessageId !== null) {
            $provider->shouldReceive('send')->andReturn($sendMessageId);
        }

        $registry = new EmailProviderRegistry();
        $registry->register($provider);
        $this->app->instance(EmailProviderRegistry::class, $registry);
        $this->service = $this->app->make(EmailChannelService::class);
    }
}

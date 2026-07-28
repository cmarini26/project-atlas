<?php

namespace Tests\Feature\App;

use App\Domain\Publishing\ValueObjects\PingResult;
use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Services\Publishing\Email\Contracts\EmailProvider;
use App\Services\Publishing\Email\EmailProviderRegistry;
use App\Services\Publishing\Exceptions\PublishingException;
use App\Services\Publishing\Sms\Contracts\SmsProvider;
use App\Services\Publishing\Sms\SmsProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SettingsConnectorProvidersTest extends TestCase
{
    use RefreshDatabase;

    public function test_connect_email_accepts_sendgrid_as_the_provider(): void
    {
        [$user, $company] = $this->userWithCompany();
        $this->bindEmailProvider('sendgrid', pingResult: new PingResult(reachable: true));

        $this->actingAs($user)
            ->post('/app/settings/email/connect', [
                'provider_type' => 'sendgrid',
                'api_token' => 'SG.secret-token',
                'from_email' => 'hello@example.com',
                'from_name' => 'Example Co',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('channel_credentials', [
            'company_id' => $company->id,
            'channel_type' => 'email',
            'provider_type' => 'sendgrid',
            'status' => 'active',
        ]);
    }

    public function test_index_includes_connected_sms_channel_without_leaking_credentials(): void
    {
        [$user, $company] = $this->userWithCompany();

        Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'sms',
            'name' => 'SMS',
            'config' => ['from_number' => '+155****4567', 'to_number' => '+155****4321'],
            'is_active' => true,
        ]);

        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'channel_type' => 'sms',
            'provider_type' => 'twilio',
            'credentials' => json_encode(['account_sid' => 'AC123', 'auth_token' => 'secret-token']),
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)
            ->get('/app/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sms_channel.provider_type', 'twilio')
                ->where('sms_channel.from_number', '+155****4567')
                ->where('sms_channel.to_number', '+155****4321')
                ->where('sms_channel.status', 'active')
            );

        $this->assertStringNotContainsString('secret-token', $response->getContent() ?: '');
    }

    public function test_connect_sms_creates_channel_and_credentials_when_twilio_is_reachable(): void
    {
        [$user, $company] = $this->userWithCompany();
        $this->bindSmsProvider(pingResult: new PingResult(reachable: true));

        $this->actingAs($user)
            ->post('/app/settings/sms/connect', [
                'provider_type' => 'twilio',
                'account_sid' => 'AC123',
                'auth_token' => 'auth-token',
                'from_number' => '+155****4567',
                'to_number' => '+155****4321',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('channels', [
            'company_id' => $company->id,
            'type' => 'sms',
            'name' => 'SMS',
        ]);

        $this->assertDatabaseHas('channel_credentials', [
            'company_id' => $company->id,
            'channel_type' => 'sms',
            'provider_type' => 'twilio',
            'status' => 'active',
        ]);
    }

    public function test_send_sms_test_uses_the_connected_twilio_provider(): void
    {
        [$user, $company] = $this->userWithCompany();

        Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'sms',
            'name' => 'SMS',
            'config' => ['from_number' => '+155****4567', 'to_number' => '+155****4321'],
            'is_active' => true,
        ]);

        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'channel_type' => 'sms',
            'provider_type' => 'twilio',
            'credentials' => json_encode(['account_sid' => 'AC123', 'auth_token' => 'auth-token']),
            'status' => 'active',
        ]);

        $provider = $this->bindSmsProvider(sendMessageId: 'SM123');

        $this->actingAs($user)
            ->post('/app/settings/sms/test', ['to_number' => '+15557654321'])
            ->assertSessionHas('success');

        $provider->shouldHaveReceived('send')->once();
    }

    /** @return array{User, Company} */
    private function userWithCompany(string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => $role]);

        return [$user, $company];
    }

    private function bindEmailProvider(
        string $providerType,
        ?PingResult $pingResult = null,
        ?string $sendMessageId = null,
        ?PublishingException $sendException = null,
    ): EmailProvider {
        $provider = Mockery::mock(EmailProvider::class);
        $provider->shouldReceive('supports')->with($providerType)->andReturn(true)->byDefault();

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

        return $provider;
    }

    private function bindSmsProvider(
        ?PingResult $pingResult = null,
        ?string $sendMessageId = null,
        ?PublishingException $sendException = null,
    ): SmsProvider {
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

        return $provider;
    }
}

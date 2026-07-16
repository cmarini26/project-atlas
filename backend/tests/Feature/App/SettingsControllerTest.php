<?php

namespace Tests\Feature\App;

use App\Domain\Publishing\ValueObjects\PingResult;
use App\Jobs\SyncIntegration;
use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\InstagramAccount;
use App\Models\Integration;
use App\Models\MarketingChannel;
use App\Models\User;
use App\Services\Publishing\Email\Contracts\EmailProvider;
use App\Services\Publishing\Email\EmailProviderRegistry;
use App\Services\Publishing\Exceptions\PublishingException;
use App\Services\Publishing\WordPressPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_auth(): void
    {
        $this->get('/app/settings')->assertRedirect('/login');
    }

    public function test_index_renders_inertia_component(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/app/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('App/Settings'));
    }

    public function test_index_includes_company_and_integrations(): void
    {
        [$user, $company] = $this->userWithCompany();

        Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Website Scraper',
            'status' => 'active',
            'config' => ['url' => 'https://example.com'],
        ]);

        $this->actingAs($user)
            ->get('/app/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('company.name', 'Test Co')
                ->has('integrations', 1)
            );
    }

    public function test_index_includes_connected_meta_channels(): void
    {
        [$user, $company] = $this->userWithCompany();

        Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'facebook', 'name' => 'CBB Auctions', 'is_active' => true,
        ]);
        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'channel_type' => 'facebook', 'provider_type' => 'meta',
            'credentials' => 'page-token', 'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get('/app/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('meta_channels', 1)
                ->where('meta_channels.0.name', 'CBB Auctions')
                ->where('meta_channels.0.type', 'facebook')
            );
    }

    public function test_index_omits_revoked_meta_channels(): void
    {
        [$user, $company] = $this->userWithCompany();

        Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'facebook', 'name' => 'CBB Auctions', 'is_active' => true,
        ]);
        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'channel_type' => 'facebook', 'provider_type' => 'meta',
            'credentials' => 'page-token', 'status' => 'revoked',
        ]);

        $this->actingAs($user)
            ->get('/app/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('meta_channels', 0));
    }

    public function test_index_includes_null_wordpress_channel_when_not_connected(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/app/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('wordpress_channel', null));
    }

    public function test_index_includes_connected_wordpress_channel(): void
    {
        [$user, $company] = $this->userWithCompany();

        Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'blog', 'name' => 'blog.cbb-auctions.example',
            'config' => ['site_url' => 'https://blog.cbb-auctions.example'], 'is_active' => true,
        ]);
        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'channel_type' => 'blog', 'provider_type' => 'wordpress',
            'credentials' => json_encode(['username' => 'atlas', 'app_password' => 'xxxx']), 'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get('/app/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('wordpress_channel.name', 'blog.cbb-auctions.example')
                ->where('wordpress_channel.site_url', 'https://blog.cbb-auctions.example')
                ->where('wordpress_channel.status', 'active')
            );
    }

    public function test_index_omits_revoked_wordpress_channel(): void
    {
        [$user, $company] = $this->userWithCompany();

        Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'blog', 'name' => 'blog.cbb-auctions.example',
            'config' => ['site_url' => 'https://blog.cbb-auctions.example'], 'is_active' => true,
        ]);
        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'channel_type' => 'blog', 'provider_type' => 'wordpress',
            'credentials' => json_encode(['username' => 'atlas', 'app_password' => 'xxxx']), 'status' => 'revoked',
        ]);

        $this->actingAs($user)
            ->get('/app/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('wordpress_channel', null));
    }

    public function test_connect_wordpress_creates_channel_and_credentials_when_the_site_is_reachable(): void
    {
        [$user, $company] = $this->userWithCompany();

        $publisher = Mockery::mock(WordPressPublisher::class);
        $publisher->shouldReceive('ping')->once()->andReturn(new PingResult(reachable: true));
        $this->app->instance(WordPressPublisher::class, $publisher);

        $this->actingAs($user)
            ->post('/app/settings/wordpress/connect', [
                'site_url' => 'https://blog.cbb-auctions.example/',
                'username' => 'atlas',
                'app_password' => 'xxxx xxxx xxxx xxxx',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('channels', [
            'company_id' => $company->id,
            'type' => 'blog',
            'name' => 'blog.cbb-auctions.example',
        ]);
        $this->assertDatabaseHas('channel_credentials', [
            'company_id' => $company->id,
            'channel_type' => 'blog',
            'provider_type' => 'wordpress',
            'status' => 'active',
        ]);
    }

    public function test_connect_wordpress_rejects_unreachable_or_invalid_credentials(): void
    {
        // Task 2.1 (production-readiness plan): don't report "connected"
        // without verifying the site/account first.
        [$user, $company] = $this->userWithCompany();

        $publisher = Mockery::mock(WordPressPublisher::class);
        $publisher->shouldReceive('ping')->once()->andReturn(new PingResult(reachable: false, error: 'Invalid application password'));
        $this->app->instance(WordPressPublisher::class, $publisher);

        $this->actingAs($user)
            ->post('/app/settings/wordpress/connect', [
                'site_url' => 'https://blog.cbb-auctions.example/',
                'username' => 'atlas',
                'app_password' => 'wrong-password',
            ])
            ->assertSessionHasErrors(['app_password']);

        $this->assertDatabaseHas('channel_credentials', [
            'company_id' => $company->id,
            'channel_type' => 'blog',
            'provider_type' => 'wordpress',
            'status' => 'error',
        ]);
    }

    public function test_connect_wordpress_requires_valid_input(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/app/settings/wordpress/connect', [])
            ->assertSessionHasErrors(['site_url', 'username', 'app_password']);
    }

    public function test_connect_wordpress_rejects_a_non_http_scheme(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/app/settings/wordpress/connect', [
                'site_url' => 'javascript:alert(1)',
                'username' => 'atlas',
                'app_password' => 'xxxx xxxx xxxx xxxx',
            ])
            ->assertSessionHasErrors(['site_url']);

        $this->assertDatabaseMissing('channels', ['type' => 'blog']);
    }

    public function test_disconnect_wordpress_revokes_credentials(): void
    {
        [$user, $company] = $this->userWithCompany();

        Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'blog', 'name' => 'blog.cbb-auctions.example',
            'config' => ['site_url' => 'https://blog.cbb-auctions.example'], 'is_active' => true,
        ]);
        $credentials = ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'channel_type' => 'blog', 'provider_type' => 'wordpress',
            'credentials' => json_encode(['username' => 'atlas', 'app_password' => 'xxxx']), 'status' => 'active',
        ]);

        $this->actingAs($user)
            ->post('/app/settings/wordpress/revoke')
            ->assertRedirect();

        $this->assertSame('revoked', $credentials->fresh()->status);
    }

    // ── Email (Postmark) ─────────────────────────────────────────────────

    public function test_index_includes_null_email_channel_when_not_connected(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/app/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('email_channel', null));
    }

    public function test_index_includes_connected_email_channel_and_never_exposes_the_token(): void
    {
        [$user, $company] = $this->userWithCompany();

        Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'email', 'name' => 'Email',
            'config' => ['from_email' => 'hello@cbb-auctions.example', 'from_name' => 'CBB Auctions'],
            'is_active' => true,
        ]);
        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'channel_type' => 'email', 'provider_type' => 'postmark',
            'credentials' => 'super-secret-server-token', 'status' => 'active',
        ]);

        $response = $this->actingAs($user)
            ->get('/app/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('email_channel.provider_type', 'postmark')
                ->where('email_channel.from_email', 'hello@cbb-auctions.example')
                ->where('email_channel.from_name', 'CBB Auctions')
                ->where('email_channel.status', 'active')
            );

        $this->assertStringNotContainsString('super-secret-server-token', $response->getContent() ?: '');
    }

    public function test_index_omits_revoked_email_channel(): void
    {
        [$user, $company] = $this->userWithCompany();

        Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'email', 'name' => 'Email',
            'config' => ['from_email' => 'hello@cbb-auctions.example'], 'is_active' => true,
        ]);
        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'channel_type' => 'email', 'provider_type' => 'postmark',
            'credentials' => 'token', 'status' => 'revoked',
        ]);

        $this->actingAs($user)
            ->get('/app/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('email_channel', null));
    }

    public function test_connect_email_creates_channel_and_credentials_when_postmark_is_reachable(): void
    {
        [$user, $company] = $this->userWithCompany();
        $this->bindEmailProvider(pingResult: new PingResult(reachable: true));

        $this->actingAs($user)
            ->post('/app/settings/email/connect', [
                'api_token' => 'server-token-abc123',
                'from_email' => 'hello@cbb-auctions.example',
                'from_name' => 'CBB Auctions',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('channels', [
            'company_id' => $company->id,
            'type' => 'email',
        ]);
        $this->assertDatabaseHas('channel_credentials', [
            'company_id' => $company->id,
            'channel_type' => 'email',
            'provider_type' => 'postmark',
            'status' => 'active',
        ]);
    }

    public function test_connect_email_rejects_invalid_credentials(): void
    {
        [$user, $company] = $this->userWithCompany();
        $this->bindEmailProvider(pingResult: new PingResult(reachable: false, error: 'Invalid server API token'));

        $this->actingAs($user)
            ->post('/app/settings/email/connect', [
                'api_token' => 'wrong-token',
                'from_email' => 'hello@cbb-auctions.example',
            ])
            ->assertSessionHasErrors(['api_token']);

        $this->assertDatabaseHas('channel_credentials', [
            'company_id' => $company->id,
            'channel_type' => 'email',
            'provider_type' => 'postmark',
            'status' => 'error',
        ]);
    }

    public function test_connect_email_requires_valid_input(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/app/settings/email/connect', [])
            ->assertSessionHasErrors(['api_token', 'from_email']);
    }

    public function test_connect_email_credentials_are_encrypted_at_rest(): void
    {
        [$user, $company] = $this->userWithCompany();
        $this->bindEmailProvider(pingResult: new PingResult(reachable: true));

        $this->actingAs($user)->post('/app/settings/email/connect', [
            'api_token' => 'server-token-abc123',
            'from_email' => 'hello@cbb-auctions.example',
        ]);

        $raw = \DB::table('channel_credentials')
            ->where('company_id', $company->id)
            ->where('channel_type', 'email')
            ->value('credentials');

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('server-token-abc123', $raw);

        $decrypted = ChannelCredentials::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('channel_type', 'email')
            ->first();
        $this->assertSame('server-token-abc123', $decrypted->credentials);
    }

    public function test_reconnect_email_replaces_the_stored_token(): void
    {
        [$user, $company] = $this->userWithCompany();
        $this->bindEmailProvider(pingResult: new PingResult(reachable: true));

        $this->actingAs($user)->post('/app/settings/email/connect', [
            'api_token' => 'old-token',
            'from_email' => 'hello@cbb-auctions.example',
        ]);

        $this->bindEmailProvider(pingResult: new PingResult(reachable: true));

        $this->actingAs($user)->post('/app/settings/email/connect', [
            'api_token' => 'new-token',
            'from_email' => 'hello@cbb-auctions.example',
        ]);

        $this->assertDatabaseCount('channel_credentials', 1);
        $credentials = ChannelCredentials::withoutGlobalScopes()
            ->where('company_id', $company->id)->where('channel_type', 'email')->first();
        $this->assertSame('new-token', $credentials->credentials);
    }

    public function test_connecting_email_for_one_company_does_not_affect_another(): void
    {
        [$userA, $companyA] = $this->userWithCompany();

        $companyB = Company::withoutGlobalScopes()->create(['name' => 'Other Co', 'slug' => 'other-co']);

        $this->bindEmailProvider(pingResult: new PingResult(reachable: true));

        $this->actingAs($userA)->post('/app/settings/email/connect', [
            'api_token' => 'company-a-token',
            'from_email' => 'hello@company-a.example',
        ]);

        $this->assertDatabaseHas('channel_credentials', ['company_id' => $companyA->id, 'channel_type' => 'email']);
        $this->assertDatabaseMissing('channel_credentials', ['company_id' => $companyB->id, 'channel_type' => 'email']);
    }

    public function test_connect_email_marks_the_declared_marketing_channel_as_publishing_verified(): void
    {
        [$user, $company] = $this->userWithCompany();
        $this->bindEmailProvider(pingResult: new PingResult(reachable: true));

        $declared = MarketingChannel::factory()->create([
            'company_id' => $company->id,
            'type' => 'email',
            'supports_publishing' => false,
        ]);

        $this->actingAs($user)->post('/app/settings/email/connect', [
            'api_token' => 'server-token-abc123',
            'from_email' => 'hello@cbb-auctions.example',
        ]);

        $declared->refresh();
        $this->assertTrue($declared->supports_publishing);
        $this->assertTrue($declared->is_connected);
        $this->assertNotNull($declared->channel_id);
    }

    public function test_connect_email_does_not_fail_when_no_declared_channel_exists(): void
    {
        [$user] = $this->userWithCompany();
        $this->bindEmailProvider(pingResult: new PingResult(reachable: true));

        $this->actingAs($user)
            ->post('/app/settings/email/connect', [
                'api_token' => 'server-token-abc123',
                'from_email' => 'hello@cbb-auctions.example',
            ])
            ->assertSessionHas('success');
    }

    public function test_disconnect_email_revokes_credentials(): void
    {
        [$user, $company] = $this->userWithCompany();

        Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'email', 'name' => 'Email',
            'config' => ['from_email' => 'hello@cbb-auctions.example'], 'is_active' => true,
        ]);
        $credentials = ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'channel_type' => 'email', 'provider_type' => 'postmark',
            'credentials' => 'token', 'status' => 'active',
        ]);

        $this->actingAs($user)
            ->post('/app/settings/email/revoke')
            ->assertRedirect();

        $this->assertSame('revoked', $credentials->fresh()->status);
    }

    public function test_disconnect_email_is_idempotent(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)->post('/app/settings/email/revoke')->assertRedirect();
        $this->actingAs($user)->post('/app/settings/email/revoke')->assertRedirect();

        $this->assertDatabaseCount('channel_credentials', 0);
    }

    public function test_disconnect_email_unmarks_publishing_verified(): void
    {
        [$user, $company] = $this->userWithCompany();

        $channel = Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'email', 'name' => 'Email',
            'config' => ['from_email' => 'hello@cbb-auctions.example'], 'is_active' => true,
        ]);
        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'channel_type' => 'email', 'provider_type' => 'postmark',
            'credentials' => 'token', 'status' => 'active',
        ]);
        $declared = MarketingChannel::factory()->create([
            'company_id' => $company->id,
            'type' => 'email',
            'channel_id' => $channel->id,
            'is_connected' => true,
            'supports_publishing' => true,
        ]);

        $this->actingAs($user)->post('/app/settings/email/revoke');

        $this->assertFalse($declared->fresh()->supports_publishing);
    }

    public function test_disconnect_email_prevents_future_provider_resolution(): void
    {
        [$user, $company] = $this->userWithCompany();

        Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'email', 'name' => 'Email',
            'config' => ['from_email' => 'hello@cbb-auctions.example'], 'is_active' => true,
        ]);
        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'channel_type' => 'email', 'provider_type' => 'postmark',
            'credentials' => 'token', 'status' => 'active',
        ]);

        $this->actingAs($user)->post('/app/settings/email/revoke');

        $this->actingAs($user)
            ->post('/app/settings/email/test', ['to_email' => 'someone@example.com'])
            ->assertSessionHas('error');
    }

    public function test_send_email_test_succeeds_for_connected_company(): void
    {
        [$user, $company] = $this->userWithCompany();

        Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'email', 'name' => 'Email',
            'config' => ['from_email' => 'hello@cbb-auctions.example', 'from_name' => 'CBB Auctions'],
            'is_active' => true,
        ]);
        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'channel_type' => 'email', 'provider_type' => 'postmark',
            'credentials' => 'server-token', 'status' => 'active',
        ]);

        $provider = $this->bindEmailProvider(sendMessageId: 'msg-test-123');

        $this->actingAs($user)
            ->post('/app/settings/email/test', ['to_email' => 'owner@example.com'])
            ->assertSessionHas('success');

        $provider->shouldHaveReceived('send')->once();
    }

    public function test_send_email_test_fails_for_disconnected_company(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/app/settings/email/test', ['to_email' => 'owner@example.com'])
            ->assertSessionHas('error');
    }

    public function test_send_email_test_surfaces_provider_failure_without_leaking_the_token(): void
    {
        [$user, $company] = $this->userWithCompany();

        Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'email', 'name' => 'Email',
            'config' => ['from_email' => 'hello@cbb-auctions.example'], 'is_active' => true,
        ]);
        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'channel_type' => 'email', 'provider_type' => 'postmark',
            'credentials' => 'super-secret-server-token', 'status' => 'active',
        ]);

        $this->bindEmailProvider(sendException: new PublishingException('Postmark rejected the send: Invalid "From" address', retryable: false));

        $response = $this->actingAs($user)
            ->post('/app/settings/email/test', ['to_email' => 'owner@example.com'])
            ->assertSessionHas('error');

        $this->assertStringContainsString('Invalid "From" address', (string) session('error'));
        $this->assertStringNotContainsString('super-secret-server-token', (string) session('error'));
        $this->assertStringNotContainsString('super-secret-server-token', $response->getContent() ?: '');
    }

    public function test_send_email_test_requires_a_recipient_address(): void
    {
        [$user, $company] = $this->userWithCompany();

        Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'email', 'name' => 'Email',
            'config' => ['from_email' => 'hello@cbb-auctions.example'], 'is_active' => true,
        ]);
        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'channel_type' => 'email', 'provider_type' => 'postmark',
            'credentials' => 'token', 'status' => 'active',
        ]);

        $this->actingAs($user)
            ->post('/app/settings/email/test', [])
            ->assertSessionHasErrors(['to_email']);
    }

    public function test_credentials_never_appear_in_the_publishing_log(): void
    {
        $logPath = storage_path('logs/publishing.log');
        $before = is_file($logPath) ? filesize($logPath) : 0;

        [$user, $company] = $this->userWithCompany();

        Channel::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'type' => 'email', 'name' => 'Email',
            'config' => ['from_email' => 'hello@cbb-auctions.example'], 'is_active' => true,
        ]);
        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'channel_type' => 'email', 'provider_type' => 'postmark',
            'credentials' => 'super-secret-server-token', 'status' => 'active',
        ]);

        $this->bindEmailProvider(sendMessageId: 'msg-test-123');

        $this->actingAs($user)->post('/app/settings/email/test', ['to_email' => 'owner@example.com']);

        $this->assertTrue(is_file($logPath), 'Expected the publishing log to exist after a test send.');
        $written = substr(file_get_contents($logPath) ?: '', $before);
        $this->assertStringContainsString('email test send succeeded', $written);
        $this->assertStringNotContainsString('super-secret-server-token', $written);
    }

    /**
     * Binds a fresh EmailProviderRegistry containing a single mocked
     * EmailProvider (supports() → 'postmark' only), mirroring the pattern
     * CheckChannelHealthTest already uses for ChannelPublisherRegistry.
     */
    private function bindEmailProvider(
        ?PingResult $pingResult = null,
        ?string $sendMessageId = null,
        ?PublishingException $sendException = null,
    ): EmailProvider {
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

        return $provider;
    }

    public function test_update_saves_company_name(): void
    {
        [$user, $company] = $this->userWithCompany();

        $this->actingAs($user)
            ->patch('/app/settings', [
                'name' => 'Updated Business Name',
                'industry' => 'retail',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'name' => 'Updated Business Name',
        ]);
    }

    public function test_update_requires_name(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->patch('/app/settings', ['industry' => 'retail'])
            ->assertSessionHasErrors('name');
    }

    public function test_sync_integration_dispatches_job(): void
    {
        Bus::fake();

        [$user, $company] = $this->userWithCompany();

        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'status' => 'active',
            'config' => ['url' => 'https://example.com'],
        ]);

        $this->actingAs($user)
            ->post("/app/settings/integrations/{$integration->id}/sync")
            ->assertRedirect();

        Bus::assertDispatched(SyncIntegration::class, fn ($job) => $job->integration->id === $integration->id);
    }

    public function test_sync_integration_is_denied_for_other_company(): void
    {
        [$user] = $this->userWithCompany();
        $other = Company::withoutGlobalScopes()->create(['name' => 'Other', 'slug' => 'other']);

        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $other->id,
            'type' => 'website_crawl',
            'name' => 'Website',
            'status' => 'active',
            'config' => ['url' => 'https://example.com'],
        ]);

        $this->actingAs($user)
            ->post("/app/settings/integrations/{$integration->id}/sync")
            ->assertNotFound();
    }

    public function test_index_includes_null_instagram_account_when_not_connected(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->get('/app/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('instagram_account', null));
    }

    public function test_index_includes_instagram_account_snapshot_when_connected(): void
    {
        [$user, $company] = $this->userWithCompany();

        $integration = Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'instagram',
            'name' => 'Instagram',
            'status' => 'active',
            'config' => ['access_token' => 'token-123'],
        ]);

        InstagramAccount::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'integration_id' => $integration->id,
            'account_id' => '17841400000000',
            'username' => 'cbb_auctions',
            'display_name' => 'CBB Auctions',
            'follower_count' => 4210,
            'following_count' => 180,
            'last_synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/app/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('instagram_account.username', 'cbb_auctions')
                ->where('instagram_account.follower_count', 4210)
            );
    }

    public function test_connect_instagram_creates_an_integration_and_dispatches_sync(): void
    {
        Bus::fake();

        [$user, $company] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/app/settings/integrations/instagram', ['access_token' => 'token-abc'])
            ->assertRedirect();

        $this->assertDatabaseHas('integrations', [
            'company_id' => $company->id,
            'type' => 'instagram',
            'status' => 'active',
        ]);

        Bus::assertDispatched(SyncIntegration::class, fn ($job) => $job->integration->company_id === $company->id);
    }

    public function test_connect_instagram_requires_an_access_token(): void
    {
        [$user] = $this->userWithCompany();

        $this->actingAs($user)
            ->post('/app/settings/integrations/instagram', [])
            ->assertSessionHasErrors('access_token');
    }

    public function test_connect_instagram_reuses_the_existing_integration_on_reconnect(): void
    {
        Bus::fake();

        [$user, $company] = $this->userWithCompany();

        $existing = Integration::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'type' => 'instagram',
            'name' => 'Instagram',
            'status' => 'error',
            'config' => ['access_token' => 'old-token'],
            'last_error' => 'Invalid token',
        ]);

        $this->actingAs($user)
            ->post('/app/settings/integrations/instagram', ['access_token' => 'new-token'])
            ->assertRedirect();

        $this->assertSame(1, Integration::withoutGlobalScopes()->where('company_id', $company->id)->where('type', 'instagram')->count());

        $existing->refresh();
        $this->assertSame('active', $existing->status);
        $this->assertNull($existing->last_error);
        $this->assertSame('new-token', $existing->config['access_token']);
    }

    /** @return array{User, Company} */
    private function userWithCompany(string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => $role]);

        return [$user, $company];
    }
}

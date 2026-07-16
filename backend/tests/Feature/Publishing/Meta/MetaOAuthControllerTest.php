<?php

namespace Tests\Feature\Publishing\Meta;

use App\Models\Channel;
use App\Models\ChannelCredentials;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\MarketingChannel;
use App\Models\User;
use App\Services\Publishing\MetaOAuthService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaOAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<Response>  $responses */
    private function bindMockedOAuthService(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $http = new Client(['handler' => $stack]);

        $this->app->instance(
            MetaOAuthService::class,
            new MetaOAuthService($http, 'app-id', 'app-secret', 'https://atlas.test/app/settings/meta/callback'),
        );
    }

    /** @return array{User, Company} */
    private function userWithCompany(string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);
        CompanyMembership::create(['company_id' => $company->id, 'user_id' => $user->id, 'role' => $role]);

        return [$user, $company];
    }

    public function test_redirect_stores_state_and_verifier_in_session_and_redirects_to_meta(): void
    {
        [$user] = $this->userWithCompany();
        $this->bindMockedOAuthService([]);

        $response = $this->actingAs($user)->get('/app/settings/meta/connect');

        $response->assertRedirect();
        $this->assertStringStartsWith('https://www.facebook.com/v19.0/dialog/oauth?', $response->headers->get('Location'));
        $this->assertNotNull(session('meta_oauth_state'));
        $this->assertNotNull(session('meta_oauth_code_verifier'));
    }

    public function test_callback_rejects_a_missing_state(): void
    {
        [$user] = $this->userWithCompany();
        $this->bindMockedOAuthService([]);

        $response = $this->actingAs($user)
            ->withSession(['meta_oauth_state' => 'expected-state', 'meta_oauth_code_verifier' => 'verifier'])
            ->get('/app/settings/meta/callback?code=abc');

        $response->assertRedirect(route('app.settings'));
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('channel_credentials', 0);
    }

    public function test_callback_rejects_a_mismatched_state(): void
    {
        [$user] = $this->userWithCompany();
        $this->bindMockedOAuthService([]);

        $response = $this->actingAs($user)
            ->withSession(['meta_oauth_state' => 'expected-state', 'meta_oauth_code_verifier' => 'verifier'])
            ->get('/app/settings/meta/callback?state=tampered-state&code=abc');

        $response->assertRedirect(route('app.settings'));
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('channel_credentials', 0);
    }

    public function test_callback_clears_session_state_regardless_of_outcome(): void
    {
        [$user] = $this->userWithCompany();
        $this->bindMockedOAuthService([]);

        $this->actingAs($user)
            ->withSession(['meta_oauth_state' => 'expected-state', 'meta_oauth_code_verifier' => 'verifier'])
            ->get('/app/settings/meta/callback?state=tampered&code=abc');

        $this->assertNull(session('meta_oauth_state'));
        $this->assertNull(session('meta_oauth_code_verifier'));
    }

    public function test_callback_success_stores_encrypted_credentials_and_channel_name(): void
    {
        [$user, $company] = $this->userWithCompany();
        $this->bindMockedOAuthService([
            new Response(200, [], json_encode(['access_token' => 'short-lived'])),
            new Response(200, [], json_encode(['access_token' => 'long-lived'])),
            new Response(200, [], json_encode(['data' => [
                ['id' => 'page-1', 'name' => 'CBB Auctions', 'access_token' => 'page-token-1'],
            ]])),
            new Response(200, [], json_encode([])), // no linked Instagram business account
        ]);

        $response = $this->actingAs($user)
            ->withSession(['meta_oauth_state' => 'expected-state', 'meta_oauth_code_verifier' => 'verifier'])
            ->get('/app/settings/meta/callback?state=expected-state&code=abc');

        $response->assertRedirect(route('app.settings'));
        $response->assertSessionHas('success');

        $credentials = ChannelCredentials::withoutGlobalScopes()
            ->where('company_id', $company->id)->where('channel_type', 'facebook')->first();
        $this->assertNotNull($credentials);
        $stored = json_decode((string) $credentials->credentials, true);
        $this->assertSame('page-token-1', $stored['access_token']);
        $this->assertSame('page-1', $stored['target_id']);
        $this->assertSame('active', $credentials->status);
        $this->assertSame('meta', $credentials->provider_type);

        $channel = Channel::where('company_id', $company->id)->where('type', 'facebook')->first();
        $this->assertSame('CBB Auctions', $channel->name);

        $this->assertDatabaseMissing('channel_credentials', ['company_id' => $company->id, 'channel_type' => 'instagram']);
    }

    public function test_callback_also_connects_instagram_when_a_business_account_is_linked(): void
    {
        [$user, $company] = $this->userWithCompany();
        $this->bindMockedOAuthService([
            new Response(200, [], json_encode(['access_token' => 'short-lived'])),
            new Response(200, [], json_encode(['access_token' => 'long-lived'])),
            new Response(200, [], json_encode(['data' => [
                ['id' => 'page-1', 'name' => 'CBB Auctions', 'access_token' => 'page-token-1'],
            ]])),
            new Response(200, [], json_encode(['instagram_business_account' => ['id' => 'ig-account-1']])),
        ]);

        $this->actingAs($user)
            ->withSession(['meta_oauth_state' => 'expected-state', 'meta_oauth_code_verifier' => 'verifier'])
            ->get('/app/settings/meta/callback?state=expected-state&code=abc');

        $credentials = ChannelCredentials::withoutGlobalScopes()
            ->where('company_id', $company->id)->where('channel_type', 'instagram')->first();
        $this->assertNotNull($credentials);
        $stored = json_decode((string) $credentials->credentials, true);
        $this->assertSame('ig-account-1', $stored['target_id']);

        $channel = Channel::where('company_id', $company->id)->where('type', 'instagram')->first();
        $this->assertSame('CBB Auctions', $channel->name);
    }

    public function test_callback_fails_gracefully_when_no_pages_exist(): void
    {
        [$user] = $this->userWithCompany();
        $this->bindMockedOAuthService([
            new Response(200, [], json_encode(['access_token' => 'short-lived'])),
            new Response(200, [], json_encode(['access_token' => 'long-lived'])),
            new Response(200, [], json_encode(['data' => []])),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['meta_oauth_state' => 'expected-state', 'meta_oauth_code_verifier' => 'verifier'])
            ->get('/app/settings/meta/callback?state=expected-state&code=abc');

        $response->assertSessionHas('error');
        $this->assertDatabaseCount('channel_credentials', 0);
    }

    public function test_callback_marks_a_declared_facebook_channel_as_publishing_verified(): void
    {
        // Production-readiness gap plan, channel capability truth slice:
        // MarketingPresenceService::link()'s docblock explicitly deferred
        // supports_publishing to "a later upgrade" — this closes that gap
        // for the one connect flow (Meta OAuth) that already exists.
        [$user, $company] = $this->userWithCompany();

        $declared = MarketingChannel::factory()->create([
            'company_id' => $company->id,
            'type' => 'facebook',
        ]);

        $this->bindMockedOAuthService([
            new Response(200, [], json_encode(['access_token' => 'short-lived'])),
            new Response(200, [], json_encode(['access_token' => 'long-lived'])),
            new Response(200, [], json_encode(['data' => [
                ['id' => 'page-1', 'name' => 'CBB Auctions', 'access_token' => 'page-token-1'],
            ]])),
            new Response(200, [], json_encode([])),
        ]);

        $this->actingAs($user)
            ->withSession(['meta_oauth_state' => 'expected-state', 'meta_oauth_code_verifier' => 'verifier'])
            ->get('/app/settings/meta/callback?state=expected-state&code=abc');

        $declared->refresh();
        $this->assertTrue($declared->supports_publishing);
        $this->assertTrue($declared->is_connected);
        $this->assertNotNull($declared->channel_id);
    }

    public function test_callback_does_not_fail_when_no_declared_channel_exists_to_link(): void
    {
        [$user] = $this->userWithCompany();

        $this->bindMockedOAuthService([
            new Response(200, [], json_encode(['access_token' => 'short-lived'])),
            new Response(200, [], json_encode(['access_token' => 'long-lived'])),
            new Response(200, [], json_encode(['data' => [
                ['id' => 'page-1', 'name' => 'CBB Auctions', 'access_token' => 'page-token-1'],
            ]])),
            new Response(200, [], json_encode([])),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['meta_oauth_state' => 'expected-state', 'meta_oauth_code_verifier' => 'verifier'])
            ->get('/app/settings/meta/callback?state=expected-state&code=abc');

        $response->assertSessionHas('success');
    }

    public function test_revoke_marks_declared_meta_channels_as_no_longer_publishing_verified(): void
    {
        [$user, $company] = $this->userWithCompany();

        $declaredFacebook = MarketingChannel::factory()->create([
            'company_id' => $company->id,
            'type' => 'facebook',
            'supports_publishing' => true,
        ]);
        $declaredInstagram = MarketingChannel::factory()->create([
            'company_id' => $company->id,
            'type' => 'instagram',
            'supports_publishing' => true,
        ]);

        $this->actingAs($user)->post('/app/settings/meta/revoke');

        $this->assertFalse($declaredFacebook->fresh()->supports_publishing);
        $this->assertFalse($declaredInstagram->fresh()->supports_publishing);
    }

    public function test_revoke_marks_meta_credentials_as_revoked(): void
    {
        [$user, $company] = $this->userWithCompany();
        ChannelCredentials::withoutGlobalScopes()->create([
            'company_id' => $company->id, 'channel_type' => 'facebook', 'provider_type' => 'meta',
            'credentials' => 'page-token', 'status' => 'active',
        ]);

        $response = $this->actingAs($user)->post('/app/settings/meta/revoke');

        $response->assertRedirect(route('app.settings'));
        $this->assertSame('revoked', ChannelCredentials::withoutGlobalScopes()->where('company_id', $company->id)->first()->status);
    }

    public function test_redirect_requires_auth(): void
    {
        $this->get('/app/settings/meta/connect')->assertRedirect('/login');
    }
}

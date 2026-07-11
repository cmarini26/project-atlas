<?php

namespace Tests\Feature\Discovery;

use App\Models\Company;
use App\Models\Integration;
use App\Services\Observatory\Connectors\ConnectorResult;
use App\Services\Observatory\Connectors\Instagram\Exceptions\InstagramApiException;
use App\Services\Observatory\Connectors\Instagram\InstagramConnector;
use App\Services\Observatory\Connectors\Instagram\InstagramProfileData;
use App\Services\Observatory\Connectors\Instagram\InstagramProfileFetcher;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class InstagramConnectorTest extends TestCase
{
    use RefreshDatabase;

    private function makeIntegration(Company $company, array $overrides = []): Integration
    {
        return Integration::withoutGlobalScopes()->make(array_merge([
            'company_id' => $company->id,
            'type' => 'instagram',
            'name' => 'Instagram',
            'config' => ['access_token' => 'token-123'],
            'status' => 'active',
        ], $overrides));
    }

    public function test_sync_maps_the_fetched_profile_to_a_single_connector_result(): void
    {
        $fetchedAt = new DateTimeImmutable('2026-07-11T12:00:00Z');

        $profile = new InstagramProfileData(
            accountId: '17841400000000',
            username: 'cbb_auctions',
            displayName: 'CBB Auctions',
            profilePictureUrl: 'https://example.com/pic.jpg',
            bio: 'Comic book auctions every week.',
            website: 'https://cbbauctions.com',
            followerCount: 4210,
            followingCount: 180,
            fetchedAt: $fetchedAt,
        );

        $fetcher = Mockery::mock(InstagramProfileFetcher::class);
        $fetcher->expects('fetchProfile')->once()->with('token-123')->andReturn($profile);

        $connector = new InstagramConnector($fetcher);

        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);
        $integration = $this->makeIntegration($company);

        $results = $connector->sync($integration);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(ConnectorResult::class, $results->first());
        $this->assertSame('social', $results->first()->sourceType);
        $this->assertSame('cbb_auctions', $results->first()->sourceIdentifier);
        $this->assertSame($fetchedAt, $results->first()->observedAt);

        $payload = json_decode($results->first()->payload, true);
        $this->assertSame('cbb_auctions', $payload['username']);
        $this->assertSame(4210, $payload['follower_count']);
    }

    public function test_sync_throws_when_no_access_token_is_configured(): void
    {
        $fetcher = Mockery::mock(InstagramProfileFetcher::class);
        $fetcher->expects('fetchProfile')->never();

        $connector = new InstagramConnector($fetcher);

        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);
        $integration = $this->makeIntegration($company, ['config' => []]);

        $this->expectException(InstagramApiException::class);
        $this->expectExceptionMessage('no Instagram access token configured');

        $connector->sync($integration);
    }

    public function test_supports_only_instagram_integrations(): void
    {
        $connector = new InstagramConnector(Mockery::mock(InstagramProfileFetcher::class));

        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);

        $instagram = $this->makeIntegration($company);
        $website = $this->makeIntegration($company, ['type' => 'website_crawl', 'config' => ['url' => 'https://example.com']]);

        $this->assertTrue($connector->supports($instagram));
        $this->assertFalse($connector->supports($website));
    }
}

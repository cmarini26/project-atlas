<?php

namespace Tests\Feature\Discovery;

use App\Models\Company;
use App\Models\Integration;
use App\Services\Observatory\Connectors\ConnectorResult;
use App\Services\Observatory\Connectors\Instagram\Exceptions\InstagramApiException;
use App\Services\Observatory\Connectors\Instagram\InstagramConnector;
use App\Services\Observatory\Connectors\Instagram\InstagramMediaFetcher;
use App\Services\Observatory\Connectors\Instagram\InstagramMediaItemData;
use App\Services\Observatory\Connectors\Instagram\InstagramProfileData;
use App\Services\Observatory\Connectors\Instagram\InstagramProfileFetcher;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
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

    private function makeProfile(DateTimeImmutable $fetchedAt): InstagramProfileData
    {
        return new InstagramProfileData(
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
    }

    private function makeConnector(
        InstagramProfileData $profile,
        ?Collection $media = null,
        int $mediaLimit = 20,
    ): InstagramConnector {
        $fetcher = Mockery::mock(InstagramProfileFetcher::class);
        $fetcher->expects('fetchProfile')->once()->with('token-123')->andReturn($profile);

        $mediaFetcher = Mockery::mock(InstagramMediaFetcher::class);
        $mediaFetcher->expects('fetchRecentMedia')->once()->with('token-123', $mediaLimit)->andReturn($media ?? collect());

        return new InstagramConnector($fetcher, $mediaFetcher, $mediaLimit);
    }

    public function test_sync_returns_a_profile_and_a_content_connector_result(): void
    {
        $fetchedAt = new DateTimeImmutable('2026-07-11T12:00:00Z');
        $profile = $this->makeProfile($fetchedAt);

        $post = new InstagramMediaItemData(
            id: '1001',
            caption: 'Ending soon! #comics #auction',
            timestamp: $fetchedAt,
            mediaType: 'IMAGE',
            permalink: 'https://instagram.com/p/abc',
            likeCount: 120,
            commentsCount: 8,
            hashtags: ['comics', 'auction'],
            mentions: [],
        );

        $connector = $this->makeConnector($profile, collect([$post]));

        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);
        $integration = $this->makeIntegration($company);

        $results = $connector->sync($integration);

        $this->assertCount(2, $results);

        $profileResult = $results->first();
        $this->assertInstanceOf(ConnectorResult::class, $profileResult);
        $this->assertSame('social', $profileResult->sourceType);
        $this->assertSame('cbb_auctions', $profileResult->sourceIdentifier);
        $this->assertSame($fetchedAt, $profileResult->observedAt);

        $profilePayload = json_decode($profileResult->payload, true);
        $this->assertSame('cbb_auctions', $profilePayload['username']);
        $this->assertSame(4210, $profilePayload['follower_count']);

        $contentResult = $results->last();
        $this->assertSame('social_content', $contentResult->sourceType);
        $this->assertSame('cbb_auctions-recent-media', $contentResult->sourceIdentifier);

        $contentPayload = json_decode($contentResult->payload, true);
        $this->assertCount(1, $contentPayload['posts']);
        $this->assertSame('1001', $contentPayload['posts'][0]['id']);
        $this->assertSame(['comics', 'auction'], $contentPayload['posts'][0]['hashtags']);
        $this->assertSame(20, $contentPayload['media_limit']);
    }

    public function test_sync_uses_a_configurable_media_limit(): void
    {
        $fetchedAt = new DateTimeImmutable('2026-07-11T12:00:00Z');
        $profile = $this->makeProfile($fetchedAt);

        $connector = $this->makeConnector($profile, collect(), mediaLimit: 5);

        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);
        $integration = $this->makeIntegration($company);

        $results = $connector->sync($integration);

        $contentPayload = json_decode($results->last()->payload, true);
        $this->assertSame(5, $contentPayload['media_limit']);
    }

    public function test_sync_throws_when_no_access_token_is_configured(): void
    {
        $fetcher = Mockery::mock(InstagramProfileFetcher::class);
        $fetcher->expects('fetchProfile')->never();
        $mediaFetcher = Mockery::mock(InstagramMediaFetcher::class);
        $mediaFetcher->expects('fetchRecentMedia')->never();

        $connector = new InstagramConnector($fetcher, $mediaFetcher);

        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);
        $integration = $this->makeIntegration($company, ['config' => []]);

        $this->expectException(InstagramApiException::class);
        $this->expectExceptionMessage('no Instagram access token configured');

        $connector->sync($integration);
    }

    public function test_supports_only_instagram_integrations(): void
    {
        $connector = new InstagramConnector(
            Mockery::mock(InstagramProfileFetcher::class),
            Mockery::mock(InstagramMediaFetcher::class),
        );

        $company = Company::withoutGlobalScopes()->create(['name' => 'Test Co', 'slug' => 'test-co']);

        $instagram = $this->makeIntegration($company);
        $website = $this->makeIntegration($company, ['type' => 'website_crawl', 'config' => ['url' => 'https://example.com']]);

        $this->assertTrue($connector->supports($instagram));
        $this->assertFalse($connector->supports($website));
    }
}
